#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use CodexRuntime\Config;
use CodexRuntime\Document\DocumentSender;
use CodexRuntime\Document\FileExchangeApiClient;
use CodexRuntime\Mcp\StdioServer;
use CodexRuntime\Router\ApiClient;
use CodexRuntime\Router\CurlHttpClient;
use CodexRuntime\Router\RouterDeliveryClient;

$config_path = trim((string) getenv('CODEX_CORE_CONFIG'));
$runtime_session_id = trim((string) getenv('RUNTIME_SID'));
if ($config_path === '' || $runtime_session_id === '') {
    throw new RuntimeException('CODEX_CORE_CONFIG and RUNTIME_SID are required');
}

$config = Config::fromFile($config_path);
$sender = new DocumentSender(
    new FileExchangeApiClient(
        (string) $config->require('file_exchange', 'base_url'),
        (string) $config->require('file_exchange', 'token')
    ),
    new RouterDeliveryClient(new ApiClient(
        (string) $config->require('router', 'base_url'),
        (string) $config->require('router', 'core_token'),
        new CurlHttpClient()
    )),
    $runtime_session_id
);

(new StdioServer($sender))->run(STDIN, STDOUT);
