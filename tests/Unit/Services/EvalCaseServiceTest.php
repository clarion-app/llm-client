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
 * eval_case_versions, covering addCase() only (data-model.md §2/§3, C3,
 * C5). editCase()/archive()/versionsFor() are User Story 2's concern and
 * are added to this file in Phase 4.
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
}
