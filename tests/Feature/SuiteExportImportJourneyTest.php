<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use Illuminate\Support\Facades\DB;

use PHPUnit\Framework\Attributes\Test;

/**
 * User Story 4 end-to-end, through the real HTTP endpoints (spec.md US4
 * Acceptance Scenarios 1-4, quickstart.md steps 10-13): exporting a suite
 * produces a self-contained document with no row identity, importing that
 * document onto an installation with a free (agent_identifier, name) pair
 * recreates the suite with every case starting fresh at version 1,
 * re-importing the identical document while the first import is still live
 * is refused with a 409 naming conflict rather than silently duplicated or
 * merged, and an operator resolves that conflict with name_override. A
 * case on the imported suite then edits exactly the way a locally-created
 * suite's case does (scenario 4) — nothing distinguishes an imported
 * suite's later behavior from a suite authored directly.
 */
class SuiteExportImportJourneyTest extends TestCase
{
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->create();

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

    private function exportEndpoint(string $suiteId): string
    {
        return $this->suiteEndpoint($suiteId).'/export';
    }

    private function importEndpoint(): string
    {
        return $this->base().'/import';
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

    private function archiveSuite(string $suiteId): void
    {
        $this->actingAs($this->operator)->deleteJson($this->suiteEndpoint($suiteId))->assertStatus(204);
    }

    // ---------------------------------------------------------------
    // Scenario 1 — GET .../export returns the self-contained document
    // ---------------------------------------------------------------

    #[Test]
    public function get_export_returns_the_document_shape_with_no_row_identity_and_no_timestamps(): void
    {
        $suite = $this->createSuite();
        $this->addCase($suite['id'], [
            'given' => 'given one',
            'expected_behavior' => 'expected behavior one',
            'expectations' => [
                ['kind' => 'action_taken', 'action' => 'contacts.create'],
            ],
        ]);

        $export = $this->actingAs($this->operator)->getJson($this->exportEndpoint($suite['id']));

        $export->assertStatus(200);
        $document = $export->json();

        $this->assertSame(1, $document['schema_version']);
        $this->assertSame('Contact management sanity checks', $document['name']);
        $this->assertSame('home-automation-agent', $document['agent_identifier']);
        $this->assertCount(1, $document['cases']);
        $this->assertSame('given one', $document['cases'][0]['given']);
        $this->assertSame('expected behavior one', $document['cases'][0]['expected_behavior']);

        $encoded = json_encode($document);
        foreach (['"id"', '"suite_id"', '"case_id"', '"version_id"', '"version_number"', '"created_at"', '"updated_at"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded, "The export document must never carry a {$forbidden} key (FR-014)");
        }
    }

    // ---------------------------------------------------------------
    // Scenario 2 — importing onto a free (agent_identifier, name) pair
    // recreates the suite, every case starting at version 1
    // ---------------------------------------------------------------

    #[Test]
    public function importing_onto_an_installation_with_no_suite_of_that_name_recreates_it_at_version_1(): void
    {
        $suite = $this->createSuite();
        $case = $this->addCase($suite['id'], [
            'given' => 'given one',
            'expected_behavior' => 'expected behavior one',
            'expectations' => [
                ['kind' => 'action_taken', 'action' => 'contacts.create'],
                ['kind' => 'human_judgment', 'note' => 'judge the tone'],
            ],
        ]);

        $document = $this->actingAs($this->operator)->getJson($this->exportEndpoint($suite['id']))->json();

        // Free the (agent_identifier, name) pair by archiving the source
        // suite, so this import lands on a clean pair (US4 Independent
        // Test).
        $this->archiveSuite($suite['id']);

        $import = $this->actingAs($this->operator)->postJson($this->importEndpoint(), $document);

        $import->assertStatus(201);
        $imported = $import->json();

        $this->assertSame('Contact management sanity checks', $imported['name']);
        $this->assertSame('home-automation-agent', $imported['agent_identifier']);
        $this->assertNotSame($suite['id'], $imported['id']);
        $this->assertCount(1, $imported['cases']);

        $importedCase = $imported['cases'][0];
        $this->assertSame('given one', $importedCase['given']);
        $this->assertSame('expected behavior one', $importedCase['expected_behavior']);
        $this->assertSame($document['cases'][0]['expectations'], $importedCase['expectations']);
        $this->assertSame(1, $importedCase['version_number']);
        $this->assertNotSame($case['id'], $importedCase['id'], 'An imported case must get a fresh id');

        $versions = $this->actingAs($this->operator)->getJson($this->versionsEndpoint($imported['id'], $importedCase['id']));
        $versions->assertStatus(200);
        $this->assertCount(1, $versions->json('data'), 'An imported case must start with exactly one version');
        $this->assertSame(1, $versions->json('data')[0]['version_number']);
    }

    // ---------------------------------------------------------------
    // Scenario 3 — re-importing the same document while the first
    // import's suite is still live is a 409 naming conflict, resolved
    // by name_override
    // ---------------------------------------------------------------

    #[Test]
    public function reimporting_the_same_document_while_the_first_import_is_live_is_a_409_naming_conflict(): void
    {
        $suite = $this->createSuite();
        $this->addCase($suite['id']);

        $document = $this->actingAs($this->operator)->getJson($this->exportEndpoint($suite['id']))->json();

        $this->archiveSuite($suite['id']);

        $firstImport = $this->actingAs($this->operator)->postJson($this->importEndpoint(), $document);
        $firstImport->assertStatus(201);

        $listBefore = $this->actingAs($this->operator)->getJson($this->base());
        $liveSuitesNamed = collect($listBefore->json('data'))->where('name', 'Contact management sanity checks');
        $this->assertCount(1, $liveSuitesNamed, 'Exactly one live suite of that name must exist after the first import');

        $secondImport = $this->actingAs($this->operator)->postJson($this->importEndpoint(), $document);

        $secondImport->assertStatus(409);
        $secondImport->assertJson(['code' => 'name_conflict']);

        $listAfter = $this->actingAs($this->operator)->getJson($this->base());
        $liveSuitesNamedAfter = collect($listAfter->json('data'))->where('name', 'Contact management sanity checks');
        $this->assertCount(1, $liveSuitesNamedAfter, 'A rejected re-import must create no new suite');

        // Retrying with a free name_override resolves the conflict.
        $resolved = $this->actingAs($this->operator)->postJson($this->importEndpoint(), array_merge($document, [
            'name_override' => 'Contact management sanity checks (imported)',
        ]));

        $resolved->assertStatus(201);
        $this->assertSame('Contact management sanity checks (imported)', $resolved->json('name'));
        $this->assertNotSame($firstImport->json('id'), $resolved->json('id'));

        $listFinal = $this->actingAs($this->operator)->getJson($this->base());
        $this->assertCount(
            2,
            collect($listFinal->json('data'))->whereIn('name', [
                'Contact management sanity checks',
                'Contact management sanity checks (imported)',
            ]),
            'Both the original import and the override-resolved import must now exist as distinct live suites',
        );
    }

    // ---------------------------------------------------------------
    // Scenario 4 — editing a case on an imported suite versions
    // identically to a locally-created suite's case
    // ---------------------------------------------------------------

    #[Test]
    public function editing_a_case_on_an_imported_suite_versions_exactly_like_a_locally_created_suites_case(): void
    {
        $suite = $this->createSuite();
        $this->addCase($suite['id']);

        $document = $this->actingAs($this->operator)->getJson($this->exportEndpoint($suite['id']))->json();
        $this->archiveSuite($suite['id']);

        $importResponse = $this->actingAs($this->operator)->postJson($this->importEndpoint(), $document);
        $importResponse->assertStatus(201);
        $imported = $importResponse->json();
        $importedCase = $imported['cases'][0];

        $v1Id = $importedCase['version_id'];

        $edit = $this->actingAs($this->operator)->putJson($this->caseEndpoint($imported['id'], $importedCase['id']), [
            'given' => 'edited given',
            'expected_behavior' => 'edited expected behavior',
            'expectations' => [
                ['kind' => 'text_match', 'expected_text' => 'edited text'],
            ],
        ]);

        $edit->assertStatus(200);
        $v2Id = $edit->json('version_id');

        $this->assertNotSame($v1Id, $v2Id, 'Editing a case on an imported suite must produce a new version, exactly like any other suite');
        $this->assertSame(2, $edit->json('version_number'));

        $versions = $this->actingAs($this->operator)->getJson($this->versionsEndpoint($imported['id'], $importedCase['id']));
        $versions->assertStatus(200);
        $byId = collect($versions->json('data'))->keyBy('id');

        $this->assertCount(2, $byId, 'Both the imported v1 and the newly edited v2 must be listed');
        $this->assertSame(1, $byId[$v1Id]['version_number']);
        $this->assertSame('User says: "add a contact named Alice"', $byId[$v1Id]['given'], 'v1\'s content must remain exactly what the import produced');
    }
}
