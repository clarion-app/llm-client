<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Exceptions\NameConflictException;
use ClarionApp\LlmClient\Models\EvalCase;
use ClarionApp\LlmClient\Models\EvalSuite;
use ClarionApp\LlmClient\Services\EvalSuiteImporter;
use ClarionApp\LlmClient\Services\EvalSuiteService;
use Illuminate\Support\Facades\DB;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for EvalSuiteImporter, covering research.md D8/D9 and
 * contracts/eval-suites-api.md §5's C7-C9: the entire document is
 * validated, in full, before EvalSuiteService/EvalCaseService write a
 * single row (C7); the identical Expectation::validate()/authoring rules
 * apply on import, never a separate looser rule set (C8, mutation-checklist
 * row 14); and a naming conflict against a live suite is a distinct
 * NameConflictException, never \InvalidArgumentException (C9,
 * mutation-checklist row 10).
 */
class EvalSuiteImporterTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('eval_case_versions')->delete();
        DB::table('eval_cases')->delete();
        DB::table('eval_suites')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function importer(): EvalSuiteImporter
    {
        return new EvalSuiteImporter();
    }

    private function suiteService(): EvalSuiteService
    {
        return new EvalSuiteService();
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $cases
     * @return array<string, mixed>
     */
    private function validDocument(array $overrides = [], ?array $cases = null): array
    {
        return array_merge([
            'schema_version' => 1,
            'name' => 'Contact management sanity checks',
            'agent_identifier' => 'home-automation-agent',
            'cases' => $cases ?? [$this->validCase()],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function validCase(array $overrides = []): array
    {
        return array_merge([
            'given' => 'User says: "add a contact named Alice"',
            'expected_behavior' => 'The agent creates the contact and confirms.',
            'expectations' => [
                ['kind' => 'action_taken', 'action' => 'contacts.create'],
                ['kind' => 'information_present', 'expected_info' => "the contact's name"],
            ],
        ], $overrides);
    }

    private function suiteRowCount(): int
    {
        return DB::table('eval_suites')->count();
    }

    private function caseRowCount(): int
    {
        return DB::table('eval_cases')->count();
    }

    private function versionRowCount(): int
    {
        return DB::table('eval_case_versions')->count();
    }

    /**
     * Snapshot row counts across all three tables, run the import expecting
     * it to be rejected with \InvalidArgumentException (never
     * NameConflictException — that is a distinct failure mode, C9), and
     * assert not one row of any of the three tables was written (FR-017,
     * mutation-checklist row 9).
     */
    private function assertImportRejected(
        array $document,
        ?string $nameOverride,
        ?string $agentIdentifierOverride,
        string $message,
    ): void {
        $suitesBefore = $this->suiteRowCount();
        $casesBefore = $this->caseRowCount();
        $versionsBefore = $this->versionRowCount();

        try {
            $this->importer()->import($document, $nameOverride, $agentIdentifierOverride);
            $this->fail($message);
        } catch (NameConflictException $e) {
            $this->fail('Expected \InvalidArgumentException, got NameConflictException: '.$message);
        } catch (\InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage(), 'A rejection must say what was wrong');
        }

        $this->assertSame($suitesBefore, $this->suiteRowCount(), 'A rejected import must write no suite row: '.$message);
        $this->assertSame($casesBefore, $this->caseRowCount(), 'A rejected import must write no case row: '.$message);
        $this->assertSame($versionsBefore, $this->versionRowCount(), 'A rejected import must write no version row: '.$message);
    }

    // ---------------------------------------------------------------
    // Happy path: fresh ids, every case starts at version_number = 1
    // ---------------------------------------------------------------

    #[Test]
    public function import_creates_a_suite_whose_cases_all_start_at_version_number_1(): void
    {
        $document = $this->validDocument(cases: [
            $this->validCase(['given' => 'first case']),
            $this->validCase(['given' => 'second case']),
        ]);

        $suite = $this->importer()->import($document, null, null);

        $this->assertInstanceOf(EvalSuite::class, $suite);
        $this->assertSame('Contact management sanity checks', $suite->name);
        $this->assertSame('home-automation-agent', $suite->agent_identifier);

        $cases = EvalCase::where('suite_id', $suite->id)->with('currentVersion')->get();
        $this->assertCount(2, $cases);

        foreach ($cases as $case) {
            $this->assertSame(1, $case->currentVersion->version_number);
        }
    }

    #[Test]
    public function importing_the_same_document_twice_via_name_override_produces_fresh_non_colliding_ids(): void
    {
        // mutation-checklist row 13 — the importer must never reuse the
        // source document's row ids (it has none) nor let two separate
        // imports collide with each other's freshly minted ids.
        $document = $this->validDocument();

        $first = $this->importer()->import($document, null, null);
        $second = $this->importer()->import($document, 'Contact management sanity checks (2)', null);

        $this->assertNotSame($first->id, $second->id);

        $firstCaseIds = EvalCase::where('suite_id', $first->id)->pluck('id')->all();
        $secondCaseIds = EvalCase::where('suite_id', $second->id)->pluck('id')->all();

        $this->assertEmpty(
            array_intersect($firstCaseIds, $secondCaseIds),
            'Two separate imports of the same document must never share a case id',
        );
        $this->assertCount(1, $firstCaseIds);
        $this->assertCount(1, $secondCaseIds);
    }

    // ---------------------------------------------------------------
    // schema_version
    // ---------------------------------------------------------------

    #[Test]
    public function an_unsupported_schema_version_is_rejected_before_any_write(): void
    {
        $this->assertImportRejected(
            $this->validDocument(['schema_version' => 999]),
            null,
            null,
            'An unsupported schema_version must be rejected',
        );
    }

    // ---------------------------------------------------------------
    // Required top-level keys
    // ---------------------------------------------------------------

    #[Test]
    public function a_missing_name_is_rejected(): void
    {
        $document = $this->validDocument();
        unset($document['name']);

        $this->assertImportRejected($document, null, null, 'A document with no "name" must be rejected');
    }

    #[Test]
    public function a_missing_agent_identifier_is_rejected(): void
    {
        $document = $this->validDocument();
        unset($document['agent_identifier']);

        $this->assertImportRejected($document, null, null, 'A document with no "agent_identifier" must be rejected');
    }

    #[Test]
    public function a_missing_cases_key_is_rejected(): void
    {
        $document = $this->validDocument();
        unset($document['cases']);

        $this->assertImportRejected($document, null, null, 'A document with no "cases" must be rejected');
    }

    #[Test]
    public function a_mistyped_cases_key_is_rejected(): void
    {
        $this->assertImportRejected(
            $this->validDocument(['cases' => 'not an array']),
            null,
            null,
            'A "cases" that is not an array must be rejected',
        );
    }

    #[Test]
    public function a_mistyped_name_is_rejected(): void
    {
        $this->assertImportRejected(
            $this->validDocument(['name' => ['not' => 'a string']]),
            null,
            null,
            'A "name" that is not a string must be rejected',
        );
    }

    // ---------------------------------------------------------------
    // Per-case / per-expectation validation — identical to
    // Expectation::validate() (C8, mutation-checklist row 14)
    // ---------------------------------------------------------------

    #[Test]
    public function a_case_missing_given_is_rejected(): void
    {
        $case = $this->validCase();
        unset($case['given']);

        $this->assertImportRejected(
            $this->validDocument(cases: [$case]),
            null,
            null,
            'A case with no "given" must be rejected',
        );
    }

    #[Test]
    public function a_case_missing_expected_behavior_is_rejected(): void
    {
        $case = $this->validCase();
        unset($case['expected_behavior']);

        $this->assertImportRejected(
            $this->validDocument(cases: [$case]),
            null,
            null,
            'A case with no "expected_behavior" must be rejected',
        );
    }

    #[Test]
    public function a_case_with_an_empty_expectations_array_is_rejected(): void
    {
        $case = $this->validCase(['expectations' => []]);

        $this->assertImportRejected(
            $this->validDocument(cases: [$case]),
            null,
            null,
            'A case with zero expectations must be rejected on import exactly as on authoring (FR-009)',
        );
    }

    #[Test]
    public function a_case_with_an_unrecognized_expectation_kind_is_rejected(): void
    {
        $case = $this->validCase(['expectations' => [['kind' => 'vibes_check']]]);

        $this->assertImportRejected(
            $this->validDocument(cases: [$case]),
            null,
            null,
            'An unrecognized expectation kind must be rejected on import, never silently dropped or coerced',
        );
    }

    #[Test]
    public function an_action_taken_expectation_with_a_missing_action_is_rejected(): void
    {
        $case = $this->validCase(['expectations' => [['kind' => 'action_taken']]]);

        $this->assertImportRejected(
            $this->validDocument(cases: [$case]),
            null,
            null,
            'An action_taken with no named action must be rejected on import exactly as on authoring (FR-010)',
        );
    }

    #[Test]
    public function an_action_not_taken_expectation_with_an_empty_action_is_rejected(): void
    {
        $case = $this->validCase(['expectations' => [['kind' => 'action_not_taken', 'action' => '']]]);

        $this->assertImportRejected(
            $this->validDocument(cases: [$case]),
            null,
            null,
            'An action_not_taken with an empty action must be rejected on import',
        );
    }

    // ---------------------------------------------------------------
    // Bounded counts / lengths (research.md D9)
    // ---------------------------------------------------------------

    #[Test]
    public function cases_longer_than_the_configured_max_cases_per_suite_is_rejected(): void
    {
        config(['llm-client.eval_suites.max_cases_per_suite' => 2]);

        $document = $this->validDocument(cases: [
            $this->validCase(['given' => 'case 1']),
            $this->validCase(['given' => 'case 2']),
            $this->validCase(['given' => 'case 3']),
        ]);

        $this->assertImportRejected($document, null, null, 'More cases than max_cases_per_suite must be rejected');
    }

    #[Test]
    public function expectations_longer_than_the_configured_max_expectations_per_case_is_rejected(): void
    {
        config(['llm-client.eval_suites.max_expectations_per_case' => 1]);

        $case = $this->validCase(['expectations' => [
            ['kind' => 'action_taken', 'action' => 'contacts.create'],
            ['kind' => 'text_match', 'expected_text' => 'Done.'],
        ]]);

        $this->assertImportRejected(
            $this->validDocument(cases: [$case]),
            null,
            null,
            'More expectations on one case than max_expectations_per_case must be rejected',
        );
    }

    #[Test]
    public function a_text_field_over_the_configured_max_text_length_is_rejected(): void
    {
        config(['llm-client.eval_suites.max_text_length' => 20]);

        $case = $this->validCase(['given' => str_repeat('a', 21)]);

        $this->assertImportRejected(
            $this->validDocument(cases: [$case]),
            null,
            null,
            'A given over max_text_length must be rejected on import',
        );
    }

    // ---------------------------------------------------------------
    // Any single failure leaves zero rows written, even deep into a
    // large document (FR-017, mutation-checklist row 9)
    // ---------------------------------------------------------------

    #[Test]
    public function a_single_invalid_case_leaves_zero_rows_written_even_when_it_is_the_seventh_of_twelve(): void
    {
        $cases = [];

        for ($i = 1; $i <= 12; $i++) {
            $cases[] = $i === 7
                ? $this->validCase(['given' => '', 'expectations' => [['kind' => 'action_taken', 'action' => 'x']]])
                : $this->validCase(['given' => "case {$i}"]);
        }

        $this->assertImportRejected(
            $this->validDocument(cases: $cases),
            null,
            null,
            'A single invalid case buried in a larger document must still abort the whole import with zero rows written',
        );
    }

    // ---------------------------------------------------------------
    // Naming conflict — NameConflictException, never
    // \InvalidArgumentException (C9, mutation-checklist row 10)
    // ---------------------------------------------------------------

    #[Test]
    public function a_name_collision_against_a_live_suite_throws_name_conflict_exception(): void
    {
        $this->suiteService()->create('Contact management sanity checks', 'home-automation-agent');

        $suitesBefore = $this->suiteRowCount();

        try {
            $this->importer()->import($this->validDocument(), null, null);
            $this->fail('A name collision against a live suite must be rejected');
        } catch (NameConflictException $e) {
            $this->assertNotSame('', $e->getMessage(), 'A rejection must say what was wrong');
        } catch (\InvalidArgumentException $e) {
            $this->fail('A name collision must throw NameConflictException, not \InvalidArgumentException (C9)');
        }

        $this->assertSame($suitesBefore, $this->suiteRowCount(), 'A rejected import must write no suite row');
        $this->assertSame(0, $this->caseRowCount(), 'A rejected import must write no case row');
    }

    #[Test]
    public function a_name_collision_is_checked_against_the_effective_override_pair_not_the_documents_own_pair(): void
    {
        // The document's own pair is free; the *override* pair collides.
        $this->suiteService()->create('Already taken', 'home-automation-agent');

        try {
            $this->importer()->import($this->validDocument(), 'Already taken', null);
            $this->fail('A collision on the effective (overridden) pair must be rejected even though the document\'s own pair is free');
        } catch (NameConflictException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }

    #[Test]
    public function an_override_naming_a_free_pair_succeeds_even_though_the_documents_own_pair_collides(): void
    {
        // The document's own pair collides; the override pair is free.
        $this->suiteService()->create('Contact management sanity checks', 'home-automation-agent');

        $suite = $this->importer()->import($this->validDocument(), 'A free name', null);

        $this->assertSame('A free name', $suite->name);
        $this->assertSame('home-automation-agent', $suite->agent_identifier);
    }

    // ---------------------------------------------------------------
    // name_override / agent_identifier_override are independent
    // ---------------------------------------------------------------

    #[Test]
    public function name_override_alone_replaces_only_the_name(): void
    {
        $suite = $this->importer()->import($this->validDocument(), 'Overridden name', null);

        $this->assertSame('Overridden name', $suite->name);
        $this->assertSame('home-automation-agent', $suite->agent_identifier);
    }

    #[Test]
    public function agent_identifier_override_alone_replaces_only_the_agent_identifier(): void
    {
        $suite = $this->importer()->import($this->validDocument(), null, 'billing-agent');

        $this->assertSame('Contact management sanity checks', $suite->name);
        $this->assertSame('billing-agent', $suite->agent_identifier);
    }

    #[Test]
    public function both_overrides_together_replace_both_fields(): void
    {
        $suite = $this->importer()->import($this->validDocument(), 'Overridden name', 'billing-agent');

        $this->assertSame('Overridden name', $suite->name);
        $this->assertSame('billing-agent', $suite->agent_identifier);
    }

    #[Test]
    public function neither_override_leaves_both_fields_as_the_document_declared_them(): void
    {
        $suite = $this->importer()->import($this->validDocument(), null, null);

        $this->assertSame('Contact management sanity checks', $suite->name);
        $this->assertSame('home-automation-agent', $suite->agent_identifier);
    }
}
