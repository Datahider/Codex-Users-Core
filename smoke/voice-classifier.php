#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\Config;
use CodexRuntime\Voice\CodexLunaVoiceSuitabilityClassifier;

require_once __DIR__ . '/../src/bootstrap.php';

try {
    $tmp_root = sys_get_temp_dir() . '/codex-voice-classifier-' . bin2hex(random_bytes(4));
    mkdir($tmp_root, 0775, true);
    $fake_codex = $tmp_root . '/codex';
    file_put_contents($fake_codex, <<<'PHP'
#!/usr/bin/env php
<?php
$args = $argv;
$output = null;
$model = null;
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '-o') {
        $output = $args[$i + 1] ?? null;
    }
    if ($args[$i] === '--model') {
        $model = $args[$i + 1] ?? null;
    }
}
if ($model !== 'gpt-5.6-luna' || $output === null) {
    fwrite(STDERR, 'invalid classifier command');
    exit(2);
}
$prompt = stream_get_contents(STDIN);
if (!str_contains($prompt, 'Краткий ответ.')) {
    fwrite(STDERR, 'missing classifier text');
    exit(3);
}
file_put_contents($output, '{"voice":true,"reason":""}');
PHP);
    chmod($fake_codex, 0775);
    $classifier = new CodexLunaVoiceSuitabilityClassifier(new Config([
        'codex' => ['bin' => $fake_codex, 'cwd' => $tmp_root],
        'voice_response' => ['classifier_model' => 'gpt-5.6-luna'],
    ]), $tmp_root);

    $decision = $classifier->classify('Краткий ответ.');
    assertSame(['voice' => true, 'reason' => ''], $decision, 'classifier decision');
    assertSame([], glob($tmp_root . '/voice-classifier-*.json') ?: [], 'classifier temporary files removed');

    fwrite(STDOUT, "Voice classifier smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Voice classifier smoke failed: {$e->getMessage()}\n");
    exit(1);
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("{$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
