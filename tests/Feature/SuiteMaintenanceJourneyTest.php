<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use Illuminate\Support\Facades\DB;

use PHPUnit\Framework\Attributes\Test;

/**
 * User Story 2 end-to-end, through the real HTTP endpoints (spec.md US2
 * Acceptance Scenarios 1-5, quickstart.md steps 5-8): an operator edits a
 * case's content over time, a different operator picks up the same suite
 * with no special setup, a case or a whole suite is removed from active
 * use without erasing its history, and a suite is renamed without
 * disturbing any case's version history.
 *
 * Scenario 3 (the "centerpiece") is this feature's central promise made
 * observable end to end: a captured version id must keep resolving to
 * byte-identical content forever, no matter how many times the case is
 * edited afterward (FR-011/FR-012, SC-004).
 */
class SuiteMaintenanceJourneyTest extends TestCase
{
    private User $operator;

    private User $secondOperator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->create();
        $this->secondOperator = User::factory()->create();

        config(['llm-client.cost.operator_user_ids' => [$this->operator->id, $this->secondOperator->id]]);
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

    private function suiteEndpoint(string $suiteId): string
    {
        return $this->base().'/'.$suiteId;
    }

    private function casesEndpoint(string $suiteId): string
    {
        return $this->suiteEndpoint($suiteId).'/cases';
    }

    private function caseEndpoint(string $suiteId, string $caseId): string
    {
        return $this->casesEndpoint($suiteId).'/'.$caseId;
    }

    private function versionsEndpoint(string $suiteId, string $caseId): string
    {
        return $this->caseEndpoint($suiteId, $caseId).'/versions';
    }

    private function createSuite(array $overrides = []): array
    {
        $response = $this->actingAs($this->operator)->postJson($this->base(), array_merge([
            'name' => 'Contact management sanity checks',
            'agent_identifier' => 'home-automation-agent',
        ], $overrides));

        $response->assertStatus(200);

        return $response->json();
    }

    private function addCase(string $suiteId, array $overrides = []): array
    {
        $response = $this->actingAs($this->operator)->postJson($this->casesEndpoint($suiteId), array_merge([
            'given' => 'User says: "add a contact named Alice"',
            'expected_behavior' => 'The agent creates the contact and confirms.',
            'expectations' => [
                ['kind' => 'action_taken', 'action' => 'contacts.create'],
                ['kind' => 'information_present', 'expected_info' => "the contact's name"],
            ],
        ], $overrides));

        $response->assertStatus(200);

        return $response->json();
    }

    // ---------------------------------------------------------------
    // Scenario 1 — editing a case is saved and visible on the next GET
    // ---------------------------------------------------------------

    #[Test]
    public function editing_a_cases_content_via_put_is_saved_and_visible_on_the_next_get(): void
    {
        $suite = $this->createSuite();
        $case = $this->addCase($suite['id']);

        $edit = $this->actingAs($this->operator)->putJson($this->caseEndpoint($suite['id'], $case['id']), [
            'given' => 'User says: "add a contact named Bob"',
            'expected_behavior' => 'The agent creates the contact for Bob and confirms.',
            'expectations' => [
                ['kind' => 'text_match', 'expected_text' => 'Bob has been added.'],
            ],
        ]);

        $edit->assertStatus(200);
        $this->assertSame('User says: "add a contact named Bob"', $edit->json('given'));

        $show = $this->actingAs($this->operator)->getJson($this->suiteEndpoint($suite['id']));
        $show->assertStatus(200);

        $retrieved = collect($show->json('cases'))->firstWhere('id', $case['id']);
        $this->assertNotNull($retrieved);
        $this->assertSame('User says: "add a contact named Bob"', $retrieved['given']);
        $this->assertSame('The agent creates the contact for Bob and confirms.', $retrieved['expected_behavior']);
        $this->assertCount(1, $retrieved['expectations']);
        $this->assertSame('text_match', $retrieved['expectations'][0]['kind']);
    }

    // ---------------------------------------------------------------
    // Scenario 2 — a different operator edits with no special setup
    // ---------------------------------------------------------------

    #[Test]
    public function a_second_operator_account_opens_the_suite_and_edits_the_same_case_with_no_special_setup(): void
    {
        $suite = $this->createSuite();
        $case = $this->addCase($suite['id']);

        // No ownership setup of any kind — the second operator is simply
        // another id in operator_user_ids (FR-008, SC-006, SC-007: the same
        // guarantee stands in for "an operator no longer active," since
        // nothing in this design keys access on the original author).
        $show = $this->actingAs($this->secondOperator)->getJson($this->suiteEndpoint($suite['id']));
        $show->assertStatus(200);

        $edit = $this->actingAs($this->secondOperator)->putJson($this->caseEndpoint($suite['id'], $case['id']), [
            'given' => 'Edited by the second operator',
            'expected_behavior' => 'Edited expected behavior',
            'expectations' => [
                ['kind' => 'action_not_taken', 'action' => 'contacts.delete'],
            ],
        ]);

        $edit->assertStatus(200);
        $this->assertSame('Edited by the second operator', $edit->json('given'));
    }

    // ---------------------------------------------------------------
    // Scenario 3 — the centerpiece: a captured version id stays
    // byte-identical forever, no matter how many later edits happen
    // ---------------------------------------------------------------

    #[Test]
    public function editing_a_case_creates_a_new_version_while_the_earlier_version_stays_byte_identical_and_both_remain_listed(): void
    {
        $suite = $this->createSuite();
        $case = $this->addCase($suite['id'], [
            'given' => 'v1 given',
            'expected_behavior' => 'v1 expected behavior',
            'expectations' => [
                ['kind' => 'text_match', 'expected_text' => 'v1 text'],
            ],
        ]);

        $v1Id = $case['version_id'];
        $this->assertSame(1, $case['version_number']);

        $versionsBeforeEdit = $this->actingAs($this->operator)->getJson($this->versionsEndpoint($suite['id'], $case['id']));
        $versionsBeforeEdit->assertStatus(200);
        $this->assertCount(1, $versionsBeforeEdit->json('data'));
        $v1BeforeEdit = $versionsBeforeEdit->json('data')[0];
        $this->assertSame($v1Id, $v1BeforeEdit['id']);
        $this->assertSame(1, $v1BeforeEdit['version_number']);

        $edit = $this->actingAs($this->operator)->putJson($this->caseEndpoint($suite['id'], $case['id']), [
            'given' => 'v2 given',
            'expected_behavior' => 'v2 expected behavior',
            'expectations' => [
                ['kind' => 'text_match', 'expected_text' => 'v2 text'],
                ['kind' => 'human_judgment', 'note' => 'judge the tone'],
            ],
        ]);
        $edit->assertStatus(200);

        $v2Id = $edit->json('version_id');
        $this->assertNotSame($v1Id, $v2Id, 'Editing must produce a new version id, never reuse or overwrite the old one');
        $this->assertSame(2, $edit->json('version_number'));

        $show = $this->actingAs($this->operator)->getJson($this->suiteEndpoint($suite['id']));
        $show->assertStatus(200);
        $retrieved = collect($show->json('cases'))->firstWhere('id', $case['id']);
        $this->assertSame($v2Id, $retrieved['version_id'], 'The suite show must now point at the new version');

        $versionsAfterEdit = $this->actingAs($this->operator)->getJson($this->versionsEndpoint($suite['id'], $case['id']));
        $versionsAfterEdit->assertStatus(200);
        $versions = collect($versionsAfterEdit->json('data'))->keyBy('id');

        $this->assertCount(2, $versions, 'Both v1 and v2 must be listed after the edit');
        $this->assertTrue($versions->has($v1Id));
        $this->assertTrue($versions->has($v2Id));

        // The centerpiece assertion: v1's content, fetched again after the
        // edit, is byte-identical to what it was before the edit — this is
        // exactly what a historical run result's captured version id would
        // still resolve to (FR-011/FR-012, SC-004).
        $v1AfterEdit = $versions[$v1Id];
        $this->assertSame($v1BeforeEdit['given'], $v1AfterEdit['given']);
        $this->assertSame($v1BeforeEdit['expected_behavior'], $v1AfterEdit['expected_behavior']);
        $this->assertSame($v1BeforeEdit['expectations'], $v1AfterEdit['expectations']);
        $this->assertSame($v1BeforeEdit['version_number'], $v1AfterEdit['version_number']);
        $this->assertSame($v1BeforeEdit['created_at'], $v1AfterEdit['created_at']);

        $v2AfterEdit = $versions[$v2Id];
        $this->assertSame('v2 given', $v2AfterEdit['given']);
        $this->assertSame(2, $v2AfterEdit['version_number']);
        $this->assertTrue($v2AfterEdit['requires_human_judgment']);
        $this->assertFalse($v1AfterEdit['requires_human_judgment']);
    }

    // ---------------------------------------------------------------
    // Scenario 4 — removing a case archives it; case_count drops but
    // its version history survives in full
    // ---------------------------------------------------------------

    #[Test]
    public function deleting_a_case_archives_it_dropping_case_count_while_its_versions_remain_fully_listed(): void
    {
        $suite = $this->createSuite();
        $case = $this->addCase($suite['id']);

        $this->actingAs($this->operator)->putJson($this->caseEndpoint($suite['id'], $case['id']), [
            'given' => 'v2 given',
            'expected_behavior' => 'v2 expected behavior',
            'expectations' => [
                ['kind' => 'text_match', 'expected_text' => 'v2 text'],
            ],
        ])->assertStatus(200);

        $beforeDelete = $this->actingAs($this->operator)->getJson($this->suiteEndpoint($suite['id']));
        $this->assertSame(1, $beforeDelete->json('case_count'));

        $delete = $this->actingAs($this->operator)->deleteJson($this->caseEndpoint($suite['id'], $case['id']));
        $delete->assertStatus(204);

        $afterDelete = $this->actingAs($this->operator)->getJson($this->suiteEndpoint($suite['id']));
        $afterDelete->assertStatus(200);
        $this->assertSame(0, $afterDelete->json('case_count'), 'The archived case must no longer count toward case_count');
        $this->assertEmpty(
            collect($afterDelete->json('cases'))->where('id', $case['id']),
            'The archived case must no longer be listed among the suite\'s live cases',
        );

        $versions = $this->actingAs($this->operator)->getJson($this->versionsEndpoint($suite['id'], $case['id']));
        $versions->assertStatus(200);
        $this->assertCount(2, $versions->json('data'), 'Archiving a case must never erase a version — both v1 and v2 must still be listed');
    }

    // ---------------------------------------------------------------
    // Scenario 5 — renaming a suite; case history is intact
    // ---------------------------------------------------------------

    #[Test]
    public function renaming_a_suite_is_reachable_under_the_new_name_and_every_cases_history_is_unchanged(): void
    {
        $suite = $this->createSuite();
        $case = $this->addCase($suite['id']);

        $rename = $this->actingAs($this->operator)->putJson($this->suiteEndpoint($suite['id']), [
            'name' => 'Renamed suite',
        ]);
        $rename->assertStatus(200);
        $this->assertSame('Renamed suite', $rename->json('name'));
        $this->assertSame('home-automation-agent', $rename->json('agent_identifier'), 'An omitted field must be left unchanged');

        $show = $this->actingAs($this->operator)->getJson($this->suiteEndpoint($suite['id']));
        $show->assertStatus(200);
        $this->assertSame('Renamed suite', $show->json('name'));

        $retrieved = collect($show->json('cases'))->firstWhere('id', $case['id']);
        $this->assertNotNull($retrieved, 'The suite\'s cases must be unaffected by renaming the suite');
        $this->assertSame($case['version_id'], $retrieved['version_id']);

        $versions = $this->actingAs($this->operator)->getJson($this->versionsEndpoint($suite['id'], $case['id']));
        $versions->assertStatus(200);
        $this->assertCount(1, $versions->json('data'), 'Renaming the suite must not touch any case\'s version history');
    }
}
