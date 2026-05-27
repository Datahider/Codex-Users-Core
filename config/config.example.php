<?php

return [
    'codex' => [
        'bin' => 'codex',
        'cwd' => __DIR__ . '/..',
        'extra_args' => [
            '--skip-git-repo-check',
            '--json',
        ],
    ],
    'router' => [
        'base_url' => 'https://cdx-router.example',
        'core_token' => '',
    ],
    'storage' => [
        'root' => __DIR__ . '/../var',
    ],
];
