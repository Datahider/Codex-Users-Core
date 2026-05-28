#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\ConfigPathResolver;

require_once __DIR__ . '/../src/bootstrap.php';

try {
    $tmpRoot = sys_get_temp_dir() . '/codex-runtime-config-resolution-' . substr(bin2hex(random_bytes(4)), 0, 8);
    $homeDir = $tmpRoot . '/home';
    $projectRoot = $tmpRoot . '/project';
    $repoConfig = $projectRoot . '/config/config.php';
    $userConfig = $homeDir . '/.codex-users-core/config.php';
    $explicitConfig = $tmpRoot . '/explicit.php';

    foreach ([$homeDir, dirname($repoConfig), dirname($userConfig)] as $dir) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory {$dir}");
        }
    }

    file_put_contents($repoConfig, "<?php return [];\n");
    file_put_contents($userConfig, "<?php return [];\n");
    file_put_contents($explicitConfig, "<?php return [];\n");

    $scriptPath = $projectRoot . '/bin/run-core.php';

    putenv("HOME={$homeDir}");

    assertSame(
        $explicitConfig,
        ConfigPathResolver::resolve([$scriptPath, $explicitConfig], dirname($scriptPath)),
        'explicit argument wins'
    );

    assertSame(
        $userConfig,
        ConfigPathResolver::resolve([$scriptPath], dirname($scriptPath)),
        'user config is preferred over repo config'
    );

    @unlink($userConfig);

    assertSame(
        $repoConfig,
        ConfigPathResolver::resolve([$scriptPath], dirname($scriptPath)),
        'repo config is fallback when user config is absent'
    );

    fwrite(STDOUT, "Default config resolution smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Default config resolution smoke failed: {$e->getMessage()}\n");
    exit(1);
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        $expectedText = var_export($expected, true);
        $actualText = var_export($actual, true);
        throw new RuntimeException("Assertion failed for {$label}: expected {$expectedText}, got {$actualText}");
    }
}
