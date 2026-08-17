<?php

namespace ClarionApp\LlmClient\Tests\Unit\Commands;

use ClarionApp\LlmClient\ValueObjects\RunEndState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * No bespoke crash-recovery sweep exists for a RunSchedulerTriggerJob that
 * dies between opening and closing its run: the already-registered, already-
 * generic llm-client:resolve-abandoned-runs sweep covers it with zero code
 * change, because its own eligibility query
 * (ResolveAbandonedRunsCommand::findEligibleRuns()) filters only on
 * agent_runs.end_state and staleness -- it has no kind = 'interactive' filter
 * anywhere, so a kind = 'system_initiated' row a scheduler trigger's job left
 * in_progress is exactly as eligible as any other stale run.
 *
 * Assertion-only: this test proves the existing query's own behavior against
 * a stale system_initiated fixture; it makes no change to
 * ResolveAbandonedRunsCommand itself.
 */
class StaleSchedulerRunEligibleForAbandonmentSweepTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.run_trace.enabled', true);
        $this->app['config']->set('llm-client.run_trace.abandonment_minutes', 60);
    }

    protected function tearDown(): void
    {
        foreach (['agent_run_export_queue', 'agent_run_steps', 'agent_runs'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function a_stale_system_initiated_run_left_by_a_crashed_scheduler_job_is_swept_identically_to_an_interactive_one(): void
    {
        $userId = (string) Str::uuid();
        $runId = (string) Str::uuid();
        $staleTime = CarbonImmutable::now()->subMinutes(120);

        DB::table('agent_runs')->insert([
            'id' => $runId,
            'kind' => 'system_initiated',
            'user_id' => $userId,
            'end_state' => RunEndState::InProgress->value,
            'started_at' => $staleTime->format('Y-m-d H:i:s.u'),
            'step_count' => 0,
            'created_at' => $staleTime->format('Y-m-d H:i:s.u'),
        ]);

        $exitCode = Artisan::call('llm-client:resolve-abandoned-runs');

        $this->assertSame(0, $exitCode);

        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertNotNull($run);
        $this->assertSame(
            RunEndState::Abandoned->value,
            $run->end_state,
            'a stale system_initiated run (e.g. left by a crashed RunSchedulerTriggerJob) must be force-closed by the existing sweep, with no kind-specific carve-out needed',
        );
        $this->assertNotNull($run->ended_at);
        $this->assertNotNull($run->duration_ms);
    }
}
