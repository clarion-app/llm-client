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
 * 105-stage-pipeline, Phase 6 (US4), tasks.md T050 (quickstart scenario 4,
 * FR-011/012, SC-006).
 *
 * Drives the real SequenceService -> RunSequenceStageJob -> DelegationService
 * -> AgentLoopService::run() chain with a scripted LlmProvider (tests/Feature/
 * convention, established by Phase 3-5's own journey tests -- NoMocksGuardTest
 * forbids this under tests/Integration/).
 *
 * A four-stage sequence where stage 1 ("Send notification") is
 * is_idempotent = false, with an OBSERVABLE invocation-count test double: the
 * scripted provider counts every chat() call whose outgoing messages mention
 * stage 1's own name, so a bug that re-invoked stage 1 during resume would be
 * caught directly, not merely inferred from stage_results being unchanged.
 * Stage 3 is engineered to fail on the first attempt; resuming the run must
 * complete stage 3 onward without ever touching stage 1/2's already-completed
 * rows.
 *
 * Written before resumeSafety()/resume()/RunSequenceStageJob's resume-point
 * logic exist -- expected to FAIL red until T057-T059 land (POST .../resume
 * currently 501s).
 */
class SequenceResumeJourneyTest extends TestCase
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

    /**
     * Unlike the plain scripted provider Phase 3-5's journey tests use, this
     * one also counts every chat() call whose outgoing messages mention
     * $marker -- the "observable invocation-count test double" T050 asks
     * for, so a bug that silently re-invoked stage 1 during resume would be
     * caught directly rather than only inferred indirectly.
     */
    private function serviceWithCountingProvider(array $responses, string $marker, array &$counters): AgentLoopService
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools, $options = []) use (&$responses, $marker, &$counters) {
            $this->assertNotEmpty($responses, 'the scripted response queue was exhausted -- the loop made more provider calls than this test expected');

            $encoded = json_encode($messages);
            if ($encoded !== false && str_contains($encoded, $marker)) {
                $counters[$marker] = ($counters[$marker] ?? 0) + 1;
            }

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

    /** Runs one RunSequenceStageJob invocation, counting chat() calls mentioning $marker into $counters. */
    private function runOneStage(string $runId, array $output, string $marker, array &$counters, string $status = 'success', string $summary = 'Stage complete.'): void
    {
        $service = $this->serviceWithCountingProvider([$this->delegationResultReply($output, $status, $summary)], $marker, $counters);
        $this->app->instance(AgentLoopService::class, $service);

        (new RunSequenceStageJob($runId))->handle(app(DelegationService::class), app(ContentSanitizer::class), app(SequenceService::class));
    }

    // =================================================================

    #[Test]
    public function resuming_a_failed_run_completes_from_the_failed_stage_without_re_invoking_earlier_stages(): void
    {
        $coordinator = $this->makeAgent('coordinator-resume');
        $helper = $this->makeAgent('helper-resume');
        app(AgentHelperService::class)->assign($this->user->id, $coordinator->id, $helper->id);

        $definition = app(SequenceService::class)->defineSequence($this->user->id, 'Resume sequence', null, $coordinator->id, [
            ['name' => 'Send notification', 'helper_agent_id' => $helper->id, 'is_idempotent' => false],
            ['name' => 'Check', 'helper_agent_id' => $helper->id],
            ['name' => 'Revise', 'helper_agent_id' => $helper->id],
            ['name' => 'Finish', 'helper_agent_id' => $helper->id],
        ]);

        Queue::fake();
        $result = app(SequenceService::class)->invoke($this->user->id, $definition->id, ['topic' => 'resume test']);
        $run = $result['sequence_run'];

        $counters = [];

        // Stage 1 ("Send notification") succeeds -- exactly one real
        // invocation so far.
        $this->runOneStage($run->id, ['notified' => true], 'Send notification', $counters);
        $this->assertSame(1, $counters['Send notification'] ?? 0);

        // Stage 2 succeeds.
        $this->runOneStage($run->id, ['checked' => true], 'Check', $counters);

        // Stage 3 fails on its first attempt.
        $this->runOneStage($run->id, [], 'Revise', $counters, 'failure', 'The reviser could not complete its task.');

        $run->refresh();
        $this->assertSame('failed', $run->status);

        $stages = Stage::where('sequence_definition_id', $definition->id)->orderBy('position')->get();
        $preResumeResults = StageResult::where('sequence_run_id', $run->id)->get()->keyBy('stage_id');

        $stage1Before = $preResumeResults[$stages[0]->id];
        $stage2Before = $preResumeResults[$stages[1]->id];
        $this->assertSame('completed', $stage1Before->status);
        $this->assertSame('completed', $stage2Before->status);
        $this->assertSame('failed', $preResumeResults[$stages[2]->id]->status);

        // Resume via the HTTP endpoint.
        Queue::fake();
        $response = $this->actingAs($this->user, 'api')
            ->postJson("/api/clarion-app/llm-client/sequence-runs/{$run->id}/resume");

        $response->assertStatus(202);
        $this->assertSame('resumed', $response->json('status'));
        $this->assertSame(1, $response->json('resume_count'));
        $this->assertSame(3, $response->json('resuming_from_stage_position'), 'resume must continue at stage 3 (position 3), the first non-completed stage');

        $run->refresh();
        $this->assertSame('resumed', $run->status);
        $this->assertSame(1, $run->resume_count);
        Queue::assertPushed(RunSequenceStageJob::class, fn (RunSequenceStageJob $job) => $job->sequenceRunId === $run->id);

        // Manually drive the resumed job through to completion (stage 3
        // retried, then stage 4) -- Queue::fake() means nothing runs on its
        // own.
        $this->runOneStage($run->id, ['revised' => true], 'Revise', $counters);
        $this->runOneStage($run->id, ['finished' => true], 'Finish', $counters);

        $run->refresh();
        $this->assertSame('completed', $run->status, 'the resumed run must reach completed once every stage is terminal');

        // Stage 1's own invocation count must be exactly 1, even after the
        // full resumed run has finished.
        $this->assertSame(1, $counters['Send notification'] ?? 0, "stage 1 must never be re-invoked by a resume that starts past it");

        // stage_results for stages 1-2 are untouched by the resume: same
        // id, same completed_at.
        $stage1After = StageResult::find($stage1Before->id);
        $stage2After = StageResult::find($stage2Before->id);
        $this->assertSame($stage1Before->id, $stage1After->id);
        $this->assertEquals($stage1Before->completed_at, $stage1After->completed_at);
        $this->assertSame($stage1Before->delegation_id, $stage1After->delegation_id);
        $this->assertSame($stage2Before->id, $stage2After->id);
        $this->assertEquals($stage2Before->completed_at, $stage2After->completed_at);
        $this->assertSame($stage2Before->delegation_id, $stage2After->delegation_id);

        // Stage 3 was genuinely re-invoked from scratch (new terminal
        // status, a Delegation row now exists where none did before).
        $stage3After = StageResult::where('sequence_run_id', $run->id)->where('stage_id', $stages[2]->id)->first();
        $this->assertSame('completed', $stage3After->status);
        $this->assertNotNull($stage3After->delegation_id);
        $this->assertSame(['revised' => true], json_decode($stage3After->output, true));

        // Total Delegation rows: stage1, stage2, stage3's first (failed)
        // attempt, stage3's resumed attempt, stage4 = 5.
        $this->assertSame(5, Delegation::count());
    }
}
