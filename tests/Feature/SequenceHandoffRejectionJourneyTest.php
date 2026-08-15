<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Jobs\RunSequenceStageJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\Stage;
use ClarionApp\LlmClient\Models\StageResult;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\ContentSanitizer;
use ClarionApp\LlmClient\Services\DelegationService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\Services\SequenceService;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 105-stage-pipeline, Phase 5 (US3), tasks.md T040 (quickstart scenario 3,
 * US3 AC2, SC-005, mutation-checklist rows 1/2).
 *
 * Drives the real SequenceService -> RunSequenceStageJob -> DelegationService
 * -> AgentLoopService::run() chain with a scripted LlmProvider (same
 * convention T022/T023 established in tests/Feature/ rather than
 * tests/Integration/, per NoMocksGuardTest.php).
 *
 * Proves a handoff mismatch (stage 2's own input_schema rejecting stage 1's
 * output) is recorded as 'handoff_rejected' -- NOT 'failed' -- with no
 * Delegation row ever created for stage 2 (its own agent is never invoked),
 * and that a sibling scenario where stage 2 IS invoked and genuinely fails
 * on its own produces 'failed' instead, confirming the two statuses are
 * distinguishable.
 *
 * Written before RunSequenceStageJob's boundary check exists (T044) -- both
 * scenarios below are expected to FAIL red until then.
 */
class SequenceHandoffRejectionJourneyTest extends TestCase
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

        DB::table('stage_results')->delete();
        DB::table('sequence_runs')->delete();
        DB::table('stages')->delete();
        DB::table('stage_sequence_definitions')->delete();
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

    private function delegationResultReply(array $output, string $status = 'success', string $summary = 'Stage complete.'): array
    {
        return $this->plainReply(json_encode([
            'status' => $status,
            'summary' => $summary,
            'output' => $output,
            'undone' => $status === 'failure' ? 'Everything -- the task could not be completed.' : '',
        ], JSON_FORCE_OBJECT));
    }

    /** Runs one RunSequenceStageJob invocation with a fresh scripted reply bound in. */
    private function runOneStage(string $runId, array $output, string $status = 'success', string $summary = 'Stage complete.'): void
    {
        $service = $this->serviceWithScriptedProvider([$this->delegationResultReply($output, $status, $summary)]);
        $this->app->instance(AgentLoopService::class, $service);

        (new RunSequenceStageJob($runId))->handle(app(DelegationService::class), app(ContentSanitizer::class), app(SequenceService::class));
    }

    // =================================================================

    #[Test]
    public function stage_2s_own_input_schema_rejects_stage_1s_output_and_stops_the_run_as_handoff_rejected_not_failed(): void
    {
        $coordinator = $this->makeAgent('coordinator-handoff');
        $helper = $this->makeAgent('helper-handoff');
        app(AgentHelperService::class)->assign($this->user->id, $coordinator->id, $helper->id);

        $inputSchema = [
            'type' => 'object',
            'properties' => ['required_key' => ['type' => 'string']],
            'required' => ['required_key'],
        ];

        $definition = app(SequenceService::class)->defineSequence($this->user->id, 'Handoff rejection sequence', null, $coordinator->id, [
            ['name' => 'Draft', 'helper_agent_id' => $helper->id],
            ['name' => 'Check', 'helper_agent_id' => $helper->id, 'input_schema' => $inputSchema],
        ]);

        Queue::fake();
        $result = app(SequenceService::class)->invoke($this->user->id, $definition->id, ['topic' => 'Q3 positioning']);
        $run = $result['sequence_run'];

        // Stage 1 succeeds but its output does NOT carry 'required_key'.
        $this->runOneStage($run->id, ['draft_text' => 'a first draft']);
        $run->refresh();
        $this->assertSame('in_progress', $run->status, 'the run must not be terminal yet -- only stage 1 has run so far');

        // Stage 2's own input_schema check runs BEFORE its agent is ever
        // invoked -- no scripted provider response is queued for it, so if
        // the implementation wrongly calls delegate() anyway, the mock's
        // own "queue exhausted" assertion in serviceWithScriptedProvider()
        // (still bound from stage 1's call) will fail this test.
        (new RunSequenceStageJob($run->id))->handle(app(DelegationService::class), app(ContentSanitizer::class), app(SequenceService::class));

        $run->refresh();
        $this->assertSame('failed', $run->status, 'a handoff rejection must stop the run, never leaving it in_progress or completed');
        $this->assertNotNull($run->failure_reason);
        $this->assertStringContainsString('required_key', $run->failure_reason);

        $stages = Stage::where('sequence_definition_id', $definition->id)->orderBy('position')->get();
        $results = StageResult::where('sequence_run_id', $run->id)->get()->keyBy('stage_id');

        $stage1Result = $results[$stages[0]->id];
        $this->assertSame('completed', $stage1Result->status, "stage 1's own success must be unaffected by stage 2's rejection");
        $this->assertNotNull($stage1Result->delegation_id);

        $stage2Result = $results[$stages[1]->id];
        $this->assertSame('handoff_rejected', $stage2Result->status, 'a boundary mismatch must be handoff_rejected, distinct from failed');
        $this->assertNull($stage2Result->delegation_id, "stage 2's own agent must never be invoked -- no Delegation row for it");
        $this->assertNotNull($stage2Result->failure_reason);
        $this->assertStringContainsString('required_key', $stage2Result->failure_reason, 'failure_reason must name the specific missing property');

        // Only stage 1's own Delegation row exists -- stage 2 was rejected
        // before dispatch, exactly as data-model.md §4 describes.
        $this->assertSame(1, Delegation::count());
    }

    #[Test]
    public function a_sibling_scenario_where_stage_2_is_invoked_and_genuinely_fails_produces_failed_not_handoff_rejected(): void
    {
        $coordinator = $this->makeAgent('coordinator-siblingfail');
        $helper = $this->makeAgent('helper-siblingfail');
        app(AgentHelperService::class)->assign($this->user->id, $coordinator->id, $helper->id);

        // Stage 2 declares NO input_schema this time -- it IS invoked, and
        // its own delegated agent reports a genuine failure.
        $definition = app(SequenceService::class)->defineSequence($this->user->id, 'Sibling failure sequence', null, $coordinator->id, [
            ['name' => 'Draft', 'helper_agent_id' => $helper->id],
            ['name' => 'Check', 'helper_agent_id' => $helper->id],
        ]);

        Queue::fake();
        $result = app(SequenceService::class)->invoke($this->user->id, $definition->id, ['topic' => 'Q3 positioning']);
        $run = $result['sequence_run'];

        $this->runOneStage($run->id, ['draft_text' => 'a first draft']);
        $this->runOneStage($run->id, [], 'failure', 'The checker could not validate the draft.');

        $run->refresh();
        $this->assertSame('failed', $run->status);

        $stages = Stage::where('sequence_definition_id', $definition->id)->orderBy('position')->get();
        $results = StageResult::where('sequence_run_id', $run->id)->get()->keyBy('stage_id');

        $stage2Result = $results[$stages[1]->id];
        $this->assertSame('failed', $stage2Result->status, 'a genuine execution failure must be failed, distinct from a handoff mismatch');
        $this->assertNotNull($stage2Result->delegation_id, 'stage 2 WAS invoked this time -- a Delegation row must exist for it');
        $this->assertNotNull($stage2Result->failure_reason);

        // Both stages were actually invoked this time.
        $this->assertSame(2, Delegation::count());
    }
}
