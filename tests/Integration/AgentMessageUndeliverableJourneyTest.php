<?php

namespace Tests\Integration;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentMessage;
use ClarionApp\LlmClient\Services\AgentMessageService;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageContentPart;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageEnvelope;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageUnavailabilityReport;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 107-agent-message-protocol, Phase 8 (US6, T039), quickstart.md §3: a
 * message addressed to an agent that is subsequently DEACTIVATED (not
 * just never-created) is reported unavailable, not silently dropped or
 * delivered against stale/cached agent state. Proves the recipient
 * resolution step reads the agent's live is_active flag at send() time —
 * a second send() to the very same agent id that delivered cleanly a
 * moment earlier flips to 'unavailable'/'inactive' once that agent is
 * deactivated in between, with no caching layer anywhere in
 * AgentMessageService papering over the change.
 *
 * Written before AgentMessageService::send() has any recipient-resolution
 * branch — expected to FAIL red until T040's implementation.
 */
class AgentMessageUndeliverableJourneyTest extends TestCase
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

    private function envelope(): MessageEnvelope
    {
        return new MessageEnvelope(
            fromAgentId: $this->agentA->id,
            toAgentId: $this->agentB->id,
            content: [new MessageContentPart('a fact')],
            context: [],
            expectedResponse: 'an acknowledgement',
            ownerUserId: $this->user->id,
            conversationId: null,
        );
    }

    public function test_a_message_to_a_subsequently_deactivated_agent_is_reported_unavailable_not_dropped(): void
    {
        // First: agent B is active. A send() delivers cleanly against it —
        // proving there was nothing wrong with the destination up front.
        $firstOutcome = $this->service()->send($this->envelope());
        $this->assertSame('delivered', $firstOutcome->status());

        // Then: agent B is deactivated — not deleted, still exists, still
        // findable by id, just no longer active.
        $this->agentB->is_active = false;
        $this->agentB->save();

        // A second, otherwise-identical send() to the exact same
        // toAgentId must now be reported unavailable, not silently
        // delivered against stale/cached agent state and not dropped.
        $secondOutcome = $this->service()->send($this->envelope());

        $this->assertInstanceOf(MessageUnavailabilityReport::class, $secondOutcome);
        $this->assertSame('unavailable', $secondOutcome->status());
        $this->assertSame('inactive', $secondOutcome->reason);

        $row = AgentMessage::find($secondOutcome->agentMessageId);
        $this->assertNotNull($row);
        $this->assertSame('unavailable', $row->status);
        $this->assertSame('inactive', $row->refusal_reason);
        // The message itself was well-formed and within bounds — only the
        // destination is unreachable, so its content is still fully
        // recorded, not dropped and not nulled out.
        $this->assertNotNull($row->content);
        $this->assertNotNull($row->expected_response);

        // The earlier, genuinely-delivered row is unaffected by the later
        // deactivation — the report is reported explicitly per attempt,
        // never retroactively.
        $firstRow = AgentMessage::find($firstOutcome->agentMessageId);
        $this->assertSame('delivered', $firstRow->status);
    }
}
