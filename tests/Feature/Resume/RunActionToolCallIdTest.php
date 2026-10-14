<?php

namespace Tests\Feature\Resume;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\GeneratorConfig;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\TaskBudgetHelperJourneys;
use Tests\Support\EffectLedger;
use Tests\Support\ScriptedProvider;

/**
 * A `tool_invocation` action opened by a funnelled site carries the
 * provider's call id in `tool_call_id` (checkpoint-ledger contract §5), so
 * the action and the checkpoint's call entry can be joined. A call id too
 * long for the column is cut to 255 characters, and a `delegation` action
 * carries the call id too.
 */
class RunActionToolCallIdTest extends AssembledSystemTestCase
{
    use TaskBudgetHelperJourneys;

    private const USAGE = [10, 10];

    private EffectLedger $effectLedger;

    /** Bind the executor with a fixed token and seed the ledger operations. */
    private function setupJourney(): void
    {
        // ledger.write is a POST: keep it out of the confirmation path so
        // the interactive run executes it (and completes) rather than
        // pausing on a confirmation marker.
        $this->app['config']->set('llm-client.confirm_methods', []);
        $this->app['config']->set('llm-client.api_denylist', []);

        $this->effectLedger = new EffectLedger();
        $this->app->singleton(
            McpToolExecutor::class,
            fn () => new McpToolExecutor($this->app->make(McpToolRegistry::class), null, fn ($user) => 'test-token-'.$user->id),
        );
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

    private function syncConversation(): Conversation
    {
        return $this->fixture()->build()->conversation;
    }

    /** @return object the run row for the conversation's single run */
    private function runRowOf(Conversation $conversation): object
    {
        $row = DB::table('agent_runs')->where('conversation_id', $conversation->id)->orderByDesc('started_at')->first();
        $this->assertNotNull($row, 'the run exists');

        return $row;
    }

    /** @return object the action of the given type for the run */
    private function actionOf(object $run, string $type): object
    {
        $action = DB::table('agent_run_actions')->where('run_id', $run->id)->where('action_type', $type)->first();
        $this->assertNotNull($action, "a {$type} action exists");

        return $action;
    }

    // ---------------------------------------------------------------
    // A tool_invocation action carries the provider's call id
    // ---------------------------------------------------------------

    #[Test]
    public function a_tool_invocation_action_carries_the_providers_call_id(): void
    {
        $this->scenario = 'action_tool_call_id_tool_invocation';
        $this->setupJourney();
        $conversation = $this->syncConversation();

        // A single successful execute_operation closes the run without a
        // follow-up model turn (allExecuteOperationsSucceeded()), so no
        // final answer is scripted -- the tool_invocation action is
        // recorded when the call itself executes.
        $this->script()
            ->toolRequests([['id' => 'call_write', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.write']]])
            ->usage(...self::USAGE);

        $this->app->make(AgentLoopService::class)->run($conversation, 'Write to the ledger.');

        $run = $this->runRowOf($conversation);
        $action = $this->actionOf($run, 'tool_invocation');
        $this->assertSame('call_write', $action->tool_call_id, 'the tool_invocation action carries the provider\'s call id');
    }

    // ---------------------------------------------------------------
    // A 300-character call id is cut to 255
    // ---------------------------------------------------------------

    #[Test]
    public function a_300_character_call_id_is_cut_to_255(): void
    {
        $this->scenario = 'action_tool_call_id_cut';
        $this->setupJourney();
        $conversation = $this->syncConversation();
        $longId = str_repeat('x', 300);

        // A single successful execute_operation closes the run without a
        // follow-up model turn (allExecuteOperationsSucceeded()), so no
        // final answer is scripted -- the tool_invocation action is
        // recorded when the call itself executes.
        $this->script()
            ->toolRequests([['id' => $longId, 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.write']]])
            ->usage(...self::USAGE);

        $this->app->make(AgentLoopService::class)->run($conversation, 'Write to the ledger.');

        $run = $this->runRowOf($conversation);
        $action = $this->actionOf($run, 'tool_invocation');
        $this->assertSame(255, strlen((string) $action->tool_call_id), 'the 300-character call id is cut to 255');
    }

    // ---------------------------------------------------------------
    // A delegation action carries the call id too
    // ---------------------------------------------------------------

    #[Test]
    public function a_delegation_action_carries_the_call_id(): void
    {
        $this->scenario = 'action_tool_call_id_delegation';
        $this->setUpHelperJourney();
        $this->priceModel();
        $this->fixtureWithModel();
        $root = $this->agent('root');
        $a = $this->agent('a');
        $this->assignHelper($root, $a);

        $conversation = $this->rootConversation('sync', $root);
        $this->script()->toolRequests([
            ['id' => 'call_to_a', 'name' => 'delegate_to_helper', 'arguments' => ['helper_agent_id' => $a->id, 'task' => 'Part one.']],
        ])->usage(...self::USAGE);
        $this->scriptHelperAnswer('Part one done.', self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);

        $this->send('sync', $conversation, 'Do the part.')->assertStatus(200);

        $run = $this->runRowOf($conversation);
        $action = $this->actionOf($run, 'delegation');
        $this->assertSame('call_to_a', $action->tool_call_id, 'the delegation action carries the provider\'s call id');
        $this->tearDownTaskBudgetJourney();
    }
}
