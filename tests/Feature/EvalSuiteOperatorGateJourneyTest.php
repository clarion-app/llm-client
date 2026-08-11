<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use Illuminate\Support\Facades\DB;

use PHPUnit\Framework\Attributes\Test;

/**
 * FR-018 across every route this feature adds (research.md D1): a
 * non-operator is refused everywhere, including plain reads, with no row
 * created or changed by any write attempt; an operator succeeds on the
 * identical calls (positive control), so a gate that refuses everyone
 * equally cannot pass this test by accident.
 */
class EvalSuiteOperatorGateJourneyTest extends TestCase
{
    private User $operator;
    private User $nonOperator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->create();
        $this->nonOperator = User::factory()->create();

        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);
    }

    protected function tearDown(): void
    {
        DB::table('eval_case_versions')->delete();
        DB::table('eval_cases')->delete();
        DB::table('eval_suites')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function base(): string
    {
        return '/api/clarion-app/llm-client/agent-eval-suites';
    }

    private function casesEndpoint(string $suiteId): string
    {
        return $this->base().'/'.$suiteId.'/cases';
    }

    private function caseExpectations(): array
    {
        return [
            ['kind' => 'action_taken', 'action' => 'orders.create'],
        ];
    }

    /**
     * A suite plus one case, created by the operator, used as the fixed
     * target every non-operator attempt below is made against — so a 403
     * is proven against a real, resolvable resource, not merely a 404 in
     * disguise.
     *
     * @return array{suite: array, case: array}
     */
    private function createSuiteAndCaseAsOperator(): array
    {
        $suite = $this->actingAs($this->operator)->postJson($this->base(), [
            'name' => 'Operator gate fixture suite',
            'agent_identifier' => 'home-automation-agent',
        ])->assertStatus(200)->json();

        $case = $this->actingAs($this->operator)->postJson($this->casesEndpoint($suite['id']), [
            'given' => 'given',
            'expected_behavior' => 'expected behavior',
            'expectations' => $this->caseExpectations(),
        ])->assertStatus(200)->json();

        return ['suite' => $suite, 'case' => $case];
    }

    private function importDocument(string $name, string $agentIdentifier): array
    {
        return [
            'schema_version' => 1,
            'name' => $name,
            'agent_identifier' => $agentIdentifier,
            'cases' => [
                [
                    'given' => 'given',
                    'expected_behavior' => 'expected behavior',
                    'expectations' => $this->caseExpectations(),
                ],
            ],
        ];
    }

    // ---------------------------------------------------------------
    // Non-operator is refused on every route, no row created or changed
    // ---------------------------------------------------------------

    #[Test]
    public function a_non_operator_is_refused_on_every_route_and_no_row_is_created_or_changed(): void
    {
        $fixture = $this->createSuiteAndCaseAsOperator();
        $suiteId = $fixture['suite']['id'];
        $caseId = $fixture['case']['id'];

        $suiteCountBefore = DB::table('eval_suites')->count();
        $caseCountBefore = DB::table('eval_cases')->count();
        $versionCountBefore = DB::table('eval_case_versions')->count();
        $suiteRowBefore = DB::table('eval_suites')->where('id', $suiteId)->first();
        $caseRowBefore = DB::table('eval_cases')->where('id', $caseId)->first();

        $as = fn () => $this->actingAs($this->nonOperator);

        // Suite CRUD (contracts §2)
        $as()->getJson($this->base())->assertStatus(403);
        $as()->postJson($this->base(), [
            'name' => 'Should never be created',
            'agent_identifier' => 'intruder-agent',
        ])->assertStatus(403);
        $as()->getJson($this->base().'/'.$suiteId)->assertStatus(403);
        $as()->putJson($this->base().'/'.$suiteId, [
            'name' => 'Should never be renamed',
        ])->assertStatus(403);
        $as()->deleteJson($this->base().'/'.$suiteId)->assertStatus(403);

        // Case CRUD (contracts §3)
        $as()->postJson($this->casesEndpoint($suiteId), [
            'given' => 'intruder given',
            'expected_behavior' => 'intruder expected behavior',
            'expectations' => $this->caseExpectations(),
        ])->assertStatus(403);
        $as()->putJson($this->casesEndpoint($suiteId).'/'.$caseId, [
            'given' => 'intruder edit',
            'expected_behavior' => 'intruder edit',
            'expectations' => $this->caseExpectations(),
        ])->assertStatus(403);
        $as()->deleteJson($this->casesEndpoint($suiteId).'/'.$caseId)->assertStatus(403);
        $as()->getJson($this->casesEndpoint($suiteId).'/'.$caseId.'/versions')->assertStatus(403);

        // Export / import (contracts §4)
        $as()->getJson($this->base().'/'.$suiteId.'/export')->assertStatus(403);
        $as()->postJson($this->base().'/import', $this->importDocument(
            'Intruder-imported suite',
            'intruder-agent',
        ))->assertStatus(403);

        // No row was created or changed by any of the above write attempts.
        $this->assertSame($suiteCountBefore, DB::table('eval_suites')->count(), 'No suite row may be created by a refused write');
        $this->assertSame($caseCountBefore, DB::table('eval_cases')->count(), 'No case row may be created by a refused write');
        $this->assertSame($versionCountBefore, DB::table('eval_case_versions')->count(), 'No version row may be created by a refused write');

        $suiteRowAfter = DB::table('eval_suites')->where('id', $suiteId)->first();
        $caseRowAfter = DB::table('eval_cases')->where('id', $caseId)->first();

        $this->assertSame($suiteRowBefore->name, $suiteRowAfter->name, 'The suite name must be unchanged by a refused rename');
        $this->assertSame($suiteRowBefore->agent_identifier, $suiteRowAfter->agent_identifier, 'The suite agent_identifier must be unchanged');
        $this->assertNull($suiteRowAfter->deleted_at, 'The suite must not have been archived by a refused delete');
        $this->assertSame($caseRowBefore->current_version_id, $caseRowAfter->current_version_id, 'The case must still point at its original version — a refused edit must not repoint it');
        $this->assertNull($caseRowAfter->deleted_at, 'The case must not have been archived by a refused delete');
    }

    // ---------------------------------------------------------------
    // Positive control — an operator succeeds on the identical calls
    // ---------------------------------------------------------------

    #[Test]
    public function an_operator_succeeds_on_the_identical_calls(): void
    {
        $suite = $this->actingAs($this->operator)->postJson($this->base(), [
            'name' => 'Operator positive-control suite',
            'agent_identifier' => 'home-automation-agent',
        ])->assertStatus(200)->json();
        $suiteId = $suite['id'];

        $this->actingAs($this->operator)->getJson($this->base())->assertStatus(200);
        $this->actingAs($this->operator)->getJson($this->base().'/'.$suiteId)->assertStatus(200);
        $this->actingAs($this->operator)->putJson($this->base().'/'.$suiteId, [
            'name' => 'Operator positive-control suite (renamed)',
        ])->assertStatus(200);

        $case = $this->actingAs($this->operator)->postJson($this->casesEndpoint($suiteId), [
            'given' => 'given',
            'expected_behavior' => 'expected behavior',
            'expectations' => $this->caseExpectations(),
        ])->assertStatus(200)->json();
        $caseId = $case['id'];

        $this->actingAs($this->operator)->putJson($this->casesEndpoint($suiteId).'/'.$caseId, [
            'given' => 'given, edited',
            'expected_behavior' => 'expected behavior, edited',
            'expectations' => $this->caseExpectations(),
        ])->assertStatus(200);

        $this->actingAs($this->operator)->getJson($this->casesEndpoint($suiteId).'/'.$caseId.'/versions')->assertStatus(200);
        $this->actingAs($this->operator)->getJson($this->base().'/'.$suiteId.'/export')->assertStatus(200);

        $this->actingAs($this->operator)->postJson($this->base().'/import', $this->importDocument(
            'Operator-imported suite',
            'home-automation-agent',
        ))->assertStatus(201);

        $this->actingAs($this->operator)->deleteJson($this->casesEndpoint($suiteId).'/'.$caseId)->assertStatus(204);
        $this->actingAs($this->operator)->deleteJson($this->base().'/'.$suiteId)->assertStatus(204);
    }
}
