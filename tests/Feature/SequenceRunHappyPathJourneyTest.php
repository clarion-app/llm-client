<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Jobs\RunSequenceStageJob;
use ClarionApp\LlmClient\Models\Agent;
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
 * 105-stage-pipeline, Phase 3 (US1), tasks.md T022 (quickstart scenario 1).
 *
 * Drives the real SequenceService -> RunSequenceStageJob -> DelegationService
 * -> AgentLoopService::run() chain (never mocked beyond the LlmProvider
 * itself) with a scripted LlmProvider, mirroring
 * ManagedTaskCoherentResponseJourneyTest.php's own convention. Confirms a
 * 3+ stage sequence chains correctly: each stage's StageResult.input equals
 * the immediately preceding stage's output exactly (or SequenceRun.
 * starting_input for stage 1), SequenceRun.status reaches 'completed' only
 * once every stage's StageResult.status = 'completed', and
 * current_stage_position advances stage by stage, never skipping.
 *
 * Written before RunSequenceStageJob exists -- every scenario below is
 * expected to FAIL red until T028 lands.
 */
class SequenceRunHappyPathJourneyTest extends TestCase
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

    private function delegationResultReply(array $output, string $status = 'success'): array
    {
        return $this->plainReply(json_encode([
            'status' => $status,
            'summary' => 'Stage complete.',
            'output' => $output,
            'undone' => '',
        ], JSON_FORCE_OBJECT));
    }

    /** Runs one RunSequenceStageJob invocation with a fresh scripted reply bound in. */
    private function runOneStage(string $runId, array $output): void
    {
        $service = $this->serviceWithScriptedProvider([$this->delegationResultReply($output)]);
        $this->app->instance(AgentLoopService::class, $service);

        (new RunSequenceStageJob($runId))->handle(app(DelegationService::class), app(ContentSanitizer::class));
    }

    // =================================================================

    #[Test]
    public function a_three_stage_sequence_chains_output_to_input_and_completes(): void
    {
        $coordinator = $this->makeAgent('coordinator-happy');
        $helper = $this->makeAgent('helper-happy');
        app(AgentHelperService::class)->assign($this->user->id, $coordinator->id, $helper->id);

        $definition = app(SequenceService::class)->defineSequence($this->user->id, 'Three stages', null, $coordinator->id, [
            ['name' => 'Draft', 'helper_agent_id' => $helper->id],
            ['name' => 'Check', 'helper_agent_id' => $helper->id],
            ['name' => 'Finish', 'helper_agent_id' => $helper->id],
        ]);

        Queue::fake();
        $result = app(SequenceService::class)->invoke($this->user->id, $definition->id, ['topic' => 'Q3 positioning']);
        $run = $result['sequence_run'];

        Queue::assertPushed(RunSequenceStageJob::class, fn (RunSequenceStageJob $job) => $job->sequenceRunId === $run->id);

        $this->runOneStage($run->id, ['draft_text' => 'a first draft']);
        $run->refresh();
        $this->assertSame('in_progress', $run->status, 'the run must not complete after only the first of three stages');
        $this->assertSame(1, $run->current_stage_position);

        $this->runOneStage($run->id, ['draft_text' => 'a checked draft']);
        $run->refresh();
        $this->assertSame('in_progress', $run->status);
        $this->assertSame(2, $run->current_stage_position);

        $this->runOneStage($run->id, ['draft_text' => 'the finished text']);
        $run->refresh();
        $this->assertSame('completed', $run->status, 'the run must reach completed once every stage has completed');
        $this->assertNotNull($run->completed_at);

        $stages = Stage::where('sequence_definition_id', $definition->id)->orderBy('position')->get();
        $results = StageResult::where('sequence_run_id', $run->id)->get()->keyBy('stage_id');

        // Stage 1's input is the run's own starting_input, exactly.
        $stageOneResult = $results[$stages[0]->id];
        $this->assertSame('completed', $stageOneResult->status);
        $this->assertSame($run->starting_input, $stageOneResult->input);
        $this->assertSame(['draft_text' => 'a first draft'], json_decode($stageOneResult->output, true));

        // Stage 2's input is EXACTLY stage 1's stored output.
        $stageTwoResult = $results[$stages[1]->id];
        $this->assertSame('completed', $stageTwoResult->status);
        $this->assertSame($stageOneResult->output, $stageTwoResult->input, "stage 2's input must equal stage 1's output exactly");
        $this->assertSame(['draft_text' => 'a checked draft'], json_decode($stageTwoResult->output, true));

        // Stage 3's input is EXACTLY stage 2's stored output.
        $stageThreeResult = $results[$stages[2]->id];
        $this->assertSame('completed', $stageThreeResult->status);
        $this->assertSame($stageTwoResult->output, $stageThreeResult->input, "stage 3's input must equal stage 2's output exactly");
        $this->assertSame(['draft_text' => 'the finished text'], json_decode($stageThreeResult->output, true));

        // A Delegation row was created for each stage, never null.
        $this->assertNotNull($stageOneResult->delegation_id);
        $this->assertNotNull($stageTwoResult->delegation_id);
        $this->assertNotNull($stageThreeResult->delegation_id);
        $this->assertSame(3, \ClarionApp\LlmClient\Models\Delegation::count());
    }

    #[Test]
    public function current_stage_position_advances_stage_by_stage_never_skipping(): void
    {
        $coordinator = $this->makeAgent('coordinator-position');
        $helper = $this->makeAgent('helper-position');
        app(AgentHelperService::class)->assign($this->user->id, $coordinator->id, $helper->id);

        $definition = app(SequenceService::class)->defineSequence($this->user->id, 'Position tracking', null, $coordinator->id, [
            ['name' => 'One', 'helper_agent_id' => $helper->id],
            ['name' => 'Two', 'helper_agent_id' => $helper->id],
        ]);

        Queue::fake();
        $result = app(SequenceService::class)->invoke($this->user->id, $definition->id, ['x' => 1]);
        $run = $result['sequence_run'];

        $this->assertNull($run->current_stage_position, 'current_stage_position must not be set before any stage has started');

        $this->runOneStage($run->id, ['y' => 1]);
        $run->refresh();
        $this->assertSame(1, $run->current_stage_position);

        $this->runOneStage($run->id, ['z' => 1]);
        $run->refresh();
        $this->assertSame(2, $run->current_stage_position);
        $this->assertSame('completed', $run->status);
    }
}
