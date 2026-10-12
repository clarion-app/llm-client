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
use ClarionApp\LlmClient\Services\AutoMemoryRetriever;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\QuestionAnswerIntake;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\Services\TaskBudgetGate;
use ClarionApp\LlmClient\Services\TaskBudgetPauser;
use ClarionApp\LlmClient\Services\TaskBudgetService;
use ClarionApp\LlmClient\ValueObjects\IntakeResult;
use ClarionApp\LlmClient\ValueObjects\MemoryInjectionSection;
use ClarionApp\LlmClient\ValueObjects\MemoryRetrievalResult;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use ClarionApp\LlmClient\ValueObjects\StatedCeilings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\TestCase;

/**
 * A decision typed at a task's budget ceiling ("continue steps=20") is
 * stored as a user message, but it is not the user's request: the routing
 * fallback and the auto-memory query of the resumed task must keep using
 * the request that started it. Both read the conversation's last user
 * message through AgentLoopService::getLastUserMessage().
 */
class LastUserMessageDecisionSkipTest extends TestCase
{
    private const REQUEST = 'Summarise the meeting notes from last week.';

    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-12 09:00:00', 'UTC'));
        config(['llm-client.run_trace.enabled' => true, 'llm-client.cost.currency' => 'USD']);
        Queue::fake();
        Event::fake([NewConversationMessageEvent::class]);

        $this->user = User::factory()->create();
        $this->server = Server::create(['name' => 'Test Server', 'server_url' => 'http://localhost:11434', 'provider_type' => 'openai', 'token' => 'sk-test']);
        app(ConversationChannelRegistry::class)->register(new RecordingChannelDelivery('web'));
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

    /** A conversation whose 2-step task is paused at its ceiling, with the prompt raised. */
    private function pausedTask(): array
    {
        $conversation = Conversation::create([
            'user_id' => $this->user->id, 'server_id' => $this->server->id, 'model' => 'test-model',
            'character' => 'Clarion', 'title' => 'Already titled', 'channel' => 'web', 'is_processing' => true,
        ]);
        Message::create(['conversation_id' => $conversation->id, 'role' => 'user', 'user' => $this->user->name, 'content' => self::REQUEST]);
        $this->travel(1)->seconds();
        Message::create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'user' => 'Clarion', 'content' => 'Reading the notes.']);
        $this->travel(1)->seconds();

        $runId = app(RunTraceRecorder::class)->openRun(RunKind::Interactive, (string) $this->user->id, $conversation->id, streamed: true);
        $task = app(TaskBudgetService::class)->forNewRun($conversation, new StatedCeilings(maxSteps: 2), false, $runId);
        $gate = app(TaskBudgetGate::class);
        $gate->beforeStep($conversation, $runId);
        $gate->beforeStep($conversation, $runId);
        DB::table('task_budgets')->where('id', $task->id)->update([
            'state' => 'paused', 'paused_at' => now()->format('Y-m-d H:i:s.u'), 'pause_reason' => 'steps',
            'paused_run_id' => $runId, 'paused_iteration' => 3, 'resume_mode' => 'stream', 'paused_steps_used' => 2, 'paused_spend' => '0',
        ]);
        app(TaskBudgetPauser::class)->raisePrompt($conversation, TaskBudget::findOrFail($task->id), 'stream');
        $prompt = AgentQuestion::findOrFail(DB::table('task_budgets')->where('id', $task->id)->value('prompt_question_id'));
        $this->travel(1)->seconds();

        return [Conversation::findOrFail($conversation->id), $prompt];
    }

    private function lastUserMessage(Conversation $conversation): ?Message
    {
        $loop = app(AgentLoopService::class);

        return (new \ReflectionMethod($loop, 'getLastUserMessage'))->invoke($loop, $conversation);
    }

    #[Test]
    public function a_stored_ceiling_decision_is_not_the_users_last_request(): void
    {
        [$conversation, $prompt] = $this->pausedTask();

        $intake = app(QuestionAnswerIntake::class)->accept($conversation, (string) $this->user->id, 'continue steps=20');

        $this->assertSame(IntakeResult::ANSWERED, $intake->kind);
        $this->assertSame('task_budget', $intake->message->tool_data['origin'] ?? null, 'the stored decision is marked as the task budget\'s');
        $this->assertSame(self::REQUEST, $this->lastUserMessage($conversation)?->content, 'the routing fallback reads the original request');
    }

    #[Test]
    public function the_resumed_tasks_memory_query_is_the_original_request(): void
    {
        [$conversation] = $this->pausedTask();
        app(QuestionAnswerIntake::class)->accept($conversation, (string) $this->user->id, 'continue steps=20');

        $queries = [];
        $retriever = \Mockery::mock(AutoMemoryRetriever::class);
        $retriever->shouldReceive('isEnabled')->andReturn(true);
        $retriever->shouldReceive('retrieveWithMetrics')->andReturnUsing(function (string $turnKey, string $userId, string $agentId, string $query) use (&$queries) {
            $queries[] = $query;

            return new MemoryRetrievalResult();
        });
        $retriever->shouldReceive('formatInjectionSection')->andReturn(new MemoryInjectionSection('', 0, 0, false));
        $this->app->instance(AutoMemoryRetriever::class, $retriever);
        $this->app->forgetInstance(AgentLoopService::class);

        $loop = app(AgentLoopService::class);
        (new \ReflectionMethod($loop, 'buildAutoMemorySection'))->invoke($loop, $conversation);

        $this->assertSame([self::REQUEST], $queries);
    }

    #[Test]
    public function a_stop_typed_at_the_ceiling_is_not_the_users_last_request_either(): void
    {
        [$conversation] = $this->pausedTask();

        $intake = app(QuestionAnswerIntake::class)->accept($conversation, (string) $this->user->id, 'stop');

        $this->assertSame(IntakeResult::DECISION_RECORDED, $intake->kind);
        $this->assertSame(self::REQUEST, $this->lastUserMessage($conversation)?->content);
    }

    #[Test]
    public function an_agent_questions_answer_is_still_the_last_user_message(): void
    {
        [$conversation] = $this->pausedTask();
        $answer = Message::create([
            'conversation_id' => $conversation->id, 'role' => 'user', 'user' => $this->user->name, 'content' => 'The second option.',
            'tool_data' => ['kind' => AgentQuestionService::KIND_ANSWER, 'question_id' => 'q-1'],
        ]);

        $this->assertSame($answer->id, $this->lastUserMessage($conversation)?->id);
    }
}
