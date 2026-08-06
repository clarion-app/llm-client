<?php

namespace Tests\Unit;

use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * contracts/trace-id-propagation.md §2 (P1-P3): openRun()/closeRun() manage the
 * single ambient Context key 'run_id' every other stamping mechanism in this
 * feature (Message/ToolInvocationRecord/UsageRecord creating listeners,
 * RunTraceQuery reads, log correlation) assumes is already correct. These
 * tests drive RunTraceRecorder directly, independent of the model listeners.
 */
class RunTraceRecorderContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.run_trace.enabled', true);
        Context::forget('run_id');
    }

    protected function tearDown(): void
    {
        Context::forget('run_id');

        foreach (['agent_run_messages', 'agent_run_steps', 'agent_runs'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    // ========== openRun() — P1 ==========

    /** @test */
    public function open_run_sets_context_run_id_on_success(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);

        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');

        $this->assertNotNull($runId);
        $this->assertSame($runId, Context::get('run_id'));
    }

    /** @test */
    public function open_run_leaves_context_untouched_when_disabled(): void
    {
        // Seed Context as if a different, already-open run left its id there.
        // A disabled openRun() must not clear a value it never set (P1).
        $sentinel = 'sentinel-belonging-to-another-run';
        Context::add('run_id', $sentinel);

        $this->app['config']->set('llm-client.run_trace.enabled', false);
        $recorder = $this->app->make(RunTraceRecorder::class);

        $result = $recorder->openRun(RunKind::Interactive, 'user-1');

        $this->assertNull($result);
        $this->assertSame($sentinel, Context::get('run_id'));
    }

    /** @test */
    public function open_run_leaves_context_untouched_when_insert_fails(): void
    {
        $sentinel = 'sentinel-belonging-to-another-run';
        Context::add('run_id', $sentinel);

        // Force the insert to fail.
        DB::statement('DROP TABLE IF EXISTS agent_runs');

        $recorder = $this->app->make(RunTraceRecorder::class);
        $result = $recorder->openRun(RunKind::Interactive, 'user-1');

        $this->assertNull($result);
        $this->assertSame(
            $sentinel,
            Context::get('run_id'),
            'A failed insert never mints a run id, so openRun() must not touch Context (P1)'
        );
    }

    // ========== closeRun() — P2 ==========

    /** @test */
    public function close_run_clears_context_on_success(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');
        $this->assertSame($runId, Context::get('run_id'));

        $recorder->closeRun($runId, RunEndState::Completed);

        $this->assertNull(Context::get('run_id'));
    }

    /** @test */
    public function close_run_clears_context_on_already_terminal_early_return(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');
        $recorder->closeRun($runId, RunEndState::Completed);

        // Simulate a stray Context value surviving into a second, no-op close
        // of the now-terminal run — the "already terminal" early-return path.
        Context::add('run_id', $runId);

        $recorder->closeRun($runId, RunEndState::Failed, 'retry after terminal');

        $this->assertNull(
            Context::get('run_id'),
            'closeRun() must clear Context even on the already-terminal early-return path (P2)'
        );
    }

    /** @test */
    public function close_run_clears_context_on_forced_failure_catch_path(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, 'user-1');
        $this->assertSame($runId, Context::get('run_id'));

        // Force closeRun()'s own body to throw (it queries agent_run_steps for
        // step_count before its terminal UPDATE), exercising the catch-path
        // clear rather than the success-path clear.
        DB::statement('DROP TABLE IF EXISTS agent_run_steps');

        $warned = false;
        Log::listen(function ($entry) use (&$warned) {
            if ($entry->level === 'warning') {
                $warned = true;
            }
        });

        $recorder->closeRun($runId, RunEndState::Completed);

        $this->assertTrue($warned, 'Expected a warning log entry from the forced failure');
        $this->assertNull(
            Context::get('run_id'),
            'closeRun() must clear Context even when the close itself fails (P2)'
        );
    }
}
