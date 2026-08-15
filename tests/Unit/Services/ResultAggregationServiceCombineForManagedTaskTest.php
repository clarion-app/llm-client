<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Models\ManagedTaskPart;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\ContentSanitizer;
use ClarionApp\LlmClient\Services\ResultAggregationService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 103-manager-agent, Phase 5 (US3), tasks.md T037.
 *
 * Unit tests for the not-yet-built `ResultAggregationService::
 * combineForManagedTask()` (data-model.md §7, Grounding note item 14):
 * reads every `accepted` `ManagedTaskPart`'s `accepted_delegation_id` row
 * (NOT a `parent_run_id`-scoped set -- a managed task's parts span many
 * different manager-turn runs), unions their `result_output` maps, flags
 * a conflicting key with both differing values and their own part/helper
 * provenance -- mirroring `combineForRun()`'s own conflict shape exactly
 * (mutation-checklist row 9 depends on `finalize()` actually calling this).
 *
 * Written before `combineForManagedTask()` exists -- every test below is
 * expected to FAIL red (method not found) until T041 adds it.
 */
class ResultAggregationServiceCombineForManagedTaskTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog();
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_delegations')->delete();
        DB::table('managed_task_parts')->delete();
        DB::table('managed_tasks')->delete();
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

    private function service(): ResultAggregationService
    {
        return new ResultAggregationService(new ContentSanitizer());
    }

    private function makeManagedTask(): ManagedTask
    {
        return ManagedTask::create([
            'conversation_id' => (string) Str::uuid(),
            'owner_user_id' => $this->user->id,
            'manager_agent_id' => null,
            'original_request' => 'A multi-part task.',
            'status' => 'in_progress',
            'round_ceiling' => 30,
            'rounds_used' => 0,
            'max_seconds' => 1800,
            'last_progress_at' => now(),
            'started_at' => now(),
        ]);
    }

    /**
     * An accepted ManagedTaskPart, its own Delegation row (helper_agent_id
     * pointing to a real Agent for name resolution), and accepted_delegation_id
     * stamped on the part, mirroring how ManagerService::acceptPart() itself
     * leaves a part.
     */
    private function makeAcceptedPart(ManagedTask $task, int $sequence, Agent $helper, array $delegationOverrides = []): ManagedTaskPart
    {
        $delegation = Delegation::create(array_merge([
            'parent_conversation_id' => $task->conversation_id,
            'helper_agent_id' => $helper->id,
            'helper_conversation_id' => (string) Str::uuid(),
            'owner_user_id' => $this->user->id,
            'task' => 'Do the part.',
            'depth' => 1,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
            'result_status' => 'success',
            'result_summary' => 'Done.',
            'result_undone' => '',
            'managed_task_id' => $task->id,
        ], $delegationOverrides));

        $part = ManagedTaskPart::create([
            'managed_task_id' => $task->id,
            'sequence' => $sequence,
            'description' => "Part {$sequence}.",
            'state' => 'accepted',
            'current_delegation_id' => $delegation->id,
            'accepted_delegation_id' => $delegation->id,
            'accepted_summary' => $delegation->result_summary,
            'assignment_count' => 1,
        ]);
        $delegation->part_id = $part->id;
        $delegation->save();

        return $part;
    }

    // =================================================================
    // Fewer than two qualifying accepted parts -> null
    // =================================================================

    #[Test]
    public function returns_null_when_no_parts_are_accepted(): void
    {
        $task = $this->makeManagedTask();
        ManagedTaskPart::create([
            'managed_task_id' => $task->id,
            'sequence' => 1,
            'description' => 'Not yet assigned.',
            'state' => 'not_yet_assigned',
            'assignment_count' => 0,
        ]);

        $this->assertNull($this->service()->combineForManagedTask($task->id));
    }

    #[Test]
    public function returns_null_when_exactly_one_part_is_accepted(): void
    {
        $task = $this->makeManagedTask();
        $helper = $this->makeAgent('helper-solo');
        $this->makeAcceptedPart($task, 1, $helper, ['result_output' => json_encode(['a' => 1])]);

        $this->assertNull($this->service()->combineForManagedTask($task->id));
    }

    // =================================================================
    // Two or more accepted parts -> combined view
    // =================================================================

    #[Test]
    public function unions_result_output_across_every_accepted_parts_own_delegation(): void
    {
        $task = $this->makeManagedTask();
        $extractor = $this->makeAgent('Invoice Line-Item Extractor');
        $normalizer = $this->makeAgent('Currency Normalizer');

        $partA = $this->makeAcceptedPart($task, 1, $extractor, [
            'result_output' => json_encode(['line_items' => ['Widget A']]),
        ]);
        $partB = $this->makeAcceptedPart($task, 2, $normalizer, [
            'result_output' => json_encode(['currency' => 'USD']),
        ]);

        $combined = $this->service()->combineForManagedTask($task->id);

        $this->assertNotNull($combined);
        $combinedOutput = $combined['combined_output'];
        ksort($combinedOutput);
        $this->assertSame(
            ['currency' => 'USD', 'line_items' => ['Widget A']],
            $combinedOutput,
        );
        $this->assertSame([], $combined['conflicts']);
        $this->assertCount(2, $combined['contributors']);
    }

    #[Test]
    public function a_part_still_out_for_correction_never_contributes_to_the_combined_view(): void
    {
        $task = $this->makeManagedTask();
        $extractor = $this->makeAgent('helper-accepted');
        $this->makeAcceptedPart($task, 1, $extractor, [
            'result_output' => json_encode(['a' => 1]),
        ]);

        $unaccepted = ManagedTaskPart::create([
            'managed_task_id' => $task->id,
            'sequence' => 2,
            'description' => 'Still outstanding.',
            'state' => 'out_for_correction',
            'assignment_count' => 1,
        ]);

        $this->assertNull(
            $this->service()->combineForManagedTask($task->id),
            'only one accepted part exists -- an outstanding part must never count toward the qualifying set',
        );
        $this->assertSame('out_for_correction', $unaccepted->state);
    }

    #[Test]
    public function a_conflicting_key_is_excluded_from_combined_output_and_recorded_with_provenance(): void
    {
        $task = $this->makeManagedTask();
        $extractor = $this->makeAgent('Invoice Line-Item Extractor');
        $normalizer = $this->makeAgent('Currency Normalizer');

        $partA = $this->makeAcceptedPart($task, 1, $extractor, [
            'result_output' => json_encode(['total' => '1042.50']),
        ]);
        $partB = $this->makeAcceptedPart($task, 2, $normalizer, [
            'result_output' => json_encode(['total' => '1024.50']),
        ]);

        $combined = $this->service()->combineForManagedTask($task->id);

        $this->assertArrayNotHasKey('total', $combined['combined_output']);
        $this->assertCount(1, $combined['conflicts']);

        $conflict = $combined['conflicts'][0];
        $this->assertSame('total', $conflict['key']);
        $this->assertCount(2, $conflict['values']);

        $byHelper = collect($conflict['values'])->keyBy('helper_agent_id');
        $this->assertSame('1042.50', $byHelper[$extractor->id]['value']);
        $this->assertSame('Invoice Line-Item Extractor', $byHelper[$extractor->id]['helper_agent_name']);
        $this->assertSame('1024.50', $byHelper[$normalizer->id]['value']);
        $this->assertSame('Currency Normalizer', $byHelper[$normalizer->id]['helper_agent_name']);
    }

    #[Test]
    public function a_key_present_with_an_identical_value_across_accepted_parts_is_not_a_conflict(): void
    {
        $task = $this->makeManagedTask();
        $extractor = $this->makeAgent('helper-identical-one');
        $normalizer = $this->makeAgent('helper-identical-two');

        $this->makeAcceptedPart($task, 1, $extractor, [
            'result_output' => json_encode(['currency' => 'USD', 'line_items' => ['A']]),
        ]);
        $this->makeAcceptedPart($task, 2, $normalizer, [
            'result_output' => json_encode(['currency' => 'USD']),
        ]);

        $combined = $this->service()->combineForManagedTask($task->id);

        $this->assertSame(
            ['currency' => 'USD', 'line_items' => ['A']],
            $combined['combined_output'],
        );
        $this->assertSame([], $combined['conflicts']);
    }

    #[Test]
    public function combining_a_different_managed_task_never_mixes_in_another_tasks_accepted_parts(): void
    {
        $taskOne = $this->makeManagedTask();
        $taskTwo = $this->makeManagedTask();
        $helper = $this->makeAgent('helper-cross-task');

        $this->makeAcceptedPart($taskOne, 1, $helper, ['result_output' => json_encode(['a' => 1])]);
        $this->makeAcceptedPart($taskOne, 2, $helper, ['result_output' => json_encode(['b' => 2])]);
        $this->makeAcceptedPart($taskTwo, 1, $helper, ['result_output' => json_encode(['c' => 3])]);

        $combined = $this->service()->combineForManagedTask($taskOne->id);

        $this->assertNotNull($combined);
        $combinedOutput = $combined['combined_output'];
        ksort($combinedOutput);
        $this->assertSame(['a' => 1, 'b' => 2], $combinedOutput, 'task two\'s own accepted part must never leak into task one\'s combined view');
    }
}
