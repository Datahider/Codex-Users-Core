#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use CodexRuntime\RuntimeDoctor;

$configPath = $argv[1] ?? (__DIR__ . '/../config/config.php');

try {
    $issues = (new RuntimeDoctor())->diagnose($configPath);
    if ($issues !== []) {
        fwrite(STDERR, "Doctor failed:\n");
        foreach ($issues as $issue) {
            fwrite(STDERR, "- {$issue}\n");
        }

        exit(1);
    }

    fwrite(STDOUT, "Doctor OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Doctor failed: {$e->getMessage()}\n");
    exit(1);
}
