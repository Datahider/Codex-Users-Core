#!/usr/bin/env php
<?php

declare(strict_types=1);

use CodexRuntime\RuntimeInstaller;

require_once __DIR__ . '/../src/bootstrap.php';

try {
    $tmpRoot = sys_get_temp_dir() . '/codex-runtime-skills-sync-' . substr(bin2hex(random_bytes(4)), 0, 8);
    $projectRoot = $tmpRoot . '/project';
    $codexHome = $tmpRoot . '/codex-home';
    mkdir($projectRoot . '/skills/files-ioannidis-download/agents', 0775, true);
    mkdir($codexHome, 0775, true);

    file_put_contents(
        $projectRoot . '/skills/files-ioannidis-download/SKILL.md',
        "---\nname: files-ioannidis-download\n---\nsource v1\n"
    );
    file_put_contents(
        $projectRoot . '/skills/files-ioannidis-download/agents/openai.yaml',
        "interface:\n  display_name: \"Files\"\n"
    );

    $installer = new RuntimeInstaller();
    $installer->ensureBundledSkills($codexHome, $projectRoot);

    $installedSkill = $codexHome . '/skills/files-ioannidis-download/SKILL.md';
    if (!is_file($installedSkill)) {
        throw new RuntimeException('Bundled skill was not copied to CODEX_HOME');
    }

    assertSame("---\nname: files-ioannidis-download\n---\nsource v1\n", (string) file_get_contents($installedSkill), 'initial skill sync');

    file_put_contents(
        $projectRoot . '/skills/files-ioannidis-download/SKILL.md',
        "---\nname: files-ioannidis-download\n---\nsource v2\n"
    );
    $installer->ensureBundledSkills($codexHome, $projectRoot);
    assertSame("---\nname: files-ioannidis-download\n---\nsource v2\n", (string) file_get_contents($installedSkill), 'updated skill sync');

    fwrite(STDOUT, "Bundled skills sync smoke: OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Bundled skills sync smoke failed: {$e->getMessage()}\n");
    exit(1);
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            'Assertion failed for %s: expected %s, got %s',
            $label,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}
