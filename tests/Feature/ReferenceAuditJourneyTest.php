<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Jobs\RunEvalCaseJob;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\EvalCaseExecutor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Acceptance Scenarios 1-2/FR-008/FR-009/FR-013: moving the reference
 * point to a different run is a deliberate, permitted write that never
 * discards the previous designation, and never rewrites a comparison
 * that was already computed for an older run before the move happened.
 *
 * Deliberately writes no new production code: designate() already
 * accepts re-pointing at a different run as a plain new-row insert, and
 * compare() already resolves the reference active as of the compared
 * run's own completion instant rather than whatever is current "now".
 * This test exists to prove that end to end against the real HTTP
 * surface rather than leave it as an incidental byproduct of other
 * tests.
 */
class ReferenceAuditJourneyTest extends TestCase
{
    private User $operator;
    private Server $agentServer;
    private string $agentLabel = 'reference-audit-agent';
    private string $suiteId;

    protected function setUp(): void
    {
        parent::setUp();

        // Every timestamp this feature reasons about (a run's completed_at,
        // a designation's created_at) is a second-precision `timestamp`
        // column, not a microsecond one. A real operator reviews a run's
        // results before promoting it, so completion and designation are
        // never genuinely simultaneous — pin the clock and advance it
        // explicitly between steps so each event lands in its own distinct
        // second, matching that real sequencing instead of racing against
        // however fast this process happens to execute.
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $this->declareSupportingSchema();

        $this->operator = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $this->agentServer = Server::create([
            'name' => 'Reference audit fixture server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->agentServer->id,
            'model' => 'test-model',
        ]);

        $this->fakeProvider();

        $this->suiteId = $this->createSuiteWithOneCheckableCase($this->agentLabel);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        Carbon::setTestNow();

        DB::table('eval_reference_designations')->delete();
        DB::table('eval_case_results')->delete();
        DB::table('eval_run_cases')->delete();
        DB::table('eval_runs')->delete();
        DB::table('eval_case_versions')->delete();
        DB::table('eval_cases')->delete();
        DB::table('eval_suites')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixture helpers
    // ---------------------------------------------------------------

    private function suitesBase(): string
    {
        return '/api/clarion-app/llm-client/agent-eval-suites';
    }

    private function runsBase(): string
    {
        return '/api/clarion-app/llm-client/eval-runs';
    }

    private function referenceUrl(string $runId): string
    {
        return $this->runsBase().'/'.$runId.'/reference';
    }

    private function suiteReferenceUrl(string $suiteId): string
    {
        return $this->suitesBase().'/'.$suiteId.'/reference';
    }

    private function suiteReferenceHistoryUrl(string $suiteId): string
    {
        return $this->suitesBase().'/'.$suiteId.'/reference/history';
    }

    private function comparisonUrl(string $runId): string
    {
        return $this->runsBase().'/'.$runId.'/comparison';
    }

    private function declareSupportingSchema(): void
    {
        // AgentLoopService::run() consults ConversationCondenser on every
        // call, unconditionally — the RunSuiteJourneyTest precedent.
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

    private function createSuiteWithOneCheckableCase(string $agentIdentifier): string
    {
        $suite = $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => 'Reference audit fixture suite',
            'agent_identifier' => $agentIdentifier,
        ])->assertStatus(200)->json();

        $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suite['id'].'/cases', [
            'given' => 'Say the word echo',
            'expected_behavior' => 'Answer with the single word echo.',
            'expectations' => [['kind' => 'text_match', 'expected_text' => 'echo']],
        ])->assertStatus(200)->json();

        return $suite['id'];
    }

    private function fakeProvider(): void
    {
        Http::fake();

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturn([
            'choices' => [['message' => ['content' => 'echo']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]);
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);
        $this->app->instance(ProviderRegistry::class, $registry);
    }

    private function runToCompletion(string $suiteId): array
    {
        Bus::fake([RunEvalCaseJob::class]);

        $run = $this->actingAs($this->operator)
            ->postJson($this->suitesBase().'/'.$suiteId.'/runs')
            ->assertStatus(201)
            ->json();

        foreach (Bus::dispatched(RunEvalCaseJob::class)->values()->all() as $job) {
            $job->handle(app(EvalCaseExecutor::class));
        }

        return $this->actingAs($this->operator)
            ->getJson($this->runsBase().'/'.$run['id'])
            ->assertStatus(200)
            ->json();
    }

    private function designate(string $runId)
    {
        return $this->actingAs($this->operator)->postJson($this->referenceUrl($runId));
    }

    private function getComparison(string $runId)
    {
        return $this->actingAs($this->operator)->getJson($this->comparisonUrl($runId));
    }

    // ---------------------------------------------------------------
    // AC1/AC2/FR-008/FR-009/FR-013: moving the reference is recorded,
    // both designations stay visible, and the move never rewrites a
    // comparison that already existed for an older run.
    // ---------------------------------------------------------------

    #[Test]
    public function moving_the_reference_is_audited_and_never_rewrites_an_already_computed_comparison(): void
    {
        // Step 1: run A becomes the reference.
        $runA = $this->runToCompletion($this->suiteId);
        Carbon::setTestNow(Carbon::now()->addSeconds(2));
        $originalDesignation = $this->designate($runA['id']);
        $originalDesignation->assertStatus(201);
        Carbon::setTestNow(Carbon::now()->addSeconds(2));

        // Run B, compared against A while A is still the active reference.
        $runB = $this->runToCompletion($this->suiteId);
        Carbon::setTestNow(Carbon::now()->addSeconds(2));
        $comparisonBeforeMove = $this->getComparison($runB['id']);
        $comparisonBeforeMove->assertStatus(200);
        $this->assertSame($runA['id'], $comparisonBeforeMove->json('reference_run_id'));
        Carbon::setTestNow(Carbon::now()->addSeconds(2));

        // Step 8: B is now accepted as the new normal and becomes the
        // reference itself — the deliberate move.
        $moveResponse = $this->designate($runB['id']);
        $moveResponse->assertStatus(201);
        $moveDesignation = $moveResponse->json();
        $this->assertSame($this->agentLabel, $moveDesignation['agent_label']);
        $this->assertSame($runB['id'], $moveDesignation['run_id']);
        $this->assertSame($this->operator->id, $moveDesignation['designated_by']);
        $this->assertNotEmpty($moveDesignation['designated_at']);

        // The suite's current reference now names B.
        $current = $this->actingAs($this->operator)->getJson($this->suiteReferenceUrl($this->suiteId));
        $current->assertStatus(200);
        $this->assertSame($runB['id'], $current->json('run_id'));

        // FR-013: both designations remain visible in the audit
        // history — A's original is never discarded by B's move.
        // Newest first, so B (the move) appears before A (the original).
        $history = $this->actingAs($this->operator)->getJson($this->suiteReferenceHistoryUrl($this->suiteId));
        $history->assertStatus(200);
        $entries = $history->json('data');
        $this->assertCount(2, $entries, 'both the original designation and the move must remain visible, nothing discarded');
        $this->assertSame($runB['id'], $entries[0]['run_id'], 'the history is newest-first — the move is entry 0');
        $this->assertSame($runA['id'], $entries[1]['run_id'], 'the original designation must still be present, not overwritten or deleted');
        $this->assertNotEmpty($entries[0]['designated_at']);
        $this->assertNotEmpty($entries[1]['designated_at']);

        // Step 9 (research.md D1/D6): re-reading run B's own comparison
        // — the run that just became the reference itself — must be
        // byte-identical to what it showed before the move. B's
        // comparison resolves against whichever reference was active at
        // B's own completion (A), not "whatever is current now" (which,
        // post-move, is B itself — comparing B against itself would be
        // a materially different, wrong result if this broke).
        $comparisonAfterMove = $this->getComparison($runB['id']);
        $comparisonAfterMove->assertStatus(200);
        $this->assertSame(
            $comparisonBeforeMove->json(),
            $comparisonAfterMove->json(),
            'moving the reference must never retroactively change a comparison that already existed for an older run'
        );
        $this->assertSame(
            $runA['id'],
            $comparisonAfterMove->json('reference_run_id'),
            'run B must keep comparing against A (the reference active when B itself completed), never against itself'
        );
    }

    // ---------------------------------------------------------------
    // FR-008/FR-009: two designations landing in the same wall-clock
    // second must still resolve deterministically by actual creation
    // order, never by an unspecified row/tie order. created_at is a
    // second-precision column, so this cannot be proven by advancing
    // the clock the way every other scenario in this file does — the
    // clock is deliberately held still across both designations here.
    // ---------------------------------------------------------------

    #[Test]
    public function two_designations_in_the_same_wall_clock_second_still_resolve_by_creation_order(): void
    {
        $runX = $this->runToCompletion($this->suiteId);
        $runY = $this->runToCompletion($this->suiteId);

        // The clock is not advanced between these two calls, so both
        // rows land with an identical second-precision created_at.
        $this->designate($runX['id'])->assertStatus(201);
        $this->designate($runY['id'])->assertStatus(201);

        $current = $this->actingAs($this->operator)->getJson($this->suiteReferenceUrl($this->suiteId));
        $current->assertStatus(200);
        $this->assertSame(
            $runY['id'],
            $current->json('run_id'),
            'the later designation must win as "current" even when both share the same wall-clock second'
        );

        $history = $this->actingAs($this->operator)->getJson($this->suiteReferenceHistoryUrl($this->suiteId));
        $history->assertStatus(200);
        $entries = $history->json('data');
        $this->assertCount(2, $entries);
        $this->assertSame($runY['id'], $entries[0]['run_id'], 'newest-first ordering must hold even on a created_at tie');
        $this->assertSame($runX['id'], $entries[1]['run_id'], 'the earlier same-second designation must still be present and correctly ordered second');

        // activeAt() shares the identical (created_at DESC, id DESC)
        // ordering as current()/history() above, so a comparison run
        // completed "now" (same frozen second) must resolve against Y,
        // the actual later designation, not X by accident of row order.
        $laterRun = $this->runToCompletion($this->suiteId);
        $comparison = $this->getComparison($laterRun['id']);
        $comparison->assertStatus(200);
        $this->assertSame(
            $runY['id'],
            $comparison->json('reference_run_id'),
            'a comparison resolved at this same instant must use the actually-later same-second designation'
        );
    }
}
