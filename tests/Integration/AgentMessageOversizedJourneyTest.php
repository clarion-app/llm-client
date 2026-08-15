<?php

namespace Tests\Integration;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentMessage;
use ClarionApp\LlmClient\Services\AgentMessageService;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageContentPart;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageEnvelope;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageRejection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 107-agent-message-protocol, Phase 7 (US5, T034), quickstart.md §3:
 * US5 end-to-end. An oversized send() is rejected, reported to the caller
 * synchronously (not queued, not silently dropped), and the persisted row
 * proves no content byte was ever stored.
 *
 * Written before AgentMessageService::send() has any size-bound branch —
 * expected to FAIL red until T035's implementation.
 */
class AgentMessageOversizedJourneyTest extends TestCase
{
    private User $user;

    private Agent $agentA;

    private Agent $agentB;

    protected function setUp(): void
    {
        parent::setUp();

        Context::forget('run_id');

        $this->user = User::factory()->create();

        $this->agentA = Agent::create([
            'user_id' => $this->user->id,
            'name' => 'Agent A',
            'current_version_id' => null,
        ]);

        $this->agentB = Agent::create([
            'user_id' => $this->user->id,
            'name' => 'Agent B',
            'current_version_id' => null,
        ]);
    }

    protected function tearDown(): void
    {
        Context::forget('run_id');

        DB::table('agent_messages')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function service(): AgentMessageService
    {
        return app(AgentMessageService::class);
    }

    public function test_an_oversized_send_is_rejected_synchronously_with_no_content_ever_stored(): void
    {
        $maxBytes = (int) config('llm-client.messaging.max_message_bytes', 65536);

        $oversizedText = str_repeat('q', $maxBytes + 4096);

        $envelope = new MessageEnvelope(
            fromAgentId: $this->agentA->id,
            toAgentId: $this->agentB->id,
            content: [new MessageContentPart($oversizedText)],
            context: [],
            expectedResponse: 'an acknowledgement',
            ownerUserId: $this->user->id,
            conversationId: null,
        );

        // send() is a plain synchronous method call — no queue, no job
        // dispatch — so the outcome is available to the caller immediately,
        // in the same request/process, not eventually via some later
        // notification.
        $outcome = $this->service()->send($envelope);

        $this->assertInstanceOf(MessageRejection::class, $outcome);
        $this->assertSame('rejected_oversized', $outcome->status());
        $this->assertGreaterThan($maxBytes, $outcome->sizeBytes);

        $row = AgentMessage::find($outcome->agentMessageId);
        $this->assertNotNull($row);
        $this->assertSame('rejected_oversized', $row->status);

        // No content byte was ever stored — not the full payload, not a
        // truncated fragment of it. The oversized text itself must not
        // appear anywhere in the persisted row.
        $this->assertNull($row->content);
        $this->assertNull($row->context);
        $this->assertNull($row->expected_response);

        $rawRow = DB::table('agent_messages')->where('id', $outcome->agentMessageId)->first();
        $this->assertStringNotContainsString('q', (string) $rawRow->content);

        // Still fully attributable, exactly like a delivered row.
        $this->assertSame($this->user->id, $row->owner_user_id);
        $this->assertSame($this->agentA->id, $row->from_agent_id);
        $this->assertSame($this->agentB->id, $row->to_agent_id);
    }
}
