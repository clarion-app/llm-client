<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Services\AgentMessageService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageContentPart;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageEnvelope;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 107-agent-message-protocol, Phase 4 (US2, T018), quickstart.md's test
 * inventory: owner_user_id/conversation_id are stamped verbatim from the
 * envelope on a delivered row; run_id is stamped from Context::get('run_id')
 * (069's existing ambient carrier, standing rule 6) when a run is open
 * (opened via RunTraceRecorder::openRun() here, matching 069's own
 * established test pattern — see RunTraceRecorderContextTest.php) and is
 * null when no run is open.
 *
 * Written before AgentMessageService::send() reads Context::get('run_id') —
 * expected to FAIL red (run_id assertion) until T021's implementation.
 */
class AgentMessageServiceIdentifierStampingTest extends TestCase
{
    private User $user;

    private Agent $agentA;

    private Agent $agentB;

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

    private function envelope(?string $conversationId = null): MessageEnvelope
    {
        return new MessageEnvelope(
            fromAgentId: $this->agentA->id,
            toAgentId: $this->agentB->id,
            content: [new MessageContentPart('a fact')],
            context: [],
            expectedResponse: 'an acknowledgement',
            ownerUserId: $this->user->id,
            conversationId: $conversationId,
        );
    }

    #[Test]
    public function owner_user_id_and_conversation_id_are_stamped_verbatim_on_a_delivered_row(): void
    {
        $conversationId = (string) Str::uuid();

        $outcome = $this->service()->send($this->envelope($conversationId));

        $row = DB::table('agent_messages')->where('id', $outcome->agentMessageId)->first();

        $this->assertNotNull($row);
        $this->assertSame('delivered', $row->status);
        $this->assertSame($this->user->id, $row->owner_user_id);
        $this->assertSame($conversationId, $row->conversation_id);
    }

    #[Test]
    public function run_id_is_stamped_from_context_when_a_run_is_open(): void
    {
        $runId = $this->recorder()->openRun(RunKind::Interactive, $this->user->id);

        $this->assertNotNull($runId);
        $this->assertSame($runId, Context::get('run_id'));

        $outcome = $this->service()->send($this->envelope());

        $row = DB::table('agent_messages')->where('id', $outcome->agentMessageId)->first();

        $this->assertNotNull($row);
        $this->assertSame($runId, $row->run_id);
    }

    #[Test]
    public function run_id_is_null_when_no_run_is_open(): void
    {
        $this->assertNull(Context::get('run_id'));

        $outcome = $this->service()->send($this->envelope());

        $row = DB::table('agent_messages')->where('id', $outcome->agentMessageId)->first();

        $this->assertNotNull($row);
        $this->assertNull($row->run_id);
    }
}
