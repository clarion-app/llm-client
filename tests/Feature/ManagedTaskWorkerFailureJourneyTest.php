<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\ManagedTaskPart;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\ManagerService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 103-manager-agent, Phase 7 (US5), tasks.md T055.
 *
 * Full acceptance journey for quickstart.md scenario 10 (US5, FR-012/
 * FR-013, SC-008) and scenario 11 (US5 AC2, Edge Cases). Drives the real
 * ManagerService -> DelegationService -> AgentLoopService::run() chain
 * (never mocked), mirroring ManagedTaskCorrectionJourneyTest.php's own
 * scripted-LlmProvider convention -- the manager's own tool-call choice
 * is scripted rather than genuinely reasoned about, so what each scenario
 * proves is that the *mechanism* behaves correctly when the manager does
 * choose reassignment/report_shortfall over a fabricated accept_part: the
 * part's own state, the Delegation rows created, and the delivered
 * ManagedTask fields all transition exactly as contracts §2/§4/§5
 * specify.
 *
 * Written before AgentLoopService::handleReportShortfall()/
 * ManagerService::reportShortfall() are wired up -- every scenario below
 * is expected to FAIL red (an unrecognized report_shortfall tool call, or
 * BadMethodCallException from ManagerService) until T056/T057 land.
 */
class ManagedTaskWorkerFailureJourneyTest extends TestCase
{
    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);

        app(RoleAssignmentService::class)->set(ModelRole::Inference, $this->user->id, $this->server->id, 'test-model');

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
                $table->index('user_id');
            });
        }

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

        if (!Schema::hasTable('condensation_states')) {
            Schema::create('condensation_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            });
        }
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();

        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_delegations')->delete();
        DB::table('messages')->delete();
        if (Schema::hasTable('mcp_sessions')) {
            DB::table('mcp_sessions')->delete();
        }
        if (Schema::hasTable('agent_run_actions')) {
            DB::table('agent_run_actions')->delete();
        }
        if (Schema::hasTable('agent_run_steps')) {
            DB::table('agent_run_steps')->delete();
        }
        if (Schema::hasTable('agent_runs')) {
            DB::table('agent_runs')->delete();
        }
        DB::table('managed_task_parts')->delete();
        DB::table('managed_tasks')->delete();
        DB::table('agent_helper_assignments')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function seedOperationCatalog(): void
    {
        $doc = ['paths' => []];

        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);

        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->andReturn($doc);
        $this->app->instance(Generator::class, $generator);
    }

    private function clearOperationCatalog(): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function makeAgent(string $name): Agent
    {
        return app(AgentService::class)->create($this->user->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    private function serviceWithScriptedProvider(array $responses): AgentLoopService
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools, $options = []) use (&$responses) {
            $this->assertNotEmpty($responses, 'the scripted response queue was exhausted -- the loop made more provider calls than this test expected');

            return array_shift($responses);
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        return new AgentLoopService(
            app(McpToolRegistry::class),
            app(McpToolExecutor::class),
            app(OperationCache::class),
            $registry,
            presetRegistry: app(\ClarionApp\LlmClient\Services\StructuredOutputPresetRegistry::class),
            runTraceRecorder: config('llm-client.run_trace.enabled', false) ? app(RunTraceRecorder::class) : null,
        );
    }

    private function plainReply(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content, 'tool_calls' => []]]]];
    }

    private function toolCall(string $name, array $arguments, string $id): array
    {
        return [
            'id' => $id,
            'type' => 'function',
            'function' => ['name' => $name, 'arguments' => json_encode($arguments)],
        ];
    }

    private function toolCallReply(array $calls): array
    {
        return ['choices' => [['message' => ['content' => '', 'tool_calls' => $calls]]]];
    }

    private function delegationResultReply(string $status, string $summary): array
    {
        return $this->plainReply(json_encode([
            'status' => $status,
            'summary' => $summary,
            'output' => [],
            'undone' => $status !== 'success' ? 'The part could not be completed as scoped.' : '',
        ], JSON_FORCE_OBJECT));
    }

    /**
     * @return array{\ClarionApp\LlmClient\Models\ManagedTask, ManagedTaskPart, Conversation}
     */
    private function makeManagedTaskWithOnePart(Agent $manager): array
    {
        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A task with one part.');
        $conversation = Conversation::find($task->conversation_id);

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('plan_parts', [
                    'parts' => [['description' => 'The only part.']],
                ], 'call_plan'),
            ]),
        ]);
        $this->app->instance(AgentLoopService::class, $service);
        $service->run($conversation, $task->original_request, ['max_iterations' => 1]);

        $part = ManagedTaskPart::where('managed_task_id', $task->id)->firstOrFail();

        return [$task, $part, $conversation];
    }

    // =================================================================
    // Quickstart scenario 10 (US5, FR-012/FR-013, SC-008): a worker's
    // outright failure triggers reassignment to another suitable helper;
    // a second failure triggers report_shortfall, never accept_part.
    // =================================================================

    #[Test]
    public function a_failed_result_triggers_reassignment_then_a_second_failure_triggers_report_shortfall(): void
    {
        $manager = $this->makeAgent('manager-'.uniqid());
        $helper1 = $this->makeAgent('helper1-'.uniqid());
        $helper2 = $this->makeAgent('helper2-'.uniqid());
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helper1->id);
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helper2->id);

        [$task, $part, $conversation] = $this->makeManagedTaskWithOnePart($manager);

        $service = $this->serviceWithScriptedProvider([
            // Turn 1: first attempt, helper 1.
            $this->toolCallReply([
                $this->toolCall('assign_part', [
                    'part_id' => $part->id,
                    'helper_agent_id' => $helper1->id,
                    'task' => 'Do the only part.',
                ], 'call_assign_1'),
            ]),
            $this->delegationResultReply('failure', 'Could not access the required resource.'),
            // Turn 2: NOT a correction to helper 1 -- reassignment to a
            // DIFFERENT helper (research.md D2, contracts §2).
            $this->toolCallReply([
                $this->toolCall('assign_part', [
                    'part_id' => $part->id,
                    'helper_agent_id' => $helper2->id,
                    'task' => 'Do the only part.',
                ], 'call_assign_2'),
            ]),
            $this->delegationResultReply('failure', 'Also could not access the required resource.'),
            // Turn 3: no further suitable helper -- report_shortfall, not
            // accept_part on a result that never succeeded.
            $this->toolCallReply([
                $this->toolCall('report_shortfall', [
                    'part_id' => $part->id,
                    'reason' => 'Neither assigned helper could access the resource this part required.',
                ], 'call_shortfall_1'),
            ]),
            // Turn 4: finalize_task WITHOUT shortfall_note -- refused
            // (FR-010), the model must keep working.
            $this->toolCallReply([
                $this->toolCall('finalize_task', [
                    'final_response' => 'The task could not be fully completed.',
                ], 'call_finalize_missing_note'),
            ]),
            // Turn 5: finalize_task WITH shortfall_note -- succeeds.
            $this->toolCallReply([
                $this->toolCall('finalize_task', [
                    'final_response' => 'The task could not be fully completed because the required resource could not be reached by either available helper.',
                    'shortfall_note' => 'The only part of this task could not be completed: neither assigned helper could access the required resource.',
                ], 'call_finalize_2'),
            ]),
            // Turn 6: the loop still asks for a closing turn after a
            // tool call executes -- a plain reply ends it (contracts §5:
            // finalize_task's own result is also the model's turn-ending
            // response, but the loop itself still issues one more chat()
            // call before recognizing the conversation as terminal).
            $this->plainReply('Task finalized.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $service->run($conversation, 'Continue.', ['max_iterations' => 20]);

        // Reassignment targeted a DIFFERENT helper, not a same-helper
        // correction.
        $delegations = Delegation::where('part_id', $part->id)->orderBy('started_at')->get();
        $this->assertCount(2, $delegations, 'exactly two attempts: helper 1, then helper 2');
        $this->assertSame($helper1->id, $delegations->first()->helper_agent_id);
        $this->assertSame($helper2->id, $delegations->last()->helper_agent_id, 'the second attempt must be a REASSIGNMENT to a different helper, not a correction back to helper 1');
        $this->assertSame(['failure', 'failure'], $delegations->pluck('result_status')->all());

        // report_shortfall, not accept_part.
        $part->refresh();
        $this->assertSame('reported_as_shortfall', $part->state);
        $this->assertNull($part->accepted_delegation_id, 'a part that was never successfully completed must never carry an accepted_delegation_id');
        $this->assertNotEmpty($part->shortfall_reason);

        // finalize_task's guard refused the first attempt (no
        // shortfall_note) -- the task must not have finalized on that
        // turn, only on the second, corrected attempt.
        $task->refresh();
        $this->assertSame('completed_with_shortfalls', $task->status);
        $this->assertNotNull($task->shortfall_note, 'finalize_task must have been refused once for omitting shortfall_note, then re-called with one supplied');
        $this->assertNotNull($task->final_response);

        // The delivered final_response never claims or implies the part
        // was completed.
        $this->assertStringNotContainsStringIgnoringCase('successfully', $task->final_response);
        $this->assertStringContainsStringIgnoringCase('could not', $task->final_response);

        // Confirmed via the real GET /managed-tasks/{id} endpoint
        // (contracts §2), not just the model directly.
        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/managed-tasks/{$task->id}");

        $response->assertOk();
        $response->assertJson([
            'status' => 'completed_with_shortfalls',
        ]);
        $this->assertNotNull($response->json('shortfall_note'));
        $this->assertStringNotContainsStringIgnoringCase('successfully', (string) $response->json('final_response'));
    }

    // =================================================================
    // Quickstart scenario 11 (US5 AC2, Edge Cases): only one helper, its
    // assignment deactivated mid-task -- the only reachable outcomes are
    // a narrowed retry to that same helper, or report_shortfall. Never a
    // fabricated accept_part, never a finalize_task silent about the
    // part.
    // =================================================================

    #[Test]
    public function a_single_helpers_deactivated_assignment_leaves_only_a_narrowed_retry_or_report_shortfall(): void
    {
        $manager = $this->makeAgent('manager-'.uniqid());
        $helper = $this->makeAgent('helper-'.uniqid());
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helper->id);

        [$task, $part, $conversation] = $this->makeManagedTaskWithOnePart($manager);

        $service = $this->serviceWithScriptedProvider([
            // Turn 1: first (and, since there is only one helper, only
            // possible direct) attempt.
            $this->toolCallReply([
                $this->toolCall('assign_part', [
                    'part_id' => $part->id,
                    'helper_agent_id' => $helper->id,
                    'task' => 'Do the only part.',
                ], 'call_assign_1'),
            ]),
            $this->delegationResultReply('failure', 'Could not complete: missing required access.'),
            // The loop still asks for one more turn after the tool call
            // executes -- a plain reply ends it here, since the rest of
            // this scenario is driven directly through ManagerService
            // (simulating the assignment being deactivated OUTSIDE the
            // manager's own reasoning turn, i.e. by something other than
            // the model's own choice).
            $this->plainReply('Reviewing the result.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $service->run($conversation, 'Continue.', ['max_iterations' => 5]);

        $part->refresh();
        $this->assertSame('out_for_assignment', $part->state, 'fixture sanity -- one failed attempt so far, never assigned before this call');
        $this->assertSame(1, Delegation::where('part_id', $part->id)->count());

        // The helper's own assignment becomes unavailable mid-task --
        // simulating the "only suitable worker" being lost, not by the
        // manager's own choice.
        app(AgentHelperService::class)->remove($this->user->id, $manager->id, $helper->id);

        // Never a fabricated accept_part: the part's own outstanding
        // delegation still reports failure, so accept_part remains
        // structurally refused (FR-013) regardless of the deactivation.
        $acceptRefusal = app(ManagerService::class)->acceptPartRefusal($part);
        $this->assertSame('cannot_accept_failed_result', $acceptRefusal['error'] ?? null, 'accept_part must never be reachable for a result that reports failure, deactivated helper or not');

        // Never a finalize_task silent about the part: with the part
        // still not accepted/reported_as_shortfall, and the round
        // ceiling not reached, finalize_task remains refused.
        $finalizeRefusal = app(ManagerService::class)->finalizeRefusal($task, null);
        $this->assertSame('parts_outstanding', $finalizeRefusal['error'] ?? null, 'finalize_task must refuse to conclude silently while the part is still outstanding');

        // Outcome A (this scenario): a further assign_part on the same
        // part_id, narrowed, to the same (only) helper -- refused,
        // because that helper's own assignment is no longer active. No
        // new Delegation row is created; the round ceiling machinery
        // does not fabricate a result.
        $narrowedResult = app(ManagerService::class)->assignPart($task, $part, $helper->id, 'A narrower version of the part, avoiding the resource that previously failed.', 'Prior attempt failed for lack of access.');

        $this->assertArrayHasKey('error', $narrowedResult, 'a narrowed retry to a helper whose assignment is no longer active must be refused, not silently succeed');
        $this->assertSame(1, Delegation::where('part_id', $part->id)->count(), 'a refused assign_part call must never create a new Delegation row');

        // Outcome B: with the narrowed retry itself refused, the only
        // remaining reachable outcome is an honest report_shortfall.
        app(ManagerService::class)->reportShortfall($task, $part, 'The only assigned helper is no longer available and no further attempt is possible.');

        $part->refresh();
        $this->assertSame('reported_as_shortfall', $part->state);

        app(ManagerService::class)->finalize($task, 'This task could not be completed: the only available helper became unavailable before the part could be finished.', 'The only part of this task could not be completed because the assigned helper was no longer available.');

        $task->refresh();
        $this->assertSame('completed_with_shortfalls', $task->status);
        $this->assertNotNull($task->shortfall_note);
        $this->assertStringNotContainsStringIgnoringCase('successfully', $task->final_response);
    }
}
