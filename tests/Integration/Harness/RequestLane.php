<?php

namespace Tests\Integration\Harness;

/**
 * T007: RequestLane enum — classifies boundary requests.
 *
 * S1: Classification from wire body only.
 */
enum RequestLane: string
{
    case AgentTurn = 'agent_turn';
    case Condensation = 'condensation';
    case EpisodicSummary = 'episodic_summary';
    case Embedding = 'embedding';

    /**
     * Classify a CapturedPayload into a lane based on wire body only.
     *
     * Discriminators (R2a):
     * - embedding: kind === 'embedding'
     * - condensation: system starts with "You are condensing a segment"
     * - episodic_summary: system starts with "You are a conversation summarizer"
     * - agent_turn: fallback for everything else
     */
    public static function classify(CapturedPayload $payload): self
    {
        if ($payload->kind === 'embedding') {
            return self::Embedding;
        }

        // Check the system field first (populated by Anthropic formatter)
        $system = $payload->system ?? '';

        // Fall back to extracting system message from the messages array
        // (OpenAI/LlamaCpp keep system messages inline)
        if ($system === '' && !empty($payload->messages)) {
            $firstMsg = $payload->messages[0];
            if (is_array($firstMsg) && ($firstMsg['role'] ?? null) === 'system') {
                $system = $firstMsg['content'] ?? '';
            }
        }

        if (str_starts_with($system, 'You are condensing a segment')) {
            return self::Condensation;
        }

        if (str_starts_with($system, 'You are a conversation summarizer')) {
            return self::EpisodicSummary;
        }

        return self::AgentTurn;
    }
}
