<?php

namespace Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\LateReplyNotifier;
use ClarionApp\LlmClient\Services\QuestionAnswerIntake;
use ClarionApp\LlmClient\Services\QuestionRenderer;
use ClarionApp\LlmClient\Services\QuestionResumer;
use ClarionApp\LlmClient\Services\QuestionViewBuilder;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\Services\TaskBudgetGate;
use ClarionApp\LlmClient\Services\TaskBudgetPauser;
use ClarionApp\LlmClient\Services\TaskBudgetService;
use ClarionApp\LlmClient\Services\TaskBudgetText;
use ClarionApp\LlmClient\ValueObjects\IntakeResult;
use ClarionApp\LlmClient\ValueObjects\ParsedAnswer;
use ClarionApp\LlmClient\ValueObjects\QuestionResolution;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\ResumeCaller;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use ClarionApp\LlmClient\ValueObjects\StatedCeilings;
use ClarionApp\LlmClient\ValueObjects\UnansweredPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\TestCase;

/**
 * Every place that reads a question row, given a ceiling prompt.
 *
 * A ceiling prompt is a question row the system wrote, not the agent: no
 * agent turn is paused behind it (its display message stands in for one),
 * and it is never answered by giving a tool call a result. So the places
 * built around those two things must either never meet one, or tell it
 * apart:
 *
 *  - the late-reply notice exists only for a question that expired on an
 *    assumption, which a ceiling prompt never does — unanswered, it stops;
 *  - the lookup made when an agent's question cannot be opened looks for the
 *    agent turn it just stored, never a ceiling prompt's display message;
 *  - the agent loop's continuation after an answered question is never
 *    reached for one: its resumption goes to the task budget instead;
 *  - turning a resolution into a tool result is a programming error for one;
 *  - the question view says which kind of question it is and, for a
 *    ceiling prompt, what continuing would give and where the task stands.
 */
class QuestionSiteOriginTest extends TestCase
{
    private User $user;

    private Server $server;

    private RecordingChannelDelivery $web;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-12 09:00:00', 'UTC'));
        config(['llm-client.run_trace.enabled' => true, 'llm-client.cost.currency' => 'USD']);
        Queue::fake();
        Event::fake([NewConversationMessageEvent::class]);

        $this->user = User::factory()->create();
        $this->server = Server::create(['name' => 'Test Server', 'server_url' => 'http://localhost:11434', 'provider_type' => 'openai', 'token' => 'sk-test']);
        $this->web = new RecordingChannelDelivery('web');
        app(ConversationChannelRegistry::class)->register($this->web);
    }

    protected function tearDown(): void
    {
        foreach (['task_budget_events', 'task_budgets', 'agent_questions', 'agent_run_actions', 'agent_run_steps', 'agent_runs', 'usage_records', 'messages', 'conversations'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    private function conversation(): Conversation
    {
        $conversation = Conversation::create([
            'user_id' => $this->user->id, 'server_id' => $this->server->id, 'model' => 'test-model',
            'character' => 'Clarion', 'title' => 'Already titled', 'channel' => 'web', 'is_processing' => true,
        ]);
        Message::create(['conversation_id' => $conversation->id, 'role' => 'user', 'user' => $this->user->name, 'content' => 'Store notes for me.']);

        return $conversation;
    }

    /**
     * A task paused at its ceiling (2 steps; with $spend, 0.40 of 0.40
     * spent as well) and the ceiling prompt raised for it.
     */
    private function ceilingPrompt(string $mode = 'stream', bool $spend = false): array
    {
        $conversation = $this->conversation();
        $runId = app(RunTraceRecorder::class)->openRun(RunKind::Interactive, (string) $this->user->id, $conversation->id, streamed: $mode === 'stream');
        $task = app(TaskBudgetService::class)->forNewRun($conversation, new StatedCeilings(maxSteps: 2, maxSpend: $spend ? '0.40' : null), false, $runId);
        $gate = app(TaskBudgetGate::class);
        $gate->beforeStep($conversation, $runId);
        $gate->beforeStep($conversation, $runId);
        if ($spend) {
            DB::table('usage_records')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(), 'conversation_id' => $conversation->id, 'user_id' => $this->user->id,
                'attempt_group_id' => (string) \Illuminate\Support\Str::uuid(), 'run_id' => $runId,
                'task_budget_id' => $task->id, 'model' => 'test-model', 'input_tokens' => 10, 'output_tokens' => 10, 'total_tokens' => 20,
                'total_cost' => '0.4000000000', 'cost_unpriced' => false, 'cost_estimated' => false, 'created_at' => now(),
            ]);
        }
        DB::table('task_budgets')->where('id', $task->id)->update([
            'state' => 'paused', 'paused_at' => now()->format('Y-m-d H:i:s.u'), 'pause_reason' => $spend ? 'both' : 'steps',
            'paused_run_id' => $runId, 'paused_iteration' => 3, 'resume_mode' => $mode, 'paused_steps_used' => 2, 'paused_spend' => $spend ? '0.4' : '0',
        ]);

        app(TaskBudgetPauser::class)->raisePrompt($conversation, TaskBudget::findOrFail($task->id), $mode);
        $prompt = AgentQuestion::findOrFail(DB::table('task_budgets')->where('id', $task->id)->value('prompt_question_id'));

        return [Conversation::findOrFail($conversation->id), $prompt, TaskBudget::findOrFail($task->id), $runId];
    }

    /** An agent's own pending question, asked from a stored assistant turn. */
    private function agentQuestion(Conversation $conversation): AgentQuestion
    {
        $paused = Message::create([
            'conversation_id' => $conversation->id, 'role' => 'assistant', 'user' => 'Clarion', 'content' => '', 'responseTime' => 0,
            'tool_data' => ['tool_calls' => [['id' => 'call_ask', 'type' => 'function', 'function' => ['name' => 'ask_user', 'arguments' => '{}']]]],
        ]);

        return AgentQuestion::create([
            'conversation_id' => $conversation->id,
            'user_id' => $conversation->user_id,
            'pending_conversation_id' => $conversation->id,
            'paused_message_id' => $paused->id,
            'tool_call_id' => 'call_ask',
            'channel' => 'web',
            'resume_mode' => 'stream',
            'question' => 'Which one?',
            'choices' => ['A', 'B'],
            'state' => QuestionState::Pending,
            'asked_at' => now(),
            'expires_at' => now()->addHour(),
            'unanswered_policy' => UnansweredPolicy::Stop,
        ]);
    }

    // ---------------------------------------------------------------
    // The late-reply notice
    // ---------------------------------------------------------------

    #[Test]
    public function an_unanswered_ceiling_prompt_stops_so_the_late_reply_notice_never_fires_for_it(): void
    {
        [$conversation, $prompt] = $this->ceilingPrompt();

        $this->travel(2)->days();
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('llm-client:resolve-expired-questions'));

        $this->assertSame(QuestionState::Stopped, $prompt->fresh()->state, 'never expired on an assumption');
        $this->assertFalse(app(AgentQuestionService::class)->claimLateReplyNotice($prompt->id), 'there is no late-reply notice to claim');
        $this->assertNull(app(LateReplyNotifier::class)->noticeFor($conversation->fresh(), (string) $this->user->id), 'a late reply is not told it came too late');
        $this->assertNull($prompt->fresh()->late_reply_noticed_at);
    }

    // ---------------------------------------------------------------
    // The lookup made when an agent's question cannot be opened
    // ---------------------------------------------------------------

    #[Test]
    public function a_ceiling_prompts_paused_message_is_its_own_display_message_never_an_agent_turn(): void
    {
        [$conversation, $prompt] = $this->ceilingPrompt();

        $display = Message::findOrFail($prompt->paused_message_id);
        $this->assertSame($prompt->display_message_id, $display->id);
        $this->assertSame(AgentQuestionService::KIND_QUESTION, $display->tool_data['kind'] ?? null);
        $this->assertArrayNotHasKey('tool_calls', $display->tool_data, 'not a stored agent turn');

        // The lookup an ask failure makes, for every stored agent turn of the
        // conversation, finds no ceiling prompt behind it.
        $turns = Message::where('conversation_id', $conversation->id)->where('role', 'assistant')->get()
            ->filter(fn (Message $m) => !empty($m->tool_data['tool_calls'] ?? null));
        foreach ($turns as $turn) {
            $this->assertFalse(AgentQuestion::where('paused_message_id', $turn->id)->where('origin', AgentQuestion::ORIGIN_TASK_BUDGET)->exists());
        }
    }

    // ---------------------------------------------------------------
    // The agent loop's continuation after a question
    // ---------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function modes(): array
    {
        return ['streamed' => ['stream'], 'synchronous' => ['sync']];
    }

    #[Test]
    #[DataProvider('modes')]
    public function resuming_a_decided_ceiling_prompt_never_reaches_the_loops_question_continuation(string $mode): void
    {
        $spy = $this->app->make(QuestionContinuationSpy::class);
        $this->app->instance(AgentLoopService::class, $spy);
        [$conversation, $prompt, $task] = $this->ceilingPrompt($mode);
        $this->assertSame(IntakeResult::ANSWERED, app(QuestionAnswerIntake::class)->accept($conversation, (string) $this->user->id, 'accept')->kind);

        app(QuestionResumer::class)->resume($prompt->id, ResumeCaller::HttpRequest);

        $this->assertSame([], $spy->reached, 'neither resumeQuestion() nor resumeQuestionSync() is reached for a ceiling prompt');
        $this->assertSame('concluded', DB::table('task_budgets')->where('id', $task->id)->value('state'), 'the task budget took the decision instead');
        $display = Message::findOrFail($prompt->display_message_id);
        $this->assertArrayNotHasKey('tool_results', $display->tool_data, 'the display message is never written as a paused turn');
    }

    // ---------------------------------------------------------------
    // Resolutions as tool results
    // ---------------------------------------------------------------

    #[Test]
    public function a_ceiling_prompts_resolution_is_never_a_tool_result(): void
    {
        [, $prompt] = $this->ceilingPrompt();
        $resolution = new QuestionResolution(QuestionState::Answered, new ParsedAnswer('accept', true, [['number' => 2, 'text' => 'Accept what has been done']]));

        $this->expectException(\LogicException::class);

        $resolution->toToolResult($prompt);
    }

    #[Test]
    public function an_agent_questions_resolution_is_still_a_tool_result(): void
    {
        $question = $this->agentQuestion($this->conversation());
        $resolution = new QuestionResolution(QuestionState::Answered, new ParsedAnswer('1', true, [['number' => 1, 'text' => 'A']]));

        $result = json_decode($resolution->toToolResult($question), true);

        $this->assertSame('answered', $result['outcome'] ?? null);
        $this->assertSame($question->id, $result['question_id'] ?? null);
    }

    // ---------------------------------------------------------------
    // The question view
    // ---------------------------------------------------------------

    private const AGENT_VIEW_KEYS = [
        'id', 'question', 'preamble', 'choices', 'allow_multiple', 'state', 'expires_at', 'answer', 'answerable',
        'if_unanswered', 'consequential', 'assumed_answer', 'expiry_statement', 'outcome_text', 'stopped_at', 'answered_late',
    ];

    #[Test]
    public function an_agent_questions_view_says_it_is_the_agents_and_nothing_more(): void
    {
        $view = app(QuestionViewBuilder::class)->forQuestion($this->agentQuestion($this->conversation()));

        $this->assertSame('agent', $view['kind'] ?? null);
        $this->assertEqualsCanonicalizing([...self::AGENT_VIEW_KEYS, 'kind'], array_keys($view));
    }

    #[Test]
    public function a_ceiling_prompts_view_carries_the_offered_allowance_what_was_reached_and_the_standing(): void
    {
        [, $prompt, $task] = $this->ceilingPrompt();

        $view = app(QuestionViewBuilder::class)->forQuestion($prompt);

        $this->assertSame('task_budget', $view['kind'] ?? null);
        $this->assertSame(['steps' => 2, 'spend' => null], $view['offered_allowance'] ?? null, 'continuing would add the original 2 steps');
        $this->assertSame(['steps'], $view['reached'] ?? null);
        $this->assertSame(app(TaskBudgetService::class)->standing($task)->toArray(), $view['standing'] ?? null);
        $this->assertEqualsCanonicalizing([...self::AGENT_VIEW_KEYS, 'kind', 'offered_allowance', 'reached', 'standing'], array_keys($view));
        $this->assertSame('pending', $view['state']);
        $this->assertCount(3, $view['choices']);
    }

    #[Test]
    public function with_both_ceilings_reached_the_view_offers_both_allowances(): void
    {
        [, $prompt, $task] = $this->ceilingPrompt('stream', spend: true);

        $view = app(QuestionViewBuilder::class)->forQuestion($prompt);

        $this->assertSame(['steps' => 2, 'spend' => '0.4000000000'], $view['offered_allowance'] ?? null);
        $this->assertSame(['steps', 'spend'], $view['reached'] ?? null);
        $this->assertSame(app(TaskBudgetService::class)->standing($task)->toArray(), $view['standing'] ?? null);
    }

    #[Test]
    public function a_stopped_ceiling_prompts_view_is_still_answerable_and_still_a_task_budget_view(): void
    {
        [, $prompt] = $this->ceilingPrompt();
        $this->travel(2)->days();
        app(AgentQuestionService::class)->expireWithPolicy($prompt);

        $view = app(QuestionViewBuilder::class)->forQuestion($prompt->fresh());

        $this->assertSame('stopped', $view['state']);
        $this->assertTrue($view['answerable']);
        $this->assertSame('task_budget', $view['kind'] ?? null);
        $this->assertSame(['steps' => 2, 'spend' => null], $view['offered_allowance'] ?? null);
    }

    // ---------------------------------------------------------------
    // Wording: the advance statement and the stopped outcome
    // ---------------------------------------------------------------

    #[Test]
    public function a_ceiling_prompt_says_in_advance_that_work_stays_stopped_at_this_tasks_budget(): void
    {
        [, $prompt] = $this->ceilingPrompt();
        $renderer = app(QuestionRenderer::class);
        $statement = app(TaskBudgetText::class)->promptExpiryStatement($renderer->deadlineText($prompt));

        $this->assertSame($statement, $renderer->expiryStatement($prompt));
        $this->assertStringContainsString($statement, $renderer->render($prompt, $prompt->preamble));
        $this->assertStringNotContainsString('it will pause until you reply', $renderer->render($prompt, $prompt->preamble));
        $this->assertSame($statement, app(QuestionViewBuilder::class)->forQuestion($prompt)['expiry_statement']);
    }

    #[Test]
    public function an_agent_questions_advance_statement_is_unchanged(): void
    {
        $question = $this->agentQuestion($this->conversation());
        $statement = app(QuestionRenderer::class)->expiryStatement($question);

        $this->assertNotNull($statement);
        $this->assertStringNotContainsString("this task's budget", $statement);
    }

    #[Test]
    public function a_stopped_ceiling_prompts_outcome_names_this_tasks_budget_however_it_stopped(): void
    {
        [, $expired] = $this->ceilingPrompt();
        $this->travel(2)->days();
        app(AgentQuestionService::class)->expireWithPolicy($expired);
        $this->travelBack();

        $already = app(TaskBudgetText::class)->alreadyStopped();
        $this->assertSame($already, app(QuestionRenderer::class)->renderOutcome($expired->fresh()));
        $this->assertSame($already, app(QuestionViewBuilder::class)->forQuestion($expired->fresh())['outcome_text']);

        [, $chosen] = $this->ceilingPrompt();
        $this->assertNotNull(app(AgentQuestionService::class)->stopCeilingPrompt($chosen, (string) $this->user->id, 'stop'));
        $this->assertSame(QuestionState::Stopped, $chosen->fresh()->state);
        $this->assertSame($already, app(QuestionRenderer::class)->renderOutcome($chosen->fresh()));
    }
}

/**
 * The agent loop, recording whether the continuation after an answered
 * question is reached. Both methods exist on the loop today; neither may be
 * reached for a ceiling prompt.
 */
class QuestionContinuationSpy extends AgentLoopService
{
    /** @var list<string> */
    public array $reached = [];

    public function resumeQuestion(Conversation $conversation, Message $message, QuestionResolution $resolution): void
    {
        $this->reached[] = 'resumeQuestion';
    }

    public function resumeQuestionSync(Conversation $conversation, Message $message, QuestionResolution $resolution): array
    {
        $this->reached[] = 'resumeQuestionSync';

        return ['status' => 'completed', 'content' => '', 'message_id' => null];
    }
}
