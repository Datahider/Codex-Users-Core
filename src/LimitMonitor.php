<?php

declare(strict_types=1);

namespace CodexRuntime;

use CodexRuntime\Contracts\RateLimitsProviderInterface;
use CodexRuntime\Contracts\ClockInterface;
use CodexRuntime\Contracts\TransportClientInterface;
use RuntimeException;

final class LimitMonitor
{
    private const DEFAULT_PRIMARY_WARNING_PERCENT = 5;
    private const DEFAULT_SECONDARY_WARNING_PERCENT = 1;

    public function __construct(
        private Config $config,
        private RateLimitsProviderInterface $provider,
        private TransportClientInterface $transport,
        private ClockInterface $clock
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function sendCurrentLimits(int|string $session_id): array
    {
        $limits = $this->provider->read();
        $message = $this->transport->sendSystem($session_id, $this->formatStatus($limits));

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

    public function checkAfterFinal(int|string $session_id): void
    {
        $this->sendWarningIfNeeded($session_id, $this->provider->read());
    }

    /**
     * @param array<string, mixed> $limits
     */
    private function formatStatus(array $limits): string
    {
        $blocks = ['Лимиты Codex'];
        $primary = $this->window($limits, 'primary');
        $secondary = $this->window($limits, 'secondary');
        if ($primary !== null) {
            $blocks[] = $this->formatWindow('5 часов', $primary);
        }
        if ($secondary !== null) {
            $blocks[] = $this->formatWindow('7 дней', $secondary);
        }
        $plan_type = trim((string) ($limits['planType'] ?? ''));
        if ($plan_type !== '') {
            $blocks[] = 'Тариф: ' . ucfirst($plan_type);
        }

        return implode("\n\n", $blocks);
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
            "Внимание: заканчиваются лимиты Codex.\n\n" . implode("\n\n", $warnings)
        );
    }

    private function threshold(string $key): int
    {
        $default = match ($key) {
            'primary_remaining_warning_percent' => self::DEFAULT_PRIMARY_WARNING_PERCENT,
            'secondary_remaining_warning_percent' => self::DEFAULT_SECONDARY_WARNING_PERCENT,
        };
        $threshold = $this->config->get('limits', $key, $default);
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
        return sprintf(
            "%s\n`%s` %d%%\n%s",
            $label,
            $this->progressBar($this->remaining($window)),
            $this->remaining($window),
            $this->formatReset((int) $window['resetsAt'])
        );
    }

    private function progressBar(int $remaining_percent): string
    {
        $filled = min(20, max(0, intdiv($remaining_percent + 2, 5)));

        return str_repeat('█', $filled) . str_repeat('░', 20 - $filled);
    }

    private function formatReset(int $resets_at): string
    {
        $remaining_seconds = $resets_at - $this->clock->now();
        if ($remaining_seconds <= 0) {
            return 'Сброс сейчас';
        }
        if ($remaining_seconds < 60) {
            return 'Сброс менее чем через минуту';
        }

        $remaining_minutes = intdiv($remaining_seconds, 60);
        $days = intdiv($remaining_minutes, 1440);
        $hours = intdiv($remaining_minutes % 1440, 60);
        $minutes = $remaining_minutes % 60;
        $parts = [];
        if ($days > 0) {
            $parts[] = $days . ' д';
        }
        if ($hours > 0) {
            $parts[] = $hours . ' ч';
        }
        if ($minutes > 0) {
            $parts[] = $minutes . ' мин';
        }

        return 'Сброс через ' . implode(' ', $parts);
    }
}
