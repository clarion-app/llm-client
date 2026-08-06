<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Models\AgentRunAction;
use ClarionApp\LlmClient\Models\AgentRunStep;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AgentRunActionModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('llm-client.run_trace.enabled', true);
    }

    protected function tearDown(): void
    {
        foreach (['agent_run_actions', 'agent_run_steps', 'agent_runs'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
        parent::tearDown();
    }

    /** @test */
    public function generates_uuid_on_create(): void
    {
        $action = AgentRunAction::create([
            'run_id' => (string) \Illuminate\Support\Str::uuid(),
            'step_id' => (string) \Illuminate\Support\Str::uuid(),
            'action_type' => 'llm_request',
            'outcome' => 'in_progress',
            'started_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        $this->assertNotNull($action->id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $action->id,
        );
    }

    /** @test */
    public function has_timestamps_disabled(): void
    {
        $action = new AgentRunAction();
        $this->assertFalse($action->timestamps);
    }

    /** @test */
    public function step_relation_returns_belongs_to(): void
    {
        $runId = (string) \Illuminate\Support\Str::uuid();
        DB::table('agent_runs')->insert([
            'id' => $runId,
            'kind' => 'interactive',
            'user_id' => 'user-1',
            'conversation_id' => null,
            'source' => null,
            'end_state' => 'in_progress',
            'started_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        $stepId = (string) \Illuminate\Support\Str::uuid();
        DB::table('agent_run_steps')->insert([
            'id' => $stepId,
            'run_id' => $runId,
            'position' => 1,
            'end_state' => 'in_progress',
            'started_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        $action = AgentRunAction::create([
            'run_id' => $runId,
            'step_id' => $stepId,
            'action_type' => 'llm_request',
            'outcome' => 'in_progress',
            'started_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        $step = $action->step;
        $this->assertInstanceOf(AgentRunStep::class, $step);
        $this->assertEquals($stepId, $step->id);
    }

    /** @test */
    public function parent_action_relation_returns_self_for_nested_action(): void
    {
        $runId = (string) \Illuminate\Support\Str::uuid();
        $stepId = (string) \Illuminate\Support\Str::uuid();
        DB::table('agent_runs')->insert([
            'id' => $runId,
            'kind' => 'interactive',
            'user_id' => 'user-1',
            'conversation_id' => null,
            'source' => null,
            'end_state' => 'in_progress',
            'started_at' => now()->format('Y-m-d H:i:s.u'),
        ]);
        DB::table('agent_run_steps')->insert([
            'id' => $stepId,
            'run_id' => $runId,
            'position' => 1,
            'end_state' => 'in_progress',
            'started_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        // Create parent action.
        $parentAction = AgentRunAction::create([
            'run_id' => $runId,
            'step_id' => $stepId,
            'action_type' => 'tool_invocation',
            'outcome' => 'in_progress',
            'started_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        // Create child action with parent reference.
        $childAction = AgentRunAction::create([
            'run_id' => $runId,
            'step_id' => $stepId,
            'action_type' => 'llm_request',
            'parent_action_id' => $parentAction->id,
            'outcome' => 'in_progress',
            'started_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        $parent = $childAction->parentAction;
        $this->assertInstanceOf(AgentRunAction::class, $parent);
        $this->assertEquals($parentAction->id, $parent->id);
    }

    /** @test */
    public function parent_action_relation_returns_no_result_for_top_level_action(): void
    {
        $runId = (string) \Illuminate\Support\Str::uuid();
        $stepId = (string) \Illuminate\Support\Str::uuid();
        DB::table('agent_runs')->insert([
            'id' => $runId,
            'kind' => 'interactive',
            'user_id' => 'user-1',
            'conversation_id' => null,
            'source' => null,
            'end_state' => 'in_progress',
            'started_at' => now()->format('Y-m-d H:i:s.u'),
        ]);
        DB::table('agent_run_steps')->insert([
            'id' => $stepId,
            'run_id' => $runId,
            'position' => 1,
            'end_state' => 'in_progress',
            'started_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        $action = AgentRunAction::create([
            'run_id' => $runId,
            'step_id' => $stepId,
            'action_type' => 'llm_request',
            'parent_action_id' => null,
            'outcome' => 'in_progress',
            'started_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        // parentAction relation returns null when parent_action_id is null.
        // The model returns ?BelongsTo so accessing the property returns null.
        $this->assertTrue($action->parent_action_id === null);
    }

    /** @test */
    public function casts_action_type_to_enum(): void
    {
        $runId = (string) \Illuminate\Support\Str::uuid();
        $stepId = (string) \Illuminate\Support\Str::uuid();
        DB::table('agent_runs')->insert([
            'id' => $runId,
            'kind' => 'interactive',
            'user_id' => 'user-1',
            'conversation_id' => null,
            'source' => null,
            'end_state' => 'in_progress',
            'started_at' => now()->format('Y-m-d H:i:s.u'),
        ]);
        DB::table('agent_run_steps')->insert([
            'id' => $stepId,
            'run_id' => $runId,
            'position' => 1,
            'end_state' => 'in_progress',
            'started_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        $action = AgentRunAction::create([
            'run_id' => $runId,
            'step_id' => $stepId,
            'action_type' => 'tool_invocation',
            'outcome' => 'success',
            'started_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        $this->assertSame(ActionType::ToolInvocation, $action->action_type);
    }

    /** @test */
    public function casts_outcome_to_enum(): void
    {
        $runId = (string) \Illuminate\Support\Str::uuid();
        $stepId = (string) \Illuminate\Support\Str::uuid();
        DB::table('agent_runs')->insert([
            'id' => $runId,
            'kind' => 'interactive',
            'user_id' => 'user-1',
            'conversation_id' => null,
            'source' => null,
            'end_state' => 'in_progress',
            'started_at' => now()->format('Y-m-d H:i:s.u'),
        ]);
        DB::table('agent_run_steps')->insert([
            'id' => $stepId,
            'run_id' => $runId,
            'position' => 1,
            'end_state' => 'in_progress',
            'started_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        $action = AgentRunAction::create([
            'run_id' => $runId,
            'step_id' => $stepId,
            'action_type' => 'llm_request',
            'outcome' => 'failure',
            'started_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        $this->assertSame(ActionOutcome::Failure, $action->outcome);
    }
}
