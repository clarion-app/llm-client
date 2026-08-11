<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An operator can request that a fixed response be judged repeatedly
 * against a fixed rubric, see the full spread of resulting scores — not
 * only a mean — and see a criterion whose repeated judgments vary widely
 * flagged as producing unstable judgments, distinguishing it from a
 * criterion producing stable judgments.
 *
 * The request is synchronous end to end: POST .../consistency-checks
 * returns the completed sample in the same response, no polling. Only the
 * judge LlmProvider itself is a fixture (no real HTTP); every other layer
 * — role resolution, budget admission, authoring routes, the new
 * consistency-check routes — is exercised for real, matching the
 * RubricJudgmentJourneyTest precedent.
 */
class JudgmentConsistencyJourneyTest extends TestCase
{
    private User $operator;

    private Server $judgeServer;

    /** Deliberately tightly clustered — a well-specified rubric. */
    private array $tightScores = [8, 8, 7, 8, 8];

    /** Deliberately widely spread — a vague rubric. */
    private array $vagueScores = [2, 9, 3, 10, 4];

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $this->judgeServer = Server::create([
            'name' => 'Consistency journey judge server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        RoleAssignment::create([
            'role' => 'judge',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->judgeServer->id,
            'model' => 'judge-test-model',
        ]);

        $this->fakeJudgeProvider();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        DB::table('eval_judgment_consistency_samples')->delete();
        DB::table('eval_judgments')->delete();
        DB::table('eval_case_results')->delete();
        DB::table('eval_case_versions')->delete();
        DB::table('eval_cases')->delete();
        DB::table('eval_suites')->delete();
        DB::table('conversations')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixture helpers
    // ---------------------------------------------------------------

    private function suitesBase(): string
    {
        return '/api/clarion-app/llm-client/agent-eval-suites';
    }

    private function tightCriteria(): string
    {
        return 'The response must state the delivery date exactly as "August 11, 2026" and nothing else.';
    }

    private function vagueCriteria(): string
    {
        return 'The response should feel warm and appropriately empathetic.';
    }

    private function textChatResponse(string $content): array
    {
        return [
            'choices' => [['message' => ['content' => $content]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            'model' => 'judge-test-model',
        ];
    }

    /**
     * A single fixture judge provider, resolved for every server (there is
     * only one, the judge server) — which of the two scripted score queues
     * it draws from is decided by which criteria the built prompt's system
     * message actually contains, exactly like a real judge model reading
     * the rubric it was given.
     */
    private function fakeJudgeProvider(): void
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function (array $messages) {
            $systemContent = collect($messages)->firstWhere('role', 'system')['content'] ?? '';

            if (str_contains($systemContent, $this->tightCriteria())) {
                $score = array_shift($this->tightScores);
            } elseif (str_contains($systemContent, $this->vagueCriteria())) {
                $score = array_shift($this->vagueScores);
            } else {
                $score = 5;
            }

            return $this->textChatResponse(json_encode([
                'score' => $score,
                'justification' => 'Scripted consistency-check judge output.',
            ]));
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);
        $this->app->instance(ProviderRegistry::class, $registry);
    }

    private function createSuite(): string
    {
        return $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => 'Consistency-check journey fixture suite',
            'agent_identifier' => 'customer-support-agent',
        ])->assertStatus(200)->json('id');
    }

    private function createCase(string $suiteId, array $expectations): array
    {
        return $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suiteId.'/cases', [
            'given' => 'The customer asks when their delivery will arrive.',
            'expected_behavior' => 'State the delivery date, warmly.',
            'expectations' => $expectations,
        ])->assertStatus(200)->json();
    }

    private function consistencyChecksUrl(string $suiteId, string $caseId): string
    {
        return $this->suitesBase().'/'.$suiteId.'/cases/'.$caseId.'/consistency-checks';
    }

    /**
     * Inserts an eval_case_results row directly, standing in for the
     * result a real run would have produced — this feature's own
     * consistency-check path only ever reads produced_response off this
     * row, so a hand-seeded row is exactly as valid a fixture as a real
     * run's output would be (the EvalRunConsumptionQueryTest precedent for
     * hand-seeding this table directly in a test).
     */
    private function makeSourceResult(array $case, string $producedResponse = 'Your delivery will arrive on August 11, 2026.'): string
    {
        $result = EvalCaseResult::create([
            'run_id' => (string) Str::uuid(),
            'eval_run_case_id' => (string) Str::uuid(),
            'eval_case_id' => $case['id'],
            'eval_case_version_id' => $case['version_id'],
            'conversation_id' => (string) Str::uuid(),
            'outcome' => 'pass',
            'produced_response' => $producedResponse,
            'attempted_actions' => [],
            'expectation_results' => [],
        ]);

        return $result->id;
    }

    // ---------------------------------------------------------------
    // Scenario 1 / SC-002: every individual score is directly visible,
    // synchronously, in the POST response itself.
    // ---------------------------------------------------------------

    #[Test]
    public function post_consistency_check_returns_201_synchronously_with_every_individual_score(): void
    {
        $suiteId = $this->createSuite();
        $case = $this->createCase($suiteId, [['kind' => 'rubric_judgment', 'criteria' => $this->tightCriteria()]]);
        $sourceResultId = $this->makeSourceResult($case);

        $response = $this->actingAs($this->operator)->postJson($this->consistencyChecksUrl($suiteId, $case['id']), [
            'expectation_index' => 0,
            'source_eval_case_result_id' => $sourceResultId,
        ]);

        $response->assertStatus(201);

        $scores = $response->json('scores');
        $this->assertCount(5, $scores, 'the full spread of individual scores, not only a mean, must be directly visible');
        $this->assertSame([8, 8, 7, 8, 8], $scores);
    }

    // ---------------------------------------------------------------
    // Scenario 2 / FR-006: a tightly clustered rubric is not flagged.
    // ---------------------------------------------------------------

    #[Test]
    public function a_tightly_clustered_rubric_is_not_flagged_unstable(): void
    {
        $suiteId = $this->createSuite();
        $case = $this->createCase($suiteId, [['kind' => 'rubric_judgment', 'criteria' => $this->tightCriteria()]]);
        $sourceResultId = $this->makeSourceResult($case);

        $response = $this->actingAs($this->operator)->postJson($this->consistencyChecksUrl($suiteId, $case['id']), [
            'expectation_index' => 0,
            'source_eval_case_result_id' => $sourceResultId,
        ]);

        $response->assertStatus(201);
        $this->assertFalse($response->json('flagged_unstable'));
    }

    // ---------------------------------------------------------------
    // Scenario 3 / FR-006: a deliberately vague rubric is flagged.
    // ---------------------------------------------------------------

    #[Test]
    public function a_deliberately_vague_rubric_is_flagged_unstable(): void
    {
        $suiteId = $this->createSuite();
        $case = $this->createCase($suiteId, [['kind' => 'rubric_judgment', 'criteria' => $this->vagueCriteria()]]);
        $sourceResultId = $this->makeSourceResult($case);

        $response = $this->actingAs($this->operator)->postJson($this->consistencyChecksUrl($suiteId, $case['id']), [
            'expectation_index' => 0,
            'source_eval_case_result_id' => $sourceResultId,
        ]);

        $response->assertStatus(201);
        $this->assertSame([2, 9, 3, 10, 4], $response->json('scores'));
        $this->assertTrue($response->json('flagged_unstable'));
    }

    // ---------------------------------------------------------------
    // GET .../consistency-checks: every prior sample, newest first,
    // never paginated.
    // ---------------------------------------------------------------

    #[Test]
    public function get_consistency_checks_lists_every_prior_sample_for_the_case_newest_first_and_never_paginated(): void
    {
        $suiteId = $this->createSuite();
        $case = $this->createCase($suiteId, [['kind' => 'rubric_judgment', 'criteria' => $this->tightCriteria()]]);
        $sourceResultId = $this->makeSourceResult($case);

        $first = $this->actingAs($this->operator)->postJson($this->consistencyChecksUrl($suiteId, $case['id']), [
            'expectation_index' => 0,
            'source_eval_case_result_id' => $sourceResultId,
        ])->assertStatus(201)->json('id');

        // Re-arm the scripted queue for the second sample — each POST
        // consumes the whole queue for one sample_size-worth of repeats.
        $this->tightScores = [7, 8, 7, 8, 7];

        $second = $this->actingAs($this->operator)->postJson($this->consistencyChecksUrl($suiteId, $case['id']), [
            'expectation_index' => 0,
            'source_eval_case_result_id' => $sourceResultId,
        ])->assertStatus(201)->json('id');

        $listResponse = $this->actingAs($this->operator)->getJson($this->consistencyChecksUrl($suiteId, $case['id']));
        $listResponse->assertStatus(200);

        $data = $listResponse->json('data');
        $this->assertCount(2, $data, 'every prior sample for the case must be listed');
        $this->assertSame($second, $data[0]['id'], 'newest first');
        $this->assertSame($first, $data[1]['id']);

        $this->assertArrayNotHasKey('current_page', $listResponse->json(), 'never paginated');
        $this->assertArrayNotHasKey('per_page', $listResponse->json(), 'never paginated');
    }

    // ---------------------------------------------------------------
    // 422 branch 1: expectation_index does not point at a
    // rubric_judgment entry on the case's current version.
    // ---------------------------------------------------------------

    #[Test]
    public function post_consistency_check_rejects_an_expectation_index_that_is_not_a_rubric_judgment_entry(): void
    {
        $suiteId = $this->createSuite();
        $case = $this->createCase($suiteId, [
            ['kind' => 'text_match', 'expected_text' => 'August 11, 2026'],
            ['kind' => 'rubric_judgment', 'criteria' => $this->tightCriteria()],
        ]);

        $response = $this->actingAs($this->operator)->postJson($this->consistencyChecksUrl($suiteId, $case['id']), [
            'expectation_index' => 0, // a text_match entry, not rubric_judgment
            'response_text' => 'Your delivery will arrive on August 11, 2026.',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('eval_judgment_consistency_samples')->count());
    }

    // ---------------------------------------------------------------
    // 422 branch 2: source_eval_case_result_id belongs to a different
    // case.
    // ---------------------------------------------------------------

    #[Test]
    public function post_consistency_check_rejects_a_source_eval_case_result_id_belonging_to_a_different_case(): void
    {
        $suiteId = $this->createSuite();
        $caseA = $this->createCase($suiteId, [['kind' => 'rubric_judgment', 'criteria' => $this->tightCriteria()]]);
        $caseB = $this->createCase($suiteId, [['kind' => 'rubric_judgment', 'criteria' => $this->vagueCriteria()]]);
        $sourceFromCaseB = $this->makeSourceResult($caseB);

        $response = $this->actingAs($this->operator)->postJson($this->consistencyChecksUrl($suiteId, $caseA['id']), [
            'expectation_index' => 0,
            'source_eval_case_result_id' => $sourceFromCaseB,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('eval_judgment_consistency_samples')->count());
    }

    // ---------------------------------------------------------------
    // 422 branch 3: neither source_eval_case_result_id nor
    // response_text supplied.
    // ---------------------------------------------------------------

    #[Test]
    public function post_consistency_check_rejects_when_neither_source_nor_response_text_is_supplied(): void
    {
        $suiteId = $this->createSuite();
        $case = $this->createCase($suiteId, [['kind' => 'rubric_judgment', 'criteria' => $this->tightCriteria()]]);

        $response = $this->actingAs($this->operator)->postJson($this->consistencyChecksUrl($suiteId, $case['id']), [
            'expectation_index' => 0,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('eval_judgment_consistency_samples')->count());
    }

    // ---------------------------------------------------------------
    // 422 branch 4: both source_eval_case_result_id and response_text
    // supplied together.
    // ---------------------------------------------------------------

    #[Test]
    public function post_consistency_check_rejects_when_both_source_and_response_text_are_supplied(): void
    {
        $suiteId = $this->createSuite();
        $case = $this->createCase($suiteId, [['kind' => 'rubric_judgment', 'criteria' => $this->tightCriteria()]]);
        $sourceResultId = $this->makeSourceResult($case);

        $response = $this->actingAs($this->operator)->postJson($this->consistencyChecksUrl($suiteId, $case['id']), [
            'expectation_index' => 0,
            'source_eval_case_result_id' => $sourceResultId,
            'response_text' => 'Your delivery will arrive on August 11, 2026.',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('eval_judgment_consistency_samples')->count());
    }
}
