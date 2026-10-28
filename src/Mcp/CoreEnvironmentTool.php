<?php

declare(strict_types=1);

namespace CodexRuntime\Mcp;

use CodexRuntime\Config;
use CodexRuntime\Environment;
use CodexRuntime\JsonFileStore;
use CodexRuntime\OptionalFeatureConfig;
use CodexRuntime\ProviderAvailability;
use CodexRuntime\ProviderPreferenceStore;
use CodexRuntime\RuntimePaths;

final class CoreEnvironmentTool implements CoreEnvironmentToolInterface
{
    public function __construct(
        private Config $config,
        private RuntimePaths $paths,
        private JsonFileStore $manager_state,
        private string $runtime_session_id,
        private string $core_root
    ) {
    }

    public function describe(): array
    {
        $core_root = rtrim((string) (realpath($this->core_root) ?: $this->core_root), '/');
        $shim_root = $core_root . '/bin/shims';
        $providers = new ProviderAvailability($this->config, $this->pathWithout($shim_root));
        $provider_preferences = new ProviderPreferenceStore($this->manager_state, $this->config);
        $features = new OptionalFeatureConfig($this->config);
        $effective_provider = $provider_preferences->effectiveForRuntimeSession($this->runtime_session_id);
        $provider_status = [];
        foreach ($providers->knownProviders() as $provider) {
            $reason = $providers->unavailableReason($provider);
            $provider_status[$provider] = [
                'available' => $reason === null,
                'unavailable_reason' => $reason,
                'working_directory' => $providers->cwd($provider),
            ];
        }

        $shims = $this->shimContracts($shim_root);
        $asynchronous_workers = trim((string) getenv('CODEX_SID')) !== ''
            && trim((string) getenv('RUNTIME_SID')) !== '';

        return [
            'contract_version' => 1,
            'description' => $this->description($effective_provider, $asynchronous_workers),
            'runtime' => [
                'name' => 'CodexGate Core',
                'single_user' => true,
                'core_root' => $core_root,
            ],
            'transport_boundary' => [
                'protocol' => 'unified',
                'external' => true,
                'channel_known' => false,
                'source_format_known' => false,
                'rule' => 'Do not infer the transport channel, sender, chat type or whether the original input was text or voice unless explicitly provided.',
            ],
            'session' => [
                'effective_provider' => $effective_provider,
                'started' => $provider_preferences->isStarted($this->runtime_session_id),
                'isolated_by_runtime_session' => true,
            ],
            'providers' => $provider_status,
            'paths' => [
                'home' => Environment::homeDirectory(),
                'working_directory' => getcwd() ?: null,
                'storage_root' => $this->paths->root(),
                'runtime_log' => $this->paths->logFile(),
            ],
            'features' => [
                'file_delivery' => $features->missingFileExchangeValues() === [],
                'voice_transcription' => $features->missingTranscriptionValues() === [],
                'voice_responses' => $features->missingVoiceResponseValues() === [],
            ],
            'command_environment' => [
                'path_contains_core_shims' => in_array($shim_root, explode(PATH_SEPARATOR, (string) getenv('PATH')), true),
                'shim_dispatch_is_expected' => true,
                'queued_job_is_successful_dispatch' => true,
                'shims' => $shims,
                'background_codex_workers' => [
                    'in_process_subagent_api' => false,
                    'available_through_codex_shim' => isset($shims['codex']) && $shims['codex']['installed'],
                    'asynchronous' => $asynchronous_workers,
                    'new_session_command' => 'codex exec <prompt>',
                    'resume_session_command' => 'codex exec resume <session_id> <prompt>',
                    'current_session_resume_forbidden' => true,
                    'completion_delivery' => $asynchronous_workers
                        ? 'The shim returns a job id immediately and later delivers the result to this runtime session.'
                        : 'Without CODEX_SID the shim proxies to the real codex command synchronously.',
                ],
            ],
            'file_delivery' => [
                'user_can_access_local_paths' => false,
                'documents_tool' => 'send_document',
                'images_tool' => 'send_image',
                'rule' => 'A local path or Markdown link does not deliver a file to the user.',
            ],
            'operational_rules' => [
                'Do not treat dispatch through a Core shim, queue or background worker as an unexpected condition.',
                'After a shim reports a queued job id, do not repeat, replace or manually intercept that job.',
                'Use schedule instead of sleep for deferred work.',
                'Use start and stop only for managed processes, never for the Core process itself.',
                'Transport-provided MCP tools may add capabilities for the current runtime session.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function shimContracts(string $shim_root): array
    {
        $installed = static fn (string $name): bool => is_executable($shim_root . '/' . $name);

        return [
            'codex' => [
                'installed' => $installed('codex'),
                'purpose' => 'Start or resume a separate Codex worker. In an active Codex runtime session the job is queued and its result returns later to the originating runtime session.',
            ],
            'schedule' => [
                'installed' => $installed('schedule'),
                'usage' => "schedule --when '<datetime or +7 days>' '<prompt>'",
                'mode' => 'one_shot',
                'purpose' => 'Run a prompt later in the current runtime session.',
            ],
            'start' => [
                'installed' => $installed('start'),
                'usage' => 'start --name <process-name> [--cwd <dir>] [--pid-file <file>] -- <command...>',
                'purpose' => 'Start a long-lived process with Core ownership metadata.',
            ],
            'stop' => [
                'installed' => $installed('stop'),
                'usage' => 'stop <process-name>',
                'purpose' => 'Stop only a process whose Core ownership marker matches.',
            ],
            'timeout' => [
                'installed' => $installed('timeout'),
                'usage' => 'timeout <duration> <command...>',
                'minimum_exclusive_seconds' => 0,
                'maximum_seconds' => 30,
            ],
            'sleep' => [
                'installed' => $installed('sleep'),
                'allowed' => false,
                'replacement' => 'schedule',
            ],
        ];
    }

    private function description(string $effective_provider, bool $asynchronous_workers): string
    {
        $worker_mode = $asynchronous_workers
            ? 'В этой сессии `codex exec` через shim ставит отдельного Codex-исполнителя в фон, сразу возвращает job id, а результат позднее приходит в исходную runtime-сессию.'
            : 'В этой сессии `CODEX_SID` не установлен, поэтому `codex` shim проксирует вызов настоящему Codex синхронно.';

        return <<<MARKDOWN
# Среда CodexGate Core

Ты работаешь внутри однопользовательского Core. Текущий провайдер: `{$effective_provider}`. Сообщения приходят через внешний transport по единому протоколу. Агент не знает конкретный канал, отправителя, тип чата и исходную форму сообщения — текст или голос, — если они не переданы явно.

Попадание команды в штатный shim, очередь или фон — ожидаемый operational path, а не неожиданность и не причина прекращать работу. Если shim сообщил, что задача поставлена, запуск завершён штатно: не повторяй его, не подменяй другим бинарником и не перехватывай вручную.

## Фоновые исполнители

Core не предоставляет отдельный in-process API субагентов. {$worker_mode} `codex exec resume <текущий CODEX_SID> ...` запрещён.

## Отложенные и долгоживущие задачи

- Одноразовая отложенная задача: `schedule --when '<datetime or +7 days>' '<prompt>'`. Результат вернётся в текущую runtime-сессию.
- `sleep` запрещён; для отложенной работы используй `schedule`.
- Долгоживущий процесс: `start --name <process-name> [--cwd <dir>] [--pid-file <file>] -- <command...>`.
- Остановка созданного managed-процесса: `stop <process-name>`. `stop` проверяет marker владения и не предназначен для остановки Core.
- Короткое ограничение команды: `timeout <duration> <command...>`, максимум 30 секунд.

## Файлы и transport

Пользователь не видит локальную файловую систему. Документы передавай через `send_document`, изображения JPEG/PNG/WEBP — через `send_image`; локальный путь или Markdown-ссылка файл не передают. Transport может добавить собственные MCP tools для текущей runtime-сессии.
MARKDOWN;
    }

    private function pathWithout(string $excluded_path): string
    {
        return implode(PATH_SEPARATOR, array_values(array_filter(
            explode(PATH_SEPARATOR, (string) getenv('PATH')),
            static fn (string $path): bool => $path !== '' && rtrim($path, '/') !== $excluded_path
        )));
    }
}
