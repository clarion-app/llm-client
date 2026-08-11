<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use Illuminate\Support\Facades\DB;

use PHPUnit\Framework\Attributes\Test;

/**
 * User Story 1 end-to-end, through the real HTTP endpoints
 * (spec.md US1 Acceptance Scenarios 1-6, contracts/eval-suites-api.md
 * §2-3): an operator creates a suite, adds a case using every checkable
 * expectation kind, and retrieves everything intact.
 *
 * POST /agent-eval-suites returns 200 with the §1.3 summary shape (this
 * package's established upsert-style convention, matching
 * BudgetCeilingController) — not 201, which is reserved for import's
 * genuinely-new-cross-installation-resource case (contracts §4).
 */
class SuiteDefinitionJourneyTest extends TestCase
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
            'name' => 'Contact management sanity checks',
            'agent_identifier' => 'home-automation-agent',
        ], $overrides));

        $response->assertStatus(200);

        return $response->json();
    }

    private function fourExpectations(): array
    {
        return [
            ['kind' => 'text_match', 'expected_text' => 'The order has been placed.'],
            ['kind' => 'information_present', 'expected_info' => 'the confirmation number'],
            ['kind' => 'action_taken', 'action' => 'orders.create'],
            ['kind' => 'action_not_taken', 'action' => 'billing.charge'],
        ];
    }

    // ---------------------------------------------------------------
    // Scenario 1 — a suite is created and appears in the list
    // ---------------------------------------------------------------

    #[Test]
    public function an_operator_creates_a_suite_and_it_appears_in_the_list(): void
    {
        $suite = $this->createSuite();

        $this->assertSame('Contact management sanity checks', $suite['name']);
        $this->assertSame('home-automation-agent', $suite['agent_identifier']);
        $this->assertArrayHasKey('id', $suite);
        $this->assertArrayHasKey('created_at', $suite);
        $this->assertArrayHasKey('updated_at', $suite);

        $list = $this->actingAs($this->operator)->getJson($this->base());
        $list->assertStatus(200);

        $row = collect($list->json('data'))->firstWhere('id', $suite['id']);
        $this->assertNotNull($row, 'The new suite must appear wherever suites are listed');
        $this->assertSame('Contact management sanity checks', $row['name']);
        $this->assertSame(0, $row['case_count']);
    }

    // ---------------------------------------------------------------
    // Scenario 2 — a case with all four checkable expectation kinds
    // ---------------------------------------------------------------

    #[Test]
    public function a_case_with_all_four_checkable_expectation_kinds_is_saved_and_retrievable_intact(): void
    {
        $suite = $this->createSuite();

        $case = $this->actingAs($this->operator)->postJson($this->casesEndpoint($suite['id']), [
            'given' => 'User says: "place an order for item X"',
            'expected_behavior' => 'The agent places the order and confirms the confirmation number.',
            'expectations' => $this->fourExpectations(),
        ]);

        $case->assertStatus(200);
        $case->assertJsonStructure([
            'id', 'suite_id', 'version_id', 'version_number',
            'given', 'expected_behavior', 'expectations',
            'requires_human_judgment', 'created_at', 'updated_at',
        ]);
        $this->assertSame($suite['id'], $case->json('suite_id'));
        $this->assertSame(1, $case->json('version_number'));
        $this->assertCount(4, $case->json('expectations'));
        $this->assertFalse($case->json('requires_human_judgment'));

        $show = $this->actingAs($this->operator)->getJson($this->base().'/'.$suite['id']);
        $show->assertStatus(200);
        $this->assertCount(1, $show->json('cases'));

        $retrievedCase = $show->json('cases')[0];
        $this->assertSame($case->json('id'), $retrievedCase['id']);
        $this->assertSame($case->json('given'), $retrievedCase['given']);
        $this->assertSame($case->json('expected_behavior'), $retrievedCase['expected_behavior']);

        $kinds = collect($retrievedCase['expectations'])->pluck('kind')->all();
        $this->assertEqualsCanonicalizing(
            ['text_match', 'information_present', 'action_taken', 'action_not_taken'],
            $kinds,
        );
    }

    // ---------------------------------------------------------------
    // Scenario 3 — information_present recorded distinctly from
    // an exact text match
    // ---------------------------------------------------------------

    #[Test]
    public function information_present_is_recorded_distinctly_from_an_exact_text_match(): void
    {
        $suite = $this->createSuite();

        $case = $this->actingAs($this->operator)->postJson($this->casesEndpoint($suite['id']), [
            'given' => 'given',
            'expected_behavior' => 'expected behavior',
            'expectations' => [
                ['kind' => 'text_match', 'expected_text' => 'The order has been placed.'],
                ['kind' => 'information_present', 'expected_info' => 'the confirmation number'],
            ],
        ]);

        $case->assertStatus(200);
        $expectations = collect($case->json('expectations'))->keyBy('kind');

        $this->assertSame('The order has been placed.', $expectations['text_match']['expected_text']);
        $this->assertSame('the confirmation number', $expectations['information_present']['expected_info']);
        $this->assertArrayNotHasKey('expected_text', $expectations['information_present']);
        $this->assertArrayNotHasKey('expected_info', $expectations['text_match']);
    }

    // ---------------------------------------------------------------
    // Scenario 4 — action_taken distinct from a text-based expectation
    // ---------------------------------------------------------------

    #[Test]
    public function action_taken_is_recorded_distinctly_and_told_apart_from_a_text_based_expectation(): void
    {
        $suite = $this->createSuite();

        $case = $this->actingAs($this->operator)->postJson($this->casesEndpoint($suite['id']), [
            'given' => 'given',
            'expected_behavior' => 'expected behavior',
            'expectations' => [
                ['kind' => 'text_match', 'expected_text' => 'confirmed'],
                ['kind' => 'action_taken', 'action' => 'orders.create'],
            ],
        ]);

        $case->assertStatus(200);
        $expectations = collect($case->json('expectations'))->keyBy('kind');

        $this->assertSame('orders.create', $expectations['action_taken']['action']);
        $this->assertArrayNotHasKey('action', $expectations['text_match']);
    }

    // ---------------------------------------------------------------
    // Scenario 5 — action_not_taken distinct from action_taken and from
    // having no expectation about that action at all
    // ---------------------------------------------------------------

    #[Test]
    public function action_not_taken_for_an_action_is_distinct_from_action_taken_for_it_and_from_no_expectation_at_all(): void
    {
        $suite = $this->createSuite();

        $caseWithNotTaken = $this->actingAs($this->operator)->postJson($this->casesEndpoint($suite['id']), [
            'given' => 'given 1',
            'expected_behavior' => 'expected behavior 1',
            'expectations' => [
                ['kind' => 'action_not_taken', 'action' => 'billing.charge'],
            ],
        ]);
        $caseWithNotTaken->assertStatus(200);

        $caseWithTaken = $this->actingAs($this->operator)->postJson($this->casesEndpoint($suite['id']), [
            'given' => 'given 2',
            'expected_behavior' => 'expected behavior 2',
            'expectations' => [
                ['kind' => 'action_taken', 'action' => 'billing.charge'],
            ],
        ]);
        $caseWithTaken->assertStatus(200);

        $caseWithNeither = $this->actingAs($this->operator)->postJson($this->casesEndpoint($suite['id']), [
            'given' => 'given 3',
            'expected_behavior' => 'expected behavior 3',
            'expectations' => [
                ['kind' => 'text_match', 'expected_text' => 'unrelated'],
            ],
        ]);
        $caseWithNeither->assertStatus(200);

        $this->assertSame('action_not_taken', $caseWithNotTaken->json('expectations')[0]['kind']);
        $this->assertSame('billing.charge', $caseWithNotTaken->json('expectations')[0]['action']);
        $this->assertSame('action_taken', $caseWithTaken->json('expectations')[0]['kind']);
        $this->assertSame('billing.charge', $caseWithTaken->json('expectations')[0]['action']);

        $neitherKinds = collect($caseWithNeither->json('expectations'))->pluck('kind')->all();
        $this->assertNotContains('action_taken', $neitherKinds);
        $this->assertNotContains('action_not_taken', $neitherKinds);
    }

    // ---------------------------------------------------------------
    // Scenario 6 — a case combining more than one expectation shows all
    // of them together in one GET
    // ---------------------------------------------------------------

    #[Test]
    public function a_case_combining_multiple_expectations_shows_all_of_them_together_in_one_get(): void
    {
        $suite = $this->createSuite();

        $created = $this->actingAs($this->operator)->postJson($this->casesEndpoint($suite['id']), [
            'given' => 'given',
            'expected_behavior' => 'expected behavior',
            'expectations' => $this->fourExpectations(),
        ]);
        $created->assertStatus(200);

        $show = $this->actingAs($this->operator)->getJson($this->base().'/'.$suite['id']);
        $show->assertStatus(200);

        $retrievedCase = collect($show->json('cases'))->firstWhere('id', $created->json('id'));
        $this->assertNotNull($retrievedCase);
        $this->assertCount(4, $retrievedCase['expectations']);
    }

    // ---------------------------------------------------------------
    // expectations is a JSON array preserving submission order
    // ---------------------------------------------------------------

    #[Test]
    public function expectations_are_returned_as_a_json_array_preserving_the_submitted_order(): void
    {
        $suite = $this->createSuite();

        $submitted = $this->fourExpectations();

        $case = $this->actingAs($this->operator)->postJson($this->casesEndpoint($suite['id']), [
            'given' => 'given',
            'expected_behavior' => 'expected behavior',
            'expectations' => $submitted,
        ]);

        $case->assertStatus(200);

        $submittedKinds = collect($submitted)->pluck('kind')->all();
        $returnedKinds = collect($case->json('expectations'))->pluck('kind')->all();

        $this->assertSame($submittedKinds, $returnedKinds, 'Expectation order must be preserved exactly as submitted');
    }
}
