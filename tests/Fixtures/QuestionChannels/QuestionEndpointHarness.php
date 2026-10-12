<?php

namespace Tests\Fixtures\QuestionChannels;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\AskUserRequest;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\OpenedQuestion;
use ClarionApp\LlmClient\ValueObjects\OpenQuestionRequest;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;

/**
 * What a Feature test needs to drive the real question endpoints
 * (`POST message`, `POST agent`) and to open real questions: the package
 * tables a streamed or synchronous turn reads, an inference model for the
 * users, a stubbed API-docs generator, and an agent loop whose model
 * replies are scripted in `$scriptedReplies` (consumed in order; "Done."
 * once exhausted).
 *
 * Call setUpQuestionEndpoints() from setUp() and
 * tearDownQuestionEndpoints() from tearDown().
 */
trait QuestionEndpointHarness
{
    protected Server $questionServer;

    /** @var list<array<string, mixed>> */
    protected array $scriptedReplies = [];

    protected int $scriptedChatCalls = 0;

    /** @param list<User> $users users who may start runs */
    protected function setUpQuestionEndpoints(array $users): void
    {
        $this->questionServer = Server::create([
            'name' => 'Question LLM Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);
        foreach ($users as $user) {
            app(RoleAssignmentService::class)->set(ModelRole::Inference, $user->id, $this->questionServer->id, 'test-model');
        }

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
        if (!Schema::hasTable('chunk_summaries')) {
            Schema::create('chunk_summaries', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->index();
                $table->unsignedInteger('chunk_index');
                $table->string('source_hash', 64);
                $table->unsignedInteger('source_message_count');
                $table->json('summary');
                $table->unsignedInteger('summary_tokens')->nullable();
                $table->string('condensation_model')->nullable();
                $table->string('condensation_provider')->nullable();
                $table->timestamps();

                $table->unique(['conversation_id', 'chunk_index']);
            });
        }
        if (!Schema::hasTable('condensation_states')) {
            Schema::create('condensation_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            });
        }

        $doc = ['paths' => []];
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);
        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->andReturn($doc);
        $this->app->instance(Generator::class, $generator);

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function () {
            $this->scriptedChatCalls++;

            return array_shift($this->scriptedReplies) ?? $this->scriptedTextReply('Done.');
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        $this->app->instance(AgentLoopService::class, new AgentLoopService(
            app(McpToolRegistry::class),
            new McpToolExecutor(app(McpToolRegistry::class), null, fn ($user) => 'test-token'),
            app(OperationCache::class),
            $registry,
        ));
    }

    protected function tearDownQuestionEndpoints(): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        Mockery::close();

        foreach (['agent_questions', 'agent_run_actions', 'agent_run_steps', 'agent_runs', 'mcp_sessions', 'chunk_summaries', 'condensation_states'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
        DB::table('messages')->delete();
        DB::table('conversations')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();
    }

    /** @return array<string, mixed> a model reply that ends the turn with $content */
    protected function scriptedTextReply(string $content): array
    {
        return [
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content, 'tool_calls' => []]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
        ];
    }

    protected function questionConversation(User $owner, string $channel): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $owner->id,
            'server_id' => $this->questionServer->id,
            'model' => 'test-model',
            'title' => 'Question conversation',
            'channel' => $channel,
            'is_processing' => false,
        ]);
    }

    /**
     * Open a pending question in $conversation the way a pause site does:
     * a traced run with its `ask_user` action, the paused assistant message
     * carrying the call, then AgentQuestionService::open().
     *
     * @param  list<string>  $choices
     */
    protected function openQuestion(
        Conversation $conversation,
        string $resumeMode = OpenQuestionRequest::RESUME_STREAM,
        array $choices = ['Backend', 'Frontend', 'Docs'],
        bool $allowMultiple = false,
        ?string $preamble = null,
        string $question = 'Which part should I change first?',
        ?AskUserRequest $request = null,
    ): OpenedQuestion {
        $conversation->update(['is_processing' => true]);

        $recorder = app(RunTraceRecorder::class);
        $runId = $recorder->openRun(
            RunKind::Interactive,
            (string) $conversation->user_id,
            $conversation->id,
            streamed: $resumeMode === OpenQuestionRequest::RESUME_STREAM,
        );
        $stepId = $recorder->openStep($runId);
        $actionId = $recorder->openAction($stepId, ActionType::ToolInvocation, 'ask_user');

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'User',
            'content' => 'Help me tidy up the project.',
            'responseTime' => 0,
        ]);
        $arguments = ['question' => $question] + ($choices === [] ? [] : ['choices' => $choices, 'allow_multiple' => $allowMultiple]);
        $paused = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => $resumeMode === OpenQuestionRequest::RESUME_STREAM ? (string) $preamble : '',
            'responseTime' => 0,
            'tool_data' => ['tool_calls' => [[
                'id' => 'call_ask',
                'type' => 'function',
                'function' => ['name' => 'ask_user', 'arguments' => json_encode($arguments)],
            ]]],
        ]);

        $opened = app(AgentQuestionService::class)->open(new OpenQuestionRequest(
            conversation: $conversation->fresh(),
            // A caller-built request carries its own unanswered policy;
            // otherwise the ask names none.
            request: $request ?? new AskUserRequest($question, $choices, $allowMultiple),
            runId: $runId,
            stepId: $stepId,
            actionId: $actionId,
            pausedMessage: $paused,
            toolCallId: 'call_ask',
            resumeMode: $resumeMode,
            preamble: $preamble,
            pendingToolResults: [],
            iteration: 1,
        ));

        $this->assertInstanceOf(OpenedQuestion::class, $opened, 'precondition: the question opened');

        return $opened;
    }

    protected function pendingQuestionIn(Conversation $conversation, string $resumeMode = OpenQuestionRequest::RESUME_STREAM, array $choices = ['Backend', 'Frontend', 'Docs'], bool $allowMultiple = false): AgentQuestion
    {
        return AgentQuestion::findOrFail($this->openQuestion($conversation, $resumeMode, $choices, $allowMultiple)->question->id);
    }
}
