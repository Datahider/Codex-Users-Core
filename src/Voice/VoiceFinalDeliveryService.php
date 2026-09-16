<?php

declare(strict_types=1);

namespace CodexRuntime\Voice;

use CodexRuntime\Contracts\FinalResponseDeliveryInterface;
use CodexRuntime\LimitMonitor;
use RuntimeException;

final class VoiceFinalDeliveryService implements FinalResponseDeliveryInterface
{
    public function __construct(
        private VoiceFinalPlanner $planner,
        private VoicePreferenceStore $preference,
        private SpeechSynthesizerInterface $synthesizer,
        private VoiceOutboundSenderInterface $sender,
        private LimitMonitor $limit_monitor
    ) {
    }

    public function send(string $runtime_session_id, string $text): array
    {
        $plan = $this->planner->plan($runtime_session_id, 'final', $text);
        if (($plan['kind'] ?? null) === 'final') {
            return $this->limit_monitor->sendFinal($runtime_session_id, $plan['text']);
        }
        if (($plan['kind'] ?? null) !== 'voice') {
            throw new RuntimeException('Invalid final response plan');
        }

        $file_path = $this->synthesizer->synthesize($plan['text'], $this->preference->current());
        try {
            $result = $this->sender->send($runtime_session_id, $file_path);
        } finally {
            if (is_file($file_path) && !unlink($file_path)) {
                throw new RuntimeException('Cannot remove temporary synthesized response');
            }
        }
        $this->limit_monitor->checkAfterFinal($runtime_session_id);

        return $result;
    }
}
