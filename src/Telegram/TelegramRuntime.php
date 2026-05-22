<?php

declare(strict_types=1);

namespace CodexRuntime\Telegram;

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

final class TelegramRuntime
{
    private function __construct(
        private Config $config,
        private Logger $logger,
        private TelegramApiClient $api,
        private string $transportInstanceId
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        $logger = new Logger((string) $config->require('storage', 'log_file'));
        $api = new TelegramApiClient(
            (string) $config->require('telegram', 'bot_token'),
            (string) $config->require('telegram', 'base_url'),
            (string) $config->require('telegram', 'endpoint_prefix')
        );
        $transportInstanceId = trim((string) $config->require('telegram', 'instance_id'));

        return new self($config, $logger, $api, $transportInstanceId);
    }

    public static function fromConfigFile(string $path): self
    {
        return self::fromConfig(Config::fromFile($path));
    }

    public function createLongPollingRunner(): TelegramLongPollingRunner
    {
        return new TelegramLongPollingRunner(
            $this->config,
            $this->logger,
            $this->api,
            $this->createUpdateIngress(),
            new JsonFileStore((string) $this->config->require('telegram', 'long_poll_state_file'))
        );
    }

    public function createOutboundConsumer(): TelegramOutboundConsumer
    {
        return new TelegramOutboundConsumer(
            $this->config,
            $this->logger,
            new MessageRepository($this->config),
            $this->api,
            $this->transportInstanceId
        );
    }

    private function createUpdateIngress(): TelegramUpdateIngress
    {
        $routerApi = new ApiClient(
            (string) $this->config->require('router', 'base_url'),
            (string) $this->config->require('router', 'transport_token'),
            new CurlHttpClient()
        );

        return new TelegramUpdateIngress(
            $this->config,
            $this->logger,
            new TransportMessageIngress(new TransportIngressGateway($routerApi)),
            new ControlIngress(new CommandRepository($this->config)),
            new TelegramUpdateNormalizer(),
            $this->api,
            $this->transportInstanceId
        );
    }
}
