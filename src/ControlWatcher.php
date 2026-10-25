<?php

declare(strict_types=1);

namespace CodexRuntime;

use CodexRuntime\ControlQueue\CommandRepository;
use CodexRuntime\Contracts\TransportClientInterface;
use DateTimeImmutable;
use RuntimeException;
use Throwable;
use CodexRuntime\Voice\VoiceCommandServiceInterface;
use CodexRuntime\Router\TransportEnrollmentServiceInterface;

final class ControlWatcher
{
    private $lockHandle = null;
    private ModelPreferenceStore $model_preferences;
    private CodexModelCatalog $model_catalog;
    private ProviderAvailability $providers;
    private ProviderPreferenceStore $provider_preferences;
    private CodexDeviceAuthInterface $device_auth;
    private ClaudeSubscriptionAuthInterface $claude_auth;

    public function __construct(
        private Config $config,
        private Logger $logger,
        private CommandRepository $commands,
        private ActiveTurnRegistry $activeTurn,
        private JsonFileStore $stateStore,
        private TransportClientInterface $transport,
        private TransportMessageIngress $ingress,
        private CodexSessionCatalog $sessions,
        private WorkerShutdownFlag $shutdown,
        private LimitMonitor $limit_monitor,
        private VoiceCommandServiceInterface $voice_commands,
        ?ModelPreferenceStore $model_preferences = null,
        ?CodexModelCatalog $model_catalog = null,
        ?CodexDeviceAuthInterface $device_auth = null,
        ?ClaudeSubscriptionAuthInterface $claude_auth = null,
        private ?TransportEnrollmentServiceInterface $transport_enrollment = null
    ) {
        $this->model_preferences = $model_preferences ?? new ModelPreferenceStore($stateStore, $config);
        $this->model_catalog = $model_catalog ?? new CodexModelCatalog($config);
        $this->providers = new ProviderAvailability($config);
        $this->provider_preferences = new ProviderPreferenceStore($stateStore, $config);
        $this->device_auth = $device_auth ?? new CodexDeviceAuth($config);
        $this->claude_auth = $claude_auth ?? new ClaudeSubscriptionAuth($config);
    }

    public function run(): void
    {
        $this->acquireLock();
        $requeued = $this->commands->requeueAllRunning();
        $this->logger->info('Control watcher started');
        $this->deliverKillNotice();
        if ($requeued !== []) {
            $this->logger->warning('Requeued stale control commands', ['command_ids' => $requeued]);
        }

        $pollIntervalMs = (int) $this->config->get('control_queue', 'poll_interval_ms', 1000);

        while (true) {
            if ($this->shutdown->consumeIfRequested()) {
                $this->logger->info('Control watcher exiting for shutdown request');
                return;
            }

            $runningPath = null;
            $command = null;
            try {
                $path = $this->commands->nextPendingPath();
                if ($path === null) {
                    usleep($pollIntervalMs * 1000);
                    continue;
                }

                $runningPath = $this->commands->moveToRunning($path);
                $command = $this->commands->loadCommand($runningPath);
                $commandId = (string) ($command['id'] ?? basename($runningPath, '.json'));

                $this->logger->info('Control command received', [
                    'command_id' => $commandId,
                    'type' => $command['type'] ?? null,
                    'command' => $command,
                ]);

                $result = $this->processCommand($command);
                $this->commands->finish($runningPath, 'done', $result);

                $this->logger->info('Control command processed', [
                    'command_id' => $commandId,
                    'type' => $command['type'] ?? null,
                ]);
            } catch (Throwable $e) {
                $this->logger->error('Control watcher error', [
                    'error' => $e->getMessage(),
                    'command' => $command,
                ]);
                if ($runningPath !== null && is_file($runningPath)) {
                    $this->commands->finish($runningPath, 'failed', [
                        'ok' => false,
                        'stdout' => '',
                        'stderr' => $e->getMessage(),
                        'command' => is_array($command) ? $command : [],
                    ]);
                }

                usleep($pollIntervalMs * 1000);
            }
        }
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function processCommand(array $command): array
    {
        return match ((string) ($command['type'] ?? '')) {
            'transport_command' => $this->processTransportCommand($command),
            default => [
                'ok' => true,
                'stdout' => '',
                'stderr' => '',
                'command' => $command,
            ],
        };
    }

    private function acquireLock(): void
    {
        $paths = new RuntimePaths($this->config);
        $lockFile = (string) $this->config->get('control_queue', 'lock_file', $paths->workerLockFile('control_watcher'));
        $dir = dirname($lockFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $handle = fopen($lockFile, 'c+');
        if (!is_resource($handle)) {
            throw new RuntimeException("Cannot open {$lockFile}");
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Control watcher already running');
        }

        $this->lockHandle = $handle;
        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);

        $pidFile = (string) $this->config->get('background', 'control_watcher_pid_file', $paths->workerPidFile('control_watcher'));
        file_put_contents($pidFile, (string) getmypid());
    }

    private function killNoticeFile(): string
    {
        return (new RuntimePaths($this->config))->stateDir() . '/kill-notice.json';
    }

    private function writeKillNotice(string $sessionId): void
    {
        $file = $this->killNoticeFile();
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0775, true) && !is_dir(dirname($file))) {
            throw new \RuntimeException('Cannot create ' . dirname($file));
        }
        $tmp = $file . '.tmp';
        if (file_put_contents($tmp, json_encode(['runtime_session_id' => $sessionId, 'requested_at' => time()])) === false || !rename($tmp, $file)) {
            throw new \RuntimeException("Cannot write $file");
        }
    }

    private function deliverKillNotice(): void
    {
        $file = $this->killNoticeFile();
        if (!is_file($file)) {
            return;
        }
        $notice = json_decode((string) file_get_contents($file), true);
        unlink($file);
        if (!is_array($notice) || !is_string($notice['runtime_session_id'] ?? null) || $notice['runtime_session_id'] === '' || !is_int($notice['requested_at'] ?? null)) {
            $this->logger->error('Invalid kill notice file', ['file' => $file]);
            return;
        }
        $seconds = max(0, time() - $notice['requested_at']);
        $this->transport->sendSystem($notice['runtime_session_id'], "Ядро снова доступно после /kill (перезапуск занял $seconds с).");
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function processKillCommand(array $command, int|string $channelId, string $sessionId, string $text): array
    {
        $result = ['ok' => true, 'stdout' => '', 'stderr' => '', 'command' => $command, 'channel_id' => $channelId, 'runtime_session_id' => $sessionId];
        if (!preg_match('/^\/kill(?:@\S+)?$/ui', trim($text))) {
            $this->transport->sendSystem($sessionId, 'Команда /kill не принимает аргументов.');
            return $result;
        }
        $pidFile = (string) $this->config->get('background', 'bot_pid_file', (new RuntimePaths($this->config))->mainPidFile());
        $raw = is_file($pidFile) ? trim((string) file_get_contents($pidFile)) : '';
        $failure = null;
        if ($raw === '' || !ctype_digit($raw)) {
            $failure = "нет корректного pid-файла $pidFile";
        } elseif (!posix_kill((int) $raw, 0)) {
            $failure = "процесс $raw не найден";
        }
        if ($failure !== null) {
            $this->logger->error('Core /kill failed', ['reason' => $failure]);
            $this->transport->sendSystem($sessionId, "Не удалось завершить ядро: $failure.");
            return ['ok' => false, 'stderr' => $failure] + $result;
        }
        $this->writeKillNotice($sessionId);
        $this->logger->warning('Core /kill requested', ['main_pid' => (int) $raw]);
        $this->transport->sendSystem($sessionId, 'Ядро завершается по /kill. systemd перезапустит его через несколько секунд.');
        if (!posix_kill((int) $raw, SIGKILL)) {
            $error = posix_strerror(posix_get_last_error());
            $this->logger->error('Core /kill failed', ['reason' => $error]);
            $this->transport->sendSystem($sessionId, "Не удалось завершить ядро: $error.");
            return ['ok' => false, 'stderr' => $error] + $result;
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function processTransportCommand(array $command): array
    {
        $text = trim((string) ($command['text'] ?? ''));
        $channelId = $command['channel_id'] ?? null;
        $sessionId = trim((string) ($command['session_id'] ?? ''));
        if ($channelId === null || $sessionId === '' || $text === '') {
            return [
                'ok' => false,
                'stdout' => '',
                'stderr' => 'Invalid transport command payload',
                'command' => $command,
            ];
        }

        if (preg_match('/^\/login(?:@\S+)?(?:\s|$)/ui', $text)) {
            return $this->processLoginCommand($command, $channelId, $sessionId, $text);
        }

        if (preg_match('/^\/1c(?:@\S+)?(?:\s|$)/ui', $text)) {
            return $this->processOnecCommand($command, $channelId, $sessionId, $text);
        }

        if (preg_match('/^\/kill(?:@\S+)?(?:\s|$)/ui', $text)) {
            return $this->processKillCommand($command, $channelId, $sessionId, $text);
        }

        if (preg_match('/^\/reset(?:@\S+)?(?:\s|$)/ui', $text)) {
            return $this->processResetSessionCommand($command, $channelId, $sessionId);
        }

        if (preg_match('/^\/stop(?:@\S+)?(?:\s|$)/ui', $text)) {
            return $this->processStopTransportCommand($command, $channelId, $sessionId);
        }

        if (preg_match('/^\/session(?:@\S+)?(?:\s|$)/ui', $text)) {
            return $this->processSessionCommand($command, $channelId, $sessionId, $text);
        }

        if (preg_match('/^\/provider(?:@\S+)?(?:\s|$)/ui', $text)) {
            return $this->processProviderCommand($command, $channelId, $sessionId, $text);
        }

        if (preg_match('/^\/(codex|claude)(?:@\S+)?$/ui', trim($text), $alias)) {
            return $this->processProviderCommand($command, $channelId, $sessionId, '/provider ' . strtolower($alias[1]));
        }

        if (preg_match('/^\/model(?:@\S+)?(?:\s|$)/ui', $text)) {
            return $this->processModelCommand($command, $channelId, $sessionId, $text);
        }

        if (preg_match('/^\/limits(?:@\S+)?(?:\s|$)/ui', $text)) {
            if ($this->provider_preferences->effectiveForRuntimeSession($sessionId) === ProviderAvailability::CODEX) {
                $refused = $this->refuseWithoutCodex($command, $channelId, $sessionId, '');
                if ($refused !== null) {
                    return $refused;
                }
            }
            $this->limit_monitor->sendCurrentLimits($sessionId);

            return [
                'ok' => true,
                'stdout' => '',
                'stderr' => '',
                'command' => $command,
                'channel_id' => $channelId,
                'runtime_session_id' => $sessionId,
            ];
        }

        if (preg_match('/^\/voices(?:@\S+)?$/ui', $text) || preg_match('/^\/voice(?:@\S+)?(?:\s|$)/ui', $text)) {
            $response = $this->voice_commands->handle($sessionId, $text);
            if ($response !== '') {
                $this->transport->sendSystem($sessionId, $response);
            }

            return [
                'ok' => true,
                'stdout' => '',
                'stderr' => '',
                'command' => $command,
                'channel_id' => $channelId,
                'runtime_session_id' => $sessionId,
            ];
        }

        $eventId = $this->ingress->enqueueUserMessage(new TransportInboundMessage(
            channelId: $channelId,
            text: $text,
            sessionId: $sessionId,
            channelType: isset($command['channel_type']) ? (string) $command['channel_type'] : null,
            replyToMessageId: isset($command['reply_to_message_id']) ? (int) $command['reply_to_message_id'] : null,
            threadId: isset($command['thread_id']) ? (int) $command['thread_id'] : null,
            transportMessageId: $command['transport_message_id'] ?? null,
            meta: is_array($command['meta'] ?? null) ? $command['meta'] : []
        ), true);

        return [
            'ok' => true,
            'stdout' => '',
            'stderr' => '',
            'command' => $command,
            'forwarded_to_manager' => true,
            'event_id' => $eventId,
        ];
    }

    /** @param array<string, mixed> $command @return array<string, mixed> */
    private function processOnecCommand(array $command, int|string $channelId, string $runtimeSessionId, string $text): array
    {
        if (!preg_match('/^\/1c(?:@\S+)?$/ui', trim($text))) {
            $message = 'Команда /1c не принимает аргументов.';
            $this->transport->sendSystem($runtimeSessionId, $message);
            return $this->commandResult($command, $channelId, $runtimeSessionId, false, ['mode' => 'invalid_arguments', 'stderr' => $message]);
        }

        $meta = $this->commandMeta($command);
        if (($meta['chat_type'] ?? null) !== 'private') {
            $message = 'Команда /1c работает только в личном чате.';
            $this->transport->sendSystem($runtimeSessionId, $message);
            return $this->commandResult($command, $channelId, $runtimeSessionId, false, ['mode' => 'private_only', 'stderr' => $message]);
        }

        try {
            if ($this->transport_enrollment === null) {
                throw new RuntimeException('сервис подключения транспорта не настроен');
            }
            $enrollment = $this->transport_enrollment->createCode();
            $expires_at = (new DateTimeImmutable($enrollment['expires_at']))
                ->setTimezone(new \DateTimeZone('Europe/Moscow'))
                ->format('H:i:s d.m.Y');
            $this->transport->sendSystem(
                $runtimeSessionId,
                "Код подключения 1С: `{$enrollment['enrollment_code']}`\n\n"
                . "Введите его в настройках расширения «Гоша ИИ». Код действует до {$expires_at} (МСК)."
            );
            return $this->commandResult($command, $channelId, $runtimeSessionId, true, ['mode' => 'created']);
        } catch (Throwable $exception) {
            $message = 'Не удалось получить код подключения 1С: ' . $exception->getMessage();
            $this->transport->sendSystem($runtimeSessionId, $message);
            return $this->commandResult($command, $channelId, $runtimeSessionId, false, ['mode' => 'failed', 'stderr' => $exception->getMessage()]);
        }
    }

    /** @param array<string, mixed> $command @return array<string, mixed> */
    private function commandMeta(array $command): array
    {
        $meta = $command['meta'] ?? null;
        return is_array($meta) ? $meta : [];
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function processLoginCommand(array $command, int|string $channelId, string $runtimeSessionId, string $text): array
    {
        $meta = $this->commandMeta($command);
        if (($meta['chat_type'] ?? null) !== 'private') {
            $message = 'Команда /login работает только в личном чате.';
            $this->transport->sendSystem($runtimeSessionId, $message);

            return $this->commandResult($command, $channelId, $runtimeSessionId, false, [
                'mode' => 'private_only',
                'stderr' => $message,
            ]);
        }

        preg_match('/^\/login(?:@\S+)?(?:\s+(.*))?$/ui', $text, $matches);
        $argument = trim((string) ($matches[1] ?? ''));
        if ($argument === '') {
            return $this->sendLoginStatus($command, $channelId, $runtimeSessionId);
        }

        [$provider, $credential] = array_pad(preg_split('/\s+/', $argument, 2) ?: [], 2, '');
        $provider = strtolower($provider);
        if (!in_array($provider, $this->providers->knownProviders(), true)) {
            $message = "Неизвестный провайдер: {$provider}. Используйте `/login codex` или `/login claude`.";
            $this->transport->sendSystem($runtimeSessionId, $message);
            return $this->commandResult($command, $channelId, $runtimeSessionId, false, ['mode' => 'unknown_provider', 'stderr' => $message]);
        }

        $unavailable = $this->loginBinaryUnavailableReason($provider);
        if ($unavailable !== null) {
            $name = $provider === ProviderAvailability::CODEX ? 'Codex' : 'Claude';
            $message = "Не удалось авторизовать {$name}: {$unavailable}.";
            $this->transport->sendSystem($runtimeSessionId, $message);
            return $this->commandResult($command, $channelId, $runtimeSessionId, false, ['mode' => 'unavailable', 'stderr' => $unavailable]);
        }

        if ($provider === ProviderAvailability::CLAUDE) {
            return $this->processClaudeLoginCommand($command, $channelId, $runtimeSessionId, $credential);
        }

        try {
            if ($this->device_auth->isLoggedIn()) {
                $this->transport->sendSystem($runtimeSessionId, 'Codex уже авторизован.');

                return $this->commandResult($command, $channelId, $runtimeSessionId, true, ['mode' => 'already_authorized']);
            }

            $this->device_auth->login(function (string $url, string $code) use ($runtimeSessionId): void {
                $this->transport->sendSystem(
                    $runtimeSessionId,
                    "Откройте ссылку и подтвердите вход в Codex:\n{$url}\n\nКод: `{$code}`\n\nКод действует 15 минут. Никому его не пересылайте."
                );
            });
            $this->transport->sendSystem($runtimeSessionId, 'Codex авторизован.');

            return $this->commandResult($command, $channelId, $runtimeSessionId, true, ['mode' => 'authorized']);
        } catch (Throwable $e) {
            $message = 'Не удалось авторизовать Codex: ' . $e->getMessage();
            $this->transport->sendSystem($runtimeSessionId, $message);

            return $this->commandResult($command, $channelId, $runtimeSessionId, false, [
                'mode' => 'failed',
                'stderr' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $command @return array<string, mixed> */
    private function sendLoginStatus(array $command, int|string $channelId, string $runtimeSessionId): array
    {
        $lines = ['Авторизация:'];
        foreach ($this->providers->knownProviders() as $provider) {
            $name = $provider === ProviderAvailability::CODEX ? 'Codex' : 'Claude';
            $unavailable = $this->loginBinaryUnavailableReason($provider);
            if ($unavailable !== null) {
                $lines[] = "{$name}: недоступен — {$unavailable}.";
                continue;
            }
            try {
                $logged_in = $provider === ProviderAvailability::CODEX
                    ? $this->device_auth->isLoggedIn()
                    : $this->claude_auth->isLoggedIn();
                $lines[] = "{$name}: " . ($logged_in ? 'авторизован.' : 'не авторизован.');
            } catch (Throwable $e) {
                $lines[] = "{$name}: ошибка проверки — {$e->getMessage()}.";
            }
        }
        $lines[] = '';
        $lines[] = 'Команды:';
        $lines[] = '`/login codex`';
        $lines[] = '`/login claude`';
        $this->transport->sendSystem($runtimeSessionId, implode("\n", $lines));
        return $this->commandResult($command, $channelId, $runtimeSessionId, true, ['mode' => 'status']);
    }

    /** @param array<string, mixed> $command @return array<string, mixed> */
    private function processClaudeLoginCommand(array $command, int|string $channelId, string $runtimeSessionId, string $code): array
    {
        try {
            if ($code !== '') {
                $this->claude_auth->completeLogin($code);
                $this->transport->sendSystem($runtimeSessionId, 'Claude авторизован через Claude Pro.');
                return $this->commandResult($command, $channelId, $runtimeSessionId, true, ['mode' => 'authorized']);
            }
            if ($this->claude_auth->isLoggedIn()) {
                $this->transport->sendSystem($runtimeSessionId, 'Claude уже авторизован через Claude Pro.');
                return $this->commandResult($command, $channelId, $runtimeSessionId, true, ['mode' => 'already_authorized']);
            }
            $this->claude_auth->beginLogin(function (string $url) use ($runtimeSessionId): void {
                $this->transport->sendSystem(
                    $runtimeSessionId,
                    "Откройте ссылку и войдите в Claude Pro:\n{$url}\n\nПосле входа пришлите полученный код командой:\n`/login claude <код>`"
                );
            });
            return $this->commandResult($command, $channelId, $runtimeSessionId, true, ['mode' => 'awaiting_code']);
        } catch (Throwable $e) {
            $message = 'Не удалось авторизовать Claude: ' . $e->getMessage();
            $this->transport->sendSystem($runtimeSessionId, $message);
            return $this->commandResult($command, $channelId, $runtimeSessionId, false, ['mode' => 'failed', 'stderr' => $e->getMessage()]);
        }
    }

    private function loginBinaryUnavailableReason(string $provider): ?string
    {
        $bin = $this->providers->bin($provider);
        return Environment::resolveCommand($bin) === null ? "{$bin} не найден в PATH" : null;
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function processResetSessionCommand(array $command, int|string $channelId, string $runtimeSessionId): array
    {
        $hadSession = false;
        $this->stateStore->update(static function (array $state) use ($runtimeSessionId, &$hadSession): array {
            $state['sessions'] ??= [];
            $hadSession = array_key_exists($runtimeSessionId, $state['sessions']);
            unset($state['sessions'][$runtimeSessionId]);
            return $state;
        });

        $this->transport->sendSystem($runtimeSessionId, 'Текущая сессия сброшена.');

        return [
            'ok' => true,
            'stdout' => '',
            'stderr' => '',
            'command' => $command,
            'channel_id' => $channelId,
            'runtime_session_id' => $runtimeSessionId,
            'session_removed' => $hadSession,
        ];
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function processStopTransportCommand(array $command, int|string $channelId, string $runtimeSessionId): array
    {
        $result = $this->activeTurn->requestStop($runtimeSessionId);

        return [
            'ok' => true,
            'stdout' => '',
            'stderr' => '',
            'command' => $command,
            'channel_id' => $channelId,
            'runtime_session_id' => $runtimeSessionId,
            'signal' => 'SIGTERM',
            'signal_sent' => $result['signal_sent'],
            'pid' => $result['pid'],
            'active_turn' => $result['active_turn'],
        ];
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function processModelCommand(array $command, int|string $channelId, string $runtimeSessionId, string $text): array
    {
        preg_match('/^\/model(?:@\S+)?(?:\s+(\S+))?$/ui', trim($text), $matches);
        $model = trim((string) ($matches[1] ?? ''));
        $provider = $this->provider_preferences->effectiveForRuntimeSession($runtimeSessionId);
        if ($provider === ProviderAvailability::CODEX) {
            $refused = $this->refuseWithoutCodex($command, $channelId, $runtimeSessionId, '');
            if ($refused !== null) {
                return $refused;
            }
            $available = $this->model_catalog->available();
        } else {
            $available = $this->providers->claudeModels();
        }
        $model_commands = $this->formatModelCommands($available, $this->model_preferences->defaultModelForProvider($provider));

        if ($model === '') {
            $effective_model = $this->model_preferences->effectiveForRuntimeSession($runtimeSessionId);
            $this->transport->sendSystem(
                $runtimeSessionId,
                "Текущая модель: {$effective_model}.\n\n{$model_commands}"
            );

            return [
                'ok' => true,
                'stdout' => '',
                'stderr' => '',
                'command' => $command,
                'channel_id' => $channelId,
                'runtime_session_id' => $runtimeSessionId,
                'mode' => 'show',
                'model' => $effective_model,
            ];
        }

        if (!in_array($model, $available, true)) {
            $this->transport->sendSystem(
                $runtimeSessionId,
                "Недоступная модель: {$model}\n\n{$model_commands}"
            );

            return [
                'ok' => true,
                'stdout' => '',
                'stderr' => 'Unsupported Codex model',
                'command' => $command,
                'channel_id' => $channelId,
                'runtime_session_id' => $runtimeSessionId,
                'mode' => 'rejected',
            ];
        }

        $this->model_preferences->setSession($runtimeSessionId, $model);
        $this->transport->sendSystem($runtimeSessionId, "Модель текущей сессии: {$model}.");

        return [
            'ok' => true,
            'stdout' => '',
            'stderr' => '',
            'command' => $command,
            'channel_id' => $channelId,
            'runtime_session_id' => $runtimeSessionId,
            'mode' => 'set',
            'model' => $model,
        ];
    }

    /** @param list<string> $models */
    private function formatModelCommands(array $models, string $default_model): string
    {
        return implode("\n", array_map(
            static fn (string $model): string => '`/model ' . $model . '`' . ($model === $default_model ? ' (default)' : ''),
            $models
        ));
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function processSessionCommand(array $command, int|string $channelId, string $runtimeSessionId, string $text): array
    {
        $sessionId = $this->extractSessionId($text);
        if ($sessionId === null) {
            $refused = $this->refuseWithoutCodex($command, $channelId, $runtimeSessionId, 'Список сессий для провайдера claude не поддерживается.');
            if ($refused !== null) {
                return $refused;
            }
            $this->sendAvailableSessions($runtimeSessionId);

            return [
                'ok' => true,
                'stdout' => '',
                'stderr' => '',
                'command' => $command,
                'channel_id' => $channelId,
                'runtime_session_id' => $runtimeSessionId,
                'mode' => 'list',
            ];
        }

        $providers = $this->providers;
        $this->stateStore->update(static function (array $state) use ($runtimeSessionId, $sessionId, $providers): array {
            $state['sessions'] ??= [];
            if (!ProviderPreferenceStore::isStartedInState($state, $runtimeSessionId)) {
                $state['session_providers'] ??= [];
                $state['session_providers'][$runtimeSessionId] = ProviderPreferenceStore::effectiveFromState($state, $runtimeSessionId, $providers);
            }
            $state['sessions'][$runtimeSessionId] = $sessionId;
            return $state;
        });

        $this->transport->sendSystem(
            $runtimeSessionId,
            "Текущая сессия установлена:\n```\n{$sessionId}\n```"
        );

        return [
            'ok' => true,
            'stdout' => '',
            'stderr' => '',
            'command' => $command,
            'channel_id' => $channelId,
            'runtime_session_id' => $runtimeSessionId,
            'mode' => 'set',
            'codex_session_id' => $sessionId,
        ];
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function processProviderCommand(array $command, int|string $channelId, string $runtimeSessionId, string $text): array
    {
        preg_match('/^\/provider(?:@\S+)?(?:\s+(\S+))?$/ui', trim($text), $matches);
        $provider = trim((string) ($matches[1] ?? ''));
        $current = $this->provider_preferences->effectiveForRuntimeSession($runtimeSessionId);
        $provider_commands = $this->formatProviderCommands();

        if ($provider === '') {
            $this->transport->sendSystem($runtimeSessionId, "Текущий провайдер: {$current}.\n\n{$provider_commands}");

            return $this->commandResult($command, $channelId, $runtimeSessionId, true, ['mode' => 'show', 'provider' => $current]);
        }

        if (!$this->providers->isKnown($provider)) {
            $this->transport->sendSystem($runtimeSessionId, "Неизвестный провайдер: {$provider}\n\n{$provider_commands}");

            return $this->commandResult($command, $channelId, $runtimeSessionId, false, ['mode' => 'rejected', 'stderr' => 'Unknown provider']);
        }

        $unavailable = $this->providers->unavailableMessage($provider);
        if ($unavailable !== null) {
            $this->transport->sendSystem($runtimeSessionId, $unavailable);

            return $this->commandResult($command, $channelId, $runtimeSessionId, false, ['mode' => 'unavailable', 'stderr' => $unavailable]);
        }

        if ($this->provider_preferences->isStarted($runtimeSessionId)) {
            $this->transport->sendSystem(
                $runtimeSessionId,
                "Сессия уже начата с провайдером {$current}. Сменить провайдер можно после /reset."
            );

            return $this->commandResult($command, $channelId, $runtimeSessionId, false, ['mode' => 'started', 'stderr' => 'Runtime session is already started']);
        }

        $this->provider_preferences->setSession($runtimeSessionId, $provider);
        if ($provider !== $current) {
            $this->model_preferences->clearSession($runtimeSessionId);
        }
        $this->transport->sendSystem($runtimeSessionId, "Провайдер текущей сессии: {$provider}.");

        return $this->commandResult($command, $channelId, $runtimeSessionId, true, ['mode' => 'set', 'provider' => $provider]);
    }

    private function formatProviderCommands(): string
    {
        $default_provider = $this->providers->defaultProvider();

        return implode("\n", array_map(
            static fn (string $provider): string => '`/provider ' . $provider . '`' . ($provider === $default_provider ? ' (default)' : ''),
            $this->providers->availableProviders()
        ));
    }

    /**
     * Команды, которые работают только через codex: для claude-сессии и без установленного codex отвечают явным отказом.
     *
     * @param array<string, mixed> $command
     * @return array<string, mixed>|null
     */
    private function refuseWithoutCodex(array $command, int|string $channelId, string $runtimeSessionId, string $claude_message): ?array
    {
        $provider = $this->provider_preferences->effectiveForRuntimeSession($runtimeSessionId);
        $message = $provider === ProviderAvailability::CODEX
            ? $this->providers->unavailableMessage(ProviderAvailability::CODEX)
            : $claude_message;
        if ($message === null || $message === '') {
            return null;
        }

        $this->transport->sendSystem($runtimeSessionId, $message);

        return $this->commandResult($command, $channelId, $runtimeSessionId, false, ['mode' => 'unsupported', 'stderr' => $message]);
    }

    /**
     * @param array<string, mixed> $command
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function commandResult(array $command, int|string $channelId, string $runtimeSessionId, bool $ok, array $extra): array
    {
        return array_merge([
            'ok' => $ok,
            'stdout' => '',
            'stderr' => '',
            'command' => $command,
            'channel_id' => $channelId,
            'runtime_session_id' => $runtimeSessionId,
        ], $extra);
    }

    private function sendAvailableSessions(string $runtimeSessionId): void
    {
        $homeDirectory = trim((string) (getenv('HOME') ?: '/home/web'));
        $sessions = $this->sessions->listForHomeDirectory($homeDirectory);
        if ($sessions === []) {
            $this->transport->sendSystem($runtimeSessionId, 'Для каталога ~ доступных сессий не найдено.');
            return;
        }

        $chunks = [];
        $currentChunk = '';
        foreach ($sessions as $session) {
            $title = $this->shortenSessionTitle((string) ($session['title'] ?? ''));
            $title = $title !== '' ? $title : 'Без названия';
            $updatedAt = $this->formatUpdatedAt((int) ($session['updated_at'] ?? 0));
            $entry = '**' . $this->escapeMarkdown($title) . "**\n"
                . 'Последний доступ: ' . $this->escapeMarkdown($updatedAt) . "\n"
                . "```\n" . (string) $session['id'] . "\n```";

            $candidate = $currentChunk === '' ? $entry : ($currentChunk . "\n\n" . $entry);
            if (mb_strlen($candidate) > 3200 && $currentChunk !== '') {
                $chunks[] = $currentChunk;
                $currentChunk = $entry;
                continue;
            }

            $currentChunk = $candidate;
        }

        if ($currentChunk !== '') {
            $chunks[] = $currentChunk;
        }

        foreach ($chunks as $chunk) {
            $this->transport->sendSystem($runtimeSessionId, $chunk);
        }
    }

    private function extractSessionId(string $text): ?string
    {
        $parts = preg_split('/\s+/', trim($text), 2);
        if (!is_array($parts) || count($parts) < 2) {
            return null;
        }

        $sessionId = trim((string) $parts[1]);

        return $sessionId !== '' ? $sessionId : null;
    }

    /**
     * @return array{sessions: array<string, string>}
     */
    private function readManagerState(): array
    {
        $state = $this->stateStore->read();

        return [
            'sessions' => is_array($state['sessions'] ?? null) ? $state['sessions'] : [],
        ];
    }

    private function formatUpdatedAt(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return 'unknown';
        }

        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone((string) date_default_timezone_get()))
            ->format('Y-m-d H:i');
    }

    private function shortenSessionTitle(string $title, int $limit = 44): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        if ($title === '') {
            return '';
        }

        if (mb_strlen($title) <= $limit) {
            return $title;
        }

        return rtrim(mb_substr($title, 0, max(1, $limit - 3))) . '...';
    }

    private function escapeMarkdown(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\',
            '*' => '\*',
            '_' => '\_',
            '[' => '\[',
            ']' => '\]',
            '(' => '\(',
            ')' => '\)',
            '`' => '\`',
        ]);
    }
}
