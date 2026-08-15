<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\DelegationQuery;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * 106-multi-agent-run-view, Phase 3 (US1), tasks.md T007.
 *
 * Unit tests for the not-yet-built `DelegationQuery::arrangementForRun()`
 * (data-model.md §1.1/§2, contracts/arrangement-api.md §1, research.md
 * D1/D5/D9): the full shape of the multi-agent collaboration rooted at a
 * run -- entry-point run, every transitively-reachable delegation, and a
 * RunSummary for every run referenced along the way.
 *
 * Fixtures build real `Delegation` rows directly (mirroring
 * DelegationQueryControllerTest.php's own established
 * makeRun()/makeDelegationRow() precedent for a pure read-path service --
 * no AgentLoopService/scripted-provider scaffolding needed), since this
 * class only ever reads `agent_runs`/`agent_delegations` rows that already
 * exist by the time `arrangementForRun()` is called.
 */
class DelegationQueryArrangementTest extends TestCase
{
    private User $user;
    private User $otherUser;
    private RunTraceRecorder $recorder;
    private DelegationQuery $query;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->recorder = $this->app->make(RunTraceRecorder::class);
        $this->query = $this->app->make(DelegationQuery::class);
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

    /**
     * AgentService::create() -> AgentDefinitionParser resolves the
     * operation catalog via ApiManager/Scramble's Generator -- stubbed here
     * exactly as DelegationQueryControllerTest.php's own established
     * precedent stubs it, since this file's fixtures need real Agent rows
     * (for helper_agent_name resolution) but not a real operation catalog.
     */
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

    // -----------------------------------------------------------------
    // Fixture helpers (mirrors DelegationQueryControllerTest.php)
    // -----------------------------------------------------------------

    private function makeRun(User $owner): string
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $owner->id);
        $this->recorder->closeRun($runId, RunEndState::Completed);

        return $runId;
    }

    private function makeAgent(User $owner, string $name): Agent
    {
        return app(AgentService::class)->create($owner->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    private function makeConversation(User $owner, ?Agent $agent): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $owner->id,
            'title' => 'Already titled',
            'agent_id' => $agent?->id,
            'agent_version_id' => $agent?->current_version_id,
        ]);
    }

    private function makeDelegationRow(User $owner, ?string $parentRunId, array $overrides = []): Delegation
    {
        $parentAgent = $this->makeAgent($owner, 'parent-agent-'.Str::random(8));
        $helperAgent = $this->makeAgent($owner, 'helper-agent-'.Str::random(8));
        $parentConversation = $this->makeConversation($owner, $parentAgent);
        $helperConversation = $this->makeConversation($owner, $helperAgent);

        return Delegation::create(array_merge([
            'parent_conversation_id' => $parentConversation->id,
            'parent_agent_id' => $parentAgent->id,
            'helper_agent_id' => $helperAgent->id,
            'helper_conversation_id' => $helperConversation->id,
            'helper_agent_version_id' => $helperAgent->current_version_id,
            'owner_user_id' => $owner->id,
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

    // -----------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------

    #[Test]
    public function single_hand_off_tree_reconstructs_the_full_shape(): void
    {
        $rootRunId = $this->makeRun($this->user);
        $helperRunId = $this->makeRun($this->user);

        $delegation = $this->makeDelegationRow($this->user, $rootRunId, [
            'helper_run_id' => $helperRunId,
        ]);

        $result = $this->query->arrangementForRun($this->user->id, $rootRunId);

        $this->assertNotNull($result);
        $this->assertSame($rootRunId, $result['root_run_id']);
        $this->assertTrue($result['has_delegations']);
        $this->assertFalse($result['truncated']);
        $this->assertArrayHasKey($rootRunId, $result['runs']);
        $this->assertArrayHasKey($helperRunId, $result['runs']);
        $this->assertCount(1, $result['delegations']);

        $row = $result['delegations'][0];
        $this->assertSame($delegation->id, $row['id']);
        $this->assertSame($rootRunId, $row['parent_run_id']);
        $this->assertSame($helperRunId, $row['helper_run_id']);
        $this->assertSame($delegation->helper_agent_id, $row['helper_agent_id']);
        $this->assertNotNull($row['helper_agent_name']);
        $this->assertSame(1, $row['depth']);
        $this->assertSame('completed', $row['status']);
        $this->assertNull($row['batch_id']);
        $this->assertArrayNotHasKey('task', $row);
        $this->assertArrayNotHasKey('context', $row);
        $this->assertArrayNotHasKey('outcome_summary', $row);
        $this->assertArrayNotHasKey('result_status', $row);
    }

    #[Test]
    public function nested_delegate_to_delegate_tree_is_walked_transitively(): void
    {
        $rootRunId = $this->makeRun($this->user);
        $midRunId = $this->makeRun($this->user);
        $leafRunId = $this->makeRun($this->user);

        $this->makeDelegationRow($this->user, $rootRunId, [
            'helper_run_id' => $midRunId,
            'depth' => 1,
        ]);
        $this->makeDelegationRow($this->user, $midRunId, [
            'helper_run_id' => $leafRunId,
            'depth' => 2,
        ]);

        $result = $this->query->arrangementForRun($this->user->id, $rootRunId);

        $this->assertNotNull($result);
        $this->assertCount(2, $result['delegations']);
        $this->assertArrayHasKey($rootRunId, $result['runs']);
        $this->assertArrayHasKey($midRunId, $result['runs']);
        $this->assertArrayHasKey($leafRunId, $result['runs']);

        $depths = array_column($result['delegations'], 'depth');
        $this->assertEqualsCanonicalizing([1, 2], $depths);
    }

    #[Test]
    public function parallel_batch_tree_groups_correctly_by_batch_id(): void
    {
        $rootRunId = $this->makeRun($this->user);
        $memberOneRunId = $this->makeRun($this->user);
        $memberTwoRunId = $this->makeRun($this->user);
        $batchId = (string) Str::uuid();

        $this->makeDelegationRow($this->user, $rootRunId, [
            'helper_run_id' => $memberOneRunId,
            'batch_id' => $batchId,
            'status' => 'completed',
        ]);
        $this->makeDelegationRow($this->user, $rootRunId, [
            'helper_run_id' => $memberTwoRunId,
            'batch_id' => $batchId,
            'status' => 'completed',
        ]);

        $result = $this->query->arrangementForRun($this->user->id, $rootRunId);

        $this->assertNotNull($result);
        $this->assertCount(2, $result['delegations']);
        $batchIds = array_unique(array_column($result['delegations'], 'batch_id'));
        $this->assertSame([$batchId], array_values($batchIds));
    }

    #[Test]
    public function zero_delegation_run_has_delegations_false_with_no_error(): void
    {
        $rootRunId = $this->makeRun($this->user);

        $result = $this->query->arrangementForRun($this->user->id, $rootRunId);

        $this->assertNotNull($result);
        $this->assertFalse($result['has_delegations']);
        $this->assertFalse($result['truncated']);
        $this->assertSame([], $result['delegations']);
        $this->assertArrayHasKey($rootRunId, $result['runs']);
    }

    #[Test]
    public function denies_run_not_owned_by_caller(): void
    {
        $otherRunId = $this->makeRun($this->otherUser);
        $this->makeDelegationRow($this->otherUser, $otherRunId);

        $result = $this->query->arrangementForRun($this->user->id, $otherRunId);

        $this->assertNull($result);
    }

    #[Test]
    public function denies_absent_run(): void
    {
        $result = $this->query->arrangementForRun($this->user->id, (string) Str::uuid());

        $this->assertNull($result);
    }

    #[Test]
    public function queued_delegation_has_no_runs_entry(): void
    {
        $rootRunId = $this->makeRun($this->user);
        $batchId = (string) Str::uuid();

        // Never admitted -- still queued, no helper_run_id stamped yet
        // (D3: "no helper_run_id yet" is FR-013's "never started").
        $this->makeDelegationRow($this->user, $rootRunId, [
            'helper_run_id' => null,
            'batch_id' => $batchId,
            'status' => 'queued',
        ]);

        $result = $this->query->arrangementForRun($this->user->id, $rootRunId);

        $this->assertNotNull($result);
        $this->assertTrue($result['has_delegations']);
        $this->assertCount(1, $result['delegations']);
        $this->assertNull($result['delegations'][0]['helper_run_id']);

        // Only the root run has a runs[] entry -- the queued member never
        // started, so there is no run to describe.
        $this->assertCount(1, $result['runs']);
        $this->assertArrayHasKey($rootRunId, $result['runs']);
    }

    #[Test]
    public function truncates_beyond_max_nodes(): void
    {
        config(['llm-client.delegation.arrangement.max_nodes' => 2]);

        $rootRunId = $this->makeRun($this->user);
        for ($i = 0; $i < 3; $i++) {
            $helperRunId = $this->makeRun($this->user);
            $this->makeDelegationRow($this->user, $rootRunId, [
                'helper_run_id' => $helperRunId,
            ]);
        }

        $result = $this->query->arrangementForRun($this->user->id, $rootRunId);

        $this->assertNotNull($result);
        $this->assertTrue($result['truncated']);
        $this->assertCount(2, $result['delegations']);
    }

    #[Test]
    public function does_not_truncate_at_or_under_max_nodes(): void
    {
        config(['llm-client.delegation.arrangement.max_nodes' => 2]);

        $rootRunId = $this->makeRun($this->user);
        for ($i = 0; $i < 2; $i++) {
            $helperRunId = $this->makeRun($this->user);
            $this->makeDelegationRow($this->user, $rootRunId, [
                'helper_run_id' => $helperRunId,
            ]);
        }

        $result = $this->query->arrangementForRun($this->user->id, $rootRunId);

        $this->assertNotNull($result);
        $this->assertFalse($result['truncated']);
        $this->assertCount(2, $result['delegations']);
    }
}
