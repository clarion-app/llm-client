<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\EvalCase;
use ClarionApp\LlmClient\Models\EvalCaseVersion;
use ClarionApp\LlmClient\Models\EvalSuite;
use ClarionApp\LlmClient\Services\EvalCaseService;
use Illuminate\Support\Facades\DB;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for EvalCaseService — the sole write path for eval_cases and
 * eval_case_versions, covering addCase() (data-model.md §2/§3, C3, C5).
 *
 * Also covers editCase()/archive()/versionsFor() (User Story 2, C4/C5/C6):
 * editCase() inserts exactly one new eval_case_versions row and updates the
 * current-version pointer, leaving every previously written version row
 * byte-identical (FR-011/FR-012 — the single most important property this
 * whole feature makes structurally true); archive() soft-deletes only the
 * eval_cases row, never cascading to eval_case_versions (FR-013);
 * versionsFor() returns every version ever written, oldest first.
 *
 * Suite fixtures are built directly through the EvalSuite model rather
 * than through EvalSuiteService: this file is exercising EvalCaseService,
 * and the suite itself only needs to exist, not be created through its own
 * sole write path.
 */
class EvalCaseServiceTest extends TestCase
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

    private function service(): EvalCaseService
    {
        return new EvalCaseService();
    }

    private function suite(): EvalSuite
    {
        return EvalSuite::create([
            'name' => 'Contact management sanity checks',
            'agent_identifier' => 'home-automation-agent',
        ]);
    }

    private function validExpectations(): array
    {
        return [
            ['kind' => 'action_taken', 'action' => 'contacts.create'],
            ['kind' => 'information_present', 'expected_info' => "the contact's name"],
        ];
    }

    private function assertAddCaseRejected(EvalSuite $suite, string $given, string $expectedBehavior, array $expectations, string $message): void
    {
        try {
            $this->service()->addCase($suite, $given, $expectedBehavior, $expectations);
            $this->fail($message);
        } catch (\InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage(), 'A rejection must say what was wrong');
        }

        $this->assertSame(0, DB::table('eval_cases')->count(), 'A rejected addCase must write no case row: '.$message);
        $this->assertSame(0, DB::table('eval_case_versions')->count(), 'A rejected addCase must write no version row: '.$message);
    }

    private function otherExpectations(): array
    {
        return [
            ['kind' => 'action_not_taken', 'action' => 'billing.charge'],
            ['kind' => 'text_match', 'expected_text' => 'Done.'],
        ];
    }

    private function assertEditCaseRejected(EvalCase $case, string $given, string $expectedBehavior, array $expectations, string $message): void
    {
        $versionCountBefore = DB::table('eval_case_versions')->where('case_id', $case->id)->count();
        $currentVersionIdBefore = $case->fresh()->current_version_id;

        try {
            $this->service()->editCase($case, $given, $expectedBehavior, $expectations);
            $this->fail($message);
        } catch (\InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage(), 'A rejection must say what was wrong');
        }

        $this->assertSame(
            $versionCountBefore,
            DB::table('eval_case_versions')->where('case_id', $case->id)->count(),
            'A rejected editCase must write no new version row: '.$message,
        );
        $this->assertSame(
            $currentVersionIdBefore,
            $case->fresh()->current_version_id,
            'A rejected editCase must leave the previous version as the current one: '.$message,
        );
    }

    // ---------------------------------------------------------------
    // addCase() — the happy path (C3)
    // ---------------------------------------------------------------

    #[Test]
    public function add_case_inserts_exactly_one_version_row_and_one_case_row_pointing_at_it(): void
    {
        $suite = $this->suite();

        $case = $this->service()->addCase(
            $suite,
            'User says: "add a contact named Alice"',
            'The agent creates the contact and confirms.',
            $this->validExpectations(),
        );

        $this->assertInstanceOf(EvalCase::class, $case);
        $this->assertSame($suite->id, $case->suite_id);
        $this->assertSame(1, DB::table('eval_cases')->count());
        $this->assertSame(1, DB::table('eval_case_versions')->count());

        $version = EvalCaseVersion::find($case->current_version_id);
        $this->assertNotNull($version);
        $this->assertSame(1, $version->version_number);
        $this->assertSame('User says: "add a contact named Alice"', $version->given);
        $this->assertSame('The agent creates the contact and confirms.', $version->expected_behavior);
        $this->assertCount(2, $version->expectations);
    }

    #[Test]
    public function the_new_cases_current_version_relation_resolves_to_the_just_created_version(): void
    {
        $case = $this->service()->addCase(
            $this->suite(),
            'given',
            'expected behavior',
            $this->validExpectations(),
        );

        $this->assertNotNull($case->currentVersion);
        $this->assertSame($case->current_version_id, $case->currentVersion->id);
        $this->assertSame(1, $case->currentVersion->version_number);
    }

    // ---------------------------------------------------------------
    // FR-009 — empty expectations rejected before any write
    // ---------------------------------------------------------------

    #[Test]
    public function an_empty_expectations_array_is_rejected_before_any_write(): void
    {
        $this->assertAddCaseRejected(
            $this->suite(),
            'given',
            'expected behavior',
            [],
            'An empty expectations array must be rejected',
        );
    }

    // ---------------------------------------------------------------
    // FR-010 — action_taken/action_not_taken must name the action, via
    // the identical Expectation::validate() rule T011 already covers
    // ---------------------------------------------------------------

    #[Test]
    public function an_action_taken_expectation_with_a_missing_or_empty_action_is_rejected(): void
    {
        $suite = $this->suite();

        foreach ([
            ['kind' => 'action_taken'],
            ['kind' => 'action_taken', 'action' => ''],
            ['kind' => 'action_taken', 'action' => '   '],
        ] as $expectation) {
            $this->assertAddCaseRejected(
                $suite,
                'given',
                'expected behavior',
                [$expectation],
                'An action_taken with no named action must be rejected: '.json_encode($expectation),
            );
        }
    }

    #[Test]
    public function an_action_not_taken_expectation_with_a_missing_or_empty_action_is_rejected(): void
    {
        $suite = $this->suite();

        foreach ([
            ['kind' => 'action_not_taken'],
            ['kind' => 'action_not_taken', 'action' => ''],
        ] as $expectation) {
            $this->assertAddCaseRejected(
                $suite,
                'given',
                'expected behavior',
                [$expectation],
                'An action_not_taken with no named action must be rejected: '.json_encode($expectation),
            );
        }
    }

    #[Test]
    public function an_unrecognized_expectation_kind_is_rejected(): void
    {
        $this->assertAddCaseRejected(
            $this->suite(),
            'given',
            'expected behavior',
            [['kind' => 'vibes_check']],
            'An unrecognized expectation kind must be rejected',
        );
    }

    // ---------------------------------------------------------------
    // given / expected_behavior
    // ---------------------------------------------------------------

    #[Test]
    public function given_is_required_and_rejected_when_empty_after_trim(): void
    {
        $suite = $this->suite();

        foreach (['', '   '] as $given) {
            $this->assertAddCaseRejected(
                $suite,
                $given,
                'expected behavior',
                $this->validExpectations(),
                "given '{$given}' must be rejected",
            );
        }
    }

    #[Test]
    public function expected_behavior_is_required_and_rejected_when_empty_after_trim(): void
    {
        $suite = $this->suite();

        foreach (['', '   '] as $expectedBehavior) {
            $this->assertAddCaseRejected(
                $suite,
                'given',
                $expectedBehavior,
                $this->validExpectations(),
                "expected_behavior '{$expectedBehavior}' must be rejected",
            );
        }
    }

    #[Test]
    public function given_over_the_configured_max_text_length_is_rejected(): void
    {
        config(['llm-client.eval_suites.max_text_length' => 20]);

        $this->assertAddCaseRejected(
            $this->suite(),
            str_repeat('a', 21),
            'expected behavior',
            $this->validExpectations(),
            'A given over the configured max_text_length must be rejected',
        );
    }

    #[Test]
    public function expected_behavior_over_the_configured_max_text_length_is_rejected(): void
    {
        config(['llm-client.eval_suites.max_text_length' => 20]);

        $this->assertAddCaseRejected(
            $this->suite(),
            'given',
            str_repeat('a', 21),
            $this->validExpectations(),
            'An expected_behavior over the configured max_text_length must be rejected',
        );
    }

    // ---------------------------------------------------------------
    // editCase() — the happy path (C4)
    // ---------------------------------------------------------------

    #[Test]
    public function edit_case_inserts_exactly_one_new_version_row_and_repoints_current_version_id(): void
    {
        $case = $this->service()->addCase(
            $this->suite(),
            'original given',
            'original expected behavior',
            $this->validExpectations(),
        );
        $v1Id = $case->current_version_id;

        $edited = $this->service()->editCase(
            $case,
            'edited given',
            'edited expected behavior',
            $this->otherExpectations(),
        );

        $this->assertSame(1, DB::table('eval_cases')->count(), 'editCase must not create a new case row');
        $this->assertSame(2, DB::table('eval_case_versions')->count(), 'editCase must insert exactly one new version row');

        $this->assertNotSame($v1Id, $edited->current_version_id, 'current_version_id must repoint to the new version');
        $this->assertSame('edited given', $edited->currentVersion->given);
        $this->assertSame('edited expected behavior', $edited->currentVersion->expected_behavior);
        $this->assertSame(2, $edited->currentVersion->version_number);
    }

    #[Test]
    public function editing_a_case_leaves_the_previous_version_byte_identical_before_and_after(): void
    {
        // C4/FR-011/FR-012, mutation-checklist row 1 — "the single most
        // important row in this checklist": capture the previous version's
        // content, edit, re-fetch the *same* version row by id, and prove
        // nothing about it changed. This is what makes a historical run
        // result's captured version id stay meaningful forever.
        $case = $this->service()->addCase(
            $this->suite(),
            'original given',
            'original expected behavior',
            $this->validExpectations(),
        );
        $v1Id = $case->current_version_id;
        $v1Before = EvalCaseVersion::find($v1Id)->toArray();

        $this->service()->editCase(
            $case,
            'edited given',
            'edited expected behavior',
            $this->otherExpectations(),
        );

        $v1After = EvalCaseVersion::find($v1Id);

        $this->assertNotNull($v1After, 'The previous version row must still exist, untouched');
        $this->assertSame($v1Before['given'], $v1After->given);
        $this->assertSame($v1Before['expected_behavior'], $v1After->expected_behavior);
        $this->assertSame($v1Before['expectations'], $v1After->expectations);
        $this->assertSame($v1Before['version_number'], $v1After->version_number);
    }

    // ---------------------------------------------------------------
    // editCase() — validation identical to addCase(), before any write
    // ---------------------------------------------------------------

    #[Test]
    public function edit_case_rejects_an_empty_expectations_array_before_any_write(): void
    {
        $case = $this->service()->addCase(
            $this->suite(),
            'given',
            'expected behavior',
            $this->validExpectations(),
        );

        $this->assertEditCaseRejected(
            $case,
            'edited given',
            'edited expected behavior',
            [],
            'An empty expectations array must be rejected on edit exactly as on add',
        );
    }

    #[Test]
    public function edit_case_rejects_an_action_taken_with_a_missing_or_empty_action(): void
    {
        $case = $this->service()->addCase(
            $this->suite(),
            'given',
            'expected behavior',
            $this->validExpectations(),
        );

        foreach ([
            ['kind' => 'action_taken'],
            ['kind' => 'action_taken', 'action' => ''],
        ] as $expectation) {
            $this->assertEditCaseRejected(
                $case,
                'edited given',
                'edited expected behavior',
                [$expectation],
                'An action_taken with no named action must be rejected on edit: '.json_encode($expectation),
            );
        }
    }

    // ---------------------------------------------------------------
    // version_number is MAX(version_number) + 1, not COUNT(*)
    // (mutation-checklist row 15)
    // ---------------------------------------------------------------

    #[Test]
    public function version_number_is_derived_from_the_maximum_not_a_row_count(): void
    {
        $case = $this->service()->addCase(
            $this->suite(),
            'given',
            'expected behavior',
            $this->validExpectations(),
        );

        // Simulate a gap in the version sequence: two rows exist
        // (COUNT = 2) but the highest version_number is 5, not 2. If
        // editCase() ever derived the next number from COUNT(*) instead of
        // MAX(version_number), the two formulas would silently diverge
        // here — COUNT(*) + 1 = 3, but MAX(version_number) + 1 = 6 — and
        // the wrong one would either collide with the unique
        // (case_id, version_number) constraint or renumber a version that
        // already exists.
        EvalCaseVersion::create([
            'case_id' => $case->id,
            'version_number' => 5,
            'given' => 'out of order',
            'expected_behavior' => 'out of order',
            'expectations' => $this->validExpectations(),
        ]);

        $this->assertSame(2, DB::table('eval_case_versions')->where('case_id', $case->id)->count());

        $edited = $this->service()->editCase(
            $case,
            'edited given',
            'edited expected behavior',
            $this->otherExpectations(),
        );

        $this->assertSame(
            6,
            $edited->currentVersion->version_number,
            'The next version_number must be MAX(version_number) + 1 (6), not COUNT(*) + 1 (3)',
        );
    }

    // ---------------------------------------------------------------
    // archive() — soft-deletes only eval_cases, never cascades (C6)
    // ---------------------------------------------------------------

    #[Test]
    public function archive_soft_deletes_only_the_case_row_every_version_remains_fetchable_by_id(): void
    {
        $case = $this->service()->addCase(
            $this->suite(),
            'original given',
            'original expected behavior',
            $this->validExpectations(),
        );
        $v1Id = $case->current_version_id;

        $edited = $this->service()->editCase(
            $case,
            'edited given',
            'edited expected behavior',
            $this->otherExpectations(),
        );
        $v2Id = $edited->current_version_id;

        $this->service()->archive($case);

        $this->assertNull(EvalCase::find($case->id), 'archive() must soft-delete the case identity row');

        $row = DB::table('eval_cases')->where('id', $case->id)->first();
        $this->assertNotNull($row, 'archive() must not hard-delete the case row');
        $this->assertNotNull($row->deleted_at);

        $this->assertNotNull(EvalCaseVersion::find($v1Id), 'v1 must remain resolvable by id after the case is archived');
        $this->assertNotNull(EvalCaseVersion::find($v2Id), 'v2 must remain resolvable by id after the case is archived');
        $this->assertSame(2, DB::table('eval_case_versions')->where('case_id', $case->id)->whereNull('deleted_at')->count());
    }

    // ---------------------------------------------------------------
    // versionsFor()
    // ---------------------------------------------------------------

    #[Test]
    public function versions_for_returns_every_version_ever_written_oldest_first(): void
    {
        $case = $this->service()->addCase(
            $this->suite(),
            'v1 given',
            'v1 expected behavior',
            $this->validExpectations(),
        );

        $this->service()->editCase($case, 'v2 given', 'v2 expected behavior', $this->otherExpectations());
        $this->service()->editCase($case, 'v3 given', 'v3 expected behavior', $this->validExpectations());

        $versions = $this->service()->versionsFor($case->fresh());

        $this->assertCount(3, $versions);
        $this->assertSame([1, 2, 3], $versions->pluck('version_number')->all());
        $this->assertSame(['v1 given', 'v2 given', 'v3 given'], $versions->pluck('given')->all());
    }

    #[Test]
    public function versions_for_still_returns_every_version_after_the_case_is_archived(): void
    {
        $case = $this->service()->addCase(
            $this->suite(),
            'v1 given',
            'v1 expected behavior',
            $this->validExpectations(),
        );
        $this->service()->editCase($case, 'v2 given', 'v2 expected behavior', $this->otherExpectations());

        $this->service()->archive($case);

        // Pass the already-held (pre-archive) in-memory instance rather
        // than re-fetching by id: EvalCase's SoftDeletes global scope would
        // make an ordinary find()/fresh() call return null for an archived
        // row, but versionsFor() only needs the case's id, which this
        // instance already carries.
        $versions = $this->service()->versionsFor($case);

        $this->assertCount(2, $versions, 'Archiving a case must never make its versions unreachable through versionsFor()');
    }
}
