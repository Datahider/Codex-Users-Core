<?php

declare(strict_types=1);

namespace CodexRuntime\Max;

use CodexRuntime\Config;
use CodexRuntime\ControlIngress;
use CodexRuntime\ControlQueue\CommandRepository;
use CodexRuntime\JsonFileStore;
use CodexRuntime\Logger;
use CodexRuntime\OutboundQueue\MessageRepository;
use CodexRuntime\Router\ApiClient;
use CodexRuntime\Router\CurlHttpClient;
use CodexRuntime\Router\TransportIngressGateway;
use CodexRuntime\TransportMessageIngress;
use CodexRuntime\WorkerShutdownFlag;
use MaxApi\BotApi;
use RuntimeException;

final class MaxRuntime
{
    private function __construct(
        private Config $config,
        private Logger $logger,
        private BotApi $api,
        private WorkerShutdownFlag $transportShutdown,
        private WorkerShutdownFlag $outboundShutdown,
        private MaxTransportClient $transport,
        private string $transportInstanceId
    ) {
    }

    public static function fromConfig(Config $config, string $configPath = ''): self
    {
        self::assertLibraryInstalled();

        $logger = new Logger((string) $config->require('storage', 'log_file'));
        $api = new BotApi((string) $config->require('max', 'api_token'));
        $transport = new MaxTransportClient($api);
        $transportInstanceId = trim((string) $config->require('max', 'instance_id'));

        return new self(
            config: $config,
            logger: $logger,
            api: $api,
            transportShutdown: new WorkerShutdownFlag($config, 'max', 'transport_shutdown_flag_file'),
            outboundShutdown: new WorkerShutdownFlag($config, 'max', 'outbound_consumer_shutdown_flag_file'),
            transport: $transport,
            transportInstanceId: $transportInstanceId
        );
    }

    public static function fromConfigFile(string $path): self
    {
        return self::fromConfig(Config::fromFile($path), $path);
    }

    public function createWebhookIngress(): MaxWebhookIngress
    {
        return new MaxWebhookIngress(
            $this->config,
            $this->createUpdateIngress()
        );
    }

    public function createLongPollingRunner(): MaxLongPollingRunner
    {
        return new MaxLongPollingRunner(
            $this->config,
            $this->logger,
            $this->api,
            $this->createUpdateIngress(),
            new JsonFileStore((string) $this->config->require('max', 'long_poll_state_file')),
            $this->transportShutdown
        );
    }

    public function createOutboundConsumer(): MaxOutboundConsumer
    {
        return new MaxOutboundConsumer(
            $this->config,
            $this->logger,
            new MessageRepository($this->config),
            $this->transport,
            new MaxTransportStateStore($this->config),
            $this->outboundShutdown,
            $this->transportInstanceId
        );
    }

    public function clearPendingShutdown(): void
    {
        $this->transportShutdown->clearPending();
        $this->outboundShutdown->clearPending();
    }

    public function transport(): MaxTransportClient
    {
        return $this->transport;
    }

    private function createUpdateIngress(): MaxUpdateIngress
    {
        $routerApi = new ApiClient(
            (string) $this->config->require('router', 'base_url'),
            (string) $this->config->require('router', 'transport_token'),
            new CurlHttpClient()
        );

        return new MaxUpdateIngress(
            $this->config,
            $this->logger,
            new TransportMessageIngress(new TransportIngressGateway($routerApi)),
            new ControlIngress(new CommandRepository($this->config)),
            new MaxUpdateNormalizer(),
            $this->transport,
            new MaxTransportStateStore($this->config),
            $this->transportInstanceId
        );
    }

    private static function assertLibraryInstalled(): void
    {
        if (class_exists(BotApi::class)) {
            return;
        }

        throw new RuntimeException(
            'MAX transport requires max-api-php/client. Run composer install before bootstrapping MaxRuntime.'
        );
    }
}
