<?php

namespace Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\InteractiveAnswerService;
use ClarionApp\LlmClient\Services\QuestionAnswerIntake;
use ClarionApp\LlmClient\Services\QuestionRenderer;
use ClarionApp\LlmClient\Services\QuestionResumer;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\Services\TaskBudgetGate;
use ClarionApp\LlmClient\Services\TaskBudgetPauser;
use ClarionApp\LlmClient\Services\TaskBudgetService;
use ClarionApp\LlmClient\Services\TaskBudgetText;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\IntakeResult;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswer;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerOutcome;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerStatus;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\ResumeCaller;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use ClarionApp\LlmClient\ValueObjects\StatedCeilings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\TestCase;

/**
 * A reply to a task's ceiling prompt, through every entry that takes one.
 *
 * The entries are the typed reply (answer intake, behind both message
 * endpoints), the interactive answer (the web form's service, by selection
 * or in the user's own words), and the three HTTP endpoints themselves —
 * POST message, POST agent (naming the conversation or not), and the
 * question form's POST answer. Each reply is one of the prompt's three
 * decisions, or not a decision at all.
 *
 * Continue and accept are recorded as the prompt's answer, in the same
 * compare-and-set as any answer, with the decision stored in a normalized
 * form the resumer reads back; what the user actually wrote stays in the
 * conversation as their reply. The entry then resumes the prompt exactly as
 * it would resume an answered question.
 *
 * Stop is not an answer: it parks the prompt in the same stopped state an
 * unanswered prompt reaches, keeping the conversation's question slot, and
 * tells the user so on the conversation's channel. A later continue or
 * accept still applies. Nothing resumes, and no run starts.
 *
 * A reply that is not a decision — and a stop on a prompt already stopped —
 * changes nothing and starts nothing; the user is told which replies the
 * prompt accepts. A reply typed in the conversation is not stored as a new
 * message either (it would otherwise look like an unanswered request).
 *
 * Someone else's prompt gets exactly the not-found each entry gives today.
 */
class CeilingPromptAnswersTest extends TestCase
{
    private User $user;

    private Server $server;

    private RecordingChannelDelivery $web;

    private RecordingQuestionResumer $resumer;

    private int $modelCalls = 0;

    private TaskBudgetText $text;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-12 09:00:00', 'UTC'));
        config(['llm-client.run_trace.enabled' => true, 'llm-client.cost.currency' => 'USD']);
        $this->createSupportingTables();

        Queue::fake();
        Event::fake([NewConversationMessageEvent::class]);

        $this->text = new TaskBudgetText();
        $this->user = User::factory()->create();
        $this->server = Server::create(['name' => 'Test Server', 'server_url' => 'http://localhost:11434', 'provider_type' => 'openai', 'token' => 'sk-test']);

        $this->web = new RecordingChannelDelivery('web');
        app(ConversationChannelRegistry::class)->register($this->web);

        // Every model call counts: no entry here may start one.
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function () {
            $this->modelCalls++;

            return ['choices' => [['message' => ['role' => 'assistant', 'content' => 'Done.']]]];
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));
        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);
        $this->app->instance(ProviderRegistry::class, $registry);

        // The continuation an answer leads to is the resumer's (covered by
        // its own tests); here only whether, and how, an entry asks for it.
        $this->resumer = new RecordingQuestionResumer();
        $this->app->instance(QuestionResumer::class, $this->resumer);
    }

    protected function tearDown(): void
    {
        foreach (['task_budget_events', 'task_budgets', 'agent_questions', 'agent_run_actions', 'agent_run_steps', 'agent_runs', 'usage_records', 'messages', 'conversations'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
        Mockery::close();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /** A web conversation of the user's whose task is paused at its 2-step ceiling with its prompt raised. */
    private function pausedAtTheCeiling(bool $stopped = false): array
    {
        $conversation = Conversation::create([
            'user_id' => $this->user->id, 'server_id' => $this->server->id, 'model' => 'test-model',
            'character' => 'Clarion', 'title' => 'Already titled', 'channel' => 'web', 'is_processing' => true,
        ]);
        Message::create(['conversation_id' => $conversation->id, 'role' => 'user', 'user' => $this->user->name, 'content' => 'Store notes for me.']);

        $runId = app(RunTraceRecorder::class)->openRun(RunKind::Interactive, (string) $this->user->id, $conversation->id, streamed: true);
        $task = app(TaskBudgetService::class)->forNewRun($conversation, new StatedCeilings(maxSteps: 2), false, $runId);
        $gate = app(TaskBudgetGate::class);
        $gate->beforeStep($conversation, $runId);
        $gate->beforeStep($conversation, $runId);
        DB::table('task_budgets')->where('id', $task->id)->update([
            'state' => 'paused', 'paused_at' => now()->format('Y-m-d H:i:s.u'), 'pause_reason' => 'steps',
            'paused_run_id' => $runId, 'paused_iteration' => 3, 'resume_mode' => 'stream', 'paused_steps_used' => 2, 'paused_spend' => '0',
        ]);

        $this->assertNull(app(TaskBudgetPauser::class)->raisePrompt($conversation, TaskBudget::findOrFail($task->id), 'stream'), 'precondition: the stream path returns nothing');
        $prompt = AgentQuestion::findOrFail(DB::table('task_budgets')->where('id', $task->id)->value('prompt_question_id'));
        $this->assertTrue($prompt->isCeilingPrompt());
        $this->assertSame('Continue with more allowance (+2 steps)', $prompt->choiceList()[0], 'precondition: the offered allowance is the original 2 steps');

        if ($stopped) {
            $this->travel(1)->days();
            $this->assertSame(QuestionState::Stopped, app(AgentQuestionService::class)->expireWithPolicy($prompt));
            $this->travel(1)->minutes();
        }

        return [Conversation::findOrFail($conversation->id), $prompt->fresh(), TaskBudget::findOrFail($task->id), $runId];
    }

    private function createSupportingTables(): void
    {
        foreach ([
            'condensation_states' => function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            },
            'mcp_sessions' => function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('protocol_version');
                $table->string('client_name')->nullable();
                $table->string('client_version')->nullable();
                $table->json('capabilities')->nullable();
                $table->timestamps();
                $table->softDeletes();
            },
        ] as $name => $definition) {
            if (!Schema::hasTable($name)) {
                Schema::create($name, $definition);
            }
        }
    }

    // ---------------------------------------------------------------
    // Driving each entry
    // ---------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function entries(): array
    {
        return [
            'answer intake (typed)' => ['intake'],
            'interactive answer: selection' => ['record_selection'],
            'interactive answer: own words' => ['record_text'],
            'POST message' => ['message'],
            'POST agent, naming the conversation' => ['agent_explicit'],
            'POST agent, implicitly' => ['agent_implicit'],
            'POST question answer (web form)' => ['question_form'],
        ];
    }

    /** Each decision as typed, and as the matching selection. */
    private const REPLIES = [
        'continue' => ['Continue', [1]],
        'accept' => ['accept', [2]],
        'stop' => ['STOP', [3]],
        'invalid' => ['maybe', [4]],
    ];

    private function isSelection(string $entry): bool
    {
        return in_array($entry, ['record_selection', 'question_form'], true);
    }

    /** What the user sent through $entry for $decision, as text. */
    private function sentText(string $entry, string $decision): string
    {
        [$typed, $selection] = self::REPLIES[$decision];

        return $this->isSelection($entry) ? InteractiveAnswer::selection($selection)->toReplyText() : $typed;
    }

    private function decide(string $entry, Conversation $conversation, AgentQuestion $prompt, string $decision, ?User $as = null): IntakeResult|InteractiveAnswerOutcome|TestResponse
    {
        $as ??= $this->user;
        [$typed, $selection] = self::REPLIES[$decision];

        return match ($entry) {
            'intake' => app(QuestionAnswerIntake::class)->accept(Conversation::findOrFail($conversation->id), (string) $as->id, $typed),
            'record_selection' => app(InteractiveAnswerService::class)->record($prompt->id, (string) $as->id, InteractiveAnswer::selection($selection), AnswerMethod::WebForm),
            'record_text' => app(InteractiveAnswerService::class)->record($prompt->id, (string) $as->id, InteractiveAnswer::freeText($typed), AnswerMethod::WebForm),
            'message' => $this->actingAs($as, 'api')->postJson('/api/clarion-app/llm-client/message', ['conversation_id' => $conversation->id, 'content' => $typed]),
            'agent_explicit' => $this->actingAs($as, 'api')->postJson('/api/clarion-app/llm-client/agent', ['message' => $typed, 'conversation_id' => $conversation->id]),
            'agent_implicit' => $this->actingAs($as, 'api')->postJson('/api/clarion-app/llm-client/agent', ['message' => $typed, 'channel' => 'web']),
            'question_form' => $this->actingAs($as, 'api')->postJson("/api/clarion-app/llm-client/question/{$prompt->id}/answer", ['selected' => $selection]),
        };
    }

    private function methodOf(string $entry): string
    {
        return match ($entry) {
            'record_selection', 'record_text', 'question_form' => AnswerMethod::WebForm->value,
            default => AnswerMethod::TypedReply->value,
        };
    }

    /** The resumes $entry asks for when a decision is recorded as the answer. */
    private function expectedResumesOnAnswer(string $entry): int
    {
        return in_array($entry, ['message', 'agent_explicit', 'agent_implicit', 'question_form'], true) ? 1 : 0;
    }

    private function snapshot(Conversation $conversation, AgentQuestion $prompt, TaskBudget $task): array
    {
        return [
            'prompt' => (array) DB::table('agent_questions')->where('id', $prompt->id)->first(),
            'messages' => Message::where('conversation_id', $conversation->id)->count(),
            'task' => (array) DB::table('task_budgets')->where('id', $task->id)->first(),
            'events' => DB::table('task_budget_events')->where('task_budget_id', $task->id)->count(),
            'deliveries' => count($this->web->deliveries),
        ];
    }

    private function assertNothingStarted(int $runsBefore): void
    {
        $this->assertSame($runsBefore, DB::table('agent_runs')->count(), 'no run starts');
        $this->assertSame(0, $this->modelCalls, 'no model request is made');
        Queue::assertNotPushed(AgentStreamStepJob::class);
    }

    // ---------------------------------------------------------------
    // Continue and accept: the prompt's answer
    // ---------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function answeringDecisions(): array
    {
        $cases = [];
        foreach (self::entries() as $label => [$entry]) {
            foreach (['continue', 'accept'] as $decision) {
                foreach (['pending' => false, 'stopped' => true] as $state => $stopped) {
                    $cases["{$decision} on a {$state} prompt via {$label}"] = [$entry, $decision, $stopped];
                }
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('answeringDecisions')]
    public function continue_and_accept_are_recorded_as_the_prompts_answer_in_normalized_form(string $entry, string $decision, bool $stopped): void
    {
        [$conversation, $prompt, $task] = $this->pausedAtTheCeiling($stopped);
        $choices = $prompt->choiceList();

        $result = $this->decide($entry, $conversation, $prompt, $decision);

        $answered = $prompt->fresh();
        $this->assertSame(QuestionState::Answered, $answered->state);
        $this->assertNull($answered->pending_conversation_id, 'an answer releases the conversation\'s question slot');
        $this->assertSame($decision === 'continue' ? 'continue steps=2' : 'accept', $answered->answer_raw, 'the decision is stored normalized, the offered allowance included');
        $number = $decision === 'continue' ? 1 : 2;
        $this->assertSame([['number' => $number, 'text' => $choices[$number - 1]]], array_values($answered->answer_selected ?? []));
        $method = $answered->answer_method;
        $this->assertSame($this->methodOf($entry), $method instanceof AnswerMethod ? $method->value : $method);
        $this->assertSame((string) $this->user->id, (string) $answered->answered_by);
        $this->assertSame($stopped, $answered->stopped_at !== null, 'a late decision keeps when work stopped');

        $reply = Message::findOrFail($answered->answer_message_id);
        $this->assertSame('user', $reply->role);
        $this->assertSame($this->sentText($entry, $decision), $reply->content, 'the user\'s own words stay in the conversation');
        $this->assertSame(AgentQuestionService::KIND_ANSWER, $reply->tool_data['kind'] ?? null);
        $this->assertSame($prompt->id, $reply->tool_data['question_id'] ?? null);
        $this->assertSame('task_budget', $reply->tool_data['origin'] ?? null, 'marked as a decision on this task\'s budget');

        // The entry does not touch the task: resuming it is the resumer's job.
        $this->assertSame('paused', DB::table('task_budgets')->where('id', $task->id)->value('state'));

        $this->assertEntryAnswered($entry, $result, $answered, $reply, $decision, $choices);
        $this->assertCount($this->expectedResumesOnAnswer($entry), $this->resumer->calls, 'the entry resumes the prompt exactly as it resumes any answered question');
        foreach ($this->resumer->calls as [$questionId]) {
            $this->assertSame($prompt->id, $questionId);
        }
    }

    private function assertEntryAnswered(string $entry, mixed $result, AgentQuestion $answered, Message $reply, string $decision, array $choices): void
    {
        $number = $decision === 'continue' ? 1 : 2;

        match ($entry) {
            'intake' => (function () use ($result, $reply, $answered) {
                $this->assertSame(IntakeResult::ANSWERED, $result->kind);
                $this->assertSame($reply->id, $result->message->id);
                $this->assertSame($answered->id, $result->question->id);
            })(),
            'record_selection' => (function () use ($result, $reply, $number, $choices) {
                $this->assertSame(InteractiveAnswerStatus::Answered, $result->status);
                $this->assertSame($reply->id, $result->message->id);
                $this->assertSame(app(QuestionRenderer::class)->optionLabel($number, $choices[$number - 1], null), $result->selectedSummary);
            })(),
            'record_text' => (function () use ($result, $reply, $decision) {
                $this->assertSame(InteractiveAnswerStatus::Answered, $result->status);
                $this->assertSame($reply->id, $result->message->id);
                $this->assertSame(self::REPLIES[$decision][0], $result->selectedSummary);
            })(),
            'message' => $result->assertStatus(201)->assertJson(['id' => $reply->id]),
            'agent_explicit', 'agent_implicit' => (function () use ($result) {
                $this->assertSame(ResumeCaller::HttpRequest, $this->resumer->calls[0][1] ?? null, 'resumed for the waiting request');
                $result->assertJsonMissing(['status' => 'decision_required']);
            })(),
            'question_form' => $result->assertStatus(201)->assertJson(['status' => 'answered', 'question' => ['id' => $answered->id, 'state' => 'answered']]),
        };
    }

    // ---------------------------------------------------------------
    // Stop: parked, not answered
    // ---------------------------------------------------------------

    #[Test]
    #[DataProvider('entries')]
    public function stop_on_a_pending_prompt_parks_it_keeps_the_slot_and_starts_nothing(string $entry): void
    {
        [$conversation, $prompt, $task] = $this->pausedAtTheCeiling();
        $runsBefore = DB::table('agent_runs')->count();
        $deliveriesBefore = count($this->web->deliveries);

        $result = $this->decide($entry, $conversation, $prompt, 'stop');

        $parked = $prompt->fresh();
        $this->assertSame(QuestionState::Stopped, $parked->state);
        $this->assertNotNull($parked->stopped_at);
        $this->assertSame($conversation->id, $parked->pending_conversation_id, 'the conversation\'s question slot is kept');
        $this->assertNull($parked->resolved_at, 'a stopped prompt is not resolved');
        $this->assertNull($parked->answer_raw, 'stop is not an answer');
        $this->assertSame('stopped', Message::findOrFail($parked->display_message_id)->tool_data['state'] ?? null, 'the prompt shows that it stopped');

        $messages = Message::where('conversation_id', $conversation->id)->orderBy('created_at')->orderBy('id')->get();
        $reply = $messages->first(fn (Message $m) => $m->role === 'user' && ($m->tool_data['question_id'] ?? null) === $prompt->id);
        $this->assertNotNull($reply, 'the user\'s reply is kept in the conversation');
        $this->assertSame($this->sentText($entry, 'stop'), $reply->content);
        $this->assertSame(AgentQuestionService::KIND_ANSWER, $reply->tool_data['kind'] ?? null);
        $this->assertSame('task_budget', $reply->tool_data['origin'] ?? null);

        $notices = $messages->filter(fn (Message $m) => $m->role === 'assistant' && $m->content === $this->text->stoppedByChoice());
        $this->assertCount(1, $notices, 'the user is told the task stopped at their request');
        $notice = $notices->first();
        $this->assertSame(AgentQuestionService::KIND_NOTICE, $notice->tool_data['kind'] ?? null);
        $this->assertSame($prompt->id, $notice->tool_data['question_id'] ?? null);
        $this->assertSame([$this->text->stoppedByChoice()], array_slice($this->web->textsFor($conversation->id), $deliveriesBefore), 'and it is delivered once on the conversation\'s channel');

        $taskRow = DB::table('task_budgets')->where('id', $task->id)->first();
        $this->assertSame('paused', $taskRow->state, 'the task stays paused');
        $this->assertSame($prompt->id, $taskRow->prompt_question_id);
        $event = DB::table('task_budget_events')->where('task_budget_id', $task->id)->where('kind', 'decision')->first();
        $this->assertNotNull($event, 'the decision is recorded on the task');
        $this->assertSame('stop', json_decode($event->payload, true)['decision'] ?? null);

        $this->assertNothingStarted($runsBefore);
        $this->assertEntryRecordedStop($entry, $result, $parked, $reply, $conversation);
    }

    private function assertEntryRecordedStop(string $entry, mixed $result, AgentQuestion $parked, Message $reply, Conversation $conversation): void
    {
        match ($entry) {
            'intake' => (function () use ($result, $reply, $parked) {
                $this->assertSame(IntakeResult::DECISION_RECORDED, $result->kind);
                $this->assertSame($reply->id, $result->message?->id);
                $this->assertSame($parked->id, $result->question?->id);
                $this->assertSame($this->text->stoppedByChoice(), $result->acknowledgement);
            })(),
            'record_selection', 'record_text' => (function () use ($result, $reply) {
                $this->assertSame(InteractiveAnswerStatus::Answered, $result->status, 'a truthful acknowledgement: the stop was taken');
                $this->assertSame('Stop', $result->selectedSummary);
                $this->assertSame($reply->id, $result->message?->id);
            })(),
            'message' => (function () use ($result, $reply) {
                $result->assertStatus(201)->assertJson(['id' => $reply->id]);
                $this->assertSame([], $this->resumer->calls, 'nothing is resumed');
            })(),
            'agent_explicit', 'agent_implicit' => (function () use ($result, $parked, $conversation) {
                // The notice was delivered on the channel; an empty 200 would
                // make a messaging channel post a "no response" message.
                $result->assertStatus(202)->assertJson([
                    'status' => 'awaiting_user',
                    'content' => '',
                    'question_id' => $parked->id,
                    'conversation_id' => $conversation->id,
                ]);
                $this->assertSame([], $this->resumer->calls, 'nothing is resumed');
            })(),
            'question_form' => $result->assertStatus(201)->assertJson([
                'status' => 'answered',
                'question' => ['id' => $parked->id, 'state' => 'stopped', 'answerable' => true],
                'message' => ['id' => $reply->id],
            ]),
        };
    }

    // ---------------------------------------------------------------
    // Nothing applied: stop on a stopped prompt, and replies that are not decisions
    // ---------------------------------------------------------------

    #[Test]
    #[DataProvider('entries')]
    public function stop_on_a_prompt_already_stopped_writes_nothing_and_says_so(string $entry): void
    {
        [$conversation, $prompt, $task] = $this->pausedAtTheCeiling(stopped: true);

        $this->assertNothingApplied($entry, $conversation, $prompt, $task, 'stop', $this->text->alreadyStopped(), QuestionState::Stopped);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function invalidCases(): array
    {
        $cases = [];
        foreach (self::entries() as $label => [$entry]) {
            foreach (['pending' => false, 'stopped' => true] as $state => $stopped) {
                $cases["{$state} prompt via {$label}"] = [$entry, $stopped];
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('invalidCases')]
    public function a_reply_that_is_not_a_decision_writes_nothing_starts_nothing_and_gets_guidance(string $entry, bool $stopped): void
    {
        [$conversation, $prompt, $task] = $this->pausedAtTheCeiling($stopped);
        $guidance = $this->text->decisionGuidance(TaskBudgetText::PROBLEM_UNRECOGNISED, ['reply' => $this->sentText($entry, 'invalid')]);

        $this->assertNothingApplied($entry, $conversation, $prompt, $task, 'invalid', $guidance, $stopped ? QuestionState::Stopped : QuestionState::Pending);
    }

    private function assertNothingApplied(string $entry, Conversation $conversation, AgentQuestion $prompt, TaskBudget $task, string $decision, string $guidance, QuestionState $state): void
    {
        $before = $this->snapshot($conversation, $prompt, $task);
        $runsBefore = DB::table('agent_runs')->count();

        $result = $this->decide($entry, $conversation, $prompt, $decision);

        $this->assertNothingStarted($runsBefore);
        $this->assertSame([], $this->resumer->calls, 'nothing is resumed');
        $this->assertSame($before, $this->snapshot($conversation, $prompt, $task), 'no write: the prompt, the task, the conversation\'s messages and the channel are untouched');
        $this->assertSame($state, $prompt->fresh()->state);

        match ($entry) {
            'intake' => (function () use ($result, $guidance, $prompt) {
                $this->assertSame(IntakeResult::DECISION_REQUIRED, $result->kind);
                $this->assertSame($guidance, $result->guidance);
                $this->assertSame($prompt->id, $result->question?->id);
                $this->assertNull($result->message);
            })(),
            'record_selection', 'record_text' => (function () use ($result, $guidance, $state) {
                $this->assertSame(InteractiveAnswerStatus::InvalidAnswer, $result->status);
                $this->assertSame($state, $result->state);
                $this->assertSame($guidance, $result->guidance);
            })(),
            'message' => $result->assertStatus(422)->assertExactJson(['code' => 'decision_required', 'message' => $guidance]),
            'agent_explicit', 'agent_implicit' => $result->assertStatus(200)->assertJson([
                'status' => 'decision_required',
                'content' => $guidance,
                'conversation_id' => $conversation->id,
            ]),
            'question_form' => $result->assertStatus(422)->assertExactJson(['code' => 'invalid_answer', 'message' => $guidance]),
        };
    }

    // ---------------------------------------------------------------
    // Someone else's prompt
    // ---------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function entriesNamingTheConversation(): array
    {
        $entries = self::entries();
        unset($entries['POST agent, implicitly']);

        return $entries;
    }

    #[Test]
    #[DataProvider('entriesNamingTheConversation')]
    public function another_user_gets_the_same_not_found_as_today_and_changes_nothing(string $entry): void
    {
        [$conversation, $prompt, $task] = $this->pausedAtTheCeiling();
        $stranger = User::factory()->create();
        $before = $this->snapshot($conversation, $prompt, $task);
        $runsBefore = DB::table('agent_runs')->count();

        $result = $this->decide($entry, $conversation, $prompt, 'stop', $stranger);

        match ($entry) {
            'intake' => $this->assertSame(IntakeResult::NOT_AN_ANSWER, $result->kind),
            'record_selection', 'record_text' => $this->assertSame(InteractiveAnswerStatus::NotFound, $result->status),
            'message', 'agent_explicit' => $result->assertStatus(403),
            'question_form' => $result->assertStatus(404),
        };
        $this->assertSame($before, $this->snapshot($conversation, $prompt, $task));
        $this->assertNothingStarted($runsBefore);
    }
}

/**
 * QuestionResumer as the entries reach it, recording each resume they ask
 * for. A resume of an answered prompt stops there — the continuation is
 * covered by the resumer's own tests; any other resume (one that has
 * nothing to continue) runs for real, so it is shown to change nothing.
 */
class RecordingQuestionResumer extends QuestionResumer
{
    /** @var list<array{0: string, 1: ResumeCaller}> */
    public array $calls = [];

    public function resume(string $questionId, ResumeCaller $caller): ?array
    {
        $this->calls[] = [$questionId, $caller];

        if (DB::table('agent_questions')->where('id', $questionId)->value('state') === QuestionState::Answered->value) {
            return null;
        }

        return parent::resume($questionId, $caller);
    }
}
