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
    'limits' => [
        'primary_remaining_warning_percent' => 20,
        'secondary_remaining_warning_percent' => 20,
        'timezone' => 'Europe/Moscow',
    ],
    'router' => [
        'base_url' => 'https://cdx-router.botmeister.ru',
        'core_token' => '',
    ],
    'file_exchange' => [
        'base_url' => 'https://files.ioannidis.ru',
        'token' => '',
    ],
    'transcription' => [
        'api_key' => '',
        'model' => 'gpt-transcribe',
    ],
    'storage' => [
        // По умолчанию runtime-данные лежат вне каталога с точкой:
        // ~/var/codex-users-core
        // Переопредели, только если данные должны жить в другом месте.
        'root' => $home . '/var/codex-users-core',
    ],
];
