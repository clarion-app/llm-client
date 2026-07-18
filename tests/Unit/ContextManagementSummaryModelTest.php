<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\ContextManagementSummary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

class ContextManagementSummaryModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    #[Test]
    public function it_creates_a_summary_with_auto_uuid()
    {
        $summary = ContextManagementSummary::create([
            'entity_type' => 'conversation',
            'entity_id' => (string) Str::uuid(),
        ]);

        $this->assertNotNull($summary->id);
        $this->assertTrue(Str::isUuid($summary->id));
        $this->assertEquals('conversation', $summary->entity_type);
        $this->assertEquals(0, $summary->trim_activations);
        $this->assertEquals(0, $summary->total_requests);
    }

    #[Test]
    public function it_performs_atomic_upsert_with_insert_or_ignore()
    {
        $convId = (string) Str::uuid();

        // First call: insertOrIgnore creates the row
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

        // Atomic increment
        DB::table('context_management_summaries')
            ->where('entity_type', 'conversation')
            ->where('entity_id', $convId)
            ->update([
                'trim_activations' => DB::raw('trim_activations + 1'),
                'total_tokens_saved' => DB::raw('total_tokens_saved + 5000'),
                'total_requests' => DB::raw('total_requests + 1'),
                'updated_at' => now(),
            ]);

        $summary = ContextManagementSummary::getConversationTotals($convId);
        $this->assertNotNull($summary);
        $this->assertEquals(1, $summary->trim_activations);
        $this->assertEquals(5000, $summary->total_tokens_saved);
        $this->assertEquals(1, $summary->total_requests);
    }

    #[Test]
    public function it_performs_multiple_atomic_increments()
    {
        $convId = (string) Str::uuid();

        // Create initial row
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

        // First increment
        DB::table('context_management_summaries')
            ->where('entity_type', 'conversation')
            ->where('entity_id', $convId)
            ->update([
                'trim_activations' => DB::raw('trim_activations + 2'),
                'total_tokens_saved' => DB::raw('total_tokens_saved + 3000'),
                'total_requests' => DB::raw('total_requests + 1'),
                'updated_at' => now(),
            ]);

        // Second increment
        DB::table('context_management_summaries')
            ->where('entity_type', 'conversation')
            ->where('entity_id', $convId)
            ->update([
                'smart_trim_activations' => DB::raw('smart_trim_activations + 1'),
                'total_tokens_saved' => DB::raw('total_tokens_saved + 2000'),
                'total_requests' => DB::raw('total_requests + 1'),
                'updated_at' => now(),
            ]);

        $summary = ContextManagementSummary::getConversationTotals($convId);
        $this->assertEquals(2, $summary->trim_activations);
        $this->assertEquals(1, $summary->smart_trim_activations);
        $this->assertEquals(0, $summary->condense_activations);
        $this->assertEquals(5000, $summary->total_tokens_saved);
        $this->assertEquals(2, $summary->total_requests);
    }

    #[Test]
    public function it_creates_separate_summaries_for_conversation_and_user()
    {
        $convId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        // Create conversation summary
        DB::table('context_management_summaries')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'entity_type' => 'conversation',
            'entity_id' => $convId,
            'total_requests' => 0,
            'updated_at' => now(),
        ]);
        DB::table('context_management_summaries')
            ->where('entity_type', 'conversation')
            ->where('entity_id', $convId)
            ->update([
                'total_requests' => DB::raw('total_requests + 5'),
                'updated_at' => now(),
            ]);

        // Create user summary
        DB::table('context_management_summaries')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'entity_type' => 'user',
            'entity_id' => $userId,
            'total_requests' => 0,
            'updated_at' => now(),
        ]);
        DB::table('context_management_summaries')
            ->where('entity_type', 'user')
            ->where('entity_id', $userId)
            ->update([
                'total_requests' => DB::raw('total_requests + 3'),
                'updated_at' => now(),
            ]);

        $convSummary = ContextManagementSummary::getConversationTotals($convId);
        $this->assertEquals(5, $convSummary->total_requests);

        $userSummary = ContextManagementSummary::getUserTotals($userId);
        $this->assertEquals(3, $userSummary->total_requests);
    }

    #[Test]
    public function scope_high_trim_activations_filters_correctly()
    {
        $convId1 = (string) Str::uuid();
        $convId2 = (string) Str::uuid();

        // High trim activations
        $this->createCmSummary($convId1, 'conversation', trimActivations: 15, totalRequests: 20);
        // Low trim activations
        $this->createCmSummary($convId2, 'conversation', trimActivations: 3, totalRequests: 10);

        $highTrim = ContextManagementSummary::highTrimActivations(10)->get();
        $this->assertCount(1, $highTrim);
        $this->assertEquals($convId1, $highTrim->first()->entity_id);
    }

    #[Test]
    public function scope_order_by_tokens_saved_returns_correct_order()
    {
        $convId1 = (string) Str::uuid();
        $convId2 = (string) Str::uuid();

        $this->createCmSummary($convId1, 'conversation', totalTokensSaved: 1000);
        $this->createCmSummary($convId2, 'conversation', totalTokensSaved: 5000);

        $ordered = ContextManagementSummary::forConversation()
            ->orderByTokensSaved()
            ->get();

        $this->assertEquals($convId2, $ordered->first()->entity_id);
        $this->assertEquals($convId1, $ordered->last()->entity_id);
    }

    #[Test]
    public function constants_are_correct()
    {
        $this->assertEquals('conversation', ContextManagementSummary::ENTITY_CONVERSATION);
        $this->assertEquals('user', ContextManagementSummary::ENTITY_USER);
    }

    /**
     * Helper to create a context management summary row with atomic increments.
     */
    private function createCmSummary(
        string $entityId,
        string $entityType,
        int $trimActivations = 0,
        int $smartTrimActivations = 0,
        int $condenseActivations = 0,
        int $totalTokensSaved = 0,
        int $totalRequests = 0,
    ): void {
        DB::table('context_management_summaries')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'trim_activations' => 0,
            'smart_trim_activations' => 0,
            'condense_activations' => 0,
            'total_tokens_saved' => 0,
            'total_requests' => 0,
            'updated_at' => now(),
        ]);

        DB::table('context_management_summaries')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->update([
                'trim_activations' => DB::raw("trim_activations + {$trimActivations}"),
                'smart_trim_activations' => DB::raw("smart_trim_activations + {$smartTrimActivations}"),
                'condense_activations' => DB::raw("condense_activations + {$condenseActivations}"),
                'total_tokens_saved' => DB::raw("total_tokens_saved + {$totalTokensSaved}"),
                'total_requests' => DB::raw("total_requests + {$totalRequests}"),
                'updated_at' => now(),
            ]);
    }
}
