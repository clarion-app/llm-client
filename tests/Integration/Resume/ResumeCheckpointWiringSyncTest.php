<?php

namespace Tests\Integration\Resume;

use ClarionApp\LlmClient\Jobs\RunEvalCaseJob;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\EvalCaseExecutor;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\GeneratorConfig;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\TaskBudgetHelperJourneys;
use Tests\Support\EffectLedger;
use Tests\Support\ScriptedProvider;

/**
 * The synchronous path writes the checkpoint ledger (C0/C1/C2/C5).
 *
 * A scripted three-step run (read, write, reply) leaves the run
 * checkpointed (version 1, sync, root) with every step committed and its
 * message id, and the run's lease cleared at close. With resume disabled the
 * run keeps the legacy shape (every new column null). And every caller sets
 * its own resume_driver, so no later sweep can mistake a helper for a root.
 */
class ResumeCheckpointWiringSyncTest extends AssembledSystemTestCase
{
    use TaskBudgetHelperJourneys;

    private const USAGE = [10, 10];

    private EffectLedger $effectLedger;

    /** Bind the executor with a fixed token and seed the two ledger operations. */
    private function setupLedgerJourney(): void
    {
        $this->effectLedger = new EffectLedger();
        $this->app->singleton(
            McpToolExecutor::class,
            fn () => new McpToolExecutor($this->app->make(McpToolRegistry::class), null, fn ($user) => 'test-token-'.$user->id),
        );
        // Agent definitions are parsed against the live operation catalog,
        // which the real Scramble generator cannot build in the harness;
        // stand in for it the way the helper journeys do.
        $this->app->instance(Generator::class, new class extends Generator
        {
            public function __construct()
            {
            }

            public function __invoke(?GeneratorConfig $config = null): array
            {
                return ['paths' => []];
            }
        });
        (new ScriptedProvider($this->transport, $this->stream))->seedLedgerOperations($this->effectLedger);
    }

    /** The fixture's conversation, ready for a synchronous run. */
    private function syncConversation(): Conversation
    {
        return $this->fixture()->build()->conversation;
    }

    /**
     * A three-step script: two searches, then a reply. The tool calls are
     * deliberately `search_operations` (a built-in, non-execute_operation
     * tool) rather than the ledger's execute_operation calls: a run whose
     * every tool call is a successful execute_operation ends on the loop's
     * all-successful shortcut (AgentLoopService, "no need for a summary
     * response"), so an all-execute_operation script would stop after one
     * iteration and leave the later steps unconsumed.
     */
    private function scriptThreeSteps(): void
    {
        $this->script()
            ->toolRequest('search_operations', ['query' => 'first'])
            ->usage(...self::USAGE)
            ->toolRequest('search_operations', ['query' => 'second'])
            ->usage(...self::USAGE)
            ->finalAnswer('Done.')
            ->usage(...self::USAGE);
    }

    /** @return object the run row for the conversation's single run */
    private function runRowOf(Conversation $conversation): object
    {
        $row = DB::table('agent_runs')->where('conversation_id', $conversation->id)->orderByDesc('started_at')->first();
        $this->assertNotNull($row, 'the run exists');

        return $row;
    }

    // ---------------------------------------------------------------
    // Case 1 — a checkpointed three-step synchronous run
    // ---------------------------------------------------------------

    #[Test]
    public function a_three_step_synchronous_run_is_checkpointed_and_closes_clean(): void
    {
        $this->scenario = 'resume_wiring_sync_three_step';
        $this->setupLedgerJourney();
        $conversation = $this->syncConversation();
        $this->scriptThreeSteps();

        $result = $this->app->make(AgentLoopService::class)->run($conversation, 'Read, then write, then reply.');

        $this->assertSame('completed', $result['status']);

        $run = $this->runRowOf($conversation);
        $this->assertSame(1, (int) $run->checkpoint_version, 'the run is checkpointed');
        $this->assertSame('sync', $run->resume_mode);
        $this->assertSame('root', $run->resume_driver, 'a plain interactive run is driven by root');
        $this->assertNotNull($run->resume_options, 'the whitelisted options are stored');

        // Every step is committed, carries its loop iteration, and names the
        // assistant message that holds its tool_results.
        $steps = DB::table('agent_run_steps')->where('run_id', $run->id)->orderBy('position')->get();
        $this->assertCount(3, $steps, 'three steps, one per loop iteration');
        foreach ($steps as $i => $step) {
            $this->assertSame($i + 1, (int) $step->iteration, "step {$i} carries its loop iteration");
            $this->assertSame('committed', $step->checkpoint_state, "step {$i} is committed");
            $this->assertNotNull($step->message_id, "step {$i} names its assistant message");
            $this->assertNotNull($step->checkpoint, "step {$i} has a checkpoint");
        }

        // The run closed: its lease is cleared.
        $this->assertNull($run->lease_holder, 'the lease is cleared at close');
        $this->assertNull($run->dispatch_id, 'the dispatch id is cleared at close');
        $this->assertNull($run->resume_state, 'the resume state is cleared at close');
    }

    // ---------------------------------------------------------------
    // Case 2 — resume disabled keeps the legacy shape
    // ---------------------------------------------------------------

    #[Test]
    public function with_resume_disabled_every_new_column_stays_null(): void
    {
        $this->scenario = 'resume_wiring_sync_disabled';
        config(['llm-client.resume.enabled' => false]);
        $this->setupLedgerJourney();
        $conversation = $this->syncConversation();
        $this->scriptThreeSteps();

        $result = $this->app->make(AgentLoopService::class)->run($conversation, 'Read, then write, then reply.');

        $this->assertSame('completed', $result['status']);

        $run = $this->runRowOf($conversation);
        $this->assertNull($run->checkpoint_version, 'no checkpoint_version');
        $this->assertNull($run->resume_mode, 'no resume_mode');
        $this->assertNull($run->resume_driver, 'no resume_driver');
        $this->assertNull($run->resume_state, 'no resume_state');
        $this->assertNull($run->dispatch_id, 'no dispatch_id');
        $this->assertNull($run->lease_holder, 'no lease_holder');

        $steps = DB::table('agent_run_steps')->where('run_id', $run->id)->get();
        foreach ($steps as $step) {
            $this->assertNull($step->checkpoint_state, 'no step checkpoint_state');
            $this->assertNull($step->checkpoint, 'no step checkpoint');
            $this->assertNull($step->iteration, 'no step iteration');
        }
    }

    // ---------------------------------------------------------------
    // Case 3 — driver attribution
    // ---------------------------------------------------------------

    #[Test]
    public function an_unattended_run_is_driven_by_root(): void
    {
        $this->scenario = 'resume_wiring_sync_unattended';
        $this->setupLedgerJourney();
        $conversation = $this->syncConversation();

        // An unattended run refuses to start without a resolvable agent
        // definition bound to the conversation, so bind one first.
        $agent = $this->agent('root');
        $conversation->update(['agent_id' => $agent->id, 'agent_version_id' => $agent->current_version_id]);
        $conversation = $conversation->fresh();

        $this->scriptThreeSteps();

        $this->app->make(AgentLoopService::class)->run($conversation, 'Read, then write, then reply.', ['unattended' => true]);

        $this->assertSame('root', $this->runRowOf($conversation)->resume_driver, 'an unattended run is still driven by root');
    }

    #[Test]
    public function an_eval_executor_run_is_driven_by_none(): void
    {
        $this->scenario = 'resume_wiring_sync_eval';
        $this->setUpTaskBudgetJourney();
        config(['llm-client.agent_loop.max_iterations' => 2]);
        $fixture = $this->fixture()->build();
        config(['llm-client.cost.operator_user_ids' => [$fixture->user->id]]);
        RoleAssignment::create([
            'role' => 'inference', 'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $fixture->server->id, 'model' => 'gpt-4',
        ]);
        $api = '/api/clarion-app/llm-client/agent-eval-suites';
        $suite = $this->actingAs($fixture->user, 'api')->postJson($api, ['name' => 'Resume suite', 'agent_identifier' => 'note-keeper'])->assertStatus(200)->json();
        $this->actingAs($fixture->user, 'api')->postJson("{$api}/{$suite['id']}/cases", [
            'given' => 'Store a note.',
            'expected_behavior' => 'Stores a note.',
            'expectations' => [['kind' => 'text_match', 'expected_text' => 'note']],
        ])->assertStatus(200);
        Bus::fake([RunEvalCaseJob::class]);
        $this->actingAs($fixture->user, 'api')->postJson("{$api}/{$suite['id']}/runs")->assertStatus(201);
        $this->scriptSteps(1, 2);

        foreach (Bus::dispatched(RunEvalCaseJob::class) as $job) {
            $job->handle(app(EvalCaseExecutor::class));
        }

        $conversation = Conversation::where('title', 'like', 'eval-run-case:%')->firstOrFail();
        $this->assertSame('none', $this->runRowOf($conversation)->resume_driver, 'an eval executor run is not resumable');
        $this->tearDownTaskBudgetJourney();
    }

    #[Test]
    public function a_solo_delegations_helper_run_is_driven_by_parent(): void
    {
        $this->scenario = 'resume_wiring_sync_solo_delegation';
        $this->setUpHelperJourney();
        $this->priceModel();
        $this->fixtureWithModel();
        $root = $this->agent('root');
        $helper = $this->agent('helper');
        $this->assignHelper($root, $helper);

        $conversation = $this->rootConversation('sync', $root);
        // The root delegates; the helper works and answers; the root finishes.
        $this->script()->toolRequests([$this->delegateCall('call_to_helper', $helper, 'Research the topic.')])->usage(...self::USAGE);
        $this->scriptHelperAnswer('Researched.', self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);

        $this->send('sync', $conversation, 'Research the topic.')->assertStatus(200);

        $helperConversation = $this->helperConversationOf($helper);
        $this->assertSame('parent', $this->runRowOf($helperConversation)->resume_driver, 'a solo delegation helper is driven by its parent');
        $this->tearDownTaskBudgetJourney();
    }

    #[Test]
    public function a_batch_members_helper_run_is_driven_by_batch_member(): void
    {
        $this->scenario = 'resume_wiring_sync_batch_member';
        $this->setUpHelperJourney();
        $this->priceModel();
        $this->fixtureWithModel();
        $root = $this->agent('root');
        $a = $this->agent('a');
        $b = $this->agent('b');
        $this->assignHelper($root, $a);
        $this->assignHelper($root, $b);

        $conversation = $this->rootConversation('sync', $root);
        // The root delegates to two at once; they run one after the other;
        // the root finishes.
        $this->script()->toolRequests([
            $this->delegateCall('call_to_a', $a, 'Part one.'),
            $this->delegateCall('call_to_b', $b, 'Part two.'),
        ])->usage(...self::USAGE);
        $this->scriptHelperAnswer('Part one done.', self::USAGE);
        $this->scriptHelperAnswer('Part two done.', self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);

        $this->send('sync', $conversation, 'Do both parts.')->assertStatus(200);

        $helperConversation = $this->helperConversationOf($a);
        $this->assertSame('batch_member', $this->runRowOf($helperConversation)->resume_driver, 'a batch member helper is driven as a batch member');
        $this->tearDownTaskBudgetJourney();
    }
}
