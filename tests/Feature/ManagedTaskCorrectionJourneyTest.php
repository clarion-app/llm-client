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
 * 103-manager-agent, Phase 4 (US2), tasks.md T031.
 *
 * Full acceptance journey for quickstart.md scenario 3 (US2, FR-004/
 * FR-005, SC-004) plus US2 AC1 (a satisfying result is accepted without an
 * unnecessary correction round) and AC3 (a corrected revision judged
 * again against the same standard). Drives the real ManagerService ->
 * DelegationService -> AgentLoopService::run() chain (never mocked),
 * mirroring ManagedTaskSuitabilityJourneyTest.php's own scripted-
 * LlmProvider convention -- the manager's own tool-call choice is
 * scripted rather than genuinely reasoned about (nothing in this test
 * suite can judge an LLM's actual judgment), so what each scenario proves
 * is that the *mechanism* behaves correctly when the manager does choose
 * assign_part-again-for-a-correction vs. accept_part: the part's own
 * state, accepted_delegation_id/accepted_summary, and assignment_count
 * all transition exactly as contracts §2/§3 specify.
 *
 * Written before ManagerService::acceptPart()/AgentLoopService's
 * accept_part wiring exist -- every scenario below is expected to FAIL
 * red until T032/T033 land.
 */
class ManagedTaskCorrectionJourneyTest extends TestCase
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
            'undone' => $status === 'partial' ? 'Some aspects were not covered.' : '',
        ], JSON_FORCE_OBJECT));
    }

    /**
     * Builds a manager agent, one helper, a channel='managed-task'
     * ManagedTask, and one ManagedTaskPart via the real plan_parts
     * mechanism (T027 precedent: max_iterations => 1 is enough since
     * plan_parts needs no nested LLM call).
     *
     * @return array{\ClarionApp\LlmClient\Models\ManagedTask, ManagedTaskPart, Agent, Conversation}
     */
    private function makeManagedTaskWithOnePart(): array
    {
        $manager = $this->makeAgent('manager-'.uniqid());
        $helper = $this->makeAgent('helper-'.uniqid());
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helper->id);

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

        return [$task, $part, $helper, $conversation];
    }

    // =================================================================
    // Quickstart scenario 3: a partial result gets a specific correction,
    // never silent acceptance.
    // =================================================================

    #[Test]
    public function a_partial_result_produces_a_correction_via_assign_part_not_a_silent_accept(): void
    {
        [$task, $part, $helper, $conversation] = $this->makeManagedTaskWithOnePart();

        $service = $this->serviceWithScriptedProvider([
            // Manager's turn 1: first attempt.
            $this->toolCallReply([
                $this->toolCall('assign_part', [
                    'part_id' => $part->id,
                    'helper_agent_id' => $helper->id,
                    'task' => 'Do the only part.',
                ], 'call_assign_1'),
            ]),
            // Consumed by the nested delegate() call -- the helper's own
            // result is incomplete.
            $this->delegationResultReply('partial', 'Covered half of what was asked.'),
            // Manager's turn 2: NOT accept_part -- a correction, same
            // helper, naming what was missing.
            $this->toolCallReply([
                $this->toolCall('assign_part', [
                    'part_id' => $part->id,
                    'helper_agent_id' => $helper->id,
                    'task' => 'Finish the remaining half of the part.',
                    'context' => 'Prior attempt only covered half of what was asked.',
                ], 'call_assign_2'),
            ]),
            $this->delegationResultReply('success', 'Completed the whole part.'),
            // Turn-ending reply.
            $this->plainReply('Working on it.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $service->run($conversation, 'Continue.', ['max_iterations' => 10]);

        $part->refresh();
        $this->assertSame('out_for_correction', $part->state, 'a second assign_part call on the same part_id must move it to out_for_correction, never back to not_yet_assigned');
        $this->assertSame(2, $part->assignment_count, 'both the initial attempt and the correction each count as a round');
        $this->assertNull($part->accepted_delegation_id, 'accept_part was never called for this part in this scenario -- it must not be accepted');

        $delegations = Delegation::where('part_id', $part->id)->orderBy('started_at')->get();
        $this->assertCount(2, $delegations, 'the correction must be a SECOND Delegation row, not a reuse of the first');
        $this->assertSame('partial', $delegations->first()->result_status);
        $this->assertSame('success', $delegations->last()->result_status);
    }

    // =================================================================
    // US2 AC1: a satisfying result is accepted, with no unnecessary
    // correction round.
    // =================================================================

    #[Test]
    public function a_satisfying_result_is_accepted_with_no_unnecessary_correction_round(): void
    {
        [$task, $part, $helper, $conversation] = $this->makeManagedTaskWithOnePart();

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('assign_part', [
                    'part_id' => $part->id,
                    'helper_agent_id' => $helper->id,
                    'task' => 'Do the only part.',
                ], 'call_assign_1'),
            ]),
            $this->delegationResultReply('success', 'Fully completed the part.'),
            // Manager's turn 2: the result satisfies what was asked --
            // accept_part, not another assign_part.
            $this->toolCallReply([
                $this->toolCall('accept_part', ['part_id' => $part->id], 'call_accept_1'),
            ]),
            $this->plainReply('Done.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $service->run($conversation, 'Continue.', ['max_iterations' => 10]);

        $part->refresh();
        $this->assertSame('accepted', $part->state);
        $this->assertSame(1, $part->assignment_count, 'a satisfying first result must never trigger an unnecessary correction round');

        $delegation = Delegation::where('part_id', $part->id)->firstOrFail();
        $this->assertSame($delegation->id, $part->accepted_delegation_id);
        $this->assertSame('Fully completed the part.', $part->accepted_summary);
    }

    // =================================================================
    // US2 AC3: a corrected revision is judged against the same standard
    // -- a second partial revision triggers a SECOND correction, and a
    // revision that now satisfies is finally accepted.
    // =================================================================

    #[Test]
    public function a_second_partial_revision_gets_a_further_correction_then_a_satisfying_revision_is_accepted(): void
    {
        [$task, $part, $helper, $conversation] = $this->makeManagedTaskWithOnePart();

        $service = $this->serviceWithScriptedProvider([
            // Attempt 1: partial.
            $this->toolCallReply([
                $this->toolCall('assign_part', [
                    'part_id' => $part->id,
                    'helper_agent_id' => $helper->id,
                    'task' => 'Do the only part.',
                ], 'call_assign_1'),
            ]),
            $this->delegationResultReply('partial', 'First attempt, still incomplete.'),
            // Correction 1: STILL partial -- judged against the same
            // standard, not waved through because it is a revision.
            $this->toolCallReply([
                $this->toolCall('assign_part', [
                    'part_id' => $part->id,
                    'helper_agent_id' => $helper->id,
                    'task' => 'Address the remaining gap.',
                    'context' => 'First attempt was still incomplete.',
                ], 'call_assign_2'),
            ]),
            $this->delegationResultReply('partial', 'Second attempt, closer but still incomplete.'),
            // Correction 2: now satisfies.
            $this->toolCallReply([
                $this->toolCall('assign_part', [
                    'part_id' => $part->id,
                    'helper_agent_id' => $helper->id,
                    'task' => 'Address the last remaining gap.',
                    'context' => 'Second attempt was closer but still incomplete.',
                ], 'call_assign_3'),
            ]),
            $this->delegationResultReply('success', 'Third attempt, now fully complete.'),
            // Now the revision satisfies -- accept_part.
            $this->toolCallReply([
                $this->toolCall('accept_part', ['part_id' => $part->id], 'call_accept_1'),
            ]),
            $this->plainReply('Done.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $service->run($conversation, 'Continue.', ['max_iterations' => 20]);

        $part->refresh();
        $this->assertSame('accepted', $part->state);
        $this->assertSame(3, $part->assignment_count, 'two correction rounds plus the initial attempt');

        $delegations = Delegation::where('part_id', $part->id)->orderBy('started_at')->get();
        $this->assertCount(3, $delegations);
        $this->assertSame(['partial', 'partial', 'success'], $delegations->pluck('result_status')->all());

        $lastDelegation = $delegations->last();
        $this->assertSame($lastDelegation->id, $part->accepted_delegation_id, 'the accepted delegation must be the one that finally satisfied the standard, not an earlier partial attempt');
        $this->assertSame('Third attempt, now fully complete.', $part->accepted_summary);
    }
}
