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
 * 105-stage-pipeline, Phase 5 (US3), tasks.md T041 (quickstart scenario 2,
 * US3 AC1, FR-008/009/010).
 *
 * Drives the real SequenceService -> RunSequenceStageJob -> DelegationService
 * -> AgentLoopService::run() chain with a scripted LlmProvider (tests/Feature/
 * convention, NoMocksGuardTest.php forbids this under tests/Integration/).
 *
 * Stage 2's delegated agent fails outright (result_status = 'failure').
 * Proves: SequenceRun.status = 'failed' (never 'completed'); stage 1
 * completed with its real output; stage 2 failed with its own reason;
 * stages 3+ pending, never attempted, no Delegation row for them.
 *
 * Written before RunSequenceStageJob's stop-on-failure branch exists
 * (T046) -- expected to FAIL red until then.
 */
class SequenceStageFailureJourneyTest extends TestCase
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
    public function stage_2_execution_failure_stops_the_run_leaving_later_stages_never_attempted(): void
    {
        $coordinator = $this->makeAgent('coordinator-stagefail');
        $helper = $this->makeAgent('helper-stagefail');
        app(AgentHelperService::class)->assign($this->user->id, $coordinator->id, $helper->id);

        $definition = app(SequenceService::class)->defineSequence($this->user->id, 'Stage failure sequence', null, $coordinator->id, [
            ['name' => 'Draft', 'helper_agent_id' => $helper->id],
            ['name' => 'Check', 'helper_agent_id' => $helper->id],
            ['name' => 'Revise', 'helper_agent_id' => $helper->id],
            ['name' => 'Finish', 'helper_agent_id' => $helper->id],
        ]);

        Queue::fake();
        $result = app(SequenceService::class)->invoke($this->user->id, $definition->id, ['topic' => 'stage failure test']);
        $run = $result['sequence_run'];

        Queue::assertPushed(RunSequenceStageJob::class, 1);

        $this->runOneStage($run->id, ['draft_text' => 'a real draft']);
        Queue::assertPushed(RunSequenceStageJob::class, 2, 'stage 1 succeeding must dispatch the next job for stage 2');

        $run->refresh();
        $this->assertSame('in_progress', $run->status);

        $this->runOneStage($run->id, [], 'failure', 'The checker could not validate the draft.');
        Queue::assertPushed(RunSequenceStageJob::class, 2, 'a genuinely failed stage must dispatch NO further job for stage 3');

        $run->refresh();
        $this->assertSame('failed', $run->status, 'the run must never reach completed once a stage genuinely failed');
        $this->assertNotNull($run->failure_reason);

        $stages = Stage::where('sequence_definition_id', $definition->id)->orderBy('position')->get();
        $results = StageResult::where('sequence_run_id', $run->id)->get()->keyBy('stage_id');

        $stage1Result = $results[$stages[0]->id];
        $this->assertSame('completed', $stage1Result->status);
        $this->assertSame(['draft_text' => 'a real draft'], json_decode($stage1Result->output, true));

        $stage2Result = $results[$stages[1]->id];
        $this->assertSame('failed', $stage2Result->status);
        $this->assertNotNull($stage2Result->failure_reason);
        $this->assertStringContainsString('checker', $stage2Result->failure_reason);

        $stage3Result = $results[$stages[2]->id];
        $this->assertSame('pending', $stage3Result->status, 'stage 3 must never be attempted once stage 2 failed');
        $this->assertNull($stage3Result->delegation_id);

        $stage4Result = $results[$stages[3]->id];
        $this->assertSame('pending', $stage4Result->status);
        $this->assertNull($stage4Result->delegation_id);

        // Exactly two Delegation rows exist: stage 1's success and stage
        // 2's own failed attempt -- stages 3-4 were never dispatched at
        // all.
        $this->assertSame(2, Delegation::count());
    }
}
