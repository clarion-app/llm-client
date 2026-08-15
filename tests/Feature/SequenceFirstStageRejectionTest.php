<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Jobs\RunSequenceStageJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\Stage;
use ClarionApp\LlmClient\Models\StageResult;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\ContentSanitizer;
use ClarionApp\LlmClient\Services\DelegationService;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\SequenceService;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 105-stage-pipeline, Phase 5 (US3), tasks.md T042 (quickstart scenario 7,
 * Edge Case).
 *
 * starting_input rejected by stage 1's own input_schema. No scripted
 * LlmProvider is needed here at all -- stage 1's own agent must NEVER be
 * invoked when its own input_schema rejects the run's starting_input, so
 * this test exercises only SequenceService::invoke() + RunSequenceStageJob's
 * boundary check, with zero Delegation rows expected.
 *
 * Written before RunSequenceStageJob's boundary check exists (T044) --
 * expected to FAIL red until then.
 */
class SequenceFirstStageRejectionTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('stage_results')->delete();
        DB::table('sequence_runs')->delete();
        DB::table('stages')->delete();
        DB::table('stage_sequence_definitions')->delete();
        DB::table('agent_delegations')->delete();
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

    // =================================================================

    #[Test]
    public function starting_input_rejected_by_stage_1s_own_input_schema_stops_the_run_immediately(): void
    {
        $coordinator = $this->makeAgent('coordinator-firstreject');
        $helper = $this->makeAgent('helper-firstreject');
        app(AgentHelperService::class)->assign($this->user->id, $coordinator->id, $helper->id);

        $inputSchema = [
            'type' => 'object',
            'properties' => ['topic' => ['type' => 'string']],
            'required' => ['topic'],
        ];

        $definition = app(SequenceService::class)->defineSequence($this->user->id, 'First stage rejection sequence', null, $coordinator->id, [
            ['name' => 'Draft', 'helper_agent_id' => $helper->id, 'input_schema' => $inputSchema],
            ['name' => 'Check', 'helper_agent_id' => $helper->id],
            ['name' => 'Finish', 'helper_agent_id' => $helper->id],
        ]);

        Queue::fake();
        $result = app(SequenceService::class)->invoke($this->user->id, $definition->id, ['wrong_key' => 'oops']);
        $run = $result['sequence_run'];

        // No scripted LlmProvider is bound -- if the implementation
        // incorrectly calls delegate() for stage 1, the real (unmocked)
        // AgentLoopService would attempt to resolve a real provider and
        // this test would error loudly rather than silently pass.
        (new RunSequenceStageJob($run->id))->handle(app(DelegationService::class), app(ContentSanitizer::class), app(SequenceService::class));

        $run->refresh();
        $this->assertSame('failed', $run->status, 'the run must stop immediately, never reaching in_progress past stage 1');
        $this->assertNotNull($run->failure_reason);

        Queue::assertPushed(RunSequenceStageJob::class, 1, 'no further job may be dispatched once stage 1 itself is rejected');

        $stages = Stage::where('sequence_definition_id', $definition->id)->orderBy('position')->get();
        $results = StageResult::where('sequence_run_id', $run->id)->get()->keyBy('stage_id');

        $stage1Result = $results[$stages[0]->id];
        $this->assertSame('handoff_rejected', $stage1Result->status);
        $this->assertNull($stage1Result->delegation_id);
        $this->assertNotNull($stage1Result->failure_reason);
        $this->assertStringContainsString('topic', $stage1Result->failure_reason);

        $stage2Result = $results[$stages[1]->id];
        $this->assertSame('pending', $stage2Result->status);

        $stage3Result = $results[$stages[2]->id];
        $this->assertSame('pending', $stage3Result->status);

        // Zero completed stages -- stage 1 was rejected before its own
        // agent was ever invoked, so no Delegation row exists at all.
        $this->assertSame(0, Delegation::count());
        $this->assertSame(0, StageResult::where('sequence_run_id', $run->id)->where('status', 'completed')->count());
    }
}
