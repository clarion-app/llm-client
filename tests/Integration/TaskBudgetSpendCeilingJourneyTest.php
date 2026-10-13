<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\Services\TaskBudgetService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\TaskBudgetJourneys;

/**
 * The spend ceiling stops a task at the step boundary after its spend
 * reaches it.
 *
 * A step's cost is only known once the step is over, so a step that crosses
 * the ceiling runs to completion — the overshoot is that one step's — and
 * the next boundary pauses the task, with the report saying by how much it
 * went over. The figure is exactly the sum of the costs recorded for the
 * task's model requests, nothing estimated on the side. Usage on a model
 * with no configured price cannot be counted in money, so it never trips
 * the spend ceiling — the step ceiling still bounds the task — and the
 * figure says it is incomplete; usage whose tokens were estimated says the
 * figure is approximate. When the crossing step is also the last step the
 * step ceiling allows, one prompt names both ceilings and offers more of
 * both.
 */
class TaskBudgetSpendCeilingJourneyTest extends AssembledSystemTestCase
{
    use TaskBudgetJourneys;

    /** 10 completion tokens at 4000 per million: 0.04 per response. */
    private const USAGE = [10, 10];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTaskBudgetJourney();
        config(['llm-client.task_budget.default_max_spend' => '0.10']);
    }

    protected function tearDown(): void
    {
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    private function price(): void
    {
        ModelPrice::create([
            'provider_type' => 'openai',
            'model' => 'gpt-4',
            'reused_input_rate' => '0.00000000',
            'fresh_input_rate' => '0.00000000',
            'output_rate' => '4000.00000000',
            'effective_from' => Carbon::now()->subDay(),
            'effective_until' => null,
        ]);
    }

    private function spendOf(TaskBudget $task): string
    {
        return (string) DB::table('usage_records')->where('task_budget_id', $task->id)->sum('total_cost');
    }

    #[Test]
    #[DataProvider('paths')]
    public function the_task_pauses_at_the_boundary_after_its_spend_reaches_the_ceiling(string $path): void
    {
        $this->scenario = 'task_budget_spend_ceiling_'.$path;
        $this->entryPath = $path;
        $this->price();
        $conversation = $this->conversationFor($path);

        if ($path === 'sync') {
            $this->scriptSteps(1, 3, self::USAGE);
            $this->send('sync', $conversation, 'Store notes.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        } else {
            $this->send('stream', $conversation, 'Store notes.')->assertStatus(201);
            $this->playSteps(1, 3, self::USAGE);
        }

        $task = $this->taskOf($conversation);
        $this->assertSame(0, bccomp('0.10', (string) $task->max_spend, 10));
        $this->assertSame(3, $this->modelRequests($path), 'the third step crossed the ceiling and still completed; no fourth request');
        $this->assertCount(3, $this->noteWrites);
        $this->assertSame(3, DB::table('usage_records')->where('task_budget_id', $task->id)->count(), 'every model request\'s usage is the task\'s');
        $this->assertSame(0, bccomp('0.12', $this->spendOf($task), 10), 'spend is the sum of the task\'s recorded costs');

        $row = $this->taskRowOf($conversation);
        $this->assertSame('paused', $row->state);
        $this->assertSame('spend', $row->pause_reason);
        $this->assertSame(3, (int) $row->steps_used);

        $standing = app(TaskBudgetService::class)->standing($task->fresh());
        $this->assertSame(0, bccomp('0.12', $standing->spendUsed, 10));
        $this->assertSame(['spend'], $standing->reached());

        $prompt = $this->promptOf($conversation);
        $this->assertNotNull($prompt);
        $this->assertPromptDeliveredOnce($path, $conversation, $prompt);
        $this->assertStringStartsWith(
            "This task's budget ceiling was reached: spend (0.12 of 0.10 USD — 0.02 over, from the step that was already running).",
            (string) $prompt->preamble,
        );
        $this->assertStringContainsString('Continue with more allowance (+0.10 USD)', $prompt->choices[0]);
    }

    #[Test]
    #[DataProvider('paths')]
    public function unpriced_usage_never_trips_the_spend_ceiling_and_is_disclosed_as_incomplete(string $path): void
    {
        $this->scenario = 'task_budget_spend_unpriced_'.$path;
        $this->entryPath = $path;
        config(['llm-client.agent_loop.max_iterations' => 4]);
        $conversation = $this->conversationFor($path);

        if ($path === 'sync') {
            $this->scriptSteps(1, 4, self::USAGE);
            $this->send('sync', $conversation, 'Store notes.')->assertStatus(202);
        } else {
            $this->send('stream', $conversation, 'Store notes.')->assertStatus(201);
            $this->playSteps(1, 4, self::USAGE);
        }

        $row = $this->taskRowOf($conversation);
        $this->assertSame(4, $this->modelRequests($path), 'the step ceiling bounds it instead');
        $this->assertSame('paused', $row->state);
        $this->assertSame('steps', $row->pause_reason);

        $standing = app(TaskBudgetService::class)->standing($this->taskOf($conversation));
        $this->assertTrue($standing->incomplete());
        $this->assertSame(4, $standing->unpricedRequestCount);
        $this->assertTrue($standing->toArray()['spend']['incomplete']);
        $this->assertStringContainsString('Spend is incomplete (4 requests had no configured price).', (string) $this->promptOf($conversation)->preamble);
    }

    #[Test]
    #[DataProvider('paths')]
    public function estimated_usage_is_disclosed_as_approximate(string $path): void
    {
        $this->scenario = 'task_budget_spend_estimated_'.$path;
        $this->entryPath = $path;
        $this->price();
        $conversation = $this->conversationFor($path);

        // No usage reported by the provider: the tokens, and so the cost, are estimated.
        if ($path === 'sync') {
            $this->script()->toolRequests($this->stepCalls(1));
            $this->script()->finalAnswer('Done.');
            $this->send('sync', $conversation, 'Store a note.')->assertStatus(200);
        } else {
            $this->send('stream', $conversation, 'Store a note.')->assertStatus(201);
            $this->playSteps(1, 1);
            $this->playTurn(1, 'Done.');
        }

        $standing = app(TaskBudgetService::class)->standing($this->taskOf($conversation));
        $this->assertTrue($standing->approximate, 'estimated usage makes the figure approximate');
        $this->assertTrue($standing->toArray()['spend']['approximate']);
        $this->assertSame(2, DB::table('usage_records')->where('task_budget_id', $standing->taskBudgetId)->count());
    }

    #[Test]
    #[DataProvider('paths')]
    public function a_step_that_crosses_the_spend_ceiling_on_the_last_allowed_step_names_both(string $path): void
    {
        $this->scenario = 'task_budget_spend_and_steps_'.$path;
        $this->entryPath = $path;
        $this->price();
        config(['llm-client.agent_loop.max_iterations' => 3]);
        $conversation = $this->conversationFor($path);

        if ($path === 'sync') {
            $this->scriptSteps(1, 3, self::USAGE);
            $this->send('sync', $conversation, 'Store notes.')->assertStatus(202);
        } else {
            $this->send('stream', $conversation, 'Store notes.')->assertStatus(201);
            $this->playSteps(1, 3, self::USAGE);
        }

        $row = $this->taskRowOf($conversation);
        $this->assertSame(3, $this->modelRequests($path));
        $this->assertSame('paused', $row->state);
        $this->assertSame('both', $row->pause_reason);

        $prompts = \ClarionApp\LlmClient\Models\AgentQuestion::where('conversation_id', $conversation->id)->get();
        $this->assertCount(1, $prompts, 'one prompt names both');
        $this->assertStringStartsWith(
            "This task's budget ceiling was reached: steps (3 of 3) and spend (0.12 of 0.10 USD — 0.02 over, from the step that was already running).",
            (string) $prompts[0]->preamble,
        );
        $this->assertSame('Continue with more allowance (+3 steps, +0.10 USD)', $prompts[0]->choices[0], 'continue offers more of both');
    }
}
