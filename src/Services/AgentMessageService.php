<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentMessage;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageDelivered;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageEnvelope;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageOutcome;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageRefusal;
use Illuminate\Support\Facades\Context;

/**
 * The single write path for `agent_messages` (107-agent-message-protocol,
 * contracts/agent-message-service.md §1, data-model.md §1) — mirrors
 * DelegationService's/ManagerService's/SequenceService's own identical
 * framing as the sole owner of their respective tables.
 *
 * Phase 3 (US1) implements only the delivery path — every envelope this
 * phase's own tests construct is well-formed. The size bound (US5) and
 * recipient-availability resolution (US6) are later phases' own checks,
 * inserted in a fixed order ahead of this delivery step (research.md D6)
 * — that branching doesn't exist yet.
 *
 * Phase 6 (US4) adds the FIRST check in that fixed order: structural
 * validation (contracts §1 step 1) — missing fromAgentId/toAgentId, empty
 * content, or a blank expectedResponse are refused before any size or
 * availability check is ever reached (research.md D6). An empty context
 * array is deliberately NOT a violation (FR-005 — explicitly no context).
 *
 * `run_id` stamping (Phase 4, US2) reads the single ambient carrier
 * `Context::get('run_id')` (069's existing mechanism, reused unchanged) at
 * the moment of sending — never a send() parameter, never a second ambient
 * slot for owner_user_id/conversation_id (research.md D3, standing rule 6).
 * It is stamped identically on a refused row as on a delivered one.
 */
class AgentMessageService
{
    public function send(MessageEnvelope $envelope): MessageOutcome
    {
        $contentArray = array_map(fn ($part) => $part->toArray(), $envelope->content);
        $contextArray = array_map(fn ($part) => $part->toArray(), $envelope->context);

        $sizeBytes = strlen(json_encode([
            'content' => $contentArray,
            'context' => $contextArray,
            'expected_response' => $envelope->expectedResponse,
        ]));

        $runId = Context::get('run_id');

        $refusalReason = $this->structuralViolation($envelope);

        if ($refusalReason !== null) {
            $message = AgentMessage::create([
                'from_agent_id' => $envelope->fromAgentId,
                'to_agent_id' => $envelope->toAgentId,
                'owner_user_id' => $envelope->ownerUserId,
                'conversation_id' => $envelope->conversationId,
                'run_id' => $runId,
                'content' => $contentArray,
                'context' => $contextArray,
                'expected_response' => $envelope->expectedResponse,
                'status' => 'refused',
                'refusal_reason' => $refusalReason,
                'size_bytes' => $sizeBytes,
            ]);

            return new MessageRefusal($message->id, $refusalReason);
        }

        $message = AgentMessage::create([
            'from_agent_id' => $envelope->fromAgentId,
            'to_agent_id' => $envelope->toAgentId,
            'owner_user_id' => $envelope->ownerUserId,
            'conversation_id' => $envelope->conversationId,
            'run_id' => $runId,
            'content' => $contentArray,
            'context' => $contextArray,
            'expected_response' => $envelope->expectedResponse,
            'status' => 'delivered',
            'refusal_reason' => null,
            'size_bytes' => $sizeBytes,
        ]);

        return new MessageDelivered($message->id);
    }

    /**
     * contracts §1 step 1 — checked in order, first violated rule wins.
     * Returns the refusal_reason string, or null when the envelope is
     * structurally well-formed.
     */
    private function structuralViolation(MessageEnvelope $envelope): ?string
    {
        if ($envelope->fromAgentId === null || $envelope->fromAgentId === '') {
            return 'missing_sender';
        }

        if ($envelope->toAgentId === null || $envelope->toAgentId === '') {
            return 'missing_recipient';
        }

        if ($envelope->content === []) {
            return 'missing_content';
        }

        if ($envelope->expectedResponse === null || $envelope->expectedResponse === '') {
            return 'missing_expected_response';
        }

        return null;
    }
}
