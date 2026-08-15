<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Services\AgentMessageService;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageContentPart;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageEnvelope;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageUnavailabilityReport;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 107-agent-message-protocol, Phase 8 (US6, T038), contracts §1 step 3 and
 * quickstart.md's test inventory: AgentMessageService::send()'s
 * recipient-resolution step — checked LAST, after structural validation
 * and the size bound (research.md D6) — reuses AgentQuery::findAgent()
 * UNCHANGED (research.md D8, standing rule 5): an unknown toAgentId, or
 * one owned by a different user, both collapse to reason: 'not_found' (no
 * distinguishable leak, matching findAgent()'s own established contract);
 * an agent found but with is_active === false yields reason: 'inactive'.
 * Either case persists an 'unavailable' row with the full
 * content/context/expected_response stored (the message itself was
 * well-formed and within bounds, only its destination is unreachable) and
 * owner_user_id/conversation_id/run_id stamped identically to a delivered
 * row.
 *
 * Written before AgentMessageService::send() has any recipient-resolution
 * branch (Phase 3-7 only implement delivery, structural validation, and
 * the size bound) — expected to FAIL red until T040's implementation.
 */
class AgentMessageServiceUnavailabilityTest extends TestCase
{
    private User $user;

    private User $otherUser;

    private Agent $agentA;

    private Agent $agentB;

    protected function setUp(): void
    {
        parent::setUp();

        Context::forget('run_id');

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();

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

    private function envelope(
        string $toAgentId,
        ?string $conversationId = null,
    ): MessageEnvelope {
        return new MessageEnvelope(
            fromAgentId: $this->agentA->id,
            toAgentId: $toAgentId,
            content: [new MessageContentPart('a fact')],
            context: [],
            expectedResponse: 'an acknowledgement',
            ownerUserId: $this->user->id,
            conversationId: $conversationId,
        );
    }

    #[Test]
    public function unknown_to_agent_id_is_reported_not_found(): void
    {
        $unknownAgentId = (string) Str::uuid();

        $outcome = $this->service()->send($this->envelope($unknownAgentId));

        $this->assertInstanceOf(MessageUnavailabilityReport::class, $outcome);
        $this->assertSame('unavailable', $outcome->status());
        $this->assertSame('not_found', $outcome->reason);

        $row = DB::table('agent_messages')->where('id', $outcome->agentMessageId)->first();
        $this->assertNotNull($row);
        $this->assertSame('unavailable', $row->status);
        $this->assertSame('not_found', $row->refusal_reason);
        $this->assertNotNull($row->content);
        $this->assertNotNull($row->expected_response);
    }

    #[Test]
    public function to_agent_id_owned_by_a_different_user_is_reported_not_found_with_no_distinguishable_leak(): void
    {
        $foreignAgent = Agent::create([
            'user_id' => $this->otherUser->id,
            'name' => 'Foreign Agent',
            'current_version_id' => null,
        ]);

        $outcome = $this->service()->send($this->envelope($foreignAgent->id));

        $this->assertInstanceOf(MessageUnavailabilityReport::class, $outcome);
        $this->assertSame('unavailable', $outcome->status());
        // No distinguishable leak: a foreign-owned agent reports the exact
        // same reason as a wholly nonexistent one — never 'not_owned'.
        $this->assertSame('not_found', $outcome->reason);
        $this->assertNotSame('not_owned', $outcome->reason);

        $row = DB::table('agent_messages')->where('id', $outcome->agentMessageId)->first();
        $this->assertNotNull($row);
        $this->assertSame('not_found', $row->refusal_reason);
    }

    #[Test]
    public function inactive_agent_is_reported_inactive(): void
    {
        $this->agentB->is_active = false;
        $this->agentB->save();

        $outcome = $this->service()->send($this->envelope($this->agentB->id));

        $this->assertInstanceOf(MessageUnavailabilityReport::class, $outcome);
        $this->assertSame('unavailable', $outcome->status());
        $this->assertSame('inactive', $outcome->reason);

        $row = DB::table('agent_messages')->where('id', $outcome->agentMessageId)->first();
        $this->assertNotNull($row);
        $this->assertSame('unavailable', $row->status);
        $this->assertSame('inactive', $row->refusal_reason);
        $this->assertNotNull($row->content);
        $this->assertNotNull($row->context);
        $this->assertNotNull($row->expected_response);
    }

    #[Test]
    public function an_unavailable_row_stores_full_content_context_and_expected_response(): void
    {
        $unknownAgentId = (string) Str::uuid();

        $envelope = new MessageEnvelope(
            fromAgentId: $this->agentA->id,
            toAgentId: $unknownAgentId,
            content: [new MessageContentPart('a fact'), new MessageContentPart('an external one', external: true)],
            context: [new MessageContentPart('some context')],
            expectedResponse: 'an acknowledgement',
            ownerUserId: $this->user->id,
            conversationId: null,
        );

        $outcome = $this->service()->send($envelope);

        $this->assertInstanceOf(MessageUnavailabilityReport::class, $outcome);

        $row = DB::table('agent_messages')->where('id', $outcome->agentMessageId)->first();
        $this->assertNotNull($row);
        $content = json_decode($row->content, true);
        $context = json_decode($row->context, true);
        $this->assertCount(2, $content);
        $this->assertTrue($content[1]['external']);
        $this->assertCount(1, $context);
        $this->assertSame('an acknowledgement', $row->expected_response);
    }

    #[Test]
    public function an_unavailable_row_stamps_owner_conversation_and_run_id_identically_to_a_delivered_row(): void
    {
        $conversationId = (string) Str::uuid();
        $runId = (string) Str::uuid();

        Context::add('run_id', $runId);

        $unknownAgentId = (string) Str::uuid();

        $outcome = $this->service()->send($this->envelope($unknownAgentId, conversationId: $conversationId));

        $this->assertInstanceOf(MessageUnavailabilityReport::class, $outcome);

        $row = DB::table('agent_messages')->where('id', $outcome->agentMessageId)->first();
        $this->assertNotNull($row);
        $this->assertSame($this->user->id, $row->owner_user_id);
        $this->assertSame($conversationId, $row->conversation_id);
        $this->assertSame($runId, $row->run_id);
        $this->assertSame($this->agentA->id, $row->from_agent_id);
        $this->assertSame($unknownAgentId, $row->to_agent_id);

        Context::forget('run_id');
    }
}
