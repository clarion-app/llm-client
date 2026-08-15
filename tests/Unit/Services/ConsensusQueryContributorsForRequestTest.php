<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\ConsensusRequest;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Services\ConsensusQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 104-multi-agent-consensus, Phase 6 (US4), tasks.md T040.
 *
 * ConsensusQuery::contributorsForRequest() (data-model.md §4, contracts/
 * consensus-api.md §3): ownership-checked via findRequest() first (no
 * further query run for an absent/not-owned request), delegates to
 * DelegationQuery::membersForBatch() (Grounding note item 2), returning
 * delegation_id/helper_agent_id/result_status/answer
 * (= Delegation.result_summary, null when result_status = 'failure')/
 * result_reason, in started_at order -- for EVERY terminal status, not
 * only no_consensus (FR-008's own "still let the user view each
 * individual contributor's answer" applies regardless of outcome).
 *
 * Mirrors ConsensusQueryCostForRequestTest's own established technique
 * (Phase 4). Written before contributorsForRequest() exists -- every
 * assertion below is expected to FAIL red until T043 adds it.
 */
class ConsensusQueryContributorsForRequestTest extends TestCase
{
    private User $user;

    private User $otherUser;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();

        $this->conversation = Conversation::create([
            'user_id' => $this->user->id,
            'character' => 'Clarion',
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('agent_delegations')->delete();
        DB::table('consensus_requests')->delete();
        DB::table('conversations')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function query(): ConsensusQuery
    {
        return app(ConsensusQuery::class);
    }

    private function makeRequest(string $ownerUserId, string $conversationId, ?string $batchId, array $overrides = []): ConsensusRequest
    {
        return ConsensusRequest::create(array_merge([
            'conversation_id' => $conversationId,
            'owner_user_id' => $ownerUserId,
            'question' => 'Is it safe to run this migration?',
            'dispatched_count' => 3,
            'quorum_required' => 2,
            'successful_count' => 3,
            'status' => 'completed',
            'agreement_classification' => 'agreed',
            'reconciled_answer' => 'Yes, it is safe.',
            'batch_id' => $batchId,
            'started_at' => now(),
            'completed_at' => now(),
        ], $overrides));
    }

    private function makeDelegation(
        string $ownerUserId,
        string $conversationId,
        string $batchId,
        string $helperAgentId,
        string $resultStatus,
        ?string $summary,
        ?string $resultReason,
        \DateTimeInterface $startedAt,
    ): Delegation {
        $helperConversation = Conversation::create([
            'user_id' => $ownerUserId,
            'character' => 'Clarion',
        ]);

        return Delegation::create([
            'parent_conversation_id' => $conversationId,
            'helper_agent_id' => $helperAgentId,
            'helper_conversation_id' => $helperConversation->id,
            'owner_user_id' => $ownerUserId,
            'task' => 'Is it safe to run this migration?',
            'depth' => 1,
            'status' => $resultStatus === 'failure' ? 'failed' : 'completed',
            'batch_id' => $batchId,
            'result_status' => $resultStatus,
            'result_reason' => $resultReason,
            'result_summary' => $summary,
            'result_output' => $resultStatus === 'failure' ? null : json_encode([]),
            'result_undone' => $resultStatus === 'failure' ? 'Everything.' : '',
            'result_truncated' => false,
            'started_at' => $startedAt,
            'completed_at' => $startedAt,
        ]);
    }

    // =================================================================
    // Ownership-checked via findRequest() first -- null for absent/not-
    // owned, no further query run.
    // =================================================================

    #[Test]
    public function returns_null_for_a_request_not_owned_by_the_caller(): void
    {
        $othersConversation = Conversation::create([
            'user_id' => $this->otherUser->id,
            'character' => 'Clarion',
        ]);
        $batchId = (string) Str::uuid();
        $request = $this->makeRequest($this->otherUser->id, $othersConversation->id, $batchId);
        $this->makeDelegation($this->otherUser->id, $othersConversation->id, $batchId, (string) Str::uuid(), 'success', 'Answer.', null, now());

        $result = $this->query()->contributorsForRequest($this->user->id, $request->id);

        $this->assertNull($result);
    }

    #[Test]
    public function returns_null_for_a_nonexistent_request_id(): void
    {
        $result = $this->query()->contributorsForRequest($this->user->id, (string) Str::uuid());

        $this->assertNull($result);
    }

    // =================================================================
    // Delegates to DelegationQuery::membersForBatch(), returning the
    // exact contracts/consensus-api.md §3 shape, in started_at order.
    // =================================================================

    #[Test]
    public function returns_contributors_in_started_at_order_with_the_contract_shape(): void
    {
        $batchId = (string) Str::uuid();
        $request = $this->makeRequest($this->user->id, $this->conversation->id, $batchId);

        $helperA = (string) Str::uuid();
        $helperB = (string) Str::uuid();
        $helperC = (string) Str::uuid();

        // Inserted out of started_at order deliberately.
        $second = $this->makeDelegation($this->user->id, $this->conversation->id, $batchId, $helperB, 'success', 'Yes, safe given a pre-migration backup.', null, now()->addSeconds(5));
        $first = $this->makeDelegation($this->user->id, $this->conversation->id, $batchId, $helperA, 'success', 'Yes, safe -- the migration is additive-only.', null, now());
        $third = $this->makeDelegation($this->user->id, $this->conversation->id, $batchId, $helperC, 'failure', null, 'timeout', now()->addSeconds(10));

        $result = $this->query()->contributorsForRequest($this->user->id, $request->id);

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertSame([$first->id, $second->id, $third->id], array_column($result, 'delegation_id'), 'must be started_at order, never completion/insertion order');

        $this->assertSame([
            'delegation_id' => $first->id,
            'helper_agent_id' => $helperA,
            'result_status' => 'success',
            'answer' => 'Yes, safe -- the migration is additive-only.',
            'result_reason' => null,
        ], $result[0]);

        $this->assertSame([
            'delegation_id' => $third->id,
            'helper_agent_id' => $helperC,
            'result_status' => 'failure',
            'answer' => null,
            'result_reason' => 'timeout',
        ], $result[2]);
    }

    // =================================================================
    // answer is null exactly when result_status = 'failure' -- never
    // otherwise.
    // =================================================================

    #[Test]
    public function answer_is_null_only_for_a_failure_result_status(): void
    {
        $batchId = (string) Str::uuid();
        $request = $this->makeRequest($this->user->id, $this->conversation->id, $batchId);

        $this->makeDelegation($this->user->id, $this->conversation->id, $batchId, (string) Str::uuid(), 'success', 'Answer A.', null, now());
        $this->makeDelegation($this->user->id, $this->conversation->id, $batchId, (string) Str::uuid(), 'partial', 'Answer B (partial).', null, now()->addSecond());
        $this->makeDelegation($this->user->id, $this->conversation->id, $batchId, (string) Str::uuid(), 'failure', null, 'timeout', now()->addSeconds(2));

        $result = $this->query()->contributorsForRequest($this->user->id, $request->id);

        $byStatus = collect($result)->keyBy('result_status');
        $this->assertSame('Answer A.', $byStatus['success']['answer']);
        $this->assertSame('Answer B (partial).', $byStatus['partial']['answer']);
        $this->assertNull($byStatus['failure']['answer']);
    }

    // =================================================================
    // Reachable for EVERY terminal status, not only no_consensus --
    // exercised here against an insufficient_quorum request.
    // =================================================================

    #[Test]
    public function returns_contributors_for_an_insufficient_quorum_request_too(): void
    {
        $batchId = (string) Str::uuid();
        $request = $this->makeRequest($this->user->id, $this->conversation->id, $batchId, [
            'status' => 'insufficient_quorum',
            'agreement_classification' => null,
            'reconciled_answer' => null,
            'successful_count' => 1,
        ]);

        $this->makeDelegation($this->user->id, $this->conversation->id, $batchId, (string) Str::uuid(), 'success', 'Answer A.', null, now());
        $this->makeDelegation($this->user->id, $this->conversation->id, $batchId, (string) Str::uuid(), 'failure', null, 'timeout', now()->addSecond());
        $this->makeDelegation($this->user->id, $this->conversation->id, $batchId, (string) Str::uuid(), 'failure', null, 'timeout', now()->addSeconds(2));

        $result = $this->query()->contributorsForRequest($this->user->id, $request->id);

        $this->assertCount(3, $result);
    }
}
