#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use CodexRuntime\Config;
use CodexRuntime\Document\DocumentSender;
use CodexRuntime\Document\FileExchangeApiClient;
use CodexRuntime\Mcp\StdioServer;
use CodexRuntime\Mcp\TransportToolService;
use CodexRuntime\Mcp\TransportToolStore;
use CodexRuntime\Mcp\UnavailableFileTool;
use CodexRuntime\OptionalFeatureConfig;
use CodexRuntime\Image\ImageSender;
use CodexRuntime\Router\ApiClient;
use CodexRuntime\Router\CurlHttpClient;
use CodexRuntime\Router\RouterDeliveryClient;
use CodexRuntime\RuntimePaths;
use CodexRuntime\Logger;
use CodexRuntime\Voice\ResponseDeliveryTool;
use CodexRuntime\Voice\VoiceResponseModeStore;

$config_path = trim((string) getenv('CODEX_CORE_CONFIG'));
$runtime_session_id = trim((string) getenv('RUNTIME_SID'));
if ($config_path === '' || $runtime_session_id === '') {
    throw new RuntimeException('CODEX_CORE_CONFIG and RUNTIME_SID are required');
}

$config = Config::fromFile($config_path);
$paths = new RuntimePaths($config);
$logger = new Logger((string) $config->get('storage', 'log_file', $paths->logFile()), false);
$delivery = new RouterDeliveryClient(new ApiClient(
    (string) $config->require('router', 'base_url'),
    (string) $config->require('router', 'core_token'),
    new CurlHttpClient()
), $logger, (int) $config->get('router', 'retry_unavailable_after_seconds', 15));
$missing_file_exchange = (new OptionalFeatureConfig($config))->missingFileExchangeValues();
if ($missing_file_exchange === []) {
    $file_exchange = new FileExchangeApiClient(
        (string) $config->require('file_exchange', 'base_url'),
        (string) $config->require('file_exchange', 'token')
    );
    $document_sender = new DocumentSender($file_exchange, $delivery, $runtime_session_id);
    $image_sender = new ImageSender($file_exchange, $delivery, $runtime_session_id);
} else {
    $document_sender = $image_sender = new UnavailableFileTool($missing_file_exchange);
}
$response_delivery = new ResponseDeliveryTool(
    new VoiceResponseModeStore($paths->voiceResponseModesFile()),
    $runtime_session_id
);
$transport_tools = new TransportToolService(
    new TransportToolStore($paths->transportToolsDir(), $logger),
    $delivery,
    $logger,
    $runtime_session_id,
    ['send_document', 'send_image', 'set_response_delivery'],
    (float) $config->get('transport_tools', 'cache_ttl_seconds', 600),
    (float) $config->get('transport_tools', 'list_timeout_seconds', 5),
    (float) $config->get('transport_tools', 'call_timeout_seconds', 50)
);

(new StdioServer($document_sender, $image_sender, $response_delivery, $transport_tools))->run(STDIN, STDOUT);
