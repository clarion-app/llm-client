<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Jobs\RunEvalCaseJob;
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
 * spec.md US1 Acceptance Scenarios 1-4, through the real HTTP contract
 * (contracts/eval-runs-api.md §2-3), plus the FR-018/SC-008 (repeating a
 * run) and FR-020 (a suite with zero cases) edge cases.
 *
 * This phase does not yet build isolation (US2) or tool suppression
 * (D3/D4) — a run's Conversation is a normal, unscoped user_id = null row
 * and a tool call the agent attempts really reaches McpToolExecutor. The
 * LLM transport itself is provider-mocked (ProviderRegistry, the
 * CeilingStopsWorkJourneyTest precedent) so nothing here depends on any
 * real model endpoint, and Http::fake() covers everything else so no real
 * network call can escape this test either way.
 */
class RunSuiteJourneyTest extends TestCase
{
    private User $operator;
    private Server $server;
    private string $suiteId;

    /** @var array<string, string> expectation-kind => eval_case id */
    private array $caseIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // AgentLoopService::run() (driven here for real, via
        // EvalCaseExecutor) consults ConversationCondenser on every call,
        // which queries this table unconditionally — not part of the
        // eval-run schema itself, so not covered by
        // TestCase::defineEvalRunSchema(). Every other Feature test that
        // drives run() against a real Conversation declares this table
        // itself (the CeilingStopsWorkJourneyTest precedent, among ~15
        // others); this file needs the identical declaration since it is
        // the first eval-run test to reach the real synchronous loop.
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
        // (AgentLoopService::getOrCreateSession()) — the
        // EntryPathCoverageJourneyTest precedent for the identical need.
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

        $this->operator = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $this->server = Server::create([
            'name' => 'Eval Run Test Server',
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

        $this->suiteId = $this->createSuiteWithFourCheckableCases();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->seedApiDocsCache(null);

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

    /**
     * Seeds ApiManager's real static $apiDocsCache by reflection so the
     * action_taken/action_not_taken cases' real
     * AgentLoopService::executeApiCall() -> ApiManager::getOperationDetails()
     * lookup resolves 'contacts.create' without invoking the real Scramble
     * doc generator — which needs a PhpParser\Parser binding this
     * package's minimal testbench container never registers. The
     * ApiCallValidatorTest precedent for the identical seam; null clears
     * it in tearDown so it cannot leak into a later test.
     */
    private function seedApiDocsCache(?array $doc = ['paths' => ['/api/contacts' => ['post' => ['operationId' => 'contacts.create']]]]): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);
    }

    /**
     * One case per checkable ExpectationKind (text_match,
     * information_present, action_taken, action_not_taken) — human_judgment
     * is EvalCaseJudgeTest's concern, not this HTTP journey's.
     */
    private function createSuiteWithFourCheckableCases(): string
    {
        $suite = $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => 'Run journey fixture suite',
            'agent_identifier' => 'home-automation-agent',
        ])->assertStatus(200)->json();

        $cases = [
            'text_match' => [
                'given' => 'What is 2+2?',
                'expected_behavior' => 'Answer with the number 4.',
                'expectations' => [['kind' => 'text_match', 'expected_text' => '4']],
            ],
            'information_present' => [
                'given' => "What's the weather like right now?",
                'expected_behavior' => 'Mention that it is sunny.',
                'expectations' => [['kind' => 'information_present', 'expected_info' => 'sunny']],
            ],
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

            if (str_contains($firstUser, 'What is 2+2')) {
                return $this->textChatResponse('4');
            }

            if (str_contains($firstUser, 'weather')) {
                return $this->textChatResponse("It's sunny outside right now.");
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
     * Starts a run of the given suite with dispatch captured (not
     * executed) by Bus::fake(), so the response reflects the true
     * "just started" state (FR-008/US3) rather than one already advanced
     * by same-process job execution.
     */
    private function startRun(string $suiteId): array
    {
        Bus::fake([RunEvalCaseJob::class]);

        return $this->actingAs($this->operator)
            ->postJson($this->suitesBase().'/'.$suiteId.'/runs')
            ->assertStatus(201)
            ->json();
    }

    /**
     * Drives every case job Bus::fake() captured for the most recently
     * started run, synchronously and in-process, via the real
     * RunEvalCaseJob::handle() — the "Bus::dispatchSync-equivalent"
     * driving choice the task calls for, not Queue::fake(), which would
     * prove nothing about the real job actually running.
     */
    private function driveDispatchedCaseJobsToCompletion(): void
    {
        foreach (Bus::dispatched(RunEvalCaseJob::class) as $job) {
            $job->handle(app(EvalCaseExecutor::class));
        }
    }

    private function getRun(string $runId): array
    {
        return $this->actingAs($this->operator)->getJson($this->runsBase().'/'.$runId)->assertStatus(200)->json();
    }

    private function getRunCases(string $runId): array
    {
        return $this->actingAs($this->operator)->getJson($this->runsBase().'/'.$runId.'/cases')->assertStatus(200)->json();
    }

    // ---------------------------------------------------------------
    // Scenario 1 — starting a run (contracts §1.3)
    // ---------------------------------------------------------------

    #[Test]
    public function starting_a_run_returns_in_progress_with_the_full_case_count_and_nothing_completed_yet(): void
    {
        $body = $this->startRun($this->suiteId);

        $this->assertSame('in_progress', $body['status']);
        $this->assertSame(4, $body['case_count']);
        $this->assertSame(0, $body['completed_count']);
        $this->assertSame(4, $body['remaining_count']);
        Bus::assertDispatchedTimes(RunEvalCaseJob::class, 4);
    }

    // ---------------------------------------------------------------
    // Scenarios 2 & 4 — every checkable expectation kind judged correctly
    // once the run finishes
    // ---------------------------------------------------------------

    #[Test]
    public function once_the_run_completes_every_checkable_expectation_kind_is_judged_correctly(): void
    {
        $started = $this->startRun($this->suiteId);
        $this->driveDispatchedCaseJobsToCompletion();

        $run = $this->getRun($started['id']);
        $this->assertSame('completed', $run['status']);
        $this->assertSame('pass', $run['overall']);

        $cases = $this->getRunCases($started['id']);
        $byCaseId = collect($cases['data'])->keyBy('eval_case_id');

        $textMatch = $byCaseId[$this->caseIds['text_match']];
        $this->assertSame('pass', $textMatch['outcome']);
        $this->assertTrue($textMatch['expectation_results'][0]['met']);
        $this->assertSame('4', trim($textMatch['produced_response']));

        $infoPresent = $byCaseId[$this->caseIds['information_present']];
        $this->assertSame('pass', $infoPresent['outcome']);
        $this->assertTrue($infoPresent['expectation_results'][0]['met']);
        $this->assertStringContainsStringIgnoringCase('sunny', $infoPresent['produced_response']);

        $actionTaken = $byCaseId[$this->caseIds['action_taken']];
        $this->assertSame('pass', $actionTaken['outcome']);
        $this->assertTrue($actionTaken['expectation_results'][0]['met']);
        $this->assertNotEmpty($actionTaken['attempted_actions'], 'attempted_actions must capture the tool call even though this phase performs it for real');
        $this->assertSame('contacts.create', $actionTaken['attempted_actions'][0]['tool']);

        $actionNotTaken = $byCaseId[$this->caseIds['action_not_taken']];
        $this->assertSame('pass', $actionNotTaken['outcome']);
        $this->assertTrue($actionNotTaken['expectation_results'][0]['met']);
        $this->assertEmpty($actionNotTaken['attempted_actions']);
    }

    // ---------------------------------------------------------------
    // Scenario 3 — exactly one overall result, detail-shape only
    // ---------------------------------------------------------------

    #[Test]
    public function the_run_shows_exactly_one_overall_result_present_only_on_the_detail_shape(): void
    {
        $started = $this->startRun($this->suiteId);
        $this->driveDispatchedCaseJobsToCompletion();

        $detail = $this->getRun($started['id']);
        $this->assertArrayHasKey('overall', $detail);
        $this->assertNotNull($detail['overall']);

        $list = $this->actingAs($this->operator)
            ->getJson($this->suitesBase().'/'.$this->suiteId.'/runs')
            ->assertStatus(200)->json();

        $this->assertNotEmpty($list['data']);
        foreach ($list['data'] as $entry) {
            $this->assertArrayNotHasKey(
                'overall',
                $entry,
                'The list shape (contracts §1.3) must never carry the detail-only overall field'
            );
        }
    }

    // ---------------------------------------------------------------
    // Edge case — repeating a run of the same, unmodified suite
    // (FR-018/SC-008)
    // ---------------------------------------------------------------

    #[Test]
    public function starting_the_same_unmodified_suite_again_produces_a_new_independent_run_and_leaves_the_first_untouched(): void
    {
        $firstStarted = $this->startRun($this->suiteId);
        $this->driveDispatchedCaseJobsToCompletion();
        $firstCasesBefore = $this->getRunCases($firstStarted['id']);

        $secondStarted = $this->startRun($this->suiteId);
        $this->assertNotSame($firstStarted['id'], $secondStarted['id']);
        $this->assertSame(0, $secondStarted['completed_count']);
        $this->driveDispatchedCaseJobsToCompletion();

        $secondRun = $this->getRun($secondStarted['id']);
        $this->assertSame('completed', $secondRun['status']);
        $this->assertSame(4, $secondRun['case_count']);

        $firstCasesAfter = $this->getRunCases($firstStarted['id']);
        $this->assertSame(
            $firstCasesBefore,
            $firstCasesAfter,
            "The first run's already-recorded results must be byte-identical before and after the second run starts"
        );
    }

    // ---------------------------------------------------------------
    // Edge case — a suite with zero cases (FR-020)
    // ---------------------------------------------------------------

    #[Test]
    public function starting_a_run_of_a_suite_with_zero_cases_is_refused_with_no_run_created_and_the_other_suites_runs_unaffected(): void
    {
        $existingStarted = $this->startRun($this->suiteId);
        $this->driveDispatchedCaseJobsToCompletion();
        $existingRunsBefore = $this->actingAs($this->operator)
            ->getJson($this->suitesBase().'/'.$this->suiteId.'/runs')->json();

        $emptySuite = $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => 'Empty suite fixture',
            'agent_identifier' => 'home-automation-agent',
        ])->assertStatus(200)->json();

        $countBefore = DB::table('eval_runs')->count();

        $response = $this->actingAs($this->operator)
            ->postJson($this->suitesBase().'/'.$emptySuite['id'].'/runs');

        $response->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('no cases', (string) $response->json('message'));

        $this->assertSame(
            $countBefore,
            DB::table('eval_runs')->count(),
            'No eval_runs row may be created for a suite with zero cases'
        );

        $emptySuiteRuns = $this->actingAs($this->operator)
            ->getJson($this->suitesBase().'/'.$emptySuite['id'].'/runs')
            ->assertStatus(200)->json();
        $this->assertEmpty($emptySuiteRuns['data']);

        $existingRunsAfter = $this->actingAs($this->operator)
            ->getJson($this->suitesBase().'/'.$this->suiteId.'/runs')->json();
        $this->assertSame(
            $existingRunsBefore,
            $existingRunsAfter,
            "The original suite's runs must be unaffected by a refused start on a different, empty suite"
        );
        $this->assertNotEmpty($existingRunsAfter['data']);
    }
}
