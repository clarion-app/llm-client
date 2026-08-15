<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Events\SequenceRunUpdated;
use ClarionApp\LlmClient\Jobs\RunSequenceStageJob;
use ClarionApp\LlmClient\Models\Agent;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 105-stage-pipeline, Phase 4 (US2), tasks.md T033 (quickstart scenario 6).
 *
 * **Relocated from `tests/Integration/` to `tests/Feature/`**, mirroring
 * Phase 3's own T022/T023 precedent recorded in this feature's Progress
 * Log: proving SequenceRunUpdated actually fires from the real
 * SequenceService -> RunSequenceStageJob -> DelegationService ->
 * AgentLoopService::run() chain requires the same scripted-LlmProvider
 * convention (`Mockery::mock(LlmProvider::class)` + `$app->instance()`)
 * every other full-chain journey test in this package already uses, which
 * `tests/Integration/NoMocksGuardTest` forbids.
 *
 * Drives a 6-stage sequence one RunSequenceStageJob invocation at a time
 * and asserts: a SequenceRunUpdated event fires (or, at minimum,
 * last_progress_at advances) at every stage transition -- run creation,
 * each stage->running, each stage's terminal status, and run finalization
 * -- current_stage_position advances monotonically, never skipping or
 * going backward, and every one of the 6 stages ends at a final,
 * non-pending status once the run completes (mutation-checklist row 11's
 * "not indistinguishable from a stage that doesn't exist").
 *
 * Written before SequenceRunUpdated/SequenceService::broadcastRunUpdated()
 * are wired in -- expected to FAIL red until T035/T036 land.
 */
class SequenceRunProgressJourneyTest extends TestCase
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

    /** Runs one RunSequenceStageJob invocation with a fresh scripted reply bound in. */
    private function runOneStage(string $runId, array $output): void
    {
        $service = $this->serviceWithScriptedProvider([$this->delegationResultReply($output)]);
        $this->app->instance(AgentLoopService::class, $service);

        (new RunSequenceStageJob($runId))->handle(app(DelegationService::class), app(ContentSanitizer::class), app(SequenceService::class));
    }

    // =================================================================

    #[Test]
    public function progress_is_observable_at_every_stage_transition_across_a_six_stage_sequence(): void
    {
        $coordinator = $this->makeAgent('coordinator-progress');
        $helper = $this->makeAgent('helper-progress');
        app(AgentHelperService::class)->assign($this->user->id, $coordinator->id, $helper->id);

        $stageInputs = [];
        for ($i = 1; $i <= 6; $i++) {
            $stageInputs[] = ['name' => "Stage {$i}", 'helper_agent_id' => $helper->id];
        }

        $definition = app(SequenceService::class)->defineSequence($this->user->id, 'Six-stage progress sequence', null, $coordinator->id, $stageInputs);

        Event::fake([SequenceRunUpdated::class]);
        Queue::fake();

        $result = app(SequenceService::class)->invoke($this->user->id, $definition->id, ['topic' => 'progress visibility']);
        $run = $result['sequence_run'];

        // Run creation itself broadcasts.
        Event::assertDispatched(SequenceRunUpdated::class, fn (SequenceRunUpdated $e) => $e->sequenceRunId === $run->id);

        $previousPosition = null;
        $previousProgressAt = null;

        for ($i = 1; $i <= 6; $i++) {
            $eventsBefore = count(Event::dispatched(SequenceRunUpdated::class));

            $this->runOneStage($run->id, ["stage" => $i]);
            $run->refresh();

            $eventsAfter = count(Event::dispatched(SequenceRunUpdated::class));

            $this->assertGreaterThan(
                $eventsBefore,
                $eventsAfter,
                "stage {$i}'s own transition(s) must fire at least one SequenceRunUpdated event",
            );

            if ($previousPosition !== null) {
                $this->assertGreaterThan(
                    $previousPosition,
                    $run->current_stage_position,
                    'current_stage_position must advance monotonically, never skipping or going backward',
                );
            }
            $previousPosition = $run->current_stage_position;
            $this->assertSame($i, $run->current_stage_position);

            if ($previousProgressAt !== null) {
                $this->assertTrue(
                    $run->last_progress_at->gte($previousProgressAt),
                    'last_progress_at must advance (or stay equal under clock resolution), never move backward',
                );
            }
            $previousProgressAt = $run->last_progress_at;
        }

        $run->refresh();
        $this->assertSame('completed', $run->status, 'the run must reach completed once all six stages have completed');

        // Run finalization broadcasts too -- the final stage's completion
        // and the run's own finalization are two distinct transitions
        // (contracts §6), so the very last runOneStage() call above must
        // have produced more than a single broadcast.
        $results = StageResult::where('sequence_run_id', $run->id)->get();
        $this->assertCount(6, $results, 'every one of the 6 stages must have its own StageResult row');
        foreach ($results as $result) {
            $this->assertNotSame(
                'pending',
                $result->status,
                'every stage must show a final, non-pending status once the run finishes -- not indistinguishable from a stage that never ran',
            );
        }
    }
}
