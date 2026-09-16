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
        // 'primary_remaining_warning_percent' => 5,
        // 'secondary_remaining_warning_percent' => 1,
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
    'speech' => [
        'model' => 'gpt-4o-mini-tts',
    ],
    'voice_response' => [
        'max_characters' => 700,
        'classifier_model' => 'gpt-5.6-luna',
        'default_voice' => 'cedar',
        'allowed_voices' => [
            'alloy', 'ash', 'ballad', 'coral', 'echo', 'fable', 'onyx',
            'nova', 'sage', 'shimmer', 'verse', 'marin', 'cedar',
        ],
    ],
    'storage' => [
        // По умолчанию runtime-данные лежат вне каталога с точкой:
        // ~/var/codex-users-core
        // Переопредели, только если данные должны жить в другом месте.
        'root' => $home . '/var/codex-users-core',
    ],
];
