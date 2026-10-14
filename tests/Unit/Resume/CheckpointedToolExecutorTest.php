<?php

namespace Tests\Unit\Resume;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\FaultInjector;
use ClarionApp\LlmClient\Exceptions\CheckpointWriteFailed;
use ClarionApp\LlmClient\Exceptions\LeaseLost;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\CheckpointedToolExecutor;
use ClarionApp\LlmClient\Services\RunCheckpoint;
use ClarionApp\LlmClient\Services\ToolEffectClassifier;
use ClarionApp\LlmClient\Services\ToolLivenessBound;
use ClarionApp\LlmClient\ValueObjects\ResumeDriver;
use ClarionApp\LlmClient\ValueObjects\ResumeMode;
use ClarionApp\LlmClient\ValueObjects\StepRef;
use ClarionApp\LlmClient\ValueObjects\ToolEffect;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The CheckpointedToolExecutor (checkpoint-ledger contract §2): the funnel
 * that brackets every tool execution with C3 (started, before the call) and
 * C4 (completed, after). The exactly-once contract is pinned here: the start
 * marker is written before the closure runs, a failed start marker never runs
 * the closure, the call id is stamped into Context and restored, the fault
 * points fire in order, the lease is extended to the tool bound, a lost lease
 * propagates without running the closure, and the bracketing is a no-op (the
 * tool still runs) when the run is not checkpointed.
 */
class CheckpointedToolExecutorTest extends TestCase
{
    private Conversation $conversation;

    /** @var object a FaultInjector that records the points it is reached at, in order */
    private object $faultRecorder;

    private function makeRun(string $id, array $overrides = []): void
    {
        DB::table('agent_runs')->insert(array_merge([
            'id' => $id,
            'kind' => 'interactive',
            'user_id' => '11111111-1111-1111-1111-111111111111',
            'started_at' => now()->format('Y-m-d H:i:s.u'),
            'end_state' => 'in_progress',
        ], $overrides));
    }

    private function makeStep(string $id, string $runId, int $position, array $overrides = []): void
    {
        DB::table('agent_run_steps')->insert(array_merge([
            'id' => $id,
            'run_id' => $runId,
            'position' => $position,
            'started_at' => now()->format('Y-m-d H:i:s.u'),
            'end_state' => 'in_progress',
        ], $overrides));
    }

    private function runRow(string $id): object
    {
        $row = DB::table('agent_runs')->where('id', $id)->first();
        $this->assertNotNull($row, "run {$id} must exist");

        return $row;
    }

    /**
     * A run and step ready for a tool call: the lease is claimed by this
     * process, the step is open, and the model response (with one call) is
     * recorded, so the call is `not_started` in the checkpoint.
     */
    private function readyStep(string $runId = 'run-1', string $stepId = 'step-1', string $callId = 'call_x'): void
    {
        $this->makeRun($runId);
        $this->makeStep($stepId, $runId, 1);
        $checkpoint = app(RunCheckpoint::class);
        $checkpoint->openRun($runId, ResumeMode::Sync, ResumeDriver::Root, []);
        $checkpoint->openStep($runId, $stepId, 1);
        $checkpoint->recordModelResponse($stepId, 'I will call the tool.', [
            ['id' => $callId, 'type' => 'function', 'function' => ['name' => 'execute_operation', 'arguments' => json_encode(['operationId' => 'op.x'])]],
        ]);
    }

    private function toolCall(string $id): array
    {
        return ['id' => $id, 'type' => 'function', 'function' => ['name' => 'execute_operation', 'arguments' => json_encode(['operationId' => 'op.x'])]];
    }

    private function step(string $runId = 'run-1', string $stepId = 'step-1'): StepRef
    {
        return new StepRef($runId, $stepId, 1);
    }

    private function executor(): CheckpointedToolExecutor
    {
        return app(CheckpointedToolExecutor::class);
    }

    private function checkpointOf(string $stepId): array
    {
        $decoded = json_decode((string) DB::table('agent_run_steps')->where('id', $stepId)->value('checkpoint'), true);

        return is_array($decoded) ? $decoded : ['calls' => []];
    }

    private function callState(string $stepId, string $callId): ?string
    {
        return $this->checkpointOf($stepId)['calls'][$callId]['state'] ?? null;
    }

    private function callResult(string $stepId, string $callId): mixed
    {
        return $this->checkpointOf($stepId)['calls'][$callId]['result'] ?? null;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The classifier and the liveness bound are pinned to fixed answers so
        // the funnel's own behaviour is what is under test; the real
        // RunCheckpoint is used (its strictness is what the marker tests
        // exercise). The fault injector records its points in order.
        $classifier = Mockery::mock(ToolEffectClassifier::class);
        $classifier->shouldReceive('classify')->andReturn(ToolEffect::Effectful);
        $this->app->instance(ToolEffectClassifier::class, $classifier);

        $bound = Mockery::mock(ToolLivenessBound::class);
        $bound->shouldReceive('for')->andReturn(120);
        $bound->shouldReceive('leaseSeconds')->andReturn(300);
        $this->app->instance(ToolLivenessBound::class, $bound);

        $this->faultRecorder = new class implements FaultInjector {
            /** @var list<string> */
            public array $points = [];

            public function reach(string $point): void
            {
                $this->points[] = $point;
            }
        };
        $this->app->instance(FaultInjector::class, $this->faultRecorder);

        $user = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Funnel User',
            'email' => 'funnel@example.com',
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);
        $server = Server::create([
            'id' => (string) Str::uuid(),
            'name' => 'Funnel Server',
            'provider_type' => 'openai',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test-token',
        ]);
        $this->conversation = Conversation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'server_id' => $server->id,
            'model' => 'gpt-4',
            'title' => 'Funnel conversation',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // The start marker is written before the closure runs
    // ---------------------------------------------------------------

    #[Test]
    public function start_marker_written_before_closure(): void
    {
        $this->readyStep();
        $stateInside = null;

        $result = $this->executor()->execute($this->step(), $this->toolCall('call_x'), function () use (&$stateInside) {
            $stateInside = $this->callState('step-1', 'call_x');

            return 'raw-result';
        }, 'action-1', $this->conversation);

        $this->assertSame('raw-result', $result, 'the raw result is returned exactly as the closure returned it');
        $this->assertSame('started', $stateInside, 'inside the closure the step checkpoint already shows started');
    }

    // ---------------------------------------------------------------
    // A failed start marker never runs the closure (quickstart M2)
    // ---------------------------------------------------------------

    #[Test]
    public function closure_not_called_when_start_marker_fails(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);

        $checkpoint = Mockery::mock(RunCheckpoint::class);
        $checkpoint->shouldReceive('markStarted')->andThrow(new CheckpointWriteFailed('the start marker could not be written', 'test'));
        $checkpoint->shouldReceive('markCompleted')->zeroOrMoreTimes();
        $checkpoint->shouldReceive('markPaused')->zeroOrMoreTimes();
        $this->app->instance(RunCheckpoint::class, $checkpoint);

        $called = false;
        $thrown = null;
        try {
            $this->executor()->execute($this->step(), $this->toolCall('call_x'), function () use (&$called) {
                $called = true;

                return 'raw';
            }, 'action-1', $this->conversation);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(CheckpointWriteFailed::class, $thrown, 'the start marker failure propagates');
        $this->assertFalse($called, 'the closure is never invoked when the start marker fails');
    }

    // ---------------------------------------------------------------
    // recordCompleted writes C4
    // ---------------------------------------------------------------

    #[Test]
    public function recordCompleted_writes_C4(): void
    {
        $this->readyStep();
        $this->executor()->execute($this->step(), $this->toolCall('call_x'), fn () => 'raw', 'action-1', $this->conversation);

        $this->executor()->recordCompleted($this->step(), 'call_x', ['tool_call_id' => 'call_x', 'content' => 'done']);

        $this->assertSame('completed', $this->callState('step-1', 'call_x'), 'the call is marked completed');
        $this->assertSame(['tool_call_id' => 'call_x', 'content' => 'done'], $this->callResult('step-1', 'call_x'), 'the result is stored on the call');
    }

    // ---------------------------------------------------------------
    // The call id is stamped into Context and restored
    // ---------------------------------------------------------------

    #[Test]
    public function context_tool_call_id_stamped_and_restored(): void
    {
        $this->readyStep();
        Context::add('tool_call_id', 'previous-value');
        $seenInside = null;

        $this->executor()->execute($this->step(), $this->toolCall('call_x'), function () use (&$seenInside) {
            $seenInside = Context::get('tool_call_id');

            return 'raw';
        }, 'action-1', $this->conversation);

        $this->assertSame('call_x', $seenInside, 'inside the closure tool_call_id is the call id');
        $this->assertSame('previous-value', Context::get('tool_call_id'), 'after the call it is restored to the previous value');
    }

    #[Test]
    public function context_tool_call_id_restored_when_closure_throws(): void
    {
        $this->readyStep();
        Context::add('tool_call_id', 'previous-value');

        $thrown = null;
        try {
            $this->executor()->execute($this->step(), $this->toolCall('call_x'), function () {
                throw new \RuntimeException('the tool blew up');
            }, 'action-1', $this->conversation);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown, 'the closure failure propagates');
        $this->assertSame('previous-value', Context::get('tool_call_id'), 'the call id is restored even when the closure throws');
    }

    // ---------------------------------------------------------------
    // The fault points fire in order
    // ---------------------------------------------------------------

    #[Test]
    public function fault_points_in_order(): void
    {
        $this->readyStep();
        $this->executor()->execute($this->step(), $this->toolCall('call_x'), fn () => 'raw', 'action-1', $this->conversation);
        $this->executor()->recordCompleted($this->step(), 'call_x', ['tool_call_id' => 'call_x', 'content' => 'done']);

        $this->assertSame(
            ['before_tool_started', 'after_tool_started', 'after_tool_effect', 'after_tool_recorded'],
            $this->faultRecorder->points,
            'the fault points fire before_tool_started, after_tool_started, after_tool_effect, then after_tool_recorded'
        );
    }

    // ---------------------------------------------------------------
    // The lease is extended to the tool bound
    // ---------------------------------------------------------------

    #[Test]
    public function lease_extended_to_tool_bound(): void
    {
        // A bound large enough that bound + grace exceeds the detection
        // window, so the extension is observable against the window base.
        $bound = Mockery::mock(ToolLivenessBound::class);
        $bound->shouldReceive('for')->andReturn(400);
        $bound->shouldReceive('leaseSeconds')->andReturn(460); // max(300, 400 + 60)
        $this->app->instance(ToolLivenessBound::class, $bound);

        $this->readyStep();
        $this->executor()->execute($this->step(), $this->toolCall('call_x'), fn () => 'raw', 'action-1', $this->conversation);

        $leaseExpires = Carbon::parse((string) $this->runRow('run-1')->lease_expires_at);
        $floor = Carbon::now()->addSeconds(460)->subSeconds(5);
        $this->assertTrue(
            $leaseExpires->greaterThanOrEqualTo($floor),
            "lease_expires_at ({$this->runRow('run-1')->lease_expires_at}) is extended to ~now + bound + grace"
        );
    }

    // ---------------------------------------------------------------
    // A lost lease propagates and never runs the closure
    // ---------------------------------------------------------------

    #[Test]
    public function lease_lost_propagates_and_closure_not_called(): void
    {
        // The run is held by a foreign process: the start marker's lease
        // guard fails with LeaseLost before the tool is touched.
        $this->makeRun('run-1', ['lease_holder' => 'a-foreign-process-nonce', 'resume_state' => 'working']);
        $this->makeStep('step-1', 'run-1', 1);

        $called = false;
        $thrown = null;
        try {
            $this->executor()->execute($this->step(), $this->toolCall('call_x'), function () use (&$called) {
                $called = true;

                return 'raw';
            }, 'action-1', $this->conversation);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(LeaseLost::class, $thrown, 'the lost lease propagates');
        $this->assertFalse($called, 'the closure is never invoked when the lease is lost');
    }

    // ---------------------------------------------------------------
    // The bracketing is a no-op when the run is not checkpointed, but
    // the tool still runs
    // ---------------------------------------------------------------

    #[Test]
    public function noop_bracketing_when_run_id_null(): void
    {
        // Resume disabled: every checkpoint write is a no-op, but the funnel
        // must still run the tool and return its raw result.
        config(['llm-client.resume.enabled' => false]);
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);

        $called = false;
        $result = $this->executor()->execute($this->step(), $this->toolCall('call_x'), function () use (&$called) {
            $called = true;

            return 'raw-noop';
        }, 'action-1', $this->conversation);

        $this->assertSame('raw-noop', $result, 'the closure still runs and its raw result is returned');
        $this->assertTrue($called, 'the closure was invoked');
    }
}
