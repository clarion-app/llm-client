<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Jobs\RunSequenceStageJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentHelperAssignment;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\SequenceRun;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\Stage;
use ClarionApp\LlmClient\Models\StageResult;
use ClarionApp\LlmClient\Models\StageSequenceDefinition;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentService;
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
 * 105-stage-pipeline, Phase 3 (US1), tasks.md T021.
 *
 * Unit tests for the not-yet-built `SequenceService::invoke()` (contracts
 * §3, data-model.md §3-§5/§8).
 *
 * Written before invoke() exists -- every test below is expected to FAIL
 * red until T026 lands.
 */
class SequenceServiceInvokeTest extends TestCase
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
     * @return array{StageSequenceDefinition, Agent, Agent}
     */
    private function makeDefinition(int $stageCount = 2): array
    {
        $coordinator = $this->makeAgent('coordinator-'.uniqid());
        $helper = $this->makeAgent('helper-'.uniqid());
        app(AgentHelperService::class)->assign($this->user->id, $coordinator->id, $helper->id);

        $stages = [];
        for ($i = 1; $i <= $stageCount; $i++) {
            $stages[] = ['name' => "Stage {$i}", 'helper_agent_id' => $helper->id];
        }

        $definition = app(SequenceService::class)->defineSequence($this->user->id, 'A definition', null, $coordinator->id, $stages);

        return [$definition, $coordinator, $helper];
    }

    // =================================================================

    #[Test]
    public function invoke_creates_a_run_a_dedicated_conversation_and_one_pending_stage_result_per_stage(): void
    {
        [$definition, $coordinator] = $this->makeDefinition(3);

        Queue::fake();

        $result = app(SequenceService::class)->invoke($this->user->id, $definition->id, ['topic' => 'Q3']);

        $this->assertArrayNotHasKey('error', $result);
        $run = $result['sequence_run'];
        $this->assertInstanceOf(SequenceRun::class, $run);
        $this->assertSame('in_progress', $run->status);
        $this->assertSame($definition->id, $run->sequence_definition_id);
        $this->assertSame($this->user->id, $run->owner_user_id);
        $this->assertNotNull($run->last_progress_at);
        $this->assertNotNull($run->started_at);
        $this->assertNull($run->completed_at);

        $conversation = Conversation::find($run->conversation_id);
        $this->assertNotNull($conversation, 'invoke() must create a dedicated Conversation row');
        $this->assertSame($coordinator->id, $conversation->agent_id, 'the dedicated Conversation must be bound to the definition\'s coordinator_agent_id');
        $this->assertSame($this->user->id, $conversation->user_id);

        $stageResults = StageResult::where('sequence_run_id', $run->id)->get();
        $this->assertCount(3, $stageResults, 'exactly one StageResult per Stage must be pre-created');
        foreach ($stageResults as $stageResult) {
            $this->assertSame('pending', $stageResult->status);
        }

        Queue::assertPushed(RunSequenceStageJob::class, fn (RunSequenceStageJob $job) => $job->sequenceRunId === $run->id);
    }

    #[Test]
    public function invoking_the_same_definition_twice_produces_two_independent_runs(): void
    {
        [$definition] = $this->makeDefinition(2);

        Queue::fake();

        $resultOne = app(SequenceService::class)->invoke($this->user->id, $definition->id, ['topic' => 'first']);
        $resultTwo = app(SequenceService::class)->invoke($this->user->id, $definition->id, ['topic' => 'second']);

        $this->assertNotSame($resultOne['sequence_run']->id, $resultTwo['sequence_run']->id);
        $this->assertSame(2, SequenceRun::where('sequence_definition_id', $definition->id)->count());
        $this->assertSame(4, StageResult::count(), 'each run must have its own independent StageResult rows');
    }

    #[Test]
    public function refuses_with_stage_unavailable_and_creates_no_run_when_a_helper_agent_is_deactivated(): void
    {
        [$definition, , $helper] = $this->makeDefinition(2);

        $helper->is_active = false;
        $helper->save();

        Queue::fake();

        $result = app(SequenceService::class)->invoke($this->user->id, $definition->id, ['topic' => 'x']);

        $this->assertSame('stage_unavailable', $result['error'] ?? null);
        $this->assertSame(1, $result['stage_position'] ?? null, 'must name the first (lowest-position) unavailable stage');
        $this->assertSame(0, SequenceRun::count(), 'a refused invoke() call must never create a SequenceRun row');
        $this->assertSame(0, StageResult::count());
        Queue::assertNotPushed(RunSequenceStageJob::class);
    }

    #[Test]
    public function refuses_with_stage_unavailable_and_creates_no_run_when_the_helper_assignment_is_revoked(): void
    {
        [$definition, $coordinator, $helper] = $this->makeDefinition(1);

        AgentHelperAssignment::where('parent_agent_id', $coordinator->id)
            ->where('helper_agent_id', $helper->id)
            ->delete();

        Queue::fake();

        $result = app(SequenceService::class)->invoke($this->user->id, $definition->id, ['topic' => 'x']);

        $this->assertSame('stage_unavailable', $result['error'] ?? null);
        $this->assertSame(0, SequenceRun::count());
        Queue::assertNotPushed(RunSequenceStageJob::class);
    }

    #[Test]
    public function refuses_with_stage_unavailable_when_the_coordinator_agent_is_deactivated(): void
    {
        [$definition, $coordinator] = $this->makeDefinition(1);

        $coordinator->is_active = false;
        $coordinator->save();

        Queue::fake();

        $result = app(SequenceService::class)->invoke($this->user->id, $definition->id, ['topic' => 'x']);

        $this->assertSame('stage_unavailable', $result['error'] ?? null);
        $this->assertSame(0, SequenceRun::count());
        Queue::assertNotPushed(RunSequenceStageJob::class);
    }
}
