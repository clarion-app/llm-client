<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\AgentChangeSource;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\TestCase;

/**
 * Asks that must not pause are refused immediately, with a reason.
 *
 * One question may be pending per conversation at a time: a second ask in
 * the same conversation is refused as `question_already_pending` and leaves
 * the first untouched, while other conversations of the same user are
 * unaffected and a resolved question frees the slot. An ask nobody could
 * answer — a conversation with no user, an unattended run, a channel with
 * no delivery, a delegated helper running inside its caller's tool call —
 * is refused as `no_reachable_user` with a reason naming the cause; a
 * helper whose root conversation is already waiting on its user is told a
 * question is pending instead.
 *
 * Every refusal is this call's result in the same iteration: no question
 * row is written, nothing reaches the user, the run carries on, and the
 * `ask_user` action is recorded as failed with `ask refused: {code}`.
 */
class AskUserRefusalTest extends TestCase
{
    private const QUESTION = 'Which part should I change first?';

    private const CHOICES = ['Backend', 'Frontend', 'Docs'];

    private const NO_USER_REASON = 'This task has no user to ask.';

    private const UNATTENDED_REASON = 'This is an unattended run; no user is present to answer.';

    private const HELPER_REASON = 'Delegated helpers cannot pause to ask the user; return the open question to your caller in your result.';

    private User $user;

    private Server $server;

    private RecordingChannelDelivery $web;

    private RecordingChannelDelivery $telegram;

    /** Number of model requests the synchronous service made. */
    private int $chatCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->server = Server::create([
            'name' => 'Ask Refusal LLM Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);
        app(RoleAssignmentService::class)->set(ModelRole::Inference, $this->user->id, $this->server->id, 'test-model');

        config(['llm-client.run_trace.enabled' => true]);

        if (!Schema::hasTable('mcp_sessions')) {
            Schema::create('mcp_sessions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('protocol_version');
                $table->string('client_name')->nullable();
                $table->string('client_version')->nullable();
                $table->json('capabilities')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        $this->seedEmptyOperationCatalog();

        $this->web = new RecordingChannelDelivery('web');
        app(ConversationChannelRegistry::class)->register($this->web);
        $this->telegram = new RecordingChannelDelivery('telegram');
        app(ConversationChannelRegistry::class)->register($this->telegram);
    }

    protected function tearDown(): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        Mockery::close();

        foreach (['agent_questions', 'agent_delegations', 'agent_versions', 'agents', 'agent_run_actions', 'agent_run_steps', 'agent_runs', 'mcp_sessions'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
        DB::table('messages')->delete();
        DB::table('conversations')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    private function seedEmptyOperationCatalog(): void
    {
        $doc = ['paths' => []];

        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);

        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->andReturn($doc);
        $this->app->instance(Generator::class, $generator);
    }

    private function conversation(string $channel = 'telegram', array $overrides = []): Conversation
    {
        return Conversation::factory()->create($overrides + [
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => "Ask refusal on {$channel}",
            'channel' => $channel,
            'is_processing' => false,
        ]);
    }

    /**
     * A delegated helper's conversation, created as delegation creates it:
     * its own `agent-delegation` conversation, linked to its caller's by a
     * delegation row.
     */
    private function helperOf(Conversation $parent): Conversation
    {
        $helper = $this->conversation('agent-delegation', ['title' => 'Helper conversation']);

        Delegation::create([
            'parent_conversation_id' => $parent->id,
            'helper_agent_id' => (string) Str::uuid(),
            'helper_conversation_id' => $helper->id,
            'owner_user_id' => $this->user->id,
            'task' => 'Look into the project layout.',
            'depth' => 1,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        return $helper;
    }

    /**
     * The agent/version columns of a conversation bound to a resolvable
     * agent definition, as a scheduled trigger's conversation is.
     *
     * @return array{agent_id: string, agent_version_id: string}
     */
    private function boundAgentColumns(): array
    {
        $yaml = "name: tidy-agent\ninstructions: Tidy up the project.\nsafety:\n  confirmation_required: []\n  unattended_authorized: []\n";

        $agent = Agent::create(['user_id' => $this->user->id, 'name' => 'tidy-agent']);
        $version = AgentVersion::create([
            'agent_id' => $agent->id,
            'version_number' => 1,
            'raw_definition' => $yaml,
            'content_hash' => hash('sha256', $yaml),
            'source' => AgentChangeSource::Created->value,
            'changed_by_user_id' => $this->user->id,
        ]);
        $agent->update(['current_version_id' => $version->id]);

        return ['agent_id' => $agent->id, 'agent_version_id' => $version->id];
    }

    /**
     * A question already waiting on its user in $conversation, as a row
     * holding the conversation's pending slot.
     */
    private function pendingQuestionIn(Conversation $conversation, string $state = 'pending'): AgentQuestion
    {
        $askedAt = now()->subMinutes(10);
        $pending = $state === QuestionState::Pending->value;

        $paused = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
            'tool_data' => ['tool_calls' => [$this->askCall('call_original')]],
        ]);
        $display = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => 'Should I keep the old layout?',
            'responseTime' => 0,
        ]);

        $id = (string) Str::uuid();
        DB::table('agent_questions')->insert([
            'id' => $id,
            'conversation_id' => $conversation->id,
            'user_id' => $conversation->user_id,
            'pending_conversation_id' => $pending ? $conversation->id : null,
            'run_id' => null,
            'step_id' => null,
            'action_id' => null,
            'paused_message_id' => $paused->id,
            'display_message_id' => $display->id,
            'tool_call_id' => 'call_original',
            'channel' => (string) $conversation->channel,
            'resume_mode' => $conversation->channel === 'web' ? 'stream' : 'sync',
            'question' => 'Should I keep the old layout?',
            'choices' => json_encode([]),
            'allow_multiple' => false,
            'state' => $state,
            'asked_at' => $askedAt,
            'expires_at' => $askedAt->copy()->addMinutes(1440),
            'resolved_at' => $pending ? null : now()->subMinutes(5),
            'resumed_at' => $pending ? null : now()->subMinutes(5),
            'created_at' => $askedAt,
            'updated_at' => $askedAt,
        ]);

        return AgentQuestion::findOrFail($id);
    }

    private function toolCall(string $id, string $name, array $arguments): array
    {
        return [
            'id' => $id,
            'type' => 'function',
            'function' => ['name' => $name, 'arguments' => json_encode($arguments)],
        ];
    }

    private function askCall(string $id = 'call_ask', array $arguments = []): array
    {
        return $this->toolCall($id, 'ask_user', $arguments + ['question' => self::QUESTION, 'choices' => self::CHOICES]);
    }

    private function toolCallReply(array $calls, string $content = ''): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => $content, 'tool_calls' => $calls]]]];
    }

    private function textReply(string $content): array
    {
        return [
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content, 'tool_calls' => []]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
        ];
    }

    /** A synchronous service whose model replies are scripted and counted. */
    private function syncService(array $responses): AgentLoopService
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function () use (&$responses) {
            $this->chatCalls++;

            return array_shift($responses) ?? $this->textReply('Done.');
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        $service = new AgentLoopService(
            app(McpToolRegistry::class),
            new McpToolExecutor(app(McpToolRegistry::class), null, fn ($user) => 'test-token'),
            app(OperationCache::class),
            $registry,
            runTraceRecorder: app(RunTraceRecorder::class),
        );
        $this->app->instance(AgentLoopService::class, $service);

        return $service;
    }

    /**
     * Run one synchronous task whose first model turn is a single ask, and
     * whose second turn (the model reacting to the refusal) is a final
     * answer.
     */
    private function runAsking(Conversation $conversation, array $askArguments = [], array $options = []): array
    {
        $service = $this->syncService([
            $this->toolCallReply([$this->askCall('call_ask', $askArguments)]),
            $this->textReply('I will carry on without asking.'),
        ]);

        return $service->run($conversation->fresh(), 'Help me tidy up the project.', $options);
    }

    /** Drive one streamed model turn carrying $toolCalls through the real handler. */
    private function streamTurn(Conversation $conversation, array $toolCalls): Message
    {
        Queue::fake();
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            ApiCallConfirmationRequiredEvent::class,
        ]);

        $this->app->instance(AgentLoopService::class, new AgentLoopService(
            app(McpToolRegistry::class),
            new McpToolExecutor(app(McpToolRegistry::class), null, fn ($user) => 'test-token'),
            new OperationCache(),
            app(ProviderRegistry::class),
        ));

        $conversation->update(['is_processing' => true]);

        $handler = new AgentLoopStreamHandler();
        $handler->toolCalls = $toolCalls;
        $handler->message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);

        $handler->finish(json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
        ]), 2);

        return $handler->message->fresh();
    }

    private function refusal(string $code, string $reason): array
    {
        return ['outcome' => 'refused', 'code' => $code, 'reason' => $reason];
    }

    /** The `ask_user` call's stored result in $conversation's completed turn. */
    private function storedAskResult(Conversation $conversation, string $toolCallId = 'call_ask'): ?array
    {
        $turns = Message::where('conversation_id', $conversation->id)->where('role', 'assistant')->get();

        foreach ($turns as $turn) {
            foreach ((array) ($turn->tool_data['tool_results'] ?? []) as $result) {
                if (($result['tool_call_id'] ?? null) === $toolCallId) {
                    return json_decode((string) ($result['content'] ?? ''), true);
                }
            }
        }

        return null;
    }

    /**
     * The refused ask went back to the model and the run carried on: no
     * question row, nothing delivered, no paused turn left behind, and the
     * action recorded as a refused ask.
     */
    private function assertRefusedAndContinued(array $result, Conversation $conversation, array $expectedRefusal, int $questionsBefore = 0): void
    {
        $this->assertSame('completed', $result['status'] ?? null, 'a refused ask does not pause; got: '.json_encode($result));
        $this->assertSame(2, $this->chatCalls, 'the refusal goes back to the model in the next request of the same run');
        $this->assertSame($expectedRefusal, $this->storedAskResult($conversation), 'the refusal is the ask_user call\'s result');

        $this->assertSame($questionsBefore, AgentQuestion::count(), 'a refused ask writes no question');
        $this->assertSame([], $this->telegram->textsFor($conversation->id), 'nothing is shown to the user for a refused ask');
        $this->assertSame([], $this->web->textsFor($conversation->id), 'nothing is shown to the user for a refused ask');
        $this->assertSame(
            0,
            Message::where('conversation_id', $conversation->id)->get()->filter(fn (Message $m) => isset($m->tool_data['pause_kind']))->count(),
            'no paused turn is left behind',
        );

        $this->assertRefusedAction($conversation, $expectedRefusal['code']);
    }

    private function assertRefusedAction(Conversation $conversation, string $code): void
    {
        $runIds = DB::table('agent_runs')->where('conversation_id', $conversation->id)->pluck('id');
        $this->assertNotEmpty($runIds, 'the refused ask belongs to a recorded run');

        $actions = DB::table('agent_run_actions')->whereIn('run_id', $runIds)->where('target', 'ask_user')->get();
        $this->assertCount(1, $actions, 'the ask_user call is recorded once');
        $this->assertSame('failure', $actions[0]->outcome, 'a refused ask is recorded as failed');
        $this->assertSame("ask refused: {$code}", $actions[0]->failure_reason);
    }

    // ---------------------------------------------------------------
    // One question at a time
    // ---------------------------------------------------------------

    #[Test]
    public function a_second_ask_while_one_is_pending_is_refused_and_leaves_the_first_untouched(): void
    {
        $conversation = $this->conversation('telegram');
        $original = $this->pendingQuestionIn($conversation);
        $before = (array) DB::table('agent_questions')->where('id', $original->id)->first();
        $messagesBefore = Message::where('conversation_id', $conversation->id)->orderBy('created_at')->pluck('content', 'id')->all();

        $result = $this->runAsking($conversation);

        $this->assertRefusedAndContinued(
            $result,
            $conversation,
            $this->refusal('question_already_pending', "A question is already waiting for the user's answer in this conversation. Wait for it to be resolved before asking another."),
            questionsBefore: 1,
        );

        $this->assertSame($before, (array) DB::table('agent_questions')->where('id', $original->id)->first(), 'the pending question is not altered');
        $this->assertSame([], $this->telegram->deliveries, 'the pending question is not re-sent');
        foreach ($messagesBefore as $id => $content) {
            $this->assertSame($content, Message::find($id)?->content, 'the user still sees only the original question');
        }
    }

    #[Test]
    public function a_second_streamed_ask_while_one_is_pending_is_refused(): void
    {
        $conversation = $this->conversation('web');
        $original = $this->pendingQuestionIn($conversation);
        $before = (array) DB::table('agent_questions')->where('id', $original->id)->first();

        $message = $this->streamTurn($conversation, [$this->askCall()]);

        $this->assertArrayNotHasKey('pause_kind', (array) $message->tool_data, 'a refused ask does not pause the turn');
        $this->assertSame(
            $this->refusal('question_already_pending', "A question is already waiting for the user's answer in this conversation. Wait for it to be resolved before asking another."),
            json_decode((string) ($message->tool_data['tool_results'][0]['content'] ?? ''), true),
        );
        Queue::assertPushed(AgentStreamStepJob::class, 1);

        $this->assertSame(1, AgentQuestion::count());
        $this->assertSame($before, (array) DB::table('agent_questions')->where('id', $original->id)->first());
        $this->assertSame([], $this->web->deliveries);
        $this->assertRefusedAction($conversation, 'question_already_pending');
    }

    #[Test]
    public function an_ask_in_another_conversation_of_the_same_user_is_accepted(): void
    {
        $waiting = $this->conversation('telegram', ['title' => 'Waiting conversation']);
        $original = $this->pendingQuestionIn($waiting);

        $other = $this->conversation('telegram', ['title' => 'Another conversation']);
        $service = $this->syncService([$this->toolCallReply([$this->askCall()])]);

        $result = $service->run($other->fresh(), 'Help me tidy up the project.');

        $this->assertSame('awaiting_user', $result['status'] ?? null, 'the limit is per conversation; got: '.json_encode($result));
        $asked = AgentQuestion::findOrFail($result['question_id'] ?? null);
        $this->assertSame($other->id, $asked->conversation_id);
        $this->assertSame(QuestionState::Pending, $asked->state);
        $this->assertSame(QuestionState::Pending, $original->fresh()->state, 'the other conversation\'s question is unaffected');
        $this->assertSame($waiting->id, $original->fresh()->pending_conversation_id);
        $this->assertCount(1, $this->telegram->textsFor($other->id));
    }

    /** @return array<string, array{string}> */
    public static function resolvedStates(): array
    {
        return [
            'answered' => ['answered'],
            'expired' => ['expired'],
            'withdrawn' => ['withdrawn'],
        ];
    }

    #[Test]
    #[DataProvider('resolvedStates')]
    public function an_ask_after_the_previous_question_was_resolved_is_accepted(string $state): void
    {
        $conversation = $this->conversation('telegram');
        $previous = $this->pendingQuestionIn($conversation, $state);
        $service = $this->syncService([$this->toolCallReply([$this->askCall()])]);

        $result = $service->run($conversation->fresh(), 'Help me tidy up the project.');

        $this->assertSame('awaiting_user', $result['status'] ?? null, "a {$state} question frees the slot; got: ".json_encode($result));
        $asked = AgentQuestion::findOrFail($result['question_id'] ?? null);
        $this->assertNotSame($previous->id, $asked->id);
        $this->assertSame(QuestionState::Pending, $asked->state);
        $this->assertSame($conversation->id, $asked->pending_conversation_id);
        $this->assertSame($state, $previous->fresh()->state->value);
    }

    // ---------------------------------------------------------------
    // Nobody to ask
    // ---------------------------------------------------------------

    #[Test]
    public function an_ask_in_a_conversation_with_no_user_is_refused(): void
    {
        $conversation = $this->conversation('web', ['user_id' => null, 'title' => 'System conversation']);

        $result = $this->runAsking($conversation);

        $this->assertRefusedAndContinued($result, $conversation, $this->refusal('no_reachable_user', self::NO_USER_REASON));
    }

    #[Test]
    public function a_streamed_ask_in_a_conversation_with_no_user_is_refused(): void
    {
        $conversation = $this->conversation('web', ['user_id' => null, 'title' => 'System conversation']);

        $message = $this->streamTurn($conversation, [$this->askCall()]);

        $this->assertArrayNotHasKey('pause_kind', (array) $message->tool_data, 'a refused ask does not pause the turn');
        $this->assertSame(
            $this->refusal('no_reachable_user', self::NO_USER_REASON),
            json_decode((string) ($message->tool_data['tool_results'][0]['content'] ?? ''), true),
        );
        Queue::assertPushed(AgentStreamStepJob::class, 1);
        $this->assertSame(0, AgentQuestion::count());
        $this->assertSame([], $this->web->deliveries);
    }

    #[Test]
    public function an_ask_in_an_unattended_run_is_refused(): void
    {
        // A scheduled run: unattended, on a conversation bound to the agent
        // definition it runs, on a channel that could otherwise deliver.
        $conversation = $this->conversation('telegram', $this->boundAgentColumns());

        $result = $this->runAsking($conversation, [], ['unattended' => true]);

        $this->assertRefusedAndContinued($result, $conversation, $this->refusal('no_reachable_user', self::UNATTENDED_REASON));
    }

    #[Test]
    public function an_ask_on_a_channel_with_no_delivery_is_refused(): void
    {
        $conversation = $this->conversation('managed-task');
        $this->assertFalse(app(ConversationChannelRegistry::class)->has('managed-task'), 'precondition: nothing delivers on this channel');

        $result = $this->runAsking($conversation);

        $this->assertRefusedAndContinued(
            $result,
            $conversation,
            $this->refusal('no_reachable_user', "Questions cannot be delivered on the 'managed-task' channel."),
        );
    }

    #[Test]
    public function an_ask_from_a_delegated_helper_is_refused(): void
    {
        $root = $this->conversation('web', ['title' => 'Root conversation']);
        $helper = $this->helperOf($root);

        $result = $this->runAsking($helper);

        $this->assertRefusedAndContinued($result, $helper, $this->refusal('no_reachable_user', self::HELPER_REASON));
        $this->assertSame([], $this->web->textsFor($root->id), 'nothing reaches the root conversation either');
    }

    #[Test]
    public function a_helper_whose_root_conversation_has_a_pending_question_is_told_one_is_pending(): void
    {
        $root = $this->conversation('web', ['title' => 'Root conversation']);
        $original = $this->pendingQuestionIn($root);
        $before = (array) DB::table('agent_questions')->where('id', $original->id)->first();

        // Two levels deep: the walk goes all the way to the root.
        $helper = $this->helperOf($this->helperOf($root));

        $result = $this->runAsking($helper);

        $this->assertRefusedAndContinued(
            $result,
            $helper,
            $this->refusal('question_already_pending', "A question is already waiting for the user's answer in this conversation. Wait for it to be resolved before asking another."),
            questionsBefore: 1,
        );
        $this->assertSame($before, (array) DB::table('agent_questions')->where('id', $original->id)->first(), 'the root\'s question is not altered');
        $this->assertSame([], $this->web->deliveries, 'the root\'s question is not re-sent');
    }

    // ---------------------------------------------------------------
    // Invalid questions
    // ---------------------------------------------------------------

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidAsks(): array
    {
        return [
            'empty question' => [['question' => '   ', 'choices' => []], 'question text is empty'],
            'missing question' => [['question' => null, 'choices' => []], 'question text is empty'],
            'question too long' => [['question' => str_repeat('a', 4001), 'choices' => []], 'question text exceeds 4000 characters'],
            'choices not a list' => [['choices' => ['a' => 'Backend']], 'choices must be a list of strings'],
            'choice not a string' => [['choices' => ['Backend', 2]], 'choices must be a list of strings'],
            'too many choices' => [['choices' => array_map(fn (int $n) => "Option {$n}", range(1, 11))], 'more than 10 choices'],
            'empty choice' => [['choices' => ['Backend', '  ']], 'choice 2 is empty'],
            'choice too long' => [['choices' => [str_repeat('b', 501)]], 'choice 1 exceeds 500 characters'],
            'duplicate choices' => [['choices' => ['Backend', 'Docs', 'backend']], 'choices 1 and 3 are duplicates'],
            'multiple without choices' => [['choices' => [], 'allow_multiple' => true], 'allow_multiple requires at least one choice'],
        ];
    }

    #[Test]
    #[DataProvider('invalidAsks')]
    public function an_invalid_question_is_refused_with_the_validators_reason(array $askArguments, string $reason): void
    {
        $conversation = $this->conversation('telegram');

        $result = $this->runAsking($conversation, $askArguments);

        $this->assertRefusedAndContinued($result, $conversation, $this->refusal('invalid_question', $reason));
    }

    // ---------------------------------------------------------------
    // What happens if nobody answers: malformed or contradictory asks
    // ---------------------------------------------------------------

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function refusedPolicyAsks(): array
    {
        return [
            'proceed with no assumption' => [
                ['if_unanswered' => 'proceed_on_assumption'],
                'if_unanswered is "proceed_on_assumption" but no assumed answer was given',
            ],
            'stop with an assumption' => [
                ['if_unanswered' => 'stop', 'assumed_choices' => [1]],
                'if_unanswered is "stop" but an assumed answer was given; a question that stops when unanswered cannot also assume an answer',
            ],
            'consequential with an assumed choice' => [
                ['consequential' => true, 'assumed_choices' => [1]],
                'a consequential question cannot proceed on an assumed answer; it always stops until the user answers',
            ],
            'consequential with the proceed policy and assumed text' => [
                ['consequential' => true, 'if_unanswered' => 'proceed_on_assumption', 'assumed_text' => 'Backend'],
                'a consequential question cannot proceed on an assumed answer; it always stops until the user answers',
            ],
            'assumed choice out of range' => [
                ['assumed_choices' => [4]],
                'assumed choice 4 does not exist (the question has 3 choices)',
            ],
            'two assumed choices on a single-selection question' => [
                ['assumed_choices' => [1, 2]],
                'a single-selection question can assume only one choice',
            ],
            'a wait that is not a whole number' => [
                ['wait_minutes' => 'ninety'],
                'wait_minutes must be a whole number of minutes',
            ],
        ];
    }

    /**
     * The refused ask's run-record content carries the policy arguments
     * exactly as the ask sent them, and none it did not send.
     */
    private function assertRefusedSnapshotCarries(Conversation $conversation, array $askArguments): void
    {
        $runIds = DB::table('agent_runs')->where('conversation_id', $conversation->id)->pluck('id');
        $content = DB::table('agent_run_actions')->whereIn('run_id', $runIds)->where('target', 'ask_user')->value('content');
        $snapshot = json_decode((string) $content, true);
        $this->assertIsArray($snapshot, 'the refused ask is recorded with its snapshot');

        $sent = ['if_unanswered', 'assumed_choices', 'assumed_text', 'consequential', 'wait_minutes'];
        foreach ($sent as $key) {
            if (array_key_exists($key, $askArguments)) {
                $this->assertArrayHasKey($key, $snapshot, "the {$key} the ask sent is recorded");
                $this->assertSame($askArguments[$key], $snapshot[$key], "{$key} is recorded as given");
            } else {
                $this->assertArrayNotHasKey($key, $snapshot, "the ask did not send {$key}, so it is not recorded");
            }
        }
        $this->assertSame(self::QUESTION, $snapshot['question'] ?? null);
        $this->assertArrayHasKey('question_id', $snapshot);
        $this->assertNull($snapshot['question_id'], 'no question was written');
        $this->assertSame('refused:invalid_question', $snapshot['resolution'] ?? null);
    }

    #[Test]
    #[DataProvider('refusedPolicyAsks')]
    public function a_malformed_policy_ask_is_refused_before_anything_is_written_or_shown(array $askArguments, string $reason): void
    {
        $conversation = $this->conversation('telegram');

        $result = $this->runAsking($conversation, $askArguments);

        $this->assertRefusedAndContinued($result, $conversation, $this->refusal('invalid_question', $reason));
        $this->assertSame([], $this->telegram->deliveries, 'no delivery of any kind');
        $this->assertSame([], $this->web->deliveries, 'no delivery of any kind');
        $this->assertRefusedSnapshotCarries($conversation, $askArguments);
    }

    #[Test]
    #[DataProvider('refusedPolicyAsks')]
    public function a_malformed_policy_ask_is_refused_on_the_streaming_path_too(array $askArguments, string $reason): void
    {
        $conversation = $this->conversation('web');

        $message = $this->streamTurn($conversation, [$this->askCall('call_ask', $askArguments)]);

        $this->assertArrayNotHasKey('pause_kind', (array) $message->tool_data, 'a refused ask does not pause the turn');
        $this->assertSame(
            $this->refusal('invalid_question', $reason),
            json_decode((string) ($message->tool_data['tool_results'][0]['content'] ?? ''), true),
            'the refusal is the call\'s result in the same iteration',
        );
        Queue::assertPushed(AgentStreamStepJob::class, 1);
        $this->assertSame(0, AgentQuestion::count(), 'a refused ask writes no question');
        $this->assertSame(
            1,
            Message::where('conversation_id', $conversation->id)->count(),
            'no display message: the paused-turn message is the only one',
        );
        $this->assertSame([], $this->web->deliveries);
        $this->assertSame([], $this->telegram->deliveries);
        $this->assertRefusedAction($conversation, 'invalid_question');
        $this->assertRefusedSnapshotCarries($conversation, $askArguments);
    }
}
