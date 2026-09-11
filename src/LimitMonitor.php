<?php

declare(strict_types=1);

namespace CodexRuntime;

use CodexRuntime\Contracts\RateLimitsProviderInterface;
use CodexRuntime\Contracts\TransportClientInterface;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class LimitMonitor
{
    public function __construct(
        private Config $config,
        private RateLimitsProviderInterface $provider,
        private TransportClientInterface $transport
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function sendCurrentLimits(int|string $session_id): array
    {
        $limits = $this->provider->read();
        $message = $this->transport->sendMessage($session_id, $this->formatStatus($limits));
        $this->sendWarningIfNeeded($session_id, $limits);

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function sendFinal(
        int|string $session_id,
        string $text,
        ?int $reply_to_message_id = null,
        ?string $parse_mode = null
    ): array {
        $message = $this->transport->sendMessage(
            $session_id,
            $text,
            $reply_to_message_id,
            $parse_mode,
            false
        );
        $this->sendWarningIfNeeded($session_id, $this->provider->read());

        return $message;
    }

    /**
     * @param array<string, mixed> $limits
     */
    private function formatStatus(array $limits): string
    {
        $lines = ['Лимиты Codex:'];
        $primary = $this->window($limits, 'primary');
        $secondary = $this->window($limits, 'secondary');
        if ($primary !== null) {
            $lines[] = $this->formatWindow('5 часов', $primary);
        }
        if ($secondary !== null) {
            $lines[] = $this->formatWindow('7 дней', $secondary);
        }
        $plan_type = trim((string) ($limits['planType'] ?? ''));
        if ($plan_type !== '') {
            $lines[] = 'Тариф: ' . ucfirst($plan_type);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $limits
     */
    private function sendWarningIfNeeded(int|string $session_id, array $limits): void
    {
        $warnings = [];
        $primary = $this->window($limits, 'primary');
        $secondary = $this->window($limits, 'secondary');
        $primary_threshold = $this->threshold('primary_remaining_warning_percent');
        $secondary_threshold = $this->threshold('secondary_remaining_warning_percent');

        if ($primary !== null && $this->remaining($primary) < $primary_threshold) {
            $warnings[] = $this->formatWindow('5 часов', $primary);
        }
        if ($secondary !== null && $this->remaining($secondary) < $secondary_threshold) {
            $warnings[] = $this->formatWindow('7 дней', $secondary);
        }
        if ($warnings === []) {
            return;
        }

        $this->transport->sendWarning(
            $session_id,
            "Внимание: заканчиваются лимиты Codex.\n" . implode("\n", $warnings)
        );
    }

    private function threshold(string $key): int
    {
        $threshold = $this->config->require('limits', $key);
        if (!is_int($threshold) || $threshold < 0 || $threshold > 100) {
            throw new RuntimeException("Config value limits.{$key} must be an integer from 0 to 100");
        }

        return $threshold;
    }

    /**
     * @param array<string, mixed> $limits
     * @return array<string, mixed>|null
     */
    private function window(array $limits, string $key): ?array
    {
        $window = $limits[$key] ?? null;
        if ($window === null) {
            return null;
        }
        if (!is_array($window) || !is_numeric($window['usedPercent'] ?? null) || !is_int($window['resetsAt'] ?? null)) {
            throw new RuntimeException("Invalid Codex rate-limit window: {$key}");
        }

        return $window;
    }

    /**
     * @param array<string, mixed> $window
     */
    private function remaining(array $window): int
    {
        return max(0, 100 - (int) round((float) $window['usedPercent']));
    }

    /**
     * @param array<string, mixed> $window
     */
    private function formatWindow(string $label, array $window): string
    {
        $timezone = new DateTimeZone((string) $this->config->require('limits', 'timezone'));
        $reset = (new DateTimeImmutable('@' . (string) $window['resetsAt']))->setTimezone($timezone);

        return sprintf(
            '%s: осталось %d%%, сброс %s',
            $label,
            $this->remaining($window),
            $reset->format('d.m.Y H:i T')
        );
    }
}
