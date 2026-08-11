<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\EvalSuite;
use ClarionApp\LlmClient\Services\EvalCaseService;
use ClarionApp\LlmClient\Services\EvalSuiteExporter;
use Illuminate\Support\Facades\DB;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for EvalSuiteExporter — the read-only translation from a live
 * EvalSuite to the self-contained document shape data-model.md §6 defines
 * (research.md D10): schema_version, the suite's own name/agent_identifier,
 * and one entry per live case rendered from that case's *current* version.
 *
 * The document carries no row identity and no source-installation
 * timestamp anywhere (data-model.md §6) — an exported/re-imported suite
 * must describe the suite's current definition, never a copy of its rows.
 * An archived case must never appear in the export (mutation-checklist row
 * 12): a re-imported suite must not resurrect content an operator removed.
 */
class EvalSuiteExporterTest extends TestCase
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

    private function exporter(): EvalSuiteExporter
    {
        return new EvalSuiteExporter();
    }

    private function caseService(): EvalCaseService
    {
        return new EvalCaseService();
    }

    private function suite(array $overrides = []): EvalSuite
    {
        return EvalSuite::create(array_merge([
            'name' => 'Contact management sanity checks',
            'agent_identifier' => 'home-automation-agent',
        ], $overrides));
    }

    private function firstExpectations(): array
    {
        return [
            ['kind' => 'action_taken', 'action' => 'contacts.create'],
            ['kind' => 'information_present', 'expected_info' => "the contact's name"],
        ];
    }

    private function secondExpectations(): array
    {
        return [
            ['kind' => 'text_match', 'expected_text' => 'Done.'],
            ['kind' => 'human_judgment', 'note' => 'Judge the tone.'],
        ];
    }

    /**
     * Recursively collects every array key appearing anywhere in $data,
     * so a test can assert none of a forbidden set (row ids, version
     * numbers, source timestamps) leaked in at any depth.
     *
     * @return array<int, string>
     */
    private function allKeysRecursively(array $data): array
    {
        $keys = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }

            if (is_array($value)) {
                $keys = array_merge($keys, $this->allKeysRecursively($value));
            }
        }

        return $keys;
    }

    // ---------------------------------------------------------------
    // schema_version / name / agent_identifier
    // ---------------------------------------------------------------

    #[Test]
    public function export_returns_schema_version_1_and_the_suites_own_name_and_agent_identifier(): void
    {
        $suite = $this->suite([
            'name' => 'Contact management sanity checks',
            'agent_identifier' => 'home-automation-agent',
        ]);

        $document = $this->exporter()->export($suite);

        $this->assertSame(1, $document['schema_version']);
        $this->assertSame('Contact management sanity checks', $document['name']);
        $this->assertSame('home-automation-agent', $document['agent_identifier']);
    }

    // ---------------------------------------------------------------
    // One entry per live case, from each case's current version
    // ---------------------------------------------------------------

    #[Test]
    public function export_includes_one_entry_per_live_case_with_given_expected_behavior_and_expectations(): void
    {
        $suite = $this->suite();
        $service = $this->caseService();

        $service->addCase($suite, 'given one', 'expected behavior one', $this->firstExpectations());
        $service->addCase($suite, 'given two', 'expected behavior two', $this->secondExpectations());

        $document = $this->exporter()->export($suite);

        $this->assertCount(2, $document['cases']);

        $first = $document['cases'][0];
        $this->assertSame('given one', $first['given']);
        $this->assertSame('expected behavior one', $first['expected_behavior']);
        $this->assertSame($this->firstExpectations(), $first['expectations']);

        $second = $document['cases'][1];
        $this->assertSame('given two', $second['given']);
        $this->assertSame('expected behavior two', $second['expected_behavior']);
        $this->assertSame($this->secondExpectations(), $second['expectations']);
    }

    #[Test]
    public function export_renders_each_case_from_its_current_version_not_an_earlier_one(): void
    {
        $suite = $this->suite();
        $service = $this->caseService();

        $case = $service->addCase($suite, 'v1 given', 'v1 expected behavior', $this->firstExpectations());
        $service->editCase($case, 'v2 given', 'v2 expected behavior', $this->secondExpectations());

        $document = $this->exporter()->export($suite);

        $this->assertCount(1, $document['cases']);
        $this->assertSame('v2 given', $document['cases'][0]['given']);
        $this->assertSame('v2 expected behavior', $document['cases'][0]['expected_behavior']);
        $this->assertSame($this->secondExpectations(), $document['cases'][0]['expectations']);
    }

    // ---------------------------------------------------------------
    // An archived case is excluded (mutation-checklist row 12)
    // ---------------------------------------------------------------

    #[Test]
    public function an_archived_case_is_excluded_from_the_export(): void
    {
        $suite = $this->suite();
        $service = $this->caseService();

        $service->addCase($suite, 'still live', 'still live behavior', $this->firstExpectations());
        $toArchive = $service->addCase($suite, 'will be archived', 'archived behavior', $this->secondExpectations());
        $service->archive($toArchive);

        $document = $this->exporter()->export($suite->fresh());

        $this->assertCount(
            1,
            $document['cases'],
            "The export's implied case count must match the source's live count, not include the archived case",
        );
        $this->assertSame('still live', $document['cases'][0]['given']);
        $this->assertNotContains('will be archived', array_column($document['cases'], 'given'));
    }

    #[Test]
    public function a_suite_with_no_live_cases_exports_an_empty_cases_array(): void
    {
        $suite = $this->suite();

        $document = $this->exporter()->export($suite);

        $this->assertSame([], $document['cases']);
    }

    // ---------------------------------------------------------------
    // No row identity, no version_number, no source timestamp anywhere
    // ---------------------------------------------------------------

    #[Test]
    public function no_row_id_version_number_or_source_timestamp_appears_anywhere_in_the_export(): void
    {
        $suite = $this->suite();
        $service = $this->caseService();

        $case = $service->addCase($suite, 'given', 'expected behavior', $this->firstExpectations());
        $service->editCase($case, 'edited given', 'edited expected behavior', $this->secondExpectations());

        $document = $this->exporter()->export($suite->fresh());

        $forbiddenKeys = ['id', 'suite_id', 'case_id', 'version_id', 'version_number', 'created_at', 'updated_at', 'deleted_at'];
        $actualKeys = $this->allKeysRecursively($document);

        foreach ($forbiddenKeys as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $actualKeys,
                "The export document must never carry a \"{$forbidden}\" key anywhere (research.md D10)",
            );
        }
    }
}
