<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\TaskBudgetJourneys;

/**
 * A task's budget and the spending quotas are separate limits, each named
 * for what it is.
 *
 * The user's spending quota is exhausted while a task waits at its ceiling.
 * Choosing to continue grants the task more allowance, but that allowance
 * never lets work past the quota: no model request is made, and what the
 * user is told is the quota's own refusal, never a task-budget text. On the
 * web the quota refuses the reply before it is even taken as a decision, so
 * the prompt keeps waiting; through the agent endpoint the decision is
 * recorded and the continuation itself is refused by the quota. And the
 * other way round: a task paused at its own ceiling, with a quota configured
 * but not reached, is reported in task-budget words only.
 */
class TaskBudgetQuotaSeparationJourneyTest extends AssembledSystemTestCase
{
    use TaskBudgetJourneys;

    /** Wording that belongs to the spending quotas, never to a task's budget. */
    private const QUOTA_WORDS = ['spending limit', 'quota', 'ceiling for your account', 'spending ceiling'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTaskBudgetJourney();
        config(['llm-client.agent_loop.max_iterations' => 2]);
        // The scripted model has no price; the quota is measured on recorded
        // consumption alone.
        config(['llm-client.budget.on_unpriced_model' => 'admit_untracked']);
    }

    protected function tearDown(): void
    {
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    /** A per-user spending quota of 25.00 a month, with $spent recorded against it. */
    private function quotaWithSpent(string $spent): void
    {
        app(SpendingCeilingService::class)->upsert(
            BudgetScope::UserDefault,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            ['amount' => '25.00', 'period_type' => 'month', 'enforcement_mode' => 'stop'],
        );
        DB::table('cost_summaries')->updateOrInsert([
            'entity_type' => CostSummary::ENTITY_USER,
            'entity_id' => $this->fixture()->user->id,
            'user_id' => $this->fixture()->user->id,
            'period_date' => Carbon::now()->toDateString(),
        ], [
            'id' => (string) Str::uuid(),
            'request_count' => 1,
            'priced_cost_total' => $spent,
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => Carbon::now(),
        ]);
    }

    private function assertNoQuotaWords(string $text, string $what): void
    {
        foreach (self::QUOTA_WORDS as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $text, "{$what} uses no quota wording");
        }
    }

    /** Every message written since $before, joined. */
    private function writtenSince(string $conversationId, array $before): string
    {
        return Message::where('conversation_id', $conversationId)->whereNotIn('id', $before)->pluck('content')->implode("\n");
    }

    #[Test]
    public function through_the_agent_endpoint_a_continue_past_the_quota_is_refused_by_the_quota(): void
    {
        $this->scenario = 'task_budget_quota_separation_agent';
        $this->entryPath = 'sync';
        $conversation = $this->conversationFor('sync');
        $this->scriptSteps(1, 2);
        $this->send('sync', $conversation, 'Store notes.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        $prompt = $this->promptOf($conversation);
        $this->assertNotNull($prompt, 'precondition: paused at the task\'s ceiling');
        $runId = $this->latestRunIdOf($conversation);
        $this->quotaWithSpent('999.0000000000');
        $this->ledger->expect('Work stopped:*');
        $before = Message::where('conversation_id', $conversation->id)->pluck('id')->all();
        // A new request: nothing admitted by the earlier one carries over.
        $this->app->forgetScopedInstances();

        $response = $this->send('sync', $conversation, 'continue');

        $response->assertStatus(402);
        $this->assertStringContainsString('spending ceiling for your account', (string) $response->getContent(), 'the quota is named');
        $this->assertStringNotContainsString("task's budget", (string) $response->getContent(), 'not the task budget');
        $this->assertSame(2, $this->modelRequests('sync'), 'the allowance never let work past the quota');
        $task = $this->taskRowOf($conversation);
        $this->assertSame(4, (int) $task->max_steps, 'the allowance was granted');
        $this->assertSame(2, (int) $task->steps_used, 'and not one step was claimed with it');
        $this->assertSame(QuestionState::Answered, $prompt->fresh()->state, 'the decision was recorded');
        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertSame('stopped_early', $run->end_state, 'the quota closed the run, as it closes any refused resume');
        $this->assertStringNotContainsString("task's budget", (string) $run->end_reason);
        $this->assertStringNotContainsString("task's budget", $this->writtenSince($conversation->id, $before), 'no task-budget text was posted for the refusal');
        $this->assertFalse((bool) $conversation->fresh()->is_processing);
    }

    #[Test]
    public function on_the_web_a_continue_past_the_quota_is_refused_before_it_is_taken_as_a_decision(): void
    {
        $this->scenario = 'task_budget_quota_separation_web';
        $this->entryPath = 'stream';
        $conversation = $this->conversationFor('stream');
        $this->send('stream', $conversation, 'Store notes.')->assertStatus(201);
        $this->playSteps(1, 2);
        $prompt = $this->promptOf($conversation);
        $this->assertNotNull($prompt, 'precondition: paused at the task\'s ceiling');
        $this->quotaWithSpent('999.0000000000');
        $this->ledger->expect('Work stopped:*');
        $before = Message::where('conversation_id', $conversation->id)->pluck('id')->all();
        // A new request: nothing admitted by the earlier one carries over.
        $this->app->forgetScopedInstances();

        $response = $this->send('stream', $conversation, 'continue');

        $response->assertStatus(402);
        $this->assertStringContainsString('spending ceiling for your account', (string) $response->getContent());
        $this->assertStringNotContainsString("task's budget", (string) $response->getContent());
        $this->assertSame(2, $this->modelRequests('stream'), 'no model request');
        $this->assertSame(QuestionState::Pending, $prompt->fresh()->state, 'the prompt keeps waiting for a decision');
        $task = $this->taskRowOf($conversation);
        $this->assertSame('paused', $task->state);
        $this->assertSame(2, (int) $task->max_steps, 'no allowance was granted');
        $this->assertSame('', $this->writtenSince($conversation->id, $before), 'nothing was stored');
    }

    #[Test]
    public function a_task_paused_at_its_own_ceiling_is_reported_in_task_budget_words_only(): void
    {
        $this->scenario = 'task_budget_quota_separation_wording';
        $this->entryPath = 'sync';
        $conversation = $this->conversationFor('sync');
        $this->scriptSteps(1, 2);
        $this->quotaWithSpent('1.0000000000');

        $this->send('sync', $conversation, 'Store notes.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);

        $prompt = $this->promptOf($conversation);
        $this->assertNotNull($prompt);
        $display = Message::findOrFail($prompt->display_message_id)->content;
        $this->assertStringContainsString("this task's budget", $display);
        $this->assertNoQuotaWords($display, 'the prompt and its report');
        $this->assertNoQuotaWords($this->telegram->presentationsFor($conversation->id)[0]->plainText, 'the prompt as delivered');
    }
}
