<?php

$home = getenv('HOME') ?: __DIR__;

return [
    'provider' => [
        // Провайдер новых runtime-сессий: codex или claude. Если не задан — codex.
        'default' => 'codex',
    ],
    'codex' => [
        'bin' => 'codex',
        // Если model не задана, используется gpt-5.6-sol.
        'model' => 'gpt-5.6-sol',
        // По умолчанию Codex запускается из домашнего каталога пользователя.
        // Переопредели, только если нужен другой рабочий каталог.
        'cwd' => $home,
        // Ожидание завершения /login device-code flow.
        'device_auth_timeout_seconds' => 930,
        'extra_args' => [
            '--skip-git-repo-check',
            '--json',
        ],
    ],
    // Провайдер claude (Claude Code CLI). Нужен, только если claude используется.
    // 'claude' => [
    //     'bin' => 'claude',
    //     'model' => 'opus',
    //     'models' => ['opus', 'sonnet', 'haiku'],
    //     'cwd' => $home,
    //     'extra_args' => [],
    // ],
    'limits' => [
        // 'primary_remaining_warning_percent' => 5,
        // 'secondary_remaining_warning_percent' => 1,
    ],
    'manager_queue' => [
        'max_workers' => 1,
    ],
    'router' => [
        'base_url' => 'https://cdx-router.botmeister.ru',
        'core_token' => '',
    ],
    'file_exchange' => [
        'base_url' => 'https://files.ioannidis.ru',
        'token' => '',
    ],
    'transport_tools' => [
        'cache_ttl_seconds' => 600,
        'list_timeout_seconds' => 5,
        'call_timeout_seconds' => 50,
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
