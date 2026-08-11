<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use Illuminate\Support\Facades\DB;

use PHPUnit\Framework\Attributes\Test;

/**
 * User Story 3 end-to-end, through the real HTTP endpoints (spec.md US3
 * Acceptance Scenarios 1-2, quickstart.md step 9): a case marked with a
 * human_judgment expectation reports requires_human_judgment: true, a
 * case with only checkable expectations reports false, and both are
 * visibly distinguishable in one GET without opening either individually.
 *
 * Zero new production code expected — EvalCaseVersion::requiresHumanJudgment()
 * (Foundational) and its inclusion in formatCase() (US1) already exist; this
 * file only proves the behavior end-to-end.
 */
class HumanJudgmentCaseJourneyTest extends TestCase
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

    private function createSuite(array $overrides = []): array
    {
        $response = $this->actingAs($this->operator)->postJson($this->base(), array_merge([
            'name' => 'Tone and quality checks',
            'agent_identifier' => 'home-automation-agent',
        ], $overrides));

        $response->assertStatus(200);

        return $response->json();
    }

    // ---------------------------------------------------------------
    // Scenario 1 — a case with only a human_judgment expectation reports
    // requires_human_judgment: true; a checkable-only case reports false
    // ---------------------------------------------------------------

    #[Test]
    public function a_human_judgment_only_case_reports_true_and_a_checkable_only_case_reports_false(): void
    {
        $suite = $this->createSuite();

        $humanCase = $this->actingAs($this->operator)->postJson($this->casesEndpoint($suite['id']), [
            'given' => 'User asks the agent to write a condolence message.',
            'expected_behavior' => 'The agent writes something warm and appropriately toned.',
            'expectations' => [
                ['kind' => 'human_judgment', 'note' => 'Judge tone, not exact wording.'],
            ],
        ]);

        $humanCase->assertStatus(200);
        $this->assertTrue($humanCase->json('requires_human_judgment'));

        $checkableCase = $this->actingAs($this->operator)->postJson($this->casesEndpoint($suite['id']), [
            'given' => 'User says: "confirm the order."',
            'expected_behavior' => 'The agent confirms the order was placed.',
            'expectations' => [
                ['kind' => 'text_match', 'expected_text' => 'The order has been placed.'],
            ],
        ]);

        $checkableCase->assertStatus(200);
        $this->assertFalse($checkableCase->json('requires_human_judgment'));
    }

    // ---------------------------------------------------------------
    // Scenario 2 — both cases' judgment methods are visibly distinguishable
    // in one GET, without opening either individually
    // ---------------------------------------------------------------

    #[Test]
    public function both_kinds_of_case_are_visibly_distinguishable_in_one_get(): void
    {
        $suite = $this->createSuite();

        $humanCase = $this->actingAs($this->operator)->postJson($this->casesEndpoint($suite['id']), [
            'given' => 'given (human)',
            'expected_behavior' => 'expected behavior (human)',
            'expectations' => [
                ['kind' => 'human_judgment', 'note' => 'Judge tone.'],
            ],
        ]);
        $humanCase->assertStatus(200);

        $checkableCase = $this->actingAs($this->operator)->postJson($this->casesEndpoint($suite['id']), [
            'given' => 'given (checkable)',
            'expected_behavior' => 'expected behavior (checkable)',
            'expectations' => [
                ['kind' => 'text_match', 'expected_text' => 'confirmed'],
            ],
        ]);
        $checkableCase->assertStatus(200);

        $show = $this->actingAs($this->operator)->getJson($this->base().'/'.$suite['id']);
        $show->assertStatus(200);
        $this->assertCount(2, $show->json('cases'));

        $casesById = collect($show->json('cases'))->keyBy('id');

        $this->assertTrue($casesById[$humanCase->json('id')]['requires_human_judgment']);
        $this->assertFalse($casesById[$checkableCase->json('id')]['requires_human_judgment']);
    }

    // ---------------------------------------------------------------
    // A case combining a checkable expectation AND human_judgment
    // together also reports true (research.md D5 — not mutually exclusive)
    // ---------------------------------------------------------------

    #[Test]
    public function a_case_combining_a_checkable_expectation_and_human_judgment_reports_true(): void
    {
        $suite = $this->createSuite();

        $combined = $this->actingAs($this->operator)->postJson($this->casesEndpoint($suite['id']), [
            'given' => 'given',
            'expected_behavior' => 'expected behavior',
            'expectations' => [
                ['kind' => 'text_match', 'expected_text' => 'confirmed'],
                ['kind' => 'human_judgment', 'note' => 'Also judge overall tone.'],
            ],
        ]);

        $combined->assertStatus(200);
        $this->assertTrue($combined->json('requires_human_judgment'));
        $this->assertCount(2, $combined->json('expectations'));
    }

    // ---------------------------------------------------------------
    // note is optional on human_judgment
    // ---------------------------------------------------------------

    #[Test]
    public function human_judgment_note_is_optional(): void
    {
        $suite = $this->createSuite();

        $case = $this->actingAs($this->operator)->postJson($this->casesEndpoint($suite['id']), [
            'given' => 'given',
            'expected_behavior' => 'expected behavior',
            'expectations' => [
                ['kind' => 'human_judgment'],
            ],
        ]);

        $case->assertStatus(200);
        $this->assertTrue($case->json('requires_human_judgment'));
        $this->assertSame('human_judgment', $case->json('expectations')[0]['kind']);
    }
}
