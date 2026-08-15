<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Jobs\RunSequenceStageJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\SequenceRun;
use ClarionApp\LlmClient\Models\Server;
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
 * 105-stage-pipeline, Phase 3 (US1), tasks.md T023.
 *
 * Confirms invoking the same StageSequenceDefinition more than once
 * produces fully independent SequenceRun rows whose stage_results never
 * cross-contaminate -- including a third run started before either of the
 * first two finishes (US1's own Independent Test).
 *
 * Written before RunSequenceStageJob exists -- every scenario below is
 * expected to FAIL red until T028 lands.
 */
class SequenceRunIndependenceTest extends TestCase
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

    private function delegationResultReply(array $output): array
    {
        return $this->plainReply(json_encode([
            'status' => 'success',
            'summary' => 'Stage complete.',
            'output' => $output,
            'undone' => '',
        ], JSON_FORCE_OBJECT));
    }

    private function runOneStage(string $runId, array $output): void
    {
        $service = $this->serviceWithScriptedProvider([$this->delegationResultReply($output)]);
        $this->app->instance(AgentLoopService::class, $service);

        (new RunSequenceStageJob($runId))->handle(app(DelegationService::class), app(ContentSanitizer::class), app(SequenceService::class));
    }

    // =================================================================

    #[Test]
    public function invoking_the_same_definition_twice_with_different_input_produces_two_fully_independent_runs(): void
    {
        $coordinator = $this->makeAgent('coordinator-independence');
        $helper = $this->makeAgent('helper-independence');
        app(AgentHelperService::class)->assign($this->user->id, $coordinator->id, $helper->id);

        $definition = app(SequenceService::class)->defineSequence($this->user->id, 'Reusable sequence', null, $coordinator->id, [
            ['name' => 'Only stage', 'helper_agent_id' => $helper->id],
        ]);

        Queue::fake();

        $resultA = app(SequenceService::class)->invoke($this->user->id, $definition->id, ['run' => 'A']);
        $resultB = app(SequenceService::class)->invoke($this->user->id, $definition->id, ['run' => 'B']);

        $runA = $resultA['sequence_run'];
        $runB = $resultB['sequence_run'];

        $this->assertNotSame($runA->id, $runB->id);
        $this->assertNotSame($runA->conversation_id, $runB->conversation_id, 'each run must get its own dedicated Conversation');

        $this->runOneStage($runA->id, ['from' => 'A']);
        $this->runOneStage($runB->id, ['from' => 'B']);

        $runA->refresh();
        $runB->refresh();
        $this->assertSame('completed', $runA->status);
        $this->assertSame('completed', $runB->status);

        $resultAOutput = json_decode(StageResult::where('sequence_run_id', $runA->id)->first()->output, true);
        $resultBOutput = json_decode(StageResult::where('sequence_run_id', $runB->id)->first()->output, true);

        $this->assertSame(['from' => 'A'], $resultAOutput, "run A's own StageResult must carry only run A's own output");
        $this->assertSame(['from' => 'B'], $resultBOutput, "run B's own StageResult must carry only run B's own output");
    }

    #[Test]
    public function a_third_run_started_before_either_of_the_first_two_finishes_does_not_corrupt_either_ones_rows(): void
    {
        $coordinator = $this->makeAgent('coordinator-three-way');
        $helper = $this->makeAgent('helper-three-way');
        app(AgentHelperService::class)->assign($this->user->id, $coordinator->id, $helper->id);

        $definition = app(SequenceService::class)->defineSequence($this->user->id, 'Three-way sequence', null, $coordinator->id, [
            ['name' => 'First', 'helper_agent_id' => $helper->id],
            ['name' => 'Second', 'helper_agent_id' => $helper->id],
        ]);

        Queue::fake();

        // All three runs created up front, before any of them has
        // executed a single stage.
        $runOne = app(SequenceService::class)->invoke($this->user->id, $definition->id, ['run' => 1])['sequence_run'];
        $runTwo = app(SequenceService::class)->invoke($this->user->id, $definition->id, ['run' => 2])['sequence_run'];
        $runThree = app(SequenceService::class)->invoke($this->user->id, $definition->id, ['run' => 3])['sequence_run'];

        $this->assertSame(6, StageResult::count(), 'three independent two-stage runs must pre-create 6 StageResult rows total');

        // Interleaved advancement: run 1 stage 1, run 2 stage 1, run 3
        // stage 1, then run 1 stage 2, run 2 stage 2, run 3 stage 2 --
        // the exact "started before either finishes" ordering the
        // Independent Test describes.
        $this->runOneStage($runOne->id, ['from' => 'run1-stage1']);
        $this->runOneStage($runTwo->id, ['from' => 'run2-stage1']);
        $this->runOneStage($runThree->id, ['from' => 'run3-stage1']);

        $this->runOneStage($runOne->id, ['from' => 'run1-stage2']);
        $this->runOneStage($runTwo->id, ['from' => 'run2-stage2']);
        $this->runOneStage($runThree->id, ['from' => 'run3-stage2']);

        foreach ([$runOne, $runTwo, $runThree] as $run) {
            $run->refresh();
            $this->assertSame('completed', $run->status);
        }

        // Every StageResult row is scoped strictly by its own
        // sequence_run_id -- no cross-contamination between the three
        // runs' outputs.
        $resultsByRun = StageResult::all()->groupBy('sequence_run_id');
        $this->assertCount(3, $resultsByRun);

        $runOneOutputs = $resultsByRun[$runOne->id]->pluck('output')->map(fn ($o) => json_decode($o, true)['from'])->sort()->values()->all();
        $runTwoOutputs = $resultsByRun[$runTwo->id]->pluck('output')->map(fn ($o) => json_decode($o, true)['from'])->sort()->values()->all();
        $runThreeOutputs = $resultsByRun[$runThree->id]->pluck('output')->map(fn ($o) => json_decode($o, true)['from'])->sort()->values()->all();

        $this->assertSame(['run1-stage1', 'run1-stage2'], $runOneOutputs);
        $this->assertSame(['run2-stage1', 'run2-stage2'], $runTwoOutputs);
        $this->assertSame(['run3-stage1', 'run3-stage2'], $runThreeOutputs);
    }
}
