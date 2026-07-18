<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\ContextManagementSummary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

class ContextManagementSummaryAggregationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    #[Test]
    public function it_aggregates_across_multiple_requests_for_same_conversation()
    {
        $convId = (string) Str::uuid();

        // Simulate request 1: trim step
        $this->recordStepsInRequest($convId, [['mechanism' => 'trim', 'tokensSaved' => 5000]]);
        // Simulate request 2: smart_trim step
        $this->recordStepsInRequest($convId, [['mechanism' => 'smart_trim', 'tokensSaved' => 3000]]);
        // Simulate request 3: condense step
        $this->recordStepsInRequest($convId, [['mechanism' => 'condense', 'tokensSaved' => 8000]]);
        // Simulate request 4: no management (none)
        $this->recordStepsInRequest($convId, [['mechanism' => 'none', 'tokensSaved' => 0]]);

        $summary = ContextManagementSummary::getConversationTotals($convId);
        $this->assertNotNull($summary);
        $this->assertEquals(1, $summary->trim_activations);
        $this->assertEquals(1, $summary->smart_trim_activations);
        $this->assertEquals(1, $summary->condense_activations);
        $this->assertEquals(16000, $summary->total_tokens_saved);
        $this->assertEquals(4, $summary->total_requests);
    }

    #[Test]
    public function it_aggregates_multiple_steps_in_single_request()
    {
        $convId = (string) Str::uuid();

        // Simulate a single request that fires smart_trim then trim (two steps, one request)
        $this->recordStepsInRequest($convId, [
            ['mechanism' => 'smart_trim', 'tokensSaved' => 2000],
            ['mechanism' => 'trim', 'tokensSaved' => 3000],
        ]);

        $summary = ContextManagementSummary::getConversationTotals($convId);
        $this->assertNotNull($summary);
        $this->assertEquals(1, $summary->trim_activations);
        $this->assertEquals(1, $summary->smart_trim_activations);
        $this->assertEquals(5000, $summary->total_tokens_saved);
        // Both steps belong to the same request, so total_requests should be 1
        $this->assertEquals(1, $summary->total_requests);
    }

    #[Test]
    public function it_tracks_separate_summaries_for_different_conversations()
    {
        $convId1 = (string) Str::uuid();
        $convId2 = (string) Str::uuid();

        // Request on conversation 1
        $this->recordStepsInRequest($convId1, [['mechanism' => 'trim', 'tokensSaved' => 5000]]);
        // Two requests on conversation 2
        $this->recordStepsInRequest($convId2, [['mechanism' => 'condense', 'tokensSaved' => 10000]]);
        $this->recordStepsInRequest($convId2, [['mechanism' => 'none', 'tokensSaved' => 0]]);

        $summary1 = ContextManagementSummary::getConversationTotals($convId1);
        $this->assertEquals(1, $summary1->trim_activations);
        $this->assertEquals(5000, $summary1->total_tokens_saved);
        $this->assertEquals(1, $summary1->total_requests);

        $summary2 = ContextManagementSummary::getConversationTotals($convId2);
        $this->assertEquals(1, $summary2->condense_activations);
        $this->assertEquals(10000, $summary2->total_tokens_saved);
        $this->assertEquals(2, $summary2->total_requests);
    }

    #[Test]
    public function it_aggregates_user_summary_across_multiple_conversations()
    {
        $userId = (string) Str::uuid();
        $convId1 = (string) Str::uuid();
        $convId2 = (string) Str::uuid();

        // Simulate user-level recording across conversations
        // Request on conversation 1: trim step
        $this->recordStepsInRequest($convId1, [['mechanism' => 'trim', 'tokensSaved' => 5000]], $userId);
        // Request on conversation 2: condense step
        $this->recordStepsInRequest($convId2, [['mechanism' => 'condense', 'tokensSaved' => 3000]], $userId);

        $userSummary = ContextManagementSummary::getUserTotals($userId);
        $this->assertNotNull($userSummary);
        $this->assertEquals(1, $userSummary->trim_activations);
        $this->assertEquals(1, $userSummary->condense_activations);
        $this->assertEquals(8000, $userSummary->total_tokens_saved);
        $this->assertEquals(2, $userSummary->total_requests);
    }

    #[Test]
    public function none_steps_increment_total_requests_but_no_activations()
    {
        $convId = (string) Str::uuid();

        // Three requests with no management
        $this->recordStepsInRequest($convId, [['mechanism' => 'none', 'tokensSaved' => 0]]);
        $this->recordStepsInRequest($convId, [['mechanism' => 'none', 'tokensSaved' => 0]]);
        $this->recordStepsInRequest($convId, [['mechanism' => 'none', 'tokensSaved' => 0]]);

        $summary = ContextManagementSummary::getConversationTotals($convId);
        $this->assertEquals(0, $summary->trim_activations);
        $this->assertEquals(0, $summary->smart_trim_activations);
        $this->assertEquals(0, $summary->condense_activations);
        $this->assertEquals(0, $summary->total_tokens_saved);
        $this->assertEquals(3, $summary->total_requests);
    }

    #[Test]
    public function it_handles_large_aggregation_volumes()
    {
        $convId = (string) Str::uuid();

        // Simulate 100 trim requests
        for ($i = 0; $i < 100; $i++) {
            $this->recordStepsInRequest($convId, [['mechanism' => 'trim', 'tokensSaved' => 1000]]);
        }

        $summary = ContextManagementSummary::getConversationTotals($convId);
        $this->assertEquals(100, $summary->trim_activations);
        $this->assertEquals(100000, $summary->total_tokens_saved);
        $this->assertEquals(100, $summary->total_requests);
    }

    /**
     * Simulate recording multiple steps within a single request (total_requests incremented once).
     */
    protected function recordStepsInRequest(string $convId, array $steps, ?string $userId = null): void
    {
        // Upsert conversation summary
        DB::table('context_management_summaries')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'entity_type' => 'conversation',
            'entity_id' => $convId,
            'trim_activations' => 0,
            'smart_trim_activations' => 0,
            'condense_activations' => 0,
            'total_tokens_saved' => 0,
            'total_requests' => 0,
            'updated_at' => now(),
        ]);

        $updateData = [
            'total_requests' => DB::raw('total_requests + 1'),
            'updated_at' => now(),
        ];

        $totalTokensSaved = 0;
        foreach ($steps as $step) {
            $totalTokensSaved += $step['tokensSaved'];
            if ($step['mechanism'] !== 'none') {
                $col = match ($step['mechanism']) {
                    'trim' => 'trim_activations',
                    'smart_trim' => 'smart_trim_activations',
                    'condense' => 'condense_activations',
                };
                $updateData[$col] = DB::raw("$col + 1");
            }
        }
        $updateData['total_tokens_saved'] = DB::raw('total_tokens_saved + ' . $totalTokensSaved);

        DB::table('context_management_summaries')
            ->where('entity_type', 'conversation')
            ->where('entity_id', $convId)
            ->update($updateData);

        if ($userId) {
            DB::table('context_management_summaries')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'entity_type' => 'user',
                'entity_id' => $userId,
                'trim_activations' => 0,
                'smart_trim_activations' => 0,
                'condense_activations' => 0,
                'total_tokens_saved' => 0,
                'total_requests' => 0,
                'updated_at' => now(),
            ]);

            DB::table('context_management_summaries')
                ->where('entity_type', 'user')
                ->where('entity_id', $userId)
                ->update($updateData);
        }
    }

}
