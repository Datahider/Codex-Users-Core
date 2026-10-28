#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Config;
use CodexRuntime\JsonFileStore;
use CodexRuntime\Mcp\CoreEnvironmentTool;
use CodexRuntime\RuntimePaths;

require_once __DIR__ . '/../src/bootstrap.php';

$root = sys_get_temp_dir() . '/core-environment-' . bin2hex(random_bytes(4));
mkdir($root, 0775, true);
$config = new Config([
    'provider' => ['default' => 'codex'],
    'codex' => ['bin' => PHP_BINARY, 'cwd' => $root],
    'claude' => ['bin' => 'definitely-missing-claude-binary', 'cwd' => $root, 'model' => 'sonnet', 'models' => ['sonnet']],
    'router' => ['base_url' => 'https://router.invalid', 'core_token' => 'SECRET-CORE-TOKEN-MUST-NOT-LEAK'],
    'file_exchange' => ['base_url' => 'https://files.invalid', 'token' => 'SECRET-FILE-TOKEN-MUST-NOT-LEAK'],
    'transcription' => ['api_key' => 'SECRET-API-KEY-MUST-NOT-LEAK', 'model' => 'gpt-transcribe'],
    'speech' => ['model' => 'gpt-4o-mini-tts'],
    'voice_response' => ['default_voice' => 'cedar', 'allowed_voices' => ['cedar']],
    'storage' => ['root' => $root . '/var'],
]);
$paths = new RuntimePaths($config);
$state = new JsonFileStore($paths->managerStateFile());
$state->write([
    'sessions' => ['runtime-test' => 'provider-session'],
    'session_providers' => ['runtime-test' => 'codex'],
]);

$previous_codex_sid = getenv('CODEX_SID');
putenv('CODEX_SID=codex-test-session');
try {
    $result = (new CoreEnvironmentTool($config, $paths, $state, 'runtime-test', dirname(__DIR__)))->describe();
} finally {
    if ($previous_codex_sid === false) {
        putenv('CODEX_SID');
    } else {
        putenv('CODEX_SID=' . $previous_codex_sid);
    }
}

assertSame('CodexGate Core', $result['runtime']['name'] ?? null, 'runtime name');
assertSame(true, $result['runtime']['single_user'] ?? null, 'single-user runtime');
assertSame(false, $result['transport_boundary']['channel_known'] ?? null, 'transport channel hidden');
assertSame(false, $result['transport_boundary']['source_format_known'] ?? null, 'source format hidden');
assertSame('codex', $result['session']['effective_provider'] ?? null, 'effective provider');
assertSame(true, $result['session']['started'] ?? null, 'started session');
assertSame(true, $result['providers']['codex']['available'] ?? null, 'codex available');
assertSame(false, $result['providers']['claude']['available'] ?? null, 'claude unavailable');
assertSame(true, $result['features']['file_delivery'] ?? null, 'file delivery configured');
assertSame(true, $result['features']['voice_transcription'] ?? null, 'transcription configured');
assertSame(true, $result['features']['voice_responses'] ?? null, 'voice responses configured');
assertSame(true, $result['command_environment']['background_codex_workers']['asynchronous'] ?? null, 'background worker is asynchronous with CODEX_SID');
assertSame('codex exec <prompt>', $result['command_environment']['background_codex_workers']['new_session_command'] ?? null, 'background worker command');
assertSame("schedule --when '<datetime or +7 days>' '<prompt>'", $result['command_environment']['shims']['schedule']['usage'] ?? null, 'schedule usage');
assertSame('one_shot', $result['command_environment']['shims']['schedule']['mode'] ?? null, 'schedule is one-shot');
assertSame("start --name <process-name> [--cwd <dir>] [--pid-file <file>] -- <command...>", $result['command_environment']['shims']['start']['usage'] ?? null, 'managed process usage');
assertSame(30, $result['command_environment']['shims']['timeout']['maximum_seconds'] ?? null, 'timeout limit');
assertSame(false, $result['command_environment']['shims']['sleep']['allowed'] ?? null, 'sleep rejected');
assertSame($root . '/var', $result['paths']['storage_root'] ?? null, 'storage root');
assertSame($root . '/var/log/runtime.log', $result['paths']['runtime_log'] ?? null, 'runtime log');
assertContains('Попадание команды в штатный shim', $result['description'] ?? '', 'shim behavior is explicit');
assertContains('не знает конкретный канал', $result['description'] ?? '', 'transport opacity is explicit');
assertContains('codex exec', $result['description'] ?? '', 'background worker documentation');
assertContains('schedule --when', $result['description'] ?? '', 'scheduler documentation');
assertContains('start --name', $result['description'] ?? '', 'managed process documentation');

$json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
foreach (['SECRET-CORE-TOKEN-MUST-NOT-LEAK', 'SECRET-FILE-TOKEN-MUST-NOT-LEAK', 'SECRET-API-KEY-MUST-NOT-LEAK'] as $secret) {
    assertSame(false, str_contains($json, $secret), "secret {$secret}");
}

fwrite(STDOUT, "Core environment tool smoke: OK\n");

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("Assertion failed for {$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertContains(string $needle, string $actual, string $label): void
{
    if (!str_contains($actual, $needle)) {
        throw new RuntimeException("Assertion failed for {$label}: missing " . var_export($needle, true));
    }
}
