<?php

declare(strict_types=1);

namespace CodexRuntime;

use RuntimeException;

final class ProviderAvailability
{
    public const CODEX = 'codex';
    public const CLAUDE = 'claude';

    public function __construct(private Config $config)
    {
    }

    /** @return list<string> */
    public function knownProviders(): array
    {
        return [self::CODEX, self::CLAUDE];
    }

    public function isKnown(string $provider): bool
    {
        return in_array($provider, $this->knownProviders(), true);
    }

    /** @return list<string> */
    public function availableProviders(): array
    {
        return array_values(array_filter(
            $this->knownProviders(),
            fn (string $provider): bool => $this->unavailableReason($provider) === null
        ));
    }

    public function defaultProvider(): string
    {
        $provider = trim((string) $this->config->get('provider', 'default', self::CODEX));
        if (!$this->isKnown($provider)) {
            throw new RuntimeException("provider.default must be codex or claude: {$provider}");
        }

        return $provider;
    }

    public function bin(string $provider): string
    {
        $this->requireKnown($provider);

        return trim((string) $this->config->get($provider, 'bin', $provider));
    }

    public function cwd(string $provider): string
    {
        $this->requireKnown($provider);

        return trim((string) $this->config->get($provider, 'cwd', getenv('HOME') ?: ''));
    }

    public function unavailableReason(string $provider): ?string
    {
        $this->requireKnown($provider);

        $bin = $this->bin($provider);
        if (Environment::resolveCommand($bin) === null) {
            return "{$bin} не найден в PATH";
        }

        $cwd = $this->cwd($provider);
        if ($cwd === '' || !is_dir($cwd)) {
            return "каталог {$cwd} не существует";
        }

        if ($provider === self::CLAUDE) {
            $models = $this->claudeModels();
            if ($models === []) {
                return 'не задан claude.models';
            }

            $model = trim((string) $this->config->get('claude', 'model', ''));
            if ($model === '') {
                return 'не задан claude.model';
            }
            if (!in_array($model, $models, true)) {
                return "claude.model {$model} не входит в claude.models";
            }
        }

        return null;
    }

    public function unavailableMessage(string $provider): ?string
    {
        $reason = $this->unavailableReason($provider);

        return $reason === null ? null : "Провайдер {$provider} недоступен: {$reason}.";
    }

    /** @return list<string> */
    public function claudeModels(): array
    {
        $models = $this->config->get('claude', 'models', []);
        if (!is_array($models)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $model): string => trim((string) $model), $models),
            static fn (string $model): bool => $model !== ''
        )));
    }

    private function requireKnown(string $provider): void
    {
        if (!$this->isKnown($provider)) {
            throw new RuntimeException("Unknown provider: {$provider}");
        }
    }
}
