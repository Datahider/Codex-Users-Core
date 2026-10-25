#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\ActiveTurnRegistry;
use CodexRuntime\Attachment\AttachmentDownloaderInterface;
use CodexRuntime\Attachment\InboundAttachmentLocalizer;
use CodexRuntime\Attachment\VoiceAttachmentProcessor;
use CodexRuntime\Audio\AudioTranscriberInterface;
use CodexRuntime\ClaudeProcess;
use CodexRuntime\CodexProcess;
use CodexRuntime\Config;
use CodexRuntime\Contracts\RateLimitsProviderInterface;
use CodexRuntime\Contracts\TransportClientInterface;
use CodexRuntime\JsonFileStore;
use CodexRuntime\LimitMonitor;
use CodexRuntime\Logger;
use CodexRuntime\ManagerQueue\EventRepository;
use CodexRuntime\ManagerWorker;
use CodexRuntime\NoopStatusMessageService;
use CodexRuntime\RuntimePaths;
use CodexRuntime\SystemClock;
use CodexRuntime\TextFinalDeliveryService;
use CodexRuntime\WorkerShutdownFlag;

require_once __DIR__ . '/../src/bootstrap.php';

$tmp_root = sys_get_temp_dir() . '/codex-manager-provider-' . bin2hex(random_bytes(4));
mkdir($tmp_root, 0775, true);

try {
    $codex_record = $tmp_root . '/codex-argv.json';
    $fake_codex = $tmp_root . '/codex';
    file_put_contents($fake_codex, str_replace('__RECORD__', var_export($codex_record, true), <<<'PHP'
#!/usr/bin/env php
<?php
$args = array_slice($argv, 1);
file_put_contents(__RECORD__, json_encode($args));
$output = $args[array_search('-o', $args, true) + 1];
stream_get_contents(STDIN);
echo json_encode(['type' => 'thread.started', 'thread_id' => 'codex-new']) . "\n";
file_put_contents($output, 'Ответ codex');
PHP));
    chmod($fake_codex, 0775);

    $claude_record = $tmp_root . '/claude-argv.json';
    $fake_claude = $tmp_root . '/claude';
    file_put_contents($fake_claude, str_replace('__RECORD__', var_export($claude_record, true), <<<'PHP'
#!/usr/bin/env php
<?php
file_put_contents(__RECORD__, json_encode(array_slice($argv, 1)));
stream_get_contents(STDIN);
echo json_encode(['type' => 'system', 'subtype' => 'init', 'session_id' => 'claude-sess-1']) . "\n";
echo json_encode(['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => 'Ответ claude', 'session_id' => 'claude-sess-1'], JSON_UNESCAPED_UNICODE) . "\n";
PHP));
    chmod($fake_claude, 0775);

    $codex_section = ['bin' => $fake_codex, 'model' => 'gpt-5.6-sol', 'cwd' => $tmp_root, 'extra_args' => ['--json']];
    $claude_section = ['bin' => $fake_claude, 'model' => 'opus', 'models' => ['opus'], 'cwd' => $tmp_root];

    // default claude: новая сессия уходит в claude.
    $env = makeWorker($tmp_root . '/claude-default', [
        'provider' => ['default' => 'claude'],
        'codex' => $codex_section,
        'claude' => $claude_section,
    ]);
    $fresh = 'tg-1-fresh';
    $env['run']($fresh);
    assertSame(true, is_file($claude_record), 'claude launched for fresh session');
    assertSame(false, is_file($codex_record), 'codex not launched for fresh claude session');
    assertSame(['opus'], array_slice(json_decode((string) file_get_contents($claude_record), true), array_search('--model', json_decode((string) file_get_contents($claude_record), true), true) + 1, 1), 'claude default model');
    assertSame('claude-sess-1', $env['state']->read()['sessions'][$fresh] ?? null, 'claude session id stored');
    assertSame('claude', $env['state']->read()['session_providers'][$fresh] ?? null, 'provider stored with first session write');
    assertSame([['kind' => 'final', 'text' => 'Ответ claude']], $env['transport']->messages, 'claude final delivered');
    assertSame(0, $env['limits']->reads, 'codex limits not read after claude final');

    // Повторный turn resume-ит claude.
    unlink($claude_record);
    $env['run']($fresh);
    $claude_args = json_decode((string) file_get_contents($claude_record), true);
    assertSame(['--resume', 'claude-sess-1'], array_slice($claude_args, -2), 'claude resume');

    // Legacy-сессия без провайдера остаётся codex даже при default claude.
    $legacy = 'tg-1-legacy';
    $env['state']->update(static function (array $data) use ($legacy): array {
        $data['sessions'][$legacy] = 'codex-legacy';
        return $data;
    });
    @unlink($claude_record);
    $env['transport']->messages = [];
    $env['run']($legacy);
    assertSame(false, is_file($claude_record), 'claude not launched for legacy session');
    $codex_args = json_decode((string) file_get_contents($codex_record), true);
    assertSame(true, in_array('codex-legacy', $codex_args, true), 'legacy session resumed in codex');
    assertSame('gpt-5.6-sol', $codex_args[array_search('--model', $codex_args, true) + 1], 'legacy session uses codex model');
    assertSame(false, isset($env['state']->read()['session_providers'][$legacy]), 'legacy state not rewritten');
    assertSame('final', $env['transport']->messages[0]['kind'] ?? null, 'codex final delivered');
    assertSame(1, $env['limits']->reads, 'codex limits read after codex final');

    // default codex: новая сессия уходит в codex и получает провайдер.
    unlink($codex_record);
    $env = makeWorker($tmp_root . '/codex-default', [
        'codex' => $codex_section,
        'claude' => $claude_section,
    ]);
    $env['run']($fresh);
    assertSame(true, is_file($codex_record), 'codex launched for fresh codex session');
    assertSame('codex-new', $env['state']->read()['sessions'][$fresh] ?? null, 'codex session id stored');
    assertSame('codex', $env['state']->read()['session_providers'][$fresh] ?? null, 'codex provider stored');

    // claude недоступен.
    $missing_claude = $tmp_root . '/missing-claude';
    $env = makeWorker($tmp_root . '/no-claude', [
        'codex' => $codex_section,
        'claude' => ['bin' => $missing_claude] + $claude_section,
    ]);
    $started = 'tg-1-started';
    $chosen = 'tg-1-chosen';
    $env['state']->write([
        'sessions' => [$started => 'claude-sess-1'],
        'session_providers' => [$started => 'claude', $chosen => 'claude'],
    ]);
    @unlink($codex_record);
    @unlink($claude_record);

    $env['run']($started);
    assertSame(
        [['kind' => 'system', 'text' => "Провайдер claude недоступен: {$missing_claude} не найден в PATH. Отправьте /reset, чтобы начать новую сессию."]],
        $env['transport']->messages,
        'started session with unavailable provider'
    );
    assertSame(false, is_file($codex_record), 'codex not substituted for started session');

    $env['transport']->messages = [];
    $env['run']($chosen);
    assertSame(
        [['kind' => 'system', 'text' => "Провайдер claude недоступен: {$missing_claude} не найден в PATH. Выберите другой провайдер командой /provider."]],
        $env['transport']->messages,
        'unstarted session with unavailable provider'
    );
    assertSame(false, is_file($codex_record), 'codex not substituted for unstarted session');
    assertSame(false, isset($env['state']->read()['sessions'][$chosen]), 'unstarted session stays unstarted');

    // Провайдер из метаданных сообщения.
    $env = makeWorker($tmp_root . '/meta-provider', [
        'codex' => $codex_section,
        'claude' => $claude_section,
    ]);
    $claude_meta = ['provider' => 'claude'];
    @unlink($codex_record);
    @unlink($claude_record);

    $meta_fresh = 'tg-1-meta-fresh';
    $env['state']->write(['session_models' => [$meta_fresh => 'gpt-5.6-sol']]);
    $env['run']($meta_fresh, $claude_meta);
    assertSame(true, is_file($claude_record), 'meta provider launches claude for fresh session');
    assertSame(false, is_file($codex_record), 'codex not launched when meta requests claude');
    assertSame('claude', $env['state']->read()['session_providers'][$meta_fresh] ?? null, 'meta provider stored');
    assertSame(false, isset($env['state']->read()['session_models'][$meta_fresh]), 'model cleared when meta changes provider');
    assertSame([['kind' => 'final', 'text' => 'Ответ claude']], $env['transport']->messages, 'meta provider final delivered');

    // Начатая сессия с тем же провайдером: поле игнорируется.
    unlink($claude_record);
    $env['transport']->messages = [];
    $env['run']($meta_fresh, $claude_meta);
    assertSame(['--resume', 'claude-sess-1'], array_slice(json_decode((string) file_get_contents($claude_record), true), -2), 'same meta provider resumes session');

    // Начатая сессия с другим провайдером: нарушение контракта.
    unlink($claude_record);
    $env['transport']->messages = [];
    $state_before = $env['state']->read();
    $env['run']($meta_fresh, ['provider' => 'codex']);
    assertSame(
        [['kind' => 'system', 'text' => 'Сессия уже начата с провайдером claude, сообщение требует провайдер codex.']],
        $env['transport']->messages,
        'meta provider conflicts with started session'
    );
    assertSame(false, is_file($codex_record), 'codex not launched on meta conflict');
    assertSame(false, is_file($claude_record), 'claude not launched on meta conflict');
    assertSame($state_before, $env['state']->read(), 'state unchanged on meta conflict');

    // Неизвестный провайдер в метаданных.
    foreach (['gemini', '', 42] as $bad_provider) {
        $env['transport']->messages = [];
        $meta_bad = 'tg-1-meta-bad';
        $env['run']($meta_bad, ['provider' => $bad_provider]);
        assertSame(
            [['kind' => 'system', 'text' => 'Неизвестный провайдер в метаданных сообщения: ' . json_encode($bad_provider) . '.']],
            $env['transport']->messages,
            'unknown meta provider ' . var_export($bad_provider, true)
        );
        assertSame(false, is_file($codex_record) || is_file($claude_record), 'no process for unknown meta provider');
        assertSame(false, isset($env['state']->read()['session_providers'][$meta_bad]), 'unknown meta provider not stored');
    }

    // Недоступный провайдер из метаданных: сохраняется, turn падает по общим правилам.
    $env = makeWorker($tmp_root . '/meta-unavailable', [
        'codex' => $codex_section,
        'claude' => ['bin' => $missing_claude] + $claude_section,
    ]);
    $meta_unavailable = 'tg-1-meta-unavailable';
    $env['run']($meta_unavailable, $claude_meta);
    assertSame(
        [['kind' => 'system', 'text' => "Провайдер claude недоступен: {$missing_claude} не найден в PATH. Выберите другой провайдер командой /provider."]],
        $env['transport']->messages,
        'unavailable meta provider'
    );
    assertSame(false, is_file($codex_record), 'codex not substituted for unavailable meta provider');

    fwrite(STDOUT, "Manager worker provider smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Manager worker provider smoke failed: {$e->getMessage()}\n");
    exit(1);
} finally {
    deleteTree($tmp_root);
}

/**
 * @param array<string, mixed> $sections
 * @return array{run: callable, state: JsonFileStore, transport: object, limits: object}
 */
function makeWorker(string $root, array $sections): array
{
    mkdir($root, 0775, true);
    $config = new Config($sections + ['storage' => ['root' => $root . '/var']]);
    $paths = new RuntimePaths($config);
    $logger = new Logger($paths->logFile());
    $active_turn = new ActiveTurnRegistry($paths->activeTurnFile());
    $transport = new class implements TransportClientInterface {
        public array $messages = [];

        public function sendMessage(
            int|string $chatId,
            string $text,
            ?int $replyToMessageId = null,
            ?string $parseMode = null,
            bool $disableNotification = false
        ): array {
            $this->messages[] = ['kind' => $disableNotification ? 'commentary' : 'final', 'text' => $text];

            return ['message_id' => count($this->messages)];
        }

        public function sendChatAction(int|string $chatId, string $action = 'typing'): void
        {
        }

        public function sendTranscript(int|string $chatId, string $text): array
        {
            throw new RuntimeException('Unexpected transcript');
        }

        public function sendWarning(int|string $chatId, string $text): array
        {
            $this->messages[] = ['kind' => 'warning', 'text' => $text];

            return ['message_id' => count($this->messages)];
        }

        public function sendSystem(int|string $chatId, string $text): array
        {
            $this->messages[] = ['kind' => 'system', 'text' => $text];

            return ['message_id' => count($this->messages)];
        }
    };
    $limits = new class implements RateLimitsProviderInterface {
        public int $reads = 0;

        public function read(): array
        {
            $this->reads++;

            return [
                'primary' => ['usedPercent' => 0, 'resetsAt' => 1789128302],
                'secondary' => ['usedPercent' => 0, 'resetsAt' => 1789458314],
            ];
        }
    };
    $downloader = new class implements AttachmentDownloaderInterface {
        public function download(string $url, ?string $filename = null): string
        {
            throw new RuntimeException('Unexpected attachment download');
        }
    };
    $limit_monitor = new LimitMonitor($config, $limits, $transport, new SystemClock());
    $state = new JsonFileStore($paths->managerStateFile());
    $worker = new ManagerWorker(
        $config,
        $logger,
        new EventRepository($config),
        $state,
        new NoopStatusMessageService(),
        new WorkerShutdownFlag($config, 'manager_queue', 'shutdown_flag', $paths->workerShutdownFlagFile('manager_worker')),
        $transport,
        new CodexProcess($config, $logger, $active_turn),
        new VoiceAttachmentProcessor($downloader, new class implements AudioTranscriberInterface {
            public function transcribe(string $file_path): string
            {
                throw new RuntimeException('Unexpected audio transcription');
            }
        }),
        new InboundAttachmentLocalizer($downloader, $root . '/attachments'),
        $limit_monitor,
        new TextFinalDeliveryService($limit_monitor),
        claude: new ClaudeProcess($config, $logger, $active_turn)
    );
    $process = new ReflectionMethod(ManagerWorker::class, 'processUserMessage');
    $run = static function (string $session_id, array $meta = []) use ($process, $worker): void {
        try {
            $process->invoke($worker, [
                'type' => 'user_message',
                'session_id' => $session_id,
                'text' => 'Привет',
                'meta' => $meta,
            ]);
        } catch (Throwable) {
            // Ошибка turn допустима; проверяются outbound и state.
        }
    };

    return ['run' => $run, 'state' => $state, 'transport' => $transport, 'limits' => $limits];
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("Assertion failed for {$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function deleteTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $item_path = $path . '/' . $item;
        is_dir($item_path) ? deleteTree($item_path) : unlink($item_path);
    }
    rmdir($path);
}
