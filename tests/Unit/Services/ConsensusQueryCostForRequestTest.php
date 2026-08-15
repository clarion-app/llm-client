<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\ConsensusRequest;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Services\ConsensusQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 104-multi-agent-consensus, Phase 4 (US2), tasks.md T027.
 *
 * ConsensusQuery::costForRequest() (data-model.md §4, contracts/
 * consensus-reconciliation-contract.md §5): a plain, owner-scoped read of
 * five fields directly off the stored ConsensusRequest row -- never a live
 * recomputation from usage_records. Written before costForRequest() exists
 * -- every assertion below is expected to FAIL red until T031 adds it.
 */
class ConsensusQueryCostForRequestTest extends TestCase
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
        DB::table('usage_records')->delete();
        DB::table('consensus_requests')->delete();
        DB::table('conversations')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function query(): ConsensusQuery
    {
        return app(ConsensusQuery::class);
    }

    private function makeRequest(array $overrides = []): ConsensusRequest
    {
        return ConsensusRequest::create(array_merge([
            'conversation_id' => $this->conversation->id,
            'owner_user_id' => $this->user->id,
            'question' => 'Is it safe to run this migration?',
            'dispatched_count' => 3,
            'quorum_required' => 2,
            'successful_count' => 3,
            'status' => 'completed',
            'agreement_classification' => 'agreed',
            'reconciled_answer' => 'Yes, it is safe.',
            'estimated_additional_cost' => '0.0142000000',
            'actual_additional_cost' => '0.0100000000',
            'started_at' => now(),
            'completed_at' => now(),
        ], $overrides));
    }

    private function seedUsageRecord(string $conversationId, string $totalCost): UsageRecord
    {
        return UsageRecord::create([
            'conversation_id' => $conversationId,
            'user_id' => $this->user->id,
            'attempt_group_id' => (string) Str::uuid(),
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'model' => 'unrelated-model',
            'provider_type' => 'openai',
            'total_cost' => $totalCost,
            'cost_unpriced' => false,
            'created_at' => now(),
        ]);
    }

    // =================================================================
    // Reads the five fields directly off the stored row
    // =================================================================

    #[Test]
    public function returns_the_five_fields_read_directly_off_the_stored_row(): void
    {
        $request = $this->makeRequest();

        $result = $this->query()->costForRequest($this->user->id, $request->id);

        $this->assertSame([
            'estimated_additional_cost' => '0.0142000000',
            'actual_additional_cost' => '0.0100000000',
            'dispatched_count' => 3,
            'successful_count' => 3,
            'quorum_required' => 2,
        ], $result);
    }

    // =================================================================
    // Never recomputed live: a later, unrelated usage_records row landing
    // on the same helper conversation (e.g. from a later consensus request
    // reusing an assignment) must never change an earlier request's
    // reported figure (mutation-checklist row 5).
    // =================================================================

    #[Test]
    public function a_later_unrelated_usage_records_row_never_changes_the_earlier_stored_figure(): void
    {
        $helperConversation = Conversation::create([
            'user_id' => $this->user->id,
            'character' => 'Clarion',
        ]);

        $this->seedUsageRecord($helperConversation->id, '0.0050000000');

        $request = $this->makeRequest([
            'actual_additional_cost' => '0.0400000000',
        ]);

        $before = $this->query()->costForRequest($this->user->id, $request->id);

        // A later, unrelated UsageRecord lands on the very same conversation
        // id -- e.g. a different, later consensus request reusing the same
        // helper assignment. costForRequest() never sums usage_records at
        // all, so this must have zero effect on the earlier request's
        // already-stored figure.
        $this->seedUsageRecord($helperConversation->id, '999.0000000000');

        $after = $this->query()->costForRequest($this->user->id, $request->id);

        $this->assertSame($before, $after);
        $this->assertSame('0.0400000000', $after['actual_additional_cost']);
    }

    // =================================================================
    // Ownership-checked via findRequest()
    // =================================================================

    #[Test]
    public function returns_null_for_a_request_not_owned_by_the_caller(): void
    {
        $othersConversation = Conversation::create([
            'user_id' => $this->otherUser->id,
            'character' => 'Clarion',
        ]);

        $request = ConsensusRequest::create([
            'conversation_id' => $othersConversation->id,
            'owner_user_id' => $this->otherUser->id,
            'question' => 'Is it safe?',
            'dispatched_count' => 3,
            'quorum_required' => 2,
            'successful_count' => 3,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $result = $this->query()->costForRequest($this->user->id, $request->id);

        $this->assertNull($result);
    }

    #[Test]
    public function returns_null_for_a_nonexistent_request_id(): void
    {
        $result = $this->query()->costForRequest($this->user->id, (string) Str::uuid());

        $this->assertNull($result);
    }
}
