<?php

namespace Tests\Feature;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\TaskBudgetJourneys;

/**
 * Everything the user is told about a task's budget names it as this task's
 * budget, and nothing about it borrows the spending quota's words — nor
 * does a quota's refusal borrow the task budget's.
 *
 * The journeys below produce every kind of task-budget text a user can
 * receive: the approach notice, the prompt with its report, the stop notice,
 * the expiry notice, the accepted report, the halt report, the guidance for
 * an unrecognised decision, the replies to "/budget" lines (a change, a
 * view, a malformed line, a ceiling cut to the maximum) and the standing a
 * PATCH answers with. Each is collected from where the user sees it — the
 * stored conversation, the channel deliveries and the replies — and checked.
 */
class TaskBudgetWordingTest extends AssembledSystemTestCase
{
    use TaskBudgetJourneys;

    private const NAMES = ["this task's budget", "This task's budget"];

    private const QUOTA_WORDS = ['spending limit', 'quota', 'ceiling for your account', 'spending ceiling'];

    /** @var list<array{0: string, 1: string}> what was said, and where */
    private array $said = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTaskBudgetJourney();
        config(['llm-client.agent_loop.max_iterations' => 5, 'llm-client.task_budget.maximum_steps' => 50]);
    }

    protected function tearDown(): void
    {
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    private function heard(string $where, ?string $text): void
    {
        $this->assertNotNull($text, "{$where}: something was said");
        $this->assertNotSame('', trim((string) $text), "{$where}: something was said");
        $this->said[] = [(string) $text, $where];
    }

    /** Every task-budget message stored in the conversation: notices, reports, ceiling prompts and their notices. */
    private function heardStored(Conversation $conversation): void
    {
        $prompts = AgentQuestion::where('conversation_id', $conversation->id)->where('origin', AgentQuestion::ORIGIN_TASK_BUDGET)->pluck('id')->all();
        foreach (Message::where('conversation_id', $conversation->id)->where('role', 'assistant')->orderBy('created_at')->get() as $message) {
            $kind = $message->tool_data['kind'] ?? null;
            $ofAPrompt = in_array($message->tool_data['question_id'] ?? null, $prompts, true);
            if (in_array($kind, [AgentQuestionService::KIND_TASK_BUDGET_NOTICE, AgentQuestionService::KIND_TASK_BUDGET_REPORT], true) || $ofAPrompt) {
                $this->heard("stored {$kind}", $message->content);
            }
        }
    }

    private function assertEverythingSaidNamesTheTaskBudget(int $atLeast): void
    {
        $this->assertGreaterThanOrEqual($atLeast, count($this->said), 'precondition: the journeys said that much');
        foreach ($this->said as [$text, $where]) {
            $named = false;
            foreach (self::NAMES as $name) {
                $named = $named || str_contains($text, $name);
            }
            $this->assertTrue($named, "{$where} names this task's budget: {$text}");
            foreach (self::QUOTA_WORDS as $word) {
                $this->assertStringNotContainsStringIgnoringCase($word, $text, "{$where} borrows no quota wording");
            }
        }
    }

    #[Test]
    public function every_task_budget_text_on_the_web_names_this_tasks_budget(): void
    {
        $this->scenario = 'task_budget_wording_web';
        $this->entryPath = 'stream';
        $conversation = $this->conversationFor('stream');
        $user = $this->fixture()->user;
        $api = '/api/clarion-app/llm-client';

        // A ceiling cut to the maximum, the approach notice, the prompt and its report.
        $sent = $this->actingAs($user, 'api')->postJson("{$api}/message", ['conversation_id' => $conversation->id, 'content' => "/budget steps=1000\nStore notes."])->assertStatus(201);
        $this->heard('reply: reduced to the maximum', $sent->json('task_budget_notice'));
        $this->actingAs($user, 'api')->patchJson("{$api}/conversation/{$conversation->id}/task-budget", ['max_steps' => 5])->assertStatus(200);
        $this->playSteps(1, 5);
        $this->assertNotNull($this->promptOf($conversation), 'precondition: paused at the ceiling');

        // A view, a change and a malformed line while it waits.
        $this->heard('reply: bare /budget', $this->actingAs($user, 'api')->postJson("{$api}/message", ['conversation_id' => $conversation->id, 'content' => '/budget'])->assertStatus(200)->json('label'));
        $this->heard('reply: PATCH', $this->actingAs($user, 'api')->patchJson("{$api}/conversation/{$conversation->id}/task-budget", ['max_steps' => 1000])->assertStatus(200)->json('label'));
        $this->heard('reply: directive-only', $this->actingAs($user, 'api')->postJson("{$api}/message", ['conversation_id' => $conversation->id, 'content' => '/budget steps=5'])->assertStatus(200)->json('label'));
        $this->heard('reply: malformed', $this->actingAs($user, 'api')->postJson("{$api}/message", ['conversation_id' => $conversation->id, 'content' => '/budget please'])->assertStatus(422)->json('message'));

        // An unrecognised decision, then stop.
        $this->heard('reply: decision guidance', $this->send('stream', $conversation, 'maybe')->assertStatus(422)->json('message'));
        $this->send('stream', $conversation, '3')->assertStatus(201);
        $this->assertSame('stopped', $this->promptOf($conversation)->state->value, 'precondition: stopped by choice');
        $this->heard('reply: already stopped', $this->send('stream', $conversation, 'stop')->assertStatus(422)->json('message'));

        // Accepted after all.
        $this->send('stream', $conversation, 'accept')->assertStatus(201);
        $this->workQueuedResumes();
        $this->assertSame('concluded', $this->taskRowOf($conversation)->state, 'precondition: accepted');

        $this->heardStored($conversation);
        foreach ($this->web->textsFor($conversation->id) as $index => $text) {
            $this->heard("delivered on the web #{$index}", $text);
        }
        $kinds = Message::where('conversation_id', $conversation->id)->get()->map(fn (Message $m) => ($m->tool_data['notice'] ?? null) ?? ($m->tool_data['occasion'] ?? null))->filter()->values()->all();
        $this->assertEqualsCanonicalizing(['reduced_to_maximum', 'approach', 'ceiling_changed', 'accepted'], $kinds, 'precondition: every kind of notice and report was written');
        $this->assertEverythingSaidNamesTheTaskBudget(14);
    }

    #[Test]
    public function every_task_budget_text_in_the_chat_names_this_tasks_budget(): void
    {
        $this->scenario = 'task_budget_wording_chat';
        $this->entryPath = 'sync';
        config(['llm-client.agent_loop.max_iterations' => 2]);
        $conversation = $this->conversationFor('sync');
        $user = $this->fixture()->user;
        $agent = fn (array $body) => $this->actingAs($user, 'api')->postJson('/api/clarion-app/llm-client/agent', $body + ['channel' => 'telegram']);
        $this->scriptSteps(1, 2);

        $this->send('sync', $conversation, 'Store notes.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        $prompt = $this->promptOf($conversation);
        $this->assertNotNull($prompt);

        $this->heard('reply: bare /budget', $agent(['message' => '/budget'])->assertStatus(200)->json('content'));
        $this->heard('reply: directive-only', $agent(['message' => '/budget steps=2'])->assertStatus(200)->json('content'));
        $this->heard('reply: malformed', $agent(['message' => '/budget steps=0'])->assertStatus(200)->json('content'));
        $this->heard('reply: decision guidance', $agent(['message' => 'maybe'])->assertStatus(200)->json('content'));

        // Unanswered past its deadline: stopped by the sweep, with its notice.
        $this->travelTo($prompt->expires_at->copy()->addMinute());
        Artisan::call('llm-client:resolve-expired-questions');
        $this->assertSame('stopped', $prompt->fresh()->state->value, 'precondition: stopped by the sweep');

        $accepted = $agent(['message' => 'accept'])->assertStatus(200);
        $this->heard('reply: accepted', $accepted->json('content'));

        $this->heardStored($conversation);
        foreach ($this->telegram->presentationsFor($conversation->id) as $index => $presentation) {
            $this->heard("presented in the chat #{$index}", $presentation->plainText);
        }
        foreach ($this->telegram->textsFor($conversation->id) as $index => $text) {
            $this->heard("delivered in the chat #{$index}", $text);
        }
        $this->assertEverythingSaidNamesTheTaskBudget(10);
    }

    #[Test]
    public function a_halted_task_is_reported_as_this_tasks_budget(): void
    {
        $this->scenario = 'task_budget_wording_halt';
        $this->entryPath = 'sync';
        config(['llm-client.agent_loop.max_iterations' => 2]);
        $conversation = $this->conversationFor('sync');
        $conversation->update(['channel' => 'nobody-listens']);
        $this->scriptSteps(1, 2);

        $this->actingAs($this->fixture()->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'Store notes.',
            'conversation_id' => $conversation->id,
            'channel' => 'nobody-listens',
        ])->assertStatus(200)->assertJson(['status' => 'stopped']);

        $this->assertSame('halted', $this->taskRowOf($conversation)->state, 'precondition: nobody to ask');
        $this->heardStored($conversation);
        $this->assertEverythingSaidNamesTheTaskBudget(1);
    }

    #[Test]
    public function a_quotas_refusal_never_borrows_the_task_budgets_words(): void
    {
        $this->scenario = 'task_budget_wording_quota';
        $this->entryPath = 'sync';
        $conversation = $this->conversationFor('sync');
        config(['llm-client.budget.on_unpriced_model' => 'admit_untracked']);
        app(SpendingCeilingService::class)->upsert(
            BudgetScope::UserDefault,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            ['amount' => '25.00', 'period_type' => 'month', 'enforcement_mode' => 'stop'],
        );
        DB::table('cost_summaries')->insert([
            'id' => (string) Str::uuid(),
            'entity_type' => CostSummary::ENTITY_USER,
            'entity_id' => $this->fixture()->user->id,
            'user_id' => $this->fixture()->user->id,
            'period_date' => Carbon::now()->toDateString(),
            'request_count' => 1,
            'priced_cost_total' => '999.0000000000',
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => Carbon::now(),
        ]);
        $this->ledger->expect('Work stopped:*');

        $refused = $this->send('sync', $conversation, 'Store notes.')->assertStatus(402);

        $this->assertStringContainsString('spending ceiling', (string) $refused->getContent(), 'precondition: the quota refused it');
        $this->assertStringNotContainsString("task's budget", (string) $refused->getContent());
        foreach (Message::where('conversation_id', $conversation->id)->pluck('content') as $content) {
            $this->assertStringNotContainsString("task's budget", (string) $content);
        }
        foreach ($this->telegram->textsFor($conversation->id) as $text) {
            $this->assertStringNotContainsString("task's budget", $text);
        }
    }
}
