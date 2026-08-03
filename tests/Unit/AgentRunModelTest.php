<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Models\AgentRunStep;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

class AgentRunModelTest extends TestCase
{
    #[Test]
    public function it_creates_a_run_with_auto_uuid()
    {
        $run = AgentRun::create([
            'kind' => RunKind::Interactive,
            'user_id' => (string) Str::uuid(),
            'conversation_id' => (string) Str::uuid(),
            'started_at' => now(),
            'created_at' => now(),
        ]);

        $this->assertNotNull($run->id);
        $this->assertTrue(Str::isUuid($run->id));
    }

    #[Test]
    public function it_has_timestamps_disabled()
    {
        $run = new AgentRun();
        $this->assertFalse($run->timestamps);
    }

    #[Test]
    public function it_does_not_use_eloquent_multichain_bridge()
    {
        $traits = class_uses_recursive(AgentRun::class);
        $this->assertNotContains(
            \ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge::class,
            $traits,
            'AgentRun must not use EloquentMultiChainBridge'
        );
    }

    #[Test]
    public function it_casts_kind_to_run_kind_enum()
    {
        $run = AgentRun::create([
            'kind' => RunKind::SystemInitiated,
            'user_id' => (string) Str::uuid(),
            'source' => 'title_generation',
            'started_at' => now(),
            'created_at' => now(),
        ]);

        $this->assertInstanceOf(RunKind::class, $run->kind);
        $this->assertSame(RunKind::SystemInitiated, $run->kind);
    }

    #[Test]
    public function it_casts_end_state_to_run_end_state_enum()
    {
        $userId = (string) Str::uuid();
        $run = AgentRun::create([
            'kind' => RunKind::Interactive,
            'user_id' => $userId,
            'conversation_id' => (string) Str::uuid(),
            'end_state' => RunEndState::Completed,
            'started_at' => now(),
            'created_at' => now(),
        ]);

        $this->assertInstanceOf(RunEndState::class, $run->end_state);
        $this->assertSame(RunEndState::Completed, $run->end_state);
    }

    #[Test]
    public function it_has_non_incrementing_string_key()
    {
        $run = new AgentRun();
        $this->assertFalse($run->incrementing);

        $ref = new \ReflectionClass(AgentRun::class);
        $prop = $ref->getProperty('keyType');
        $this->assertEquals('string', $prop->getValue($run));
    }

    #[Test]
    public function unique_run_id_position_rejects_duplicate_position_for_same_run()
    {
        $runId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $run = AgentRun::create([
            'kind' => RunKind::Interactive,
            'user_id' => $userId,
            'conversation_id' => (string) Str::uuid(),
            'started_at' => now(),
            'created_at' => now(),
        ]);
        $runId = $run->id;

        AgentRunStep::create([
            'run_id' => $runId,
            'position' => 1,
            'started_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        AgentRunStep::create([
            'run_id' => $runId,
            'position' => 1,
            'started_at' => now(),
        ]);
    }

    #[Test]
    public function unique_run_id_position_permits_same_position_under_different_run()
    {
        $userId = (string) Str::uuid();

        $run1 = AgentRun::create([
            'kind' => RunKind::Interactive,
            'user_id' => $userId,
            'conversation_id' => (string) Str::uuid(),
            'started_at' => now(),
            'created_at' => now(),
        ]);

        $run2 = AgentRun::create([
            'kind' => RunKind::Interactive,
            'user_id' => $userId,
            'conversation_id' => (string) Str::uuid(),
            'started_at' => now(),
            'created_at' => now(),
        ]);

        $step1 = AgentRunStep::create([
            'run_id' => $run1->id,
            'position' => 1,
            'started_at' => now(),
        ]);

        $step2 = AgentRunStep::create([
            'run_id' => $run2->id,
            'position' => 1,
            'started_at' => now(),
        ]);

        $this->assertEquals(1, $step1->position);
        $this->assertEquals(1, $step2->position);
        $this->assertNotEquals($step1->run_id, $step2->run_id);
    }
}
