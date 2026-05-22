<?php

declare(strict_types=1);

namespace CodexRuntime;

use CodexRuntime\ManagerQueue\EventRepository;
use CodexRuntime\ProjectsRegistry;
use RuntimeException;
use Throwable;

final class IdleWatchdogWorker
{
    private const SKIP_OUTSIDE_ACTIVE_HOURS = 'outside_active_hours';
    private const SKIP_MANAGER_QUEUE_NOT_EMPTY = 'manager_queue_not_empty';
    private const SKIP_COMMAND_QUEUE_NOT_EMPTY = 'command_queue_not_empty';
    private const SKIP_MANAGER_RUNNING_NOT_EMPTY = 'manager_running_not_empty';
    private const SKIP_COMMAND_RUNNING_NOT_EMPTY = 'command_running_not_empty';
    private const SKIP_MANAGER_QUEUE_STALLED = 'manager_queue_stalled';
    private const SKIP_COMMAND_QUEUE_STALLED = 'command_queue_stalled';
    private const SKIP_ACTIVE_TASK = 'active_task_present';
    private const SKIP_RECENT_CHAT_ACTIVITY = 'recent_chat_activity';
    private const SKIP_WAITING_FOR_USER_RESPONSE = 'waiting_for_user_response';
    private const SKIP_COOLDOWN = 'cooldown_not_elapsed';
    private const SKIP_DUPLICATE_STALLED_RUNNING = 'duplicate_stalled_running_nudge';
    private const NUDGE_STALLED_RUNNING = 'stalled_running';

    private $lockHandle = null;

    public function __construct(
        private Config $config,
        private Logger $logger,
        private EventRepository $managerEvents,
        private JsonFileStore $managerStateStore,
        private JsonFileStore $stateStore,
        private ProjectsRegistry $projects,
        private WorkerShutdownFlag $shutdown
    ) {
    }

    public function run(): void
    {
        if (!(bool) $this->config->get('idle_watchdog', 'enabled', true)) {
            $this->logger->info('Idle watchdog disabled by config');
            return;
        }

        $this->acquireLock();
        $this->logger->info('Idle watchdog started');
        $pollIntervalSeconds = max(1, (int) $this->config->get('idle_watchdog', 'poll_interval_seconds', 60));

        while (true) {
            if ($this->shutdown->consumeIfRequested()) {
                $this->logger->info('Idle watchdog exiting for shutdown request');
                return;
            }

            try {
                $this->tick();
            } catch (Throwable $e) {
                $this->logger->error('Idle watchdog error', ['error' => $e->getMessage()]);
            }

            sleep($pollIntervalSeconds);
        }
    }

    private function tick(): void
    {
        $now = date(DATE_ATOM);
        $this->updateState([
            'last_check_at' => $now,
        ]);

        if (!$this->isWithinActiveHours()) {
            $this->skip(
                self::SKIP_OUTSIDE_ACTIVE_HOURS,
                'Idle watchdog skip: outside active hours',
                [
                    'active_hours_start' => (int) $this->config->get('idle_watchdog', 'active_hours_start', 8),
                    'active_hours_end' => (int) $this->config->get('idle_watchdog', 'active_hours_end', 22),
                    'current_hour' => (int) date('G'),
                ],
                'debug'
            );
            return;
        }

        $systemIdle = $this->systemIdleStatus();
        if (!$systemIdle['idle']) {
            $this->skip(
                $systemIdle['reason'],
                $systemIdle['message'],
                $systemIdle['context'],
                $systemIdle['level'] ?? 'info'
            );
            return;
        }

        $chatIdle = $this->chatIdleStatus();
        if (!$chatIdle['idle']) {
            $this->skip($chatIdle['reason'], $chatIdle['message'], $chatIdle['context'], 'debug');
            return;
        }

        if ($this->isWaitingForUserResponse()) {
            $this->skip(
                self::SKIP_WAITING_FOR_USER_RESPONSE,
                'Idle watchdog skip: waiting for user response',
                $this->waitingForUserResponseContext(),
                'info'
            );
            return;
        }

        $cooldown = $this->cooldownStatus();
        if (!$cooldown['elapsed']) {
            $this->skip($cooldown['reason'], $cooldown['message'], $cooldown['context'], 'debug');
            return;
        }

        $this->updateState([
            'last_skip_reason' => null,
        ]);

        $prompt = (string) ($systemIdle['prompt'] ?? $this->config->get(
            'idle_watchdog',
            'prompt',
            'Проверь, не забыл ли ты поставить следующий шаг. Если никаких действий не требуется, ничего пользователю не пиши.'
        ));
        $selectedProject = $this->selectedProjectForIdle();
        $prompt = $this->appendProjectSummaryToPrompt($prompt);
        $title = (string) ($systemIdle['title'] ?? 'Idle watchdog nudge');
        $meta = [
            'source' => 'idle_watchdog',
            'triggered_at' => date(DATE_ATOM),
        ];
        if (isset($systemIdle['meta']) && is_array($systemIdle['meta'])) {
            $meta += $systemIdle['meta'];
        }

        if ($this->isDuplicateStalledRunningNudge($meta)) {
            $this->skip(
                self::SKIP_DUPLICATE_STALLED_RUNNING,
                'Idle watchdog skip: stalled-running nudge already sent for the same running item',
                [
                    'nudge_kind' => $meta['nudge_kind'] ?? null,
                    'queue_kind' => $meta['queue_kind'] ?? null,
                    'running_item_id' => $meta['running_item_id'] ?? null,
                ],
                'info'
            );
            return;
        }

        if (is_array($selectedProject)) {
            $this->projects->touch((string) ($selectedProject['project_root'] ?? ''), [
                'source' => 'idle_watchdog',
                'status' => 'selected',
            ]);
        }

        $eventId = $this->managerEvents->enqueue([
            'type' => 'internal_decision',
            'priority' => (int) $this->config->get('idle_watchdog', 'enqueue_priority', 20),
            'title' => $title,
            'prompt' => $prompt,
            'meta' => $meta,
        ]);

        $triggeredAt = date(DATE_ATOM);
        $this->updateState([
            'last_nudge_at' => $triggeredAt,
            'last_ping_at' => $triggeredAt,
            'last_ping_event_id' => $eventId,
            'last_ping_meta' => $meta,
            'last_skip_reason' => null,
            'last_skip_context' => null,
        ]);

        $this->logger->info('Idle watchdog enqueued manager nudge', [
            'event_id' => $eventId,
            'triggered_at' => $triggeredAt,
        ]);
    }

    private function isWithinActiveHours(): bool
    {
        $startHour = max(0, min(23, (int) $this->config->get('idle_watchdog', 'active_hours_start', 8)));
        $endHour = max(0, min(24, (int) $this->config->get('idle_watchdog', 'active_hours_end', 22)));
        $hour = (int) date('G');

        if ($startHour === $endHour) {
            return true;
        }

        if ($startHour < $endHour) {
            return $hour >= $startHour && $hour < $endHour;
        }

        return $hour >= $startHour || $hour < $endHour;
    }

    private function appendProjectSummaryToPrompt(string $prompt): string
    {
        $prompt = trim($prompt);
        $projectsRoot = trim((string) $this->config->get('idle_watchdog', 'projects_scan_root', '/home/web/Документы'));
        if ($projectsRoot === '') {
            return $prompt;
        }

        $projects = $this->projects->listProjects($projectsRoot);
        if ($projects === []) {
            return $prompt;
        }

        $recommendedProject = $this->projects->recommendedProject($projectsRoot);
        if ($recommendedProject === null) {
        return $prompt . "\n\nВсе найденные проекты сейчас помечены blocked. Не пытайся выбирать другой проект и не предлагай абстрактные варианты. Отправь пользователю сообщение, что дальнейшие действия заблокированы, потому что требуется его решение. Верни notify_user=true и await_user_response=true.";
        }

        $recommendedProjectRoot = (string) ($recommendedProject['project_root'] ?? '');
        $recommendedProjectFile = (string) ($recommendedProject['project_file'] ?? ($recommendedProjectRoot !== '' ? $recommendedProjectRoot . '/PROJECT.md' : ''));
        $recommendedStateFile = (string) ($recommendedProject['state_file'] ?? ($recommendedProjectRoot !== '' ? $recommendedProjectRoot . '/project-state.json' : ''));
        $recommendedStatus = (string) ($recommendedProject['status'] ?? 'unknown');
        $recommendedLastTouchedAt = (string) ($recommendedProject['last_touched_at'] ?? 'never touched');

        return $prompt
            . "\n\nRuntime уже выбрал один проект без блокера, которым нужно заниматься сейчас."
            . "\nproject_root: {$recommendedProjectRoot}"
            . "\nstatus: {$recommendedStatus}"
            . "\nPROJECT.md: {$recommendedProjectFile}"
            . "\nproject-state.json: {$recommendedStateFile}"
            . "\nlast_touched_at: {$recommendedLastTouchedAt}"
            . "\nЕсли по этому проекту есть настоящий блокер или нужно решение пользователя, обнови именно этот project-state.json: выставь blocked=true и кратко запиши blocked_reason.";
    }

    private function selectedProjectForIdle(): ?array
    {
        $projectsRoot = trim((string) $this->config->get('idle_watchdog', 'projects_scan_root', '/home/web/Документы'));
        if ($projectsRoot === '') {
            return null;
        }

        return $this->projects->recommendedProject($projectsRoot);
    }

    private function systemIdleStatus(): array
    {
        $managerLock = $this->workerLockStatus((string) $this->config->require('manager_queue', 'lock_file'));
        $commandLock = $this->workerLockStatus((string) $this->config->require('command_watcher', 'lock_file'));

        $managerQueueNew = $this->queueSummary((string) $this->config->require('manager_queue', 'queue_new'));
        if ($managerQueueNew['count'] > 0) {
            $context = [
                'queue' => 'manager_queue_new',
                'worker_lock_held' => $managerLock['held'],
                'worker_lock_file' => $managerLock['lock_file'],
            ] + $managerQueueNew;

            if ($managerLock['held'] === false) {
                return [
                    'idle' => false,
                    'reason' => self::SKIP_MANAGER_QUEUE_STALLED,
                    'message' => 'Idle watchdog stall: manager queue has pending items but manager worker lock is not held',
                    'context' => $context,
                    'level' => 'error',
                ];
            }

            return [
                'idle' => false,
                'reason' => self::SKIP_MANAGER_QUEUE_NOT_EMPTY,
                'message' => 'Idle watchdog skip: manager queue is not empty',
                'context' => $context,
                'level' => 'info',
            ];
        }

        $commandQueueNew = $this->queueSummary((string) $this->config->require('command_watcher', 'queue_new'));
        if ($commandQueueNew['count'] > 0) {
            $context = [
                'queue' => 'command_queue_new',
                'worker_lock_held' => $commandLock['held'],
                'worker_lock_file' => $commandLock['lock_file'],
            ] + $commandQueueNew;

            if ($commandLock['held'] === false) {
                return [
                    'idle' => false,
                    'reason' => self::SKIP_COMMAND_QUEUE_STALLED,
                    'message' => 'Idle watchdog stall: command queue has pending items but command watcher lock is not held',
                    'context' => $context,
                    'level' => 'error',
                ];
            }

            return [
                'idle' => false,
                'reason' => self::SKIP_COMMAND_QUEUE_NOT_EMPTY,
                'message' => 'Idle watchdog skip: command queue is not empty',
                'context' => $context,
                'level' => 'info',
            ];
        }

        $managerQueueRunning = $this->queueSummary((string) $this->config->require('manager_queue', 'queue_running'));
        if ($managerQueueRunning['count'] > 0) {
            $context = [
                'queue' => 'manager_queue_running',
                'worker_lock_held' => $managerLock['held'],
                'worker_lock_file' => $managerLock['lock_file'],
            ] + $managerQueueRunning;

            if ($managerLock['held'] === false) {
                return [
                    'idle' => false,
                    'reason' => self::SKIP_MANAGER_QUEUE_STALLED,
                    'message' => 'Idle watchdog stall: manager running queue is not empty but manager worker lock is not held',
                    'context' => $context,
                    'level' => 'error',
                ];
            }

            if ($this->isSelfReferentialManagerRunningItem($managerQueueRunning)) {
                return [
                    'idle' => false,
                    'reason' => self::SKIP_MANAGER_RUNNING_NOT_EMPTY,
                    'message' => 'Idle watchdog skip: manager queue is running an internal watchdog decision',
                    'context' => $context,
                    'level' => 'info',
                ];
            }

            if ($this->shouldNudgeAboutStalledRunning($managerQueueRunning)) {
                return $this->stalledRunningNudgeStatus('manager', $context);
            }

            return [
                'idle' => false,
                'reason' => self::SKIP_MANAGER_RUNNING_NOT_EMPTY,
                'message' => 'Idle watchdog skip: manager queue has running items',
                'context' => $context,
                'level' => 'info',
            ];
        }

        $commandQueueRunning = $this->queueSummary((string) $this->config->require('command_watcher', 'queue_running'));
        if ($commandQueueRunning['count'] > 0) {
            $context = [
                'queue' => 'command_queue_running',
                'worker_lock_held' => $commandLock['held'],
                'worker_lock_file' => $commandLock['lock_file'],
            ] + $commandQueueRunning;

            if ($commandLock['held'] === false) {
                return [
                    'idle' => false,
                    'reason' => self::SKIP_COMMAND_QUEUE_STALLED,
                    'message' => 'Idle watchdog stall: command running queue is not empty but command watcher lock is not held',
                    'context' => $context,
                    'level' => 'error',
                ];
            }

            if ($this->shouldNudgeAboutStalledRunning($commandQueueRunning)) {
                return $this->stalledRunningNudgeStatus('command', $context);
            }

            return [
                'idle' => false,
                'reason' => self::SKIP_COMMAND_RUNNING_NOT_EMPTY,
                'message' => 'Idle watchdog skip: command watcher has running items',
                'context' => $context,
                'level' => 'info',
            ];
        }

        $managerState = $this->managerStateStore->read();
        $activeTaskId = trim((string) ($managerState['active_task_id'] ?? ''));

        if ($activeTaskId !== '') {
            return [
                'idle' => false,
                'reason' => self::SKIP_ACTIVE_TASK,
                'message' => 'Idle watchdog skip: active task is present',
                'context' => [
                    'active_task_id' => $activeTaskId,
                ],
                'level' => 'info',
            ];
        }

        return [
            'idle' => true,
            'reason' => null,
            'message' => null,
            'context' => [],
            'level' => 'info',
        ];
    }

    private function shouldNudgeAboutStalledRunning(array $queueSummary): bool
    {
        $oldestAgeSeconds = $queueSummary['oldest_age_seconds'] ?? null;
        if (!is_int($oldestAgeSeconds)) {
            return false;
        }

        $thresholdSeconds = max(
            1,
            (int) $this->config->get(
                'idle_watchdog',
                'running_stall_nudge_after_seconds',
                (int) $this->config->get('idle_watchdog', 'chat_idle_seconds', 600)
            )
        );

        return $oldestAgeSeconds >= $thresholdSeconds;
    }

    private function stalledRunningNudgeStatus(string $queueKind, array $context): array
    {
        $thresholdSeconds = max(
            1,
            (int) $this->config->get(
                'idle_watchdog',
                'running_stall_nudge_after_seconds',
                (int) $this->config->get('idle_watchdog', 'chat_idle_seconds', 600)
            )
        );

        return [
            'idle' => true,
            'reason' => null,
            'message' => null,
            'context' => [],
            'title' => 'Idle watchdog stalled running nudge',
            'prompt' => sprintf(
                'Похоже, в %s queue есть running-задача, которая висит уже не меньше %d секунд. Коротко проверь, не зависла ли она, нужен ли следующий шаг или вмешательство. Если пользователь не нужен, ничего ему не пиши.',
                $queueKind,
                $thresholdSeconds
            ),
            'meta' => [
                'nudge_kind' => self::NUDGE_STALLED_RUNNING,
                'queue_kind' => $queueKind,
                'running_item_id' => $context['oldest_item_id'] ?? null,
                'running_item_created_at' => $context['oldest_created_at'] ?? null,
                'running_item_age_seconds' => $context['oldest_age_seconds'] ?? null,
                'running_stall_threshold_seconds' => $thresholdSeconds,
            ],
        ];
    }

    private function chatIdleStatus(): array
    {
        $managerState = $this->managerStateStore->read();
        $lastActivityAt = trim((string) ($managerState['last_chat_activity_at'] ?? ''));
        if ($lastActivityAt === '') {
            return [
                'idle' => false,
                'reason' => self::SKIP_RECENT_CHAT_ACTIVITY,
                'message' => 'Idle watchdog skip: chat activity timestamp is missing',
                'context' => [
                    'last_chat_activity_at' => null,
                ],
            ];
        }

        $lastActivityTimestamp = strtotime($lastActivityAt);
        if ($lastActivityTimestamp === false) {
            return [
                'idle' => false,
                'reason' => self::SKIP_RECENT_CHAT_ACTIVITY,
                'message' => 'Idle watchdog skip: chat activity timestamp is invalid',
                'context' => [
                    'last_chat_activity_at' => $lastActivityAt,
                ],
            ];
        }

        $idleSeconds = max(0, (int) $this->config->get('idle_watchdog', 'chat_idle_seconds', 600));

        $secondsSinceActivity = time() - $lastActivityTimestamp;
        if ($secondsSinceActivity < $idleSeconds) {
            return [
                'idle' => false,
                'reason' => self::SKIP_RECENT_CHAT_ACTIVITY,
                'message' => 'Idle watchdog skip: recent chat activity',
                'context' => [
                    'last_chat_activity_at' => $lastActivityAt,
                    'seconds_since_activity' => $secondsSinceActivity,
                    'required_idle_seconds' => $idleSeconds,
                ],
            ];
        }

        return [
            'idle' => true,
            'reason' => null,
            'message' => null,
            'context' => [],
        ];
    }

    private function isWaitingForUserResponse(): bool
    {
        $managerState = $this->managerStateStore->read();

        return !empty($managerState['waiting_for_user_response']);
    }

    private function cooldownStatus(): array
    {
        $state = $this->stateStore->read();
        $lastPingAt = trim((string) ($state['last_ping_at'] ?? ''));
        if ($lastPingAt === '') {
            return [
                'elapsed' => true,
                'reason' => null,
                'message' => null,
                'context' => [],
            ];
        }

        $lastPingTimestamp = strtotime($lastPingAt);
        if ($lastPingTimestamp === false) {
            return [
                'elapsed' => true,
                'reason' => null,
                'message' => null,
                'context' => [],
            ];
        }

        $cooldownSeconds = max(0, (int) $this->config->get('idle_watchdog', 'cooldown_seconds', 1800));

        $secondsSinceLastPing = time() - $lastPingTimestamp;
        if ($secondsSinceLastPing < $cooldownSeconds) {
            return [
                'elapsed' => false,
                'reason' => self::SKIP_COOLDOWN,
                'message' => 'Idle watchdog skip: cooldown not elapsed',
                'context' => [
                    'last_nudge_at' => $lastPingAt,
                    'seconds_since_last_nudge' => $secondsSinceLastPing,
                    'cooldown_seconds' => $cooldownSeconds,
                ],
            ];
        }

        return [
            'elapsed' => true,
            'reason' => null,
            'message' => null,
            'context' => [],
        ];
    }

    private function isDuplicateStalledRunningNudge(array $meta): bool
    {
        if (($meta['nudge_kind'] ?? null) !== self::NUDGE_STALLED_RUNNING) {
            return false;
        }

        $queueKind = trim((string) ($meta['queue_kind'] ?? ''));
        $runningItemId = trim((string) ($meta['running_item_id'] ?? ''));
        if ($queueKind === '' || $runningItemId === '') {
            return false;
        }

        $state = $this->stateStore->read();
        $lastPingMeta = $state['last_ping_meta'] ?? null;
        if (!is_array($lastPingMeta)) {
            return false;
        }

        if (($lastPingMeta['nudge_kind'] ?? null) !== self::NUDGE_STALLED_RUNNING) {
            return false;
        }

        $lastQueueKind = trim((string) ($lastPingMeta['queue_kind'] ?? ''));
        if ($lastQueueKind !== $queueKind) {
            return false;
        }

        $lastRunningItemId = trim((string) ($lastPingMeta['running_item_id'] ?? ''));
        if ($lastRunningItemId === $runningItemId) {
            return true;
        }

        $lastPingAt = trim((string) ($state['last_ping_at'] ?? ''));
        $lastPingTimestamp = $lastPingAt === '' ? false : strtotime($lastPingAt);
        if ($lastPingTimestamp === false) {
            return false;
        }

        $thresholdSeconds = max(
            1,
            (int) $this->config->get(
                'idle_watchdog',
                'running_stall_nudge_after_seconds',
                (int) $this->config->get('idle_watchdog', 'chat_idle_seconds', 600)
            )
        );
        $repeatSuppressionSeconds = max($thresholdSeconds, (int) ($thresholdSeconds * 3));

        return (time() - $lastPingTimestamp) < $repeatSuppressionSeconds;
    }

    private function waitingForUserResponseContext(): array
    {
        $managerState = $this->managerStateStore->read();

        return [
            'waiting_for_user_response_at' => $managerState['waiting_for_user_response_at'] ?? null,
            'waiting_for_user_response_message' => $managerState['waiting_for_user_response_message'] ?? null,
        ];
    }

    private function skip(string $reason, string $message, array $context = [], string $level = 'info'): void
    {
        $this->updateState([
            'last_skip_reason' => $reason,
            'last_skip_context' => $context,
        ]);

        if (!$this->shouldLogSkip($reason)) {
            return;
        }

        if ($level === 'debug') {
            $this->logger->debug($message, $context + ['skip_reason' => $reason]);
            return;
        }

        if ($level === 'error') {
            $this->logger->error($message, $context + ['skip_reason' => $reason]);
            return;
        }

        $this->logger->info($message, $context + ['skip_reason' => $reason]);
    }

    private function shouldLogSkip(string $reason): bool
    {
        $state = $this->stateStore->read();
        $lastLoggedReason = trim((string) ($state['last_skip_reason_logged'] ?? ''));
        $lastLoggedAt = trim((string) ($state['last_skip_reason_logged_at'] ?? ''));
        $lastLoggedTimestamp = $lastLoggedAt === '' ? false : strtotime($lastLoggedAt);
        $now = time();

        $shouldLog = $reason !== $lastLoggedReason
            || $lastLoggedTimestamp === false
            || ($now - $lastLoggedTimestamp) >= 60;

        if ($shouldLog) {
            $this->updateState([
                'last_skip_reason_logged' => $reason,
                'last_skip_reason_logged_at' => date(DATE_ATOM, $now),
            ]);
        }

        return $shouldLog;
    }

    private function updateState(array $changes): void
    {
        $state = $this->stateStore->read();
        foreach ($changes as $key => $value) {
            $state[$key] = $value;
        }

        $this->stateStore->write($state);
    }

    private function queueSummary(string $dir): array
    {
        if (!is_dir($dir)) {
            return [
                'count' => 0,
                'oldest_item_id' => null,
                'oldest_created_at' => null,
                'oldest_age_seconds' => null,
                'oldest_item_type' => null,
                'oldest_item_source' => null,
            ];
        }

        $files = glob(rtrim($dir, '/') . '/*.json');
        if ($files === false || $files === []) {
            return [
                'count' => 0,
                'oldest_item_id' => null,
                'oldest_created_at' => null,
                'oldest_age_seconds' => null,
                'oldest_item_type' => null,
                'oldest_item_source' => null,
            ];
        }

        $oldestFile = null;
        $oldestTimestamp = null;
        $oldestCreatedAt = null;
        $oldestPayload = null;

        foreach ($files as $path) {
            $raw = file_get_contents($path);
            $payload = $raw === false ? null : json_decode($raw, true);
            $createdAt = is_array($payload) ? trim((string) (($payload['created_at'] ?? $payload['updated_at'] ?? ''))) : '';
            $timestamp = $createdAt !== '' ? strtotime($createdAt) : false;
            if ($timestamp === false) {
                $timestamp = filemtime($path);
                $createdAt = $timestamp === false ? '' : date(DATE_ATOM, $timestamp);
            }

            if (!is_int($timestamp)) {
                continue;
            }

            if ($oldestTimestamp === null || $timestamp < $oldestTimestamp) {
                $oldestTimestamp = $timestamp;
                $oldestFile = $path;
                $oldestCreatedAt = $createdAt;
                $oldestPayload = is_array($payload) ? $payload : null;
            }
        }

        return [
            'count' => count($files),
            'oldest_item_id' => $oldestFile === null ? null : basename($oldestFile, '.json'),
            'oldest_created_at' => $oldestCreatedAt,
            'oldest_age_seconds' => $oldestTimestamp === null ? null : max(0, time() - $oldestTimestamp),
            'oldest_item_type' => is_array($oldestPayload) ? ($oldestPayload['type'] ?? null) : null,
            'oldest_item_source' => is_array($oldestPayload) && is_array($oldestPayload['meta'] ?? null)
                ? ($oldestPayload['meta']['source'] ?? null)
                : null,
        ];
    }

    private function isSelfReferentialManagerRunningItem(array $queueSummary): bool
    {
        return ($queueSummary['oldest_item_type'] ?? null) === 'internal_decision'
            && ($queueSummary['oldest_item_source'] ?? null) === 'idle_watchdog';
    }

    private function workerLockStatus(string $lockFile): array
    {
        $handle = @fopen($lockFile, 'c+');
        if (!is_resource($handle)) {
            return [
                'lock_file' => $lockFile,
                'held' => null,
            ];
        }

        $lockHeld = !flock($handle, LOCK_EX | LOCK_NB);
        if (!$lockHeld) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return [
            'lock_file' => $lockFile,
            'held' => $lockHeld,
        ];
    }

    private function acquireLock(): void
    {
        $lockFile = (string) $this->config->get('idle_watchdog', 'lock_file', __DIR__ . '/../var/run/idle-watchdog.lock');
        $dir = dirname($lockFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $handle = fopen($lockFile, 'c+');
        if (!is_resource($handle)) {
            throw new RuntimeException("Cannot open {$lockFile}");
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Idle watchdog already running');
        }

        $this->lockHandle = $handle;
        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);

        $pidFile = (string) $this->config->get('background', 'idle_watchdog_pid_file', __DIR__ . '/../var/run/idle-watchdog.pid');
        file_put_contents($pidFile, (string) getmypid());
    }
}
