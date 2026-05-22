<?php

declare(strict_types=1);

namespace CodexRuntime\Max;

use CodexRuntime\Config;
use CodexRuntime\Logger;
use CodexRuntime\OutboundQueue\MessageRepository;
use CodexRuntime\WorkerShutdownFlag;
use MaxApi\Exception\ApiException;
use RuntimeException;
use Throwable;

final class MaxOutboundConsumer
{
    private readonly MaxTextRenderer $renderer;

    public function __construct(
        private Config $config,
        private Logger $logger,
        private MessageRepository $messages,
        private MaxTransportClient $transport,
        private MaxTransportStateStore $routes,
        private ?WorkerShutdownFlag $shutdown = null,
        private string $transportInstanceId = 'max'
    ) {
        $this->renderer = new MaxTextRenderer();
    }

    public function run(): void
    {
        $pollIntervalMs = max(100, (int) $this->config->require('transport', 'outbound_poll_interval_ms'));

        $this->logger->info('MAX outbound consumer started');

        while (true) {
            if ($this->shutdown?->consumeIfRequested()) {
                $this->logger->info('MAX outbound consumer exiting for shutdown request');
                return;
            }

            $handled = false;
            foreach ($this->messages->listPendingPaths() as $path) {
                $message = $this->messages->loadMessageIfPresent($path);
                if ($message === null) {
                    continue;
                }

                if (!$this->canHandle($message)) {
                    continue;
                }

                $handled = true;
                try {
                    if ($this->isStatusMessage($message)) {
                        $this->dispatchStatus($message);
                    } else {
                        $this->dispatch($message);
                    }
                    $this->messages->markDone($path);
                } catch (Throwable $e) {
                    $this->handleDispatchFailure($path, $message, $e);
                }
            }

            if (!$handled) {
                usleep($pollIntervalMs * 1000);
            }
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function canHandle(array $message): bool
    {
        $sessionId = trim((string) ($message['session_id'] ?? ''));
        if ($sessionId === '') {
            return false;
        }

        $resolved = $this->resolveRuntimeSessionTarget($sessionId);
        if ($resolved === null) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function isStatusMessage(array $message): bool
    {
        return (string) ($message['type'] ?? '') === 'status';
    }

    /**
     * @return array{type: 'dialog'|'group', chat_id: string}|null
     */
    private function resolveRuntimeSessionTarget(string $sessionId): ?array
    {
        return MaxSessionId::resolve($sessionId, $this->transportInstanceId);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function dispatch(array $message): void
    {
        $sessionId = trim((string) ($message['session_id'] ?? ''));
        $resolved = $this->resolveRuntimeSessionTarget($sessionId);
        if ($resolved === null) {
            return;
        }

        $chatId = (int) $resolved['chat_id'];
        $type = (string) ($message['type'] ?? 'message');

        if ($type === 'chat_action') {
            $result = $this->transport->sendChatActionToChat(
                $chatId,
                (string) ($message['action'] ?? 'typing')
            );
            $this->logger->debug('MAX chat action sent', [
                'target' => $chatId,
                'action' => (string) ($message['action'] ?? 'typing'),
                'result' => $result,
            ]);

            return;
        }

        if ($type !== 'message') {
            return;
        }

        [$text, $parseMode] = $this->renderMessage(
            (string) ($message['kind'] ?? ''),
            (string) ($message['text'] ?? '')
        );

        $this->transport->sendMessageToChat(
            $chatId,
            $text,
            null,
            $parseMode,
            $this->shouldDisableNotification($message)
        );
    }

    /**
     * @param array<string, mixed> $message
     */
    private function handleDispatchFailure(string $path, array $message, Throwable $error): void
    {
        $failureContext = $this->buildFailureContext($message, $error);

        $this->logger->error('MAX outbound dispatch failed', $failureContext);
        $this->messages->markFailed($path, $message, $error->getMessage(), $failureContext);
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function buildFailureContext(array $message, Throwable $error): array
    {
        $context = [
            'queue_file' => basename($message['id'] ?? ''),
            'message_id' => (string) ($message['id'] ?? ''),
            'session_id' => (string) ($message['session_id'] ?? ''),
            'type' => (string) ($message['type'] ?? ''),
            'kind' => (string) ($message['kind'] ?? ''),
            'error' => $error->getMessage(),
            'payload' => $message,
        ];

        if ($error instanceof ApiException) {
            $context['api_status_code'] = $error->statusCode();
            $context['api_response'] = $error->responseBody();
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function dispatchStatus(array $message): void
    {
        $sessionId = trim((string) ($message['session_id'] ?? ''));
        $resolved = $this->resolveRuntimeSessionTarget($sessionId);
        $route = $this->routes->routeForSession($sessionId);
        $state = strtolower(trim((string) ($message['state'] ?? '')));
        if ($resolved === null || !is_array($route)) {
            return;
        }

        $mode = strtolower(trim((string) ($route['status_mode'] ?? 'none')));
        if (!in_array($mode, ['regular', 'pinned'], true)) {
            return;
        }

        $text = $this->renderStatusText($message, $mode, $state);
        if ($text === '') {
            return;
        }

        if ($mode === 'regular' && $state === 'idle') {
            return;
        }

        $channelId = (int) $resolved['chat_id'];
        if ($channelId === 0) {
            return;
        }

        if ($mode === 'pinned') {
            $messageId = trim((string) ($route['status_message_id'] ?? ''));
            if ($messageId !== '') {
                $result = $this->transport->editMessage($messageId, $text);
                $this->logger->debug('MAX pinned status updated', [
                    'target' => $channelId,
                    'session_id' => $sessionId,
                    'message_id' => $messageId,
                    'text' => $text,
                    'result' => $result,
                ]);

                return;
            }

            $created = $this->transport->sendMessageToChat(
                $channelId,
                $text,
                null,
                null,
                true
            );
            $createdMessageId = trim((string) ($created['message_id'] ?? ''));
            if ($createdMessageId !== '') {
                $this->routes->mergeRoute($sessionId, [
                    'status_message_id' => $createdMessageId,
                ]);

                if ($resolved['type'] === 'dialog') {
                    $this->logger->debug('MAX dialog status message created without pin', [
                        'target' => $channelId,
                        'session_id' => $sessionId,
                        'message_id' => $createdMessageId,
                        'text' => $text,
                        'send_result' => $created,
                    ]);
                } else {
                    $pin = $this->transport->pinMessage($channelId, $createdMessageId);
                    $this->logger->debug('MAX pinned status created', [
                        'target' => $channelId,
                        'session_id' => $sessionId,
                        'message_id' => $createdMessageId,
                        'text' => $text,
                        'send_result' => $created,
                        'pin_result' => $pin,
                    ]);
                }
            }

            return;
        }

        $result = $this->transport->sendMessageToChat(
            $channelId,
            $text,
            null,
            null,
            true
        );

        $this->logger->debug('MAX status message sent', [
            'target' => $channelId,
            'session_id' => $sessionId,
            'text' => $text,
            'result' => $result,
            'disable_notification' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function renderStatusText(array $message, string $mode, string $state): string
    {
        if ($mode === 'pinned' && $state === 'busy') {
            $jobId = trim((string) ($message['job_id'] ?? ''));
            if ($jobId !== '') {
                return 'Занят: ' . $jobId;
            }
        }

        return trim((string) ($message['text'] ?? ''));
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function renderMessage(string $kind, string $text): array
    {
        return match ($kind) {
            'commentary' => [$this->renderer->renderCommentary($text), 'HTML'],
            default => [$this->renderer->renderFinal($text), 'HTML'],
        };
    }

    /**
     * @param array<string, mixed> $message
     */
    private function shouldDisableNotification(array $message): bool
    {
        return ((string) ($message['kind'] ?? '')) === 'commentary';
    }
}
