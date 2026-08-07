<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\CostRollupQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for CostRollupQuery (T037) — role-scoped reads directly against
 * seeded cost_summaries rows, independent of both the write path
 * (MetricsRecorder) and the HTTP layer (CostRollupController, covered by the
 * Feature journey tests instead).
 *
 * CostRollupQuery does not exist yet at the time this test is written
 * (T028 precedes T037 per tasks.md's Dependencies section), so its method
 * names/signatures are not fixed by any design doc — data-model.md/
 * contracts/cost-api.md describe the six HTTP endpoints and the common
 * response shape, but not the underlying service's API. This test assumes
 * the following shape, chosen as the most direct 1:1 mapping onto the six
 * `GET /cost-rollups/...` endpoints contracts/cost-api.md §3 describes, and
 * documents it here so the later implementation task (T037) can either
 * match it or this file can be adjusted to match a different real
 * signature:
 *
 *   conversationTotal(string $conversationId, string $from, string $to, ?string $callerId, bool $isOperator): array
 *   conversationList(string $from, string $to, ?string $callerId, bool $isOperator): array
 *   userTotal(string $userId, string $from, string $to, ?string $callerId, bool $isOperator): array
 *   userList(string $from, string $to, ?string $callerId, bool $isOperator): array
 *   agentTotal(string $agentId, string $from, string $to, ?string $callerId, bool $isOperator): array
 *   agentList(string $from, string $to, ?string $callerId, bool $isOperator): array
 *
 * Each *Total() method returns contracts/cost-api.md §3's common single-
 * rollup shape: ['priced_cost_total' => string, 'request_count' => int,
 * 'zero_priced_request_count' => int, 'unpriced_request_count' => int,
 * 'unpriced_total_tokens' => int, 'has_estimated_cost' => bool]. The
 * $callerId/$isOperator pair mirrors contracts/cost-api.md §4's
 * authorization table, resolved by the controller (T038) from
 * Auth::id()/OperatorAccess::isOperator() and passed through — this test
 * exercises the scoping decision at the service layer directly rather than
 * through an HTTP request, per T028's own description ("role-scoped
 * single/list reads per entity type").
 *
 * Money is compared via (float) cast rather than exact string equality —
 * see MetricsRecorderCostTest's doc comment for why (SQLite NUMERIC
 * affinity does not reliably round-trip an exact decimal string).
 */
class CostRollupQueryTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('cost_summaries')->delete();
        parent::tearDown();
    }

    private function today(): string
    {
        return Carbon::now()->toDateString();
    }

    private function seedSummary(array $overrides = []): void
    {
        DB::table('cost_summaries')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'entity_type' => 'user',
            'entity_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'period_date' => $this->today(),
            'request_count' => 1,
            'priced_cost_total' => '1.5000000000',
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => Carbon::now(),
        ], $overrides));
    }

    #[Test]
    public function an_operators_read_is_unrestricted_regardless_of_which_user_they_are(): void
    {
        $ownerId = (string) Str::uuid();
        $callerId = (string) Str::uuid(); // a different user than the data owner

        $this->seedSummary([
            'entity_type' => 'user',
            'entity_id' => $ownerId,
            'user_id' => $ownerId,
            'priced_cost_total' => '4.2000000000',
            'request_count' => 3,
        ]);

        $query = new CostRollupQuery();
        $result = $query->userTotal($ownerId, $this->today(), $this->today(), $callerId, true);

        $this->assertEqualsWithDelta(4.2, (float) $result['priced_cost_total'], 0.0000001);
        $this->assertSame(3, (int) $result['request_count']);
    }

    #[Test]
    public function a_non_operators_conversation_read_is_restricted_to_their_own(): void
    {
        $conversationId = (string) Str::uuid();
        $ownerId = (string) Str::uuid();
        $strangerId = (string) Str::uuid();

        $this->seedSummary([
            'entity_type' => 'conversation',
            'entity_id' => $conversationId,
            'user_id' => $ownerId,
            'priced_cost_total' => '2.0000000000',
            'request_count' => 1,
        ]);

        $query = new CostRollupQuery();

        $strangerResult = $query->conversationTotal($conversationId, $this->today(), $this->today(), $strangerId, false);
        $this->assertSame(0, (int) $strangerResult['request_count'], 'A non-operator must not see another user\'s conversation total');
        $this->assertEqualsWithDelta(0.0, (float) $strangerResult['priced_cost_total'], 0.0000001);

        $ownerResult = $query->conversationTotal($conversationId, $this->today(), $this->today(), $ownerId, false);
        $this->assertSame(1, (int) $ownerResult['request_count']);
        $this->assertEqualsWithDelta(2.0, (float) $ownerResult['priced_cost_total'], 0.0000001);
    }

    #[Test]
    public function a_non_operators_user_read_is_restricted_to_their_own(): void
    {
        $targetUserId = (string) Str::uuid();
        $strangerId = (string) Str::uuid();

        $this->seedSummary([
            'entity_type' => 'user',
            'entity_id' => $targetUserId,
            'user_id' => $targetUserId,
            'priced_cost_total' => '3.3000000000',
            'request_count' => 2,
        ]);

        $query = new CostRollupQuery();

        $strangerResult = $query->userTotal($targetUserId, $this->today(), $this->today(), $strangerId, false);
        $this->assertSame(0, (int) $strangerResult['request_count'], 'A non-operator must not see another user\'s totals');

        $ownResult = $query->userTotal($targetUserId, $this->today(), $this->today(), $targetUserId, false);
        $this->assertSame(2, (int) $ownResult['request_count']);
        $this->assertEqualsWithDelta(3.3, (float) $ownResult['priced_cost_total'], 0.0000001);
    }

    #[Test]
    public function a_non_operators_agent_read_sums_only_cost_summaries_rows_where_user_id_is_the_caller(): void
    {
        $agentId = 'shared-agent';
        $userA = (string) Str::uuid();
        $userB = (string) Str::uuid();

        $this->seedSummary([
            'entity_type' => 'agent',
            'entity_id' => $agentId,
            'user_id' => $userA,
            'priced_cost_total' => '1.0000000000',
            'request_count' => 1,
        ]);
        $this->seedSummary([
            'entity_type' => 'agent',
            'entity_id' => $agentId,
            'user_id' => $userB,
            'priced_cost_total' => '9.0000000000',
            'request_count' => 9,
        ]);

        $query = new CostRollupQuery();

        $asUserA = $query->agentTotal($agentId, $this->today(), $this->today(), $userA, false);
        $this->assertSame(1, (int) $asUserA['request_count'], 'Only the caller\'s own contribution, never the other user\'s');
        $this->assertEqualsWithDelta(1.0, (float) $asUserA['priced_cost_total'], 0.0000001);

        $asOperator = $query->agentTotal($agentId, $this->today(), $this->today(), $userA, true);
        $this->assertSame(10, (int) $asOperator['request_count'], 'An operator sees the full cross-user total');
        $this->assertEqualsWithDelta(10.0, (float) $asOperator['priced_cost_total'], 0.0000001);
    }

    #[Test]
    public function a_period_with_no_matching_rows_returns_the_zero_value_shape_never_null_or_an_exception(): void
    {
        $query = new CostRollupQuery();

        $result = $query->userTotal((string) Str::uuid(), '2000-01-01', '2000-01-02', (string) Str::uuid(), true);

        $this->assertIsArray($result);
        $this->assertSame(0, (int) $result['request_count']);
        $this->assertEqualsWithDelta(0.0, (float) $result['priced_cost_total'], 0.0000001);
        $this->assertSame(0, (int) $result['zero_priced_request_count']);
        $this->assertSame(0, (int) $result['unpriced_request_count']);
        $this->assertSame(0, (int) $result['unpriced_total_tokens']);
        $this->assertFalse((bool) $result['has_estimated_cost']);
    }
}
