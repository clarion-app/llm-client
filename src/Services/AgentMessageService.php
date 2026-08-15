<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentMessage;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageDelivered;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageEnvelope;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageOutcome;
use Illuminate\Support\Facades\Context;

/**
 * The single write path for `agent_messages` (107-agent-message-protocol,
 * contracts/agent-message-service.md §1, data-model.md §1) — mirrors
 * DelegationService's/ManagerService's/SequenceService's own identical
 * framing as the sole owner of their respective tables.
 *
 * Phase 3 (US1) implements only the delivery path — every envelope this
 * phase's own tests construct is well-formed. Structural validation (US4),
 * the size bound (US5), and recipient-availability resolution (US6) are
 * later phases' own checks, inserted in a fixed order ahead of this
 * delivery step (research.md D6) — none of that branching exists yet.
 *
 * `run_id` stamping (Phase 4, US2) reads the single ambient carrier
 * `Context::get('run_id')` (069's existing mechanism, reused unchanged) at
 * the moment of sending — never a send() parameter, never a second ambient
 * slot for owner_user_id/conversation_id (research.md D3, standing rule 6).
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
}
