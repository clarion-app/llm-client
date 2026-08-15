<?php

namespace Tests\Integration;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentMessage;
use ClarionApp\LlmClient\Services\AgentMessageService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageContentPart;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageEnvelope;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 107-agent-message-protocol, Phase 4 (US2, T020), quickstart.md §3:
 * US2/FR-008/FR-009/SC-002 end-to-end. A chain of two send() calls made
 * while one RunTraceRecorder-opened run is active (simulating two hops of a
 * hypothetical future arrangement — standing rule 4, no real caller exists
 * yet) both carry the same run_id and the same owner_user_id, each carrying
 * its own distinct, correctly-attributed from_agent_id; a message sent with
 * no run open carries run_id = null and is still fully traceable via
 * owner_user_id/conversation_id alone.
 *
 * Written before AgentMessageService::send() reads Context::get('run_id') —
 * expected to FAIL red until T021's implementation.
 */
class AgentMessageIdentifierPropagationJourneyTest extends TestCase
{
    private User $user;

    private Agent $agentA;

    private Agent $agentB;

    private Agent $agentC;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.run_trace.enabled', true);
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

        $this->agentC = Agent::create([
            'user_id' => $this->user->id,
            'name' => 'Agent C',
            'current_version_id' => null,
        ]);
    }

    protected function tearDown(): void
    {
        Context::forget('run_id');

        DB::table('agent_messages')->delete();

        foreach (['agent_run_messages', 'agent_run_steps', 'agent_runs'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function service(): AgentMessageService
    {
        return app(AgentMessageService::class);
    }

    private function recorder(): RunTraceRecorder
    {
        return app(RunTraceRecorder::class);
    }

    public function test_a_two_hop_chain_within_one_run_shares_run_id_and_owner_but_each_hop_has_its_own_sender(): void
    {
        $recorder = $this->recorder();
        $runId = $recorder->openRun(RunKind::Interactive, $this->user->id);
        $this->assertNotNull($runId, 'Precondition: the run must actually open for this test to prove anything');

        // Hop 1: A -> B
        $outcome1 = $this->service()->send(new MessageEnvelope(
            fromAgentId: $this->agentA->id,
            toAgentId: $this->agentB->id,
            content: [new MessageContentPart('hop one payload')],
            context: [],
            expectedResponse: 'relay onward',
            ownerUserId: $this->user->id,
            conversationId: null,
        ));

        // Hop 2: B -> C, still inside the same open run.
        $outcome2 = $this->service()->send(new MessageEnvelope(
            fromAgentId: $this->agentB->id,
            toAgentId: $this->agentC->id,
            content: [new MessageContentPart('hop two payload')],
            context: [],
            expectedResponse: 'final ack',
            ownerUserId: $this->user->id,
            conversationId: null,
        ));

        $recorder->closeRun($runId, RunEndState::Completed);

        $row1 = AgentMessage::find($outcome1->agentMessageId);
        $row2 = AgentMessage::find($outcome2->agentMessageId);

        $this->assertNotNull($row1);
        $this->assertNotNull($row2);

        // Same run_id, same owner_user_id, across both hops.
        $this->assertSame($runId, $row1->run_id);
        $this->assertSame($runId, $row2->run_id);
        $this->assertSame($this->user->id, $row1->owner_user_id);
        $this->assertSame($this->user->id, $row2->owner_user_id);

        // Each hop, examined in isolation, resolves to its own distinct,
        // correctly-attributed sender.
        $this->assertSame($this->agentA->id, $row1->from_agent_id);
        $this->assertSame($this->agentB->id, $row1->to_agent_id);
        $this->assertSame($this->agentB->id, $row2->from_agent_id);
        $this->assertSame($this->agentC->id, $row2->to_agent_id);
        $this->assertNotSame($row1->from_agent_id, $row2->from_agent_id);
    }

    public function test_a_message_sent_with_no_run_open_carries_null_run_id_and_is_still_traceable(): void
    {
        $this->assertNull(Context::get('run_id'), 'Precondition: no run open');

        $conversationId = (string) Str::uuid();

        $outcome = $this->service()->send(new MessageEnvelope(
            fromAgentId: $this->agentA->id,
            toAgentId: $this->agentB->id,
            content: [new MessageContentPart('standalone message')],
            context: [],
            expectedResponse: 'ack',
            ownerUserId: $this->user->id,
            conversationId: $conversationId,
        ));

        $row = AgentMessage::find($outcome->agentMessageId);

        $this->assertNotNull($row);
        $this->assertNull($row->run_id);

        // Still fully traceable via owner_user_id/conversation_id alone.
        $traced = AgentMessage::where('owner_user_id', $this->user->id)
            ->where('conversation_id', $conversationId)
            ->first();
        $this->assertNotNull($traced);
        $this->assertSame($row->id, $traced->id);
    }
}
