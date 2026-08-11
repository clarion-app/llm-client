<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Jobs\RunEvalCaseJob;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\EvalCaseExecutor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * research.md D15 — US2's centerpiece. Drives a real, destructive-shaped
 * action_taken case (and its action_not_taken companion) end-to-end
 * through the *real* production code path — the real, genuinely-queued
 * RunEvalCaseJob, the real EvalCaseExecutor, the real AgentLoopService,
 * the real McpToolExecutor. Queue::fake() is deliberately never used
 * (D15's own explicit rationale): the point is proving the real code path
 * never reaches a real HTTP call, not mocking that path away. Only the
 * literal "a worker process pulls the job off a queue" mechanic is
 * skipped — Bus::fake() captures the dispatch and this file's own
 * driveDispatchedCaseJobsToCompletion() then calls the job's real
 * handle() method directly, the RunSuiteJourneyTest/T020 precedent.
 *
 * Anchors quickstart.md's mutation-testing checklist rows 3 and 4 — row 4
 * ("AgentLoopService::executeApiCall()'s simulation branch dropped") is,
 * per this file's own task description, the row that matters most, since
 * execute_operation is the path every ordinary chat turn — and therefore
 * every eval-run case — actually uses.
 */
class EvaluationIsolationJourneyTest extends TestCase
{
    private User $operator;
    private User $realTestUser;
    private Server $server;
    private string $suiteId;

    /** @var array<string, string> expectation-kind => eval_case id */
    private array $caseIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareSupportingSchema();

        $this->operator = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        // A real, ordinary user with a real conversation of their own —
        // the FR-015 half of D15: this user's own data must be provably
        // untouched by a run that never involves them at all.
        $this->realTestUser = User::factory()->create();

        $this->server = Server::create([
            'name' => 'Isolation journey test server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->server->id,
            'model' => 'test-model',
        ]);

        $this->fakeProvider();
        $this->seedApiDocsCache();

        $this->suiteId = $this->createSuiteWithDestructiveActionCases();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->seedApiDocsCache(null);

        DB::table('episodic_memories')->delete();
        DB::table('feedback_signals')->delete();
        DB::table('declarative_memories')->delete();
        DB::table('tool_invocation_records')->delete();
        DB::table('messages')->delete();
        DB::table('conversations')->delete();
        DB::table('eval_case_results')->delete();
        DB::table('eval_run_cases')->delete();
        DB::table('eval_runs')->delete();
        DB::table('eval_case_versions')->delete();
        DB::table('eval_cases')->delete();
        DB::table('eval_suites')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixture helpers
    // ---------------------------------------------------------------

    private function suitesBase(): string
    {
        return '/api/clarion-app/llm-client/agent-eval-suites';
    }

    private function runsBase(): string
    {
        return '/api/clarion-app/llm-client/eval-runs';
    }

    private function declareSupportingSchema(): void
    {
        // AgentLoopService::run() consults ConversationCondenser on every
        // call, unconditionally — the RunSuiteJourneyTest precedent.
        if (!Schema::hasTable('condensation_states')) {
            Schema::create('condensation_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            });
        }

        // The action_taken/action_not_taken cases drive a real
        // execute_operation call, which opens an MCP session
        // (AgentLoopService::getOrCreateSession()) — user_id must be
        // nullable to match the real migrated column for a user_id = null
        // eval-run conversation (this feature's own Phase 3 production
        // fix; the hand-declared test schema doesn't cover this table at
        // all, so every test driving a real tool call through a
        // system-owned conversation must declare it itself).
        if (!Schema::hasTable('mcp_sessions')) {
            Schema::create('mcp_sessions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id')->nullable();
                $table->string('protocol_version');
                $table->string('client_name')->nullable();
                $table->string('client_version')->nullable();
                $table->json('capabilities')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('user_id');
            });
        }

        // Not touched by any code path this test exercises, but this
        // file's own no-contamination assertion reads them with a broad,
        // unscoped count — the tables must exist for that query to run at
        // all, and their absence must never be mistaken for "definitely
        // zero rows".
        if (!Schema::hasTable('episodic_memories')) {
            Schema::create('episodic_memories', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->uuid('conversation_id');
                $table->text('summary');
                $table->json('topics');
                $table->boolean('protected')->default(false);
                $table->unsignedInteger('word_count');
                $table->unsignedInteger('summary_word_count');
                $table->json('embedding')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('feedback_signals')) {
            Schema::create('feedback_signals', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->uuid('source_event_id')->nullable();
                $table->uuid('conversation_id')->nullable();
                $table->string('signal_type');
                $table->string('pattern_key')->nullable();
                $table->text('raw_context');
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->softDeletes();
            });
        }
    }

    /**
     * Seeds ApiManager's real static $apiDocsCache by reflection so the
     * real execute_operation -> ApiManager::getOperationDetails() lookup
     * resolves 'contacts.create' — the ApiCallValidatorTest/
     * RunSuiteJourneyTest precedent for the identical seam; null clears
     * it in tearDown so it cannot leak into a later test.
     */
    private function seedApiDocsCache(?array $doc = ['paths' => ['/api/contacts' => ['post' => ['operationId' => 'contacts.create']]]]): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);
    }

    /**
     * A real, destructive-shaped action (mirroring 077's own
     * contacts.create export fixture) with a companion action_not_taken
     * case naming the identical action.
     */
    private function createSuiteWithDestructiveActionCases(): string
    {
        $suite = $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => 'Isolation journey fixture suite',
            'agent_identifier' => 'home-automation-agent',
        ])->assertStatus(200)->json();

        $cases = [
            'action_taken' => [
                'given' => 'Create a contact named Alice.',
                'expected_behavior' => 'Create the contact via the contacts.create operation.',
                'expectations' => [['kind' => 'action_taken', 'action' => 'contacts.create']],
            ],
            'action_not_taken' => [
                'given' => "Just say hello, don't create anything.",
                'expected_behavior' => 'Greet the user without creating a contact.',
                'expectations' => [['kind' => 'action_not_taken', 'action' => 'contacts.create']],
            ],
        ];

        foreach ($cases as $kind => $payload) {
            $case = $this->actingAs($this->operator)
                ->postJson($this->suitesBase().'/'.$suite['id'].'/cases', $payload)
                ->assertStatus(200)->json();
            $this->caseIds[$kind] = $case['id'];
        }

        return $suite['id'];
    }

    private function fakeProvider(): void
    {
        Http::fake();

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function (array $messages, array $tools = [], array $options = []) {
            $firstUser = collect($messages)->firstWhere('role', 'user')['content'] ?? '';
            $alreadyHasToolResult = collect($messages)->contains(fn ($m) => ($m['role'] ?? null) === 'tool');

            if (str_contains($firstUser, 'Create a contact named Alice')) {
                if (!$alreadyHasToolResult) {
                    return $this->toolCallChatResponse('call_alice_1', 'contacts.create', ['body' => ['name' => 'Alice']]);
                }

                return $this->textChatResponse("I've created a contact named Alice.");
            }

            if (str_contains($firstUser, "don't create anything")) {
                return $this->textChatResponse('Hello! I have not created anything.');
            }

            return $this->textChatResponse('Acknowledged.');
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);
        $this->app->instance(ProviderRegistry::class, $registry);
    }

    private function textChatResponse(string $content): array
    {
        return [
            'choices' => [['message' => ['content' => $content]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];
    }

    private function toolCallChatResponse(string $callId, string $operationId, array $parameters): array
    {
        return [
            'choices' => [[
                'message' => [
                    'content' => '',
                    'tool_calls' => [[
                        'id' => $callId,
                        'type' => 'function',
                        'function' => [
                            'name' => 'execute_operation',
                            'arguments' => json_encode(['operationId' => $operationId, 'parameters' => $parameters]),
                        ],
                    ]],
                ],
            ]],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8, 'total_tokens' => 20],
        ];
    }

    /**
     * Starts a run with dispatch captured (not executed) by Bus::fake(),
     * then hands every captured job's real handle() method — the "real
     * queue-synchronous test harness" D15 calls for, not Queue::fake(),
     * which would prove nothing about the real job actually running.
     */
    private function startRun(string $suiteId): array
    {
        Bus::fake([RunEvalCaseJob::class]);

        return $this->actingAs($this->operator)
            ->postJson($this->suitesBase().'/'.$suiteId.'/runs')
            ->assertStatus(201)
            ->json();
    }

    private function driveDispatchedCaseJobsToCompletion(): void
    {
        foreach (Bus::dispatched(RunEvalCaseJob::class) as $job) {
            $job->handle(app(EvalCaseExecutor::class));
        }
    }

    private function getRunCases(string $runId): array
    {
        return $this->actingAs($this->operator)->getJson($this->runsBase().'/'.$runId.'/cases')->assertStatus(200)->json();
    }

    // ---------------------------------------------------------------
    // The centerpiece assertion: zero real HTTP requests to the
    // simulated operation's URL (mutation-checklist rows 3 and 4)
    // ---------------------------------------------------------------

    #[Test]
    public function no_real_http_request_ever_reaches_the_simulated_operations_url(): void
    {
        $started = $this->startRun($this->suiteId);
        $this->driveDispatchedCaseJobsToCompletion();

        // Belt: nothing at all was sent over the wire during this run.
        Http::assertNothingSent();

        // Suspenders: scoped specifically to the tool's own base path, so
        // a future, unrelated real HTTP call added elsewhere in this test
        // file could never mask a regression here.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/contacts'));

        // The run must still have completed — isolation must not come at
        // the cost of the case never finishing.
        $cases = $this->getRunCases($started['id']);
        $this->assertCount(2, $cases['data']);
    }

    /**
     * The genuinely discriminating half of this file's centerpiece
     * assertion. Http::assertNothingSent() alone cannot tell "isolated"
     * apart from "structurally incapable of making the call regardless"
     * — a null-user eval-run Conversation has no real User to mint an API
     * token for, so McpToolExecutor::executeHttpCall()'s own
     * `User::find($session->user_id)` guard already returns
     * "Error: Session user not found" *before* any Http::fake()-visible
     * request would be attempted, today, with zero code from this
     * feature involved. That accidentally satisfies "no real HTTP call",
     * but not FR-016/D4's actual requirement: a *plausible simulated
     * result* the agent can keep reasoning from, not a raw error string
     * fed back into its own transcript. This is exactly the gap
     * ToolResponseSimulator (T041) and AgentLoopService::executeApiCall()
     * 's simulation branch (T043) exist to close — asserted here as a
     * concrete, reproducible pre/post-Implementation content check, not
     * by network-call absence alone.
     */
    #[Test]
    public function the_tool_result_fed_back_to_the_agent_is_a_plausible_simulated_success_not_a_raw_error(): void
    {
        $started = $this->startRun($this->suiteId);
        $this->driveDispatchedCaseJobsToCompletion();

        $result = EvalCaseResult::where('run_id', $started['id'])
            ->where('eval_case_id', $this->caseIds['action_taken'])
            ->firstOrFail();

        $toolResultMessage = Message::where('conversation_id', $result->conversation_id)
            ->whereNotNull('tool_data')
            ->get()
            ->first(fn (Message $message) => !empty($message->tool_data['tool_results'] ?? []));

        $this->assertNotNull($toolResultMessage, 'the tool call must have produced a recorded tool result to judge');

        $toolResultContent = $toolResultMessage->tool_data['tool_results'][0]['content'] ?? null;

        $this->assertStringNotContainsString(
            'Error:',
            (string) $toolResultContent,
            'the agent must never see a raw failure string in place of a plausible simulated result (research.md D4)',
        );

        $decoded = json_decode((string) $toolResultContent, true);
        $this->assertIsArray($decoded, 'the simulated result must be JSON-decodable, per ToolResponseSimulator (D4)');
        $this->assertTrue($decoded['success'] ?? false, 'the simulated result must report success at the top level');
    }

    // ---------------------------------------------------------------
    // Both cases are still judged correctly even though nothing real
    // happened
    // ---------------------------------------------------------------

    #[Test]
    public function the_action_taken_case_is_judged_met_even_though_nothing_real_happened(): void
    {
        $started = $this->startRun($this->suiteId);
        $this->driveDispatchedCaseJobsToCompletion();

        $cases = $this->getRunCases($started['id']);
        $byCaseId = collect($cases['data'])->keyBy('eval_case_id');

        $actionTaken = $byCaseId[$this->caseIds['action_taken']];
        $this->assertSame('pass', $actionTaken['outcome']);
        $this->assertTrue($actionTaken['expectation_results'][0]['met']);
        $this->assertNotEmpty($actionTaken['attempted_actions'], 'the attempt itself must still be captured and judged');
        $this->assertSame('contacts.create', $actionTaken['attempted_actions'][0]['tool']);
    }

    #[Test]
    public function the_action_not_taken_case_is_judged_correctly(): void
    {
        $started = $this->startRun($this->suiteId);
        $this->driveDispatchedCaseJobsToCompletion();

        $cases = $this->getRunCases($started['id']);
        $byCaseId = collect($cases['data'])->keyBy('eval_case_id');

        $actionNotTaken = $byCaseId[$this->caseIds['action_not_taken']];
        $this->assertSame('pass', $actionNotTaken['outcome']);
        $this->assertTrue($actionNotTaken['expectation_results'][0]['met']);
        $this->assertEmpty($actionNotTaken['attempted_actions']);
    }

    // ---------------------------------------------------------------
    // No contamination of any real user's memory stores, anywhere —
    // deliberately unscoped, not filtered by conversation_id, so a
    // mistaken write under a different key is also caught (FR-015)
    // ---------------------------------------------------------------

    #[Test]
    public function no_episodic_declarative_or_feedback_memory_row_exists_anywhere_attributable_to_any_real_user(): void
    {
        $started = $this->startRun($this->suiteId);
        $this->driveDispatchedCaseJobsToCompletion();

        $this->assertSame(0, DB::table('episodic_memories')->count());
        $this->assertSame(0, DB::table('declarative_memories')->count());
        $this->assertSame(0, DB::table('feedback_signals')->count());

        // Sanity: the run itself did produce results, so an empty
        // database isn't simply "nothing ran".
        $this->assertNotEmpty($this->getRunCases($started['id'])['data']);
    }

    // ---------------------------------------------------------------
    // A real user's own conversation history is entirely untouched
    // (FR-015)
    // ---------------------------------------------------------------

    #[Test]
    public function a_real_users_own_conversation_count_is_unchanged_before_and_after_the_run(): void
    {
        Conversation::create([
            'user_id' => $this->realTestUser->id,
            'title' => "The real user's own, pre-existing conversation",
            'server_id' => $this->server->id,
            'model' => 'test-model',
        ]);

        $countBefore = Conversation::where('user_id', $this->realTestUser->id)->count();

        $started = $this->startRun($this->suiteId);
        $this->driveDispatchedCaseJobsToCompletion();

        $countAfter = Conversation::where('user_id', $this->realTestUser->id)->count();

        $this->assertSame($countBefore, $countAfter);
        $this->assertNotEmpty($this->getRunCases($started['id'])['data']);
    }
}
