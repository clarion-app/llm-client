<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use Illuminate\Support\Facades\DB;

use PHPUnit\Framework\Attributes\Test;

/**
 * Validation-rejection paths through the real HTTP endpoints for User
 * Story 1 (spec.md FR-009/FR-010, quickstart.md steps 3-4): a case with
 * zero expectations, an action_taken/action_not_taken expectation with no
 * named action, and an unrecognized expectation kind are all rejected with
 * a 422 that names the problem specifically, and none of them leaves a
 * case behind.
 *
 * Extended in Phase 6 (User Story 4, quickstart.md steps 14-15) with the
 * malformed-import rejection cases: a document with a missing
 * "expectations" key on one case, an unrecognized expectation kind, a
 * field over max_text_length, an unsupported schema_version, and a
 * "cases" array over max_cases_per_suite are each rejected with a 422
 * naming the reason, and — the FR-017 property this whole section proves
 * — every one of them leaves *no new suite at all* behind, not a suite
 * with the bad case silently dropped and not a suite with an empty case.
 */
class EvalSuiteValidationRejectionJourneyTest extends TestCase
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

    private function casesEndpoint(string $suiteId): string
    {
        return $this->base().'/'.$suiteId.'/cases';
    }

    private function importEndpoint(): string
    {
        return $this->base().'/import';
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $cases
     * @return array<string, mixed>
     */
    private function validImportDocument(?array $cases = null): array
    {
        return [
            'schema_version' => 1,
            'name' => 'Imported suite candidate',
            'agent_identifier' => 'home-automation-agent',
            'cases' => $cases ?? [$this->validImportCase()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validImportCase(array $overrides = []): array
    {
        return array_merge([
            'given' => 'given',
            'expected_behavior' => 'expected behavior',
            'expectations' => [
                ['kind' => 'action_taken', 'action' => 'contacts.create'],
            ],
        ], $overrides);
    }

    private function suiteListCount(): int
    {
        return count($this->actingAs($this->operator)->getJson($this->base())->json('data'));
    }

    private function createSuite(): array
    {
        $response = $this->actingAs($this->operator)->postJson($this->base(), [
            'name' => 'Contact management sanity checks',
            'agent_identifier' => 'home-automation-agent',
        ]);

        $response->assertStatus(200);

        return $response->json();
    }

    // ---------------------------------------------------------------
    // FR-009 — a case with zero expectations
    // ---------------------------------------------------------------

    #[Test]
    public function a_case_with_zero_expectations_is_rejected_and_not_added(): void
    {
        $suite = $this->createSuite();

        $response = $this->actingAs($this->operator)->postJson($this->casesEndpoint($suite['id']), [
            'given' => 'given',
            'expected_behavior' => 'expected behavior',
            'expectations' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors' => ['case']]);

        $show = $this->actingAs($this->operator)->getJson($this->base().'/'.$suite['id']);
        $show->assertStatus(200);
        $this->assertCount(0, $show->json('cases'), 'The rejected case must not appear on the suite');
        $this->assertSame(0, DB::table('eval_cases')->count());
    }

    // ---------------------------------------------------------------
    // FR-010 — action_taken/action_not_taken must name the specific
    // action
    // ---------------------------------------------------------------

    #[Test]
    public function an_action_taken_expectation_with_an_empty_action_is_rejected(): void
    {
        $suite = $this->createSuite();

        $response = $this->actingAs($this->operator)->postJson($this->casesEndpoint($suite['id']), [
            'given' => 'given',
            'expected_behavior' => 'expected behavior',
            'expectations' => [
                ['kind' => 'action_taken', 'action' => ''],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors' => ['case']]);

        $this->assertSame(0, DB::table('eval_cases')->count());
    }

    #[Test]
    public function an_action_taken_expectation_with_the_action_key_omitted_entirely_is_rejected(): void
    {
        $suite = $this->createSuite();

        $response = $this->actingAs($this->operator)->postJson($this->casesEndpoint($suite['id']), [
            'given' => 'given',
            'expected_behavior' => 'expected behavior',
            'expectations' => [
                ['kind' => 'action_taken'],
            ],
        ]);

        $response->assertStatus(422);

        $this->assertSame(0, DB::table('eval_cases')->count());
    }

    #[Test]
    public function an_action_not_taken_expectation_with_an_empty_or_omitted_action_is_rejected(): void
    {
        $suite = $this->createSuite();

        foreach ([
            ['kind' => 'action_not_taken', 'action' => ''],
            ['kind' => 'action_not_taken'],
        ] as $expectation) {
            $response = $this->actingAs($this->operator)->postJson($this->casesEndpoint($suite['id']), [
                'given' => 'given',
                'expected_behavior' => 'expected behavior',
                'expectations' => [$expectation],
            ]);

            $response->assertStatus(422, 'action_not_taken with '.json_encode($expectation).' must be rejected');
        }

        $this->assertSame(0, DB::table('eval_cases')->count());
    }

    // ---------------------------------------------------------------
    // An unrecognized expectation kind
    // ---------------------------------------------------------------

    #[Test]
    public function an_unrecognized_expectation_kind_is_rejected_never_coerced_or_dropped(): void
    {
        $suite = $this->createSuite();

        $response = $this->actingAs($this->operator)->postJson($this->casesEndpoint($suite['id']), [
            'given' => 'given',
            'expected_behavior' => 'expected behavior',
            'expectations' => [
                ['kind' => 'vibes_check', 'note' => 'this is not a real kind'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors' => ['case']]);

        $this->assertSame(0, DB::table('eval_cases')->count(), 'An unrecognized kind must never be silently dropped, leaving an empty case behind');
    }

    // ---------------------------------------------------------------
    // Malformed import (US4, quickstart.md steps 14-15, FR-017): each
    // failure is rejected with a 422 naming the reason, and GET
    // /agent-eval-suites shows no new suite at all afterward — not a
    // suite with the bad case silently dropped, not a suite with an
    // empty case.
    // ---------------------------------------------------------------

    #[Test]
    public function an_import_document_with_a_case_missing_expectations_is_rejected_and_creates_no_suite(): void
    {
        $case = $this->validImportCase();
        unset($case['expectations']);

        $before = $this->suiteListCount();

        $response = $this->actingAs($this->operator)->postJson(
            $this->importEndpoint(),
            $this->validImportDocument([$case]),
        );

        $response->assertStatus(422);
        $this->assertSame(
            $before,
            $this->suiteListCount(),
            'A case missing "expectations" must reject the whole import, leaving no new suite at all',
        );
    }

    #[Test]
    public function an_import_document_with_an_unrecognized_expectation_kind_is_rejected_and_creates_no_suite(): void
    {
        $case = $this->validImportCase(['expectations' => [
            ['kind' => 'vibes_check', 'note' => 'this is not a real kind'],
        ]]);

        $before = $this->suiteListCount();

        $response = $this->actingAs($this->operator)->postJson(
            $this->importEndpoint(),
            $this->validImportDocument([$case]),
        );

        $response->assertStatus(422);
        $this->assertSame($before, $this->suiteListCount(), 'An unrecognized expectation kind must reject the whole import');
    }

    #[Test]
    public function an_import_document_with_a_case_exceeding_max_text_length_is_rejected_and_creates_no_suite(): void
    {
        config(['llm-client.eval_suites.max_text_length' => 20]);

        $case = $this->validImportCase(['given' => str_repeat('a', 21)]);

        $before = $this->suiteListCount();

        $response = $this->actingAs($this->operator)->postJson(
            $this->importEndpoint(),
            $this->validImportDocument([$case]),
        );

        $response->assertStatus(422);
        $this->assertSame($before, $this->suiteListCount(), 'A field over max_text_length must reject the whole import');
    }

    #[Test]
    public function an_import_document_with_an_unsupported_schema_version_is_rejected_and_creates_no_suite(): void
    {
        $document = $this->validImportDocument();
        $document['schema_version'] = 999;

        $before = $this->suiteListCount();

        $response = $this->actingAs($this->operator)->postJson($this->importEndpoint(), $document);

        $response->assertStatus(422);
        $this->assertSame($before, $this->suiteListCount(), 'An unsupported schema_version must reject the whole import');
    }

    #[Test]
    public function an_import_document_with_more_cases_than_the_configured_bound_is_rejected_and_creates_no_suite(): void
    {
        config(['llm-client.eval_suites.max_cases_per_suite' => 2]);

        $cases = [
            $this->validImportCase(['given' => 'case 1']),
            $this->validImportCase(['given' => 'case 2']),
            $this->validImportCase(['given' => 'case 3']),
        ];

        $before = $this->suiteListCount();

        $response = $this->actingAs($this->operator)->postJson(
            $this->importEndpoint(),
            $this->validImportDocument($cases),
        );

        $response->assertStatus(422);
        $this->assertSame($before, $this->suiteListCount(), 'A "cases" array over max_cases_per_suite must reject the whole import, creating no suite');
    }
}
