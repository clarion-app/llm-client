<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Jobs\RunSequenceStageJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\SequenceRun;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\Stage;
use ClarionApp\LlmClient\Models\StageResult;
use ClarionApp\LlmClient\Models\StageSequenceDefinition;
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
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 105-stage-pipeline, Phase 6 (US4), tasks.md T051/T052 (quickstart scenario
 * 5, FR-013/SC-008, mutation-checklist rows 4/5).
 *
 * T051 (refusal): a StageResult directly set to 'running' with no terminal
 * status (simulating a worker crash) for a stage with is_idempotent = false,
 * on a run already 'failed' -- resume returns 409 unsafe_to_resume naming
 * that exact stage, no Delegation row is created, and the StageResult is
 * left completely unchanged. Built as a plain fixture (no delegation chain
 * needed -- resume must refuse before ever calling delegate()).
 *
 * T052 (admitted, idempotent): the identical setup but is_idempotent = true
 * -- resume IS admitted and the stage is re-invoked exactly once more from
 * scratch, so this half drives the real SequenceService ->
 * RunSequenceStageJob -> DelegationService -> AgentLoopService::run() chain
 * with a scripted LlmProvider (tests/Feature/ convention, NoMocksGuardTest
 * forbids this under tests/Integration/).
 *
 * Written before resumeSafety()/resume() exist -- expected to FAIL red
 * until T057-T059 land (POST .../resume currently 501s).
 */
class SequenceResumeSafetyTest extends TestCase
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

    private function runOneStage(string $runId, array $output): void
    {
        $service = $this->serviceWithScriptedProvider([$this->delegationResultReply($output)]);
        $this->app->instance(AgentLoopService::class, $service);

        (new RunSequenceStageJob($runId))->handle(app(DelegationService::class), app(ContentSanitizer::class), app(SequenceService::class));
    }

    /**
     * A fixture with real Agent/AgentHelperAssignment/Conversation rows
     * (needed because the $isIdempotent = true half actually drives the
     * resumed job through a real delegate() call) -- resume itself must
     * still refuse the $isIdempotent = false half before ever resolving a
     * helper agent or calling delegate(). Stage 1 completed; stage 2 left
     * 'running' (simulating a crash), $isIdempotent as given; stage 3
     * pending. SequenceRun.status is directly 'failed'.
     *
     * @return array{0: SequenceRun, 1: array<int, Stage>, 2: StageResult}
     */
    private function crashedRunFixture(bool $isIdempotent): array
    {
        $coordinator = $this->makeAgent('coordinator-crash-'.($isIdempotent ? 'idempotent' : 'nonidempotent'));
        $helper = $this->makeAgent('helper-crash-'.($isIdempotent ? 'idempotent' : 'nonidempotent'));
        app(AgentHelperService::class)->assign($this->user->id, $coordinator->id, $helper->id);

        $conversation = \ClarionApp\LlmClient\Models\Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
            'channel' => 'sequence-run',
            'agent_id' => $coordinator->id,
            'agent_version_id' => $coordinator->current_version_id,
        ]);

        $definition = StageSequenceDefinition::create([
            'owner_user_id' => $this->user->id,
            'coordinator_agent_id' => $coordinator->id,
            'name' => 'Crash-recovery fixture',
            'description' => null,
        ]);

        $stageDefs = [
            ['name' => 'Draft', 'is_idempotent' => false],
            ['name' => 'Send notification', 'is_idempotent' => $isIdempotent],
            ['name' => 'Finish', 'is_idempotent' => false],
        ];

        $stages = [];
        foreach ($stageDefs as $index => $def) {
            $stages[] = Stage::create([
                'sequence_definition_id' => $definition->id,
                'position' => $index + 1,
                'name' => $def['name'],
                'helper_agent_id' => $helper->id,
                'is_idempotent' => $def['is_idempotent'],
            ]);
        }

        $run = SequenceRun::create([
            'sequence_definition_id' => $definition->id,
            'owner_user_id' => $this->user->id,
            'conversation_id' => $conversation->id,
            'status' => 'failed',
            'starting_input' => json_encode(['topic' => 'crash recovery test']),
            'current_stage_position' => 2,
            'last_progress_at' => now(),
            'failure_reason' => 'The worker processing this run crashed mid-stage.',
            'resume_count' => 0,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);

        StageResult::create([
            'sequence_run_id' => $run->id,
            'stage_id' => $stages[0]->id,
            'status' => 'completed',
            'input' => $run->starting_input,
            'output' => json_encode(['drafted' => true]),
            'started_at' => now()->subMinutes(4),
            'completed_at' => now()->subMinutes(3),
        ]);

        $crashedResult = StageResult::create([
            'sequence_run_id' => $run->id,
            'stage_id' => $stages[1]->id,
            'status' => 'running',
            'input' => json_encode(['drafted' => true]),
            'started_at' => now()->subMinutes(2),
        ]);

        StageResult::create([
            'sequence_run_id' => $run->id,
            'stage_id' => $stages[2]->id,
            'status' => 'pending',
        ]);

        return [$run, $stages, $crashedResult];
    }

    // =================================================================
    // T051: refusal (is_idempotent = false)
    // =================================================================

    #[Test]
    public function resume_is_refused_when_the_crashed_stage_is_not_idempotent(): void
    {
        [$run, $stages, $crashedResult] = $this->crashedRunFixture(isIdempotent: false);

        $response = $this->actingAs($this->user, 'api')
            ->postJson("/api/clarion-app/llm-client/sequence-runs/{$run->id}/resume");

        $response->assertStatus(409);
        $this->assertSame('unsafe_to_resume', $response->json('error'));
        $this->assertSame($stages[1]->id, $response->json('blocking_stage_id'));
        $this->assertSame(2, $response->json('blocking_stage_position'));
        $this->assertStringContainsString('Send notification', $response->json('message'));

        // No Delegation row was ever created.
        $this->assertSame(0, Delegation::count());

        // The StageResult is left completely unchanged -- still 'running'.
        $refreshed = StageResult::find($crashedResult->id);
        $this->assertSame('running', $refreshed->status);
        $this->assertNull($refreshed->completed_at);
        $this->assertNull($refreshed->output);

        // The run itself is untouched too -- still 'failed', resume_count
        // still 0.
        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame(0, $run->resume_count);
    }

    // =================================================================
    // T052: admitted (is_idempotent = true) -- re-invoked from scratch
    // =================================================================

    #[Test]
    public function resume_is_admitted_and_the_crashed_stage_is_re_invoked_from_scratch_when_idempotent(): void
    {
        [$run, $stages, $crashedResult] = $this->crashedRunFixture(isIdempotent: true);

        Queue::fake();
        $response = $this->actingAs($this->user, 'api')
            ->postJson("/api/clarion-app/llm-client/sequence-runs/{$run->id}/resume");

        $response->assertStatus(202);
        $this->assertSame('resumed', $response->json('status'));
        $this->assertSame(2, $response->json('resuming_from_stage_position'));

        $run->refresh();
        $this->assertSame('resumed', $run->status);
        $this->assertSame(1, $run->resume_count);

        // Nothing has actually re-invoked the stage yet -- resume() only
        // dispatches. Confirmed no Delegation exists before manually
        // running the job.
        $this->assertSame(0, Delegation::count());

        // Manually process the resumed job -- the crashed stage's row is
        // reset and re-invoked from scratch, using the SAME row (never a
        // new one).
        $this->runOneStage($run->id, ['notified' => true]);

        $reProcessed = StageResult::find($crashedResult->id);
        $this->assertSame($crashedResult->id, $reProcessed->id, 'the SAME StageResult row must be reused, never a new one, to keep unique(sequence_run_id, stage_id) intact');
        $this->assertSame('completed', $reProcessed->status);
        $this->assertSame(['notified' => true], json_decode($reProcessed->output, true));
        $this->assertNotNull($reProcessed->delegation_id);

        // Exactly one Delegation row exists for the re-invocation -- it
        // was genuinely re-run exactly once more from scratch.
        $this->assertSame(1, Delegation::count());

        // The run advances to the final stage next.
        $run->refresh();
        $this->assertSame('resumed', $run->status, 'the run is not yet complete -- stage 3 has not run yet');
    }
}
