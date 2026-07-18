<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\ContextManagementSummary;
use ClarionApp\LlmClient\Models\ContextManagementRecord;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

class ContextManagementSummaryScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    #[Test]
    public function high_trim_activations_scope_filters_by_threshold()
    {
        $convId1 = (string) Str::uuid();
        $convId2 = (string) Str::uuid();
        $convId3 = (string) Str::uuid();

        // High trim activations
        $this->createSummary($convId1, 'conversation', trimActivations: 25, totalTokensSaved: 50000);
        // Medium (below threshold)
        $this->createSummary($convId2, 'conversation', trimActivations: 5, totalTokensSaved: 10000);
        // High smart_trim activations
        $this->createSummary($convId3, 'conversation', smartTrimActivations: 20, totalTokensSaved: 30000);

        $highTrim = ContextManagementSummary::highTrimActivations(10)->get();
        $this->assertCount(2, $highTrim);
        $ids = $highTrim->pluck('entity_id')->toArray();
        $this->assertContains($convId1, $ids);
        $this->assertContains($convId3, $ids);
        $this->assertNotContains($convId2, $ids);
    }

    #[Test]
    public function high_trim_activations_scope_with_custom_threshold()
    {
        $convId1 = (string) Str::uuid();
        $convId2 = (string) Str::uuid();

        $this->createSummary($convId1, 'conversation', trimActivations: 15, totalTokensSaved: 20000);
        $this->createSummary($convId2, 'conversation', trimActivations: 5, totalTokensSaved: 10000);

        // Threshold 10: only convId1 (15 > 10)
        $result = ContextManagementSummary::highTrimActivations(10)->get();
        $this->assertCount(1, $result);
        $this->assertEquals($convId1, $result->first()->entity_id);

        // Threshold 5: only convId1 (15 > 5, but 5 > 5 is false)
        $result = ContextManagementSummary::highTrimActivations(5)->get();
        $this->assertCount(1, $result);
        $this->assertEquals($convId1, $result->first()->entity_id);
    }

    #[Test]
    public function order_by_tokens_saved_scope_returns_descending_order()
    {
        $convId1 = (string) Str::uuid();
        $convId2 = (string) Str::uuid();
        $convId3 = (string) Str::uuid();

        $this->createSummary($convId1, 'conversation', totalTokensSaved: 10000);
        $this->createSummary($convId2, 'conversation', totalTokensSaved: 50000);
        $this->createSummary($convId3, 'conversation', totalTokensSaved: 25000);

        $ordered = ContextManagementSummary::forConversation()
            ->orderByTokensSaved()
            ->get();

        $this->assertEquals($convId2, $ordered[0]->entity_id);
        $this->assertEquals($convId3, $ordered[1]->entity_id);
        $this->assertEquals($convId1, $ordered[2]->entity_id);
    }

    #[Test]
    public function conversations_scope_filters_conversation_entities_only()
    {
        $convId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $this->createSummary($convId, 'conversation', totalTokensSaved: 10000);
        $this->createSummary($userId, 'user', totalTokensSaved: 50000);

        $conversations = ContextManagementSummary::conversations()->get();
        $this->assertCount(1, $conversations);
        $this->assertEquals($convId, $conversations->first()->entity_id);
    }

    #[Test]
    public function for_conversation_scope_filters_by_entity_type()
    {
        $convId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $this->createSummary($convId, 'conversation', totalTokensSaved: 10000);
        $this->createSummary($userId, 'user', totalTokensSaved: 50000);

        $conversations = ContextManagementSummary::forConversation()->get();
        $this->assertCount(1, $conversations);
        $this->assertEquals($convId, $conversations->first()->entity_id);
    }

    #[Test]
    public function for_user_scope_filters_user_entities_only()
    {
        $convId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $this->createSummary($convId, 'conversation', totalTokensSaved: 10000);
        $this->createSummary($userId, 'user', totalTokensSaved: 50000);

        $users = ContextManagementSummary::forUser()->get();
        $this->assertCount(1, $users);
        $this->assertEquals($userId, $users->first()->entity_id);
    }

    #[Test]
    public function record_with_high_utilization_scope_filters_by_ratio()
    {
        $convId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        // High utilization: 90% of capacity
        ContextManagementRecord::create([
            'conversation_id' => $convId,
            'user_id' => $userId,
            'mechanism' => 'none',
            'tokens_before' => 115200,
            'request_tokens_before' => 115200,
            'context_capacity' => 128000,
        ]);

        // Medium utilization: 55% of capacity
        ContextManagementRecord::create([
            'conversation_id' => $convId,
            'user_id' => $userId,
            'mechanism' => 'trim',
            'tokens_before' => 70400,
            'request_tokens_before' => 70400,
            'context_capacity' => 128000,
        ]);

        // Low utilization: 10% of capacity
        ContextManagementRecord::create([
            'conversation_id' => $convId,
            'user_id' => $userId,
            'mechanism' => 'none',
            'tokens_before' => 12800,
            'request_tokens_before' => 12800,
            'context_capacity' => 128000,
        ]);

        // Default threshold 0.8 (80%)
        $highUtil = ContextManagementRecord::withHighUtilization()->get();
        $this->assertCount(1, $highUtil);
        $this->assertEquals(115200, $highUtil->first()->request_tokens_before);

        // Custom threshold 0.5: both records (90% and 55% both > 50%)
        $mediumUtil = ContextManagementRecord::withHighUtilization(0.5)->get();
        $this->assertCount(2, $mediumUtil);
    }

    #[Test]
    public function record_for_conversation_scope_filters_by_conversation_id()
    {
        $convId1 = (string) Str::uuid();
        $convId2 = (string) Str::uuid();
        $userId = (string) Str::uuid();

        ContextManagementRecord::create([
            'conversation_id' => $convId1,
            'user_id' => $userId,
            'mechanism' => 'trim',
        ]);
        ContextManagementRecord::create([
            'conversation_id' => $convId2,
            'user_id' => $userId,
            'mechanism' => 'condense',
        ]);

        $records = ContextManagementRecord::forConversation($convId1)->get();
        $this->assertCount(1, $records);
        $this->assertEquals($convId1, $records->first()->conversation_id);
    }

    #[Test]
    public function record_for_user_scope_filters_by_user_id()
    {
        $convId = (string) Str::uuid();
        $userId1 = (string) Str::uuid();
        $userId2 = (string) Str::uuid();

        ContextManagementRecord::create([
            'conversation_id' => $convId,
            'user_id' => $userId1,
            'mechanism' => 'trim',
        ]);
        ContextManagementRecord::create([
            'conversation_id' => $convId,
            'user_id' => $userId2,
            'mechanism' => 'condense',
        ]);

        $records = ContextManagementRecord::forUser($userId1)->get();
        $this->assertCount(1, $records);
        $this->assertEquals($userId1, $records->first()->user_id);
    }

    #[Test]
    public function record_order_by_created_at_desc_returns_newest_first()
    {
        $convId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $first = ContextManagementRecord::create([
            'conversation_id' => $convId,
            'user_id' => $userId,
            'mechanism' => 'none',
            'created_at' => now()->subHours(2),
        ]);

        $second = ContextManagementRecord::create([
            'conversation_id' => $convId,
            'user_id' => $userId,
            'mechanism' => 'trim',
            'created_at' => now()->subHour(),
        ]);

        $third = ContextManagementRecord::create([
            'conversation_id' => $convId,
            'user_id' => $userId,
            'mechanism' => 'none',
            'created_at' => now(),
        ]);

        $ordered = ContextManagementRecord::forConversation($convId)
            ->orderByCreatedAtDesc()
            ->get();

        $this->assertEquals($third->id, $ordered[0]->id);
        $this->assertEquals($second->id, $ordered[1]->id);
        $this->assertEquals($first->id, $ordered[2]->id);
    }

    #[Test]
    public function combined_scopes_identify_degraded_conversations()
    {
        // Simulate a degraded conversation with high trim activations and high utilization
        $degradedConvId = (string) Str::uuid();
        $healthyConvId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        // Degraded: high trim activations
        $this->createSummary($degradedConvId, 'conversation', trimActivations: 50, totalTokensSaved: 100000);
        // Healthy: low activations
        $this->createSummary($healthyConvId, 'conversation', trimActivations: 2, totalTokensSaved: 5000);

        // Degraded: high utilization records
        ContextManagementRecord::create([
            'conversation_id' => $degradedConvId,
            'user_id' => $userId,
            'mechanism' => 'trim',
            'tokens_before' => 120000,
            'request_tokens_before' => 120000,
            'context_capacity' => 128000,
        ]);

        // Healthy: low utilization
        ContextManagementRecord::create([
            'conversation_id' => $healthyConvId,
            'user_id' => $userId,
            'mechanism' => 'none',
            'tokens_before' => 10000,
            'request_tokens_before' => 10000,
            'context_capacity' => 128000,
        ]);

        // Query for high-trim conversations
        $highTrimConvs = ContextManagementSummary::highTrimActivations(10)->get();
        $this->assertCount(1, $highTrimConvs);
        $this->assertEquals($degradedConvId, $highTrimConvs->first()->entity_id);

        // Query for high-utilization records
        $highUtilRecords = ContextManagementRecord::withHighUtilization(0.8)->get();
        $this->assertCount(1, $highUtilRecords);
        $this->assertEquals($degradedConvId, $highUtilRecords->first()->conversation_id);
    }

    protected function createSummary(string $entityId, string $entityType, int $trimActivations = 0, int $smartTrimActivations = 0, int $condenseActivations = 0, int $totalTokensSaved = 0, int $totalRequests = 0): ContextManagementSummary
    {
        return ContextManagementSummary::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'trim_activations' => $trimActivations,
            'smart_trim_activations' => $smartTrimActivations,
            'condense_activations' => $condenseActivations,
            'total_tokens_saved' => $totalTokensSaved,
            'total_requests' => $totalRequests,
            'updated_at' => now(),
        ]);
    }
}
