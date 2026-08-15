<?php

namespace ClarionApp\LlmClient\Tests\Unit\Events;

use Tests\TestCase;
use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\DelegationUpdated;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\DelegationQuery;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Dedoc\Scramble\Generator;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * 106-multi-agent-run-view, Phase 4 (US2), tasks.md T025 (data-model.md
 * §3.1, research.md D2/D4): broadcastOn() resolves to
 * PrivateChannel('User.{owner_user_id}') for an existing delegation, and to
 * [] once the delegation has since been purged; broadcastWith() matches the
 * shape/values ArrangementResponse.delegations[] (contracts/
 * arrangement-api.md §1) would show for the same row at the same instant --
 * re-resolved from the database at broadcast time, never from a
 * constructor-captured value (research.md D2's "payload freshness").
 *
 * Mirrors RunUpdatedTest.php's own established structure. Written before
 * ClarionApp\LlmClient\Events\DelegationUpdated exists -- every test here is
 * expected to fail with a class-not-found error until T028 creates it.
 */
class DelegationUpdatedTest extends TestCase
{
    private User $user;
    private DelegationQuery $query;
    private RunTraceRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->query = $this->app->make(DelegationQuery::class);
        $this->recorder = $this->app->make(RunTraceRecorder::class);
        $this->seedOperationCatalog();
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_delegations')->delete();
        DB::table('agent_run_actions')->delete();
        DB::table('agent_run_steps')->delete();
        DB::table('agent_runs')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function seedOperationCatalog(): void
    {
        $doc = ['paths' => []];

        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);

        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->andReturn($doc);
        $this->app->instance(Generator::class, $generator);
    }

    private function clearOperationCatalog(): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function makeAgent(string $name): Agent
    {
        return app(AgentService::class)->create($this->user->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    private function makeConversation(?Agent $agent): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Already titled',
            'agent_id' => $agent?->id,
            'agent_version_id' => $agent?->current_version_id,
        ]);
    }

    private function makeRun(): string
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $this->recorder->closeRun($runId, RunEndState::Completed);

        return $runId;
    }

    private function makeDelegationRow(?string $parentRunId, array $overrides = []): Delegation
    {
        $parentAgent = $this->makeAgent('parent-agent-'.Str::random(8));
        $helperAgent = $this->makeAgent('helper-agent-'.Str::random(8));
        $parentConversation = $this->makeConversation($parentAgent);
        $helperConversation = $this->makeConversation($helperAgent);

        return Delegation::create(array_merge([
            'parent_conversation_id' => $parentConversation->id,
            'parent_agent_id' => $parentAgent->id,
            'helper_agent_id' => $helperAgent->id,
            'helper_conversation_id' => $helperConversation->id,
            'helper_agent_version_id' => $helperAgent->current_version_id,
            'owner_user_id' => $this->user->id,
            'task' => 'Extract line items from the attached invoice text.',
            'context' => 'Invoice text: ...',
            'depth' => 1,
            'status' => 'completed',
            'batch_id' => null,
            'parent_run_id' => $parentRunId,
            'parent_action_id' => null,
            'helper_run_id' => null,
            'outcome_summary' => 'Completed normally.',
            'started_at' => now(),
            'completed_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function broadcast_on_resolves_to_the_owners_private_channel_for_an_existing_delegation(): void
    {
        $delegation = $this->makeDelegationRow(null);

        $event = new DelegationUpdated($delegation->id);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-User.' . $this->user->id, (string) $channels[0]);
    }

    #[Test]
    public function broadcast_on_resolves_to_empty_array_when_the_delegation_has_since_been_purged(): void
    {
        $event = new DelegationUpdated((string) Str::uuid());

        $this->assertSame([], $event->broadcastOn());
    }

    #[Test]
    public function broadcast_with_resolves_to_empty_array_when_the_delegation_has_since_been_purged(): void
    {
        $event = new DelegationUpdated((string) Str::uuid());

        $this->assertSame([], $event->broadcastWith());
    }

    #[Test]
    public function broadcast_with_payload_matches_the_arrangement_response_delegations_entry_for_the_same_row(): void
    {
        $rootRunId = $this->makeRun();
        $helperRunId = $this->makeRun();

        $delegation = $this->makeDelegationRow($rootRunId, [
            'helper_run_id' => $helperRunId,
            'status' => 'completed',
        ]);

        $arrangement = $this->query->arrangementForRun($this->user->id, $rootRunId);
        $this->assertNotNull($arrangement);
        $this->assertCount(1, $arrangement['delegations']);
        $arrangementRow = $arrangement['delegations'][0];

        $event = new DelegationUpdated($delegation->id);
        $payload = $event->broadcastWith();

        // DelegationUpdated's payload is a NARROWER shape than
        // ArrangementDelegation (no helper_agent_name, data-model.md §3.1)
        // -- every key it does carry must agree exactly with what the same
        // instant's arrangement fetch would show.
        foreach ($payload as $key => $value) {
            $this->assertArrayHasKey($key, $arrangementRow, "DelegationUpdated payload key \"{$key}\" must also appear in ArrangementResponse.delegations[]");
            $this->assertSame($value, $arrangementRow[$key], "DelegationUpdated payload key \"{$key}\" must match the same instant's arrangement fetch");
        }

        $this->assertSame($delegation->id, $payload['id']);
        $this->assertSame($rootRunId, $payload['parent_run_id']);
        $this->assertSame($helperRunId, $payload['helper_run_id']);
        $this->assertSame('completed', $payload['status']);
    }

    #[Test]
    public function broadcast_with_reflects_the_current_terminal_state_at_broadcast_time_not_a_stale_constructor_captured_value(): void
    {
        $delegation = $this->makeDelegationRow(null, ['status' => 'in_progress', 'completed_at' => null]);

        $event = new DelegationUpdated($delegation->id);

        $delegation->status = 'failed';
        $delegation->completed_at = now();
        $delegation->save();

        $payload = $event->broadcastWith();

        $this->assertSame('failed', $payload['status']);
        $this->assertNotNull($payload['completed_at']);
    }
}
