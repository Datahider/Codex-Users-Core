<?php

$home = getenv('HOME') ?: __DIR__;

return [
    'codex' => [
        'bin' => 'codex',
        // По умолчанию Codex запускается из домашнего каталога пользователя.
        // Переопредели, только если нужен другой рабочий каталог.
        'cwd' => $home,
        'extra_args' => [
            '--skip-git-repo-check',
            '--json',
        ],
    ],
    'router' => [
        'base_url' => 'https://cdx-router.botmeister.ru',
        'core_token' => '',
    ],
    'storage' => [
        // По умолчанию runtime-данные лежат рядом с конфигом:
        // ~/.codex-users-core/var
        // Переопредели, только если данные должны жить в другом месте.
        'root' => __DIR__ . '/var',
    ],
];
