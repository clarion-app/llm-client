<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\ContextManagementRecord;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

class ContextManagementRecordModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    #[Test]
    public function it_creates_a_record_with_auto_uuid()
    {
        $record = ContextManagementRecord::create([
            'conversation_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'mechanism' => 'none',
            'tokens_before' => 5000,
            'tokens_after' => 5000,
            'tokens_saved' => 0,
            'context_capacity' => 128000,
            'history_budget' => 100000,
        ]);

        $this->assertNotNull($record->id);
        $this->assertTrue(Str::isUuid($record->id));
        $this->assertEquals('none', $record->mechanism);
        $this->assertEquals(5000, $record->tokens_before);
        $this->assertEquals(128000, $record->context_capacity);
    }

    #[Test]
    public function it_creates_a_record_with_nullable_fields()
    {
        $record = ContextManagementRecord::create([
            'conversation_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'attempt_group_id' => (string) Str::uuid(),
            'mechanism' => 'trim',
            'tokens_before' => 10000,
            'tokens_after' => 7000,
            'tokens_saved' => 3000,
            'model' => 'gpt-4o',
            'provider_type' => 'openai',
        ]);

        $this->assertNotNull($record->attempt_group_id);
        $this->assertEquals('gpt-4o', $record->model);
        $this->assertEquals('openai', $record->provider_type);
    }

    #[Test]
    public function it_creates_a_record_with_error()
    {
        $record = ContextManagementRecord::create([
            'conversation_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'mechanism' => 'condense',
            'tokens_before' => 0,
            'tokens_after' => 0,
            'tokens_saved' => 0,
            'error' => 'Connection timeout',
        ]);

        $this->assertEquals('Connection timeout', $record->error);
    }

    #[Test]
    public function scope_for_conversation_filters_correctly()
    {
        $convId1 = (string) Str::uuid();
        $convId2 = (string) Str::uuid();
        $userId = (string) Str::uuid();

        ContextManagementRecord::create([
            'conversation_id' => $convId1,
            'user_id' => $userId,
            'mechanism' => 'none',
        ]);
        ContextManagementRecord::create([
            'conversation_id' => $convId2,
            'user_id' => $userId,
            'mechanism' => 'trim',
        ]);

        $records = ContextManagementRecord::forConversation($convId1)->get();
        $this->assertCount(1, $records);
        $this->assertEquals($convId1, $records->first()->conversation_id);
    }

    #[Test]
    public function scope_for_user_filters_correctly()
    {
        $userId1 = (string) Str::uuid();
        $userId2 = (string) Str::uuid();
        $convId = (string) Str::uuid();

        ContextManagementRecord::create([
            'conversation_id' => $convId,
            'user_id' => $userId1,
            'mechanism' => 'none',
        ]);
        ContextManagementRecord::create([
            'conversation_id' => $convId,
            'user_id' => $userId2,
            'mechanism' => 'trim',
        ]);

        $records = ContextManagementRecord::forUser($userId1)->get();
        $this->assertCount(1, $records);
        $this->assertEquals($userId1, $records->first()->user_id);
    }

    #[Test]
    public function scope_with_high_utilization_filters_correctly()
    {
        $convId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        // High utilization: request_tokens_before / context_capacity > 0.8
        ContextManagementRecord::create([
            'conversation_id' => $convId,
            'user_id' => $userId,
            'mechanism' => 'none',
            'tokens_before' => 120000,
            'request_tokens_before' => 120000,
            'context_capacity' => 128000,
        ]);

        // Low utilization
        ContextManagementRecord::create([
            'conversation_id' => $convId,
            'user_id' => $userId,
            'mechanism' => 'none',
            'tokens_before' => 5000,
            'request_tokens_before' => 5000,
            'context_capacity' => 128000,
        ]);

        $highUtilRecords = ContextManagementRecord::withHighUtilization(0.8)->get();
        $this->assertCount(1, $highUtilRecords);
        $this->assertEquals(120000, $highUtilRecords->first()->request_tokens_before);
    }

    #[Test]
    public function scope_with_high_utilization_excludes_null_capacity()
    {
        ContextManagementRecord::create([
            'conversation_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'mechanism' => 'none',
            'tokens_before' => 50000,
            'request_tokens_before' => 50000,
            'context_capacity' => null,
        ]);

        $highUtilRecords = ContextManagementRecord::withHighUtilization(0.8)->get();
        $this->assertCount(0, $highUtilRecords);
    }

    #[Test]
    public function scope_order_by_created_at_desc_returns_newest_first()
    {
        $convId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $first = ContextManagementRecord::create([
            'conversation_id' => $convId,
            'user_id' => $userId,
            'mechanism' => 'none',
            'created_at' => now()->subHour(),
        ]);

        $second = ContextManagementRecord::create([
            'conversation_id' => $convId,
            'user_id' => $userId,
            'mechanism' => 'trim',
            'created_at' => now(),
        ]);

        $ordered = ContextManagementRecord::orderByCreatedAtDesc()->get();
        $this->assertEquals($second->id, $ordered->first()->id);
        $this->assertEquals($first->id, $ordered->last()->id);
    }
}
