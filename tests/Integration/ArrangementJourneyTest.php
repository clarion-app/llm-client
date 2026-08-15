<?php

namespace ClarionApp\LlmClient\Tests\Integration;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Services\DelegationQuery;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * 106-multi-agent-run-view, Phase 3 (US1), tasks.md T010.
 *
 * A run with a mixed tree -- one solo hand-off, one 2-member parallel
 * batch, and one further nested delegation from a helper -- fetched
 * end-to-end through `GET /agent-runs/{runId}/arrangement` reconstructs to
 * the exact same shape a direct `DelegationQuery::arrangementForRun()` read
 * would produce (proving the controller/route layer adds nothing and drops
 * nothing relative to the service it wraps).
 */
class ArrangementJourneyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private RunTraceRecorder $recorder;
    private DelegationQuery $query;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.run_trace.enabled', true);

        $this->user = User::factory()->create();
        $this->recorder = $this->app->make(RunTraceRecorder::class);
        $this->query = $this->app->make(DelegationQuery::class);
    }

    protected function tearDown(): void
    {
        DB::table('agent_delegations')->delete();
        DB::table('agent_run_actions')->delete();
        DB::table('agent_run_steps')->delete();
        DB::table('agent_runs')->delete();

        parent::tearDown();
    }

    private function makeRun(RunKind $kind = RunKind::SystemInitiated): string
    {
        $runId = $this->recorder->openRun($kind, $this->user->id);
        $this->recorder->closeRun($runId, RunEndState::Completed);

        return $runId;
    }

    private function makeDelegationRow(string $parentRunId, ?string $helperRunId, array $overrides = []): Delegation
    {
        return Delegation::create(array_merge([
            'parent_conversation_id' => (string) Str::uuid(),
            'helper_agent_id' => (string) Str::uuid(),
            'helper_conversation_id' => (string) Str::uuid(),
            'owner_user_id' => $this->user->id,
            'task' => 'A mixed-tree fixture task.',
            'depth' => 1,
            'status' => 'completed',
            'batch_id' => null,
            'parent_run_id' => $parentRunId,
            'parent_action_id' => null,
            'helper_run_id' => $helperRunId,
            'outcome_summary' => 'Completed normally.',
            'started_at' => now(),
            'completed_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function end_to_end_fetch_reconstructs_the_same_shape_as_a_direct_query_read(): void
    {
        $rootRunId = $this->recorder->openRun(RunKind::Interactive, $this->user->id);
        $this->recorder->closeRun($rootRunId, RunEndState::Completed);

        // One solo hand-off.
        $soloHelperRunId = $this->makeRun();
        $this->makeDelegationRow($rootRunId, $soloHelperRunId, ['depth' => 1]);

        // One 2-member parallel batch.
        $batchId = (string) Str::uuid();
        $batchMemberOneRunId = $this->makeRun();
        $batchMemberTwoRunId = $this->makeRun();
        $this->makeDelegationRow($rootRunId, $batchMemberOneRunId, ['depth' => 1, 'batch_id' => $batchId]);
        $this->makeDelegationRow($rootRunId, $batchMemberTwoRunId, ['depth' => 1, 'batch_id' => $batchId]);

        // A further nested delegation from the solo helper.
        $nestedHelperRunId = $this->makeRun();
        $this->makeDelegationRow($soloHelperRunId, $nestedHelperRunId, ['depth' => 2]);

        $directResult = $this->query->arrangementForRun($this->user->id, $rootRunId);
        $this->assertNotNull($directResult, 'fixture sanity: the direct query read must succeed');
        $this->assertCount(4, $directResult['delegations'], 'fixture sanity: 1 solo + 2 batch + 1 nested = 4 delegations');

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$rootRunId}/arrangement");

        $response->assertStatus(200);

        $this->assertSame(
            $directResult,
            $response->json(),
            'GET /agent-runs/{runId}/arrangement must reconstruct byte-identically to a direct DelegationQuery::arrangementForRun() read',
        );
    }
}
