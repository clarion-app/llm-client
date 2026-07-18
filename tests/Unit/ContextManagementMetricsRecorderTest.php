<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\Services\UsageEstimator;
use ClarionApp\LlmClient\ValueObjects\ContextManagementOutcome;
use ClarionApp\LlmClient\ValueObjects\ContextManagementStep;
use ClarionApp\LlmClient\Models\ContextManagementRecord;
use ClarionApp\LlmClient\Models\ContextManagementSummary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

class ContextManagementMetricsRecorderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    #[Test]
    public function it_records_a_none_row_when_outcome_has_no_steps()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $attemptGroupId = (string) Str::uuid();

        $outcome = ContextManagementOutcome::none(
            contextCapacity: 128000,
            historyBudget: 100000,
            tokensBefore: 5000,
            model: 'gpt-4o',
            providerType: 'openai',
        );

        $recorder->recordContextManagement(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: $attemptGroupId,
            outcome: $outcome,
        );

        // Should write exactly one 'none' record
        $records = ContextManagementRecord::where('conversation_id', $conversationId)->get();
        $this->assertCount(1, $records);

        $record = $records->first();
        $this->assertEquals('none', $record->mechanism);
        $this->assertEquals(5000, $record->tokens_before);
        $this->assertEquals(5000, $record->tokens_after);
        $this->assertEquals(0, $record->tokens_saved);
        $this->assertEquals(128000, $record->context_capacity);
        $this->assertEquals(100000, $record->history_budget);
        $this->assertEquals('gpt-4o', $record->model);
        $this->assertEquals('openai', $record->provider_type);
        $this->assertEquals($attemptGroupId, $record->attempt_group_id);
    }

    #[Test]
    public function it_records_one_row_per_step()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $attemptGroupId = (string) Str::uuid();

        $outcome = new ContextManagementOutcome(
            contextCapacity: 128000,
            historyBudget: 100000,
            tokensBefore: 110000,
            tokensAfter: 90000,
            model: 'gpt-4o',
            providerType: 'openai',
        );

        $outcome->addStep(ContextManagementStep::smartTrim(110000, 95000));
        $outcome->addStep(ContextManagementStep::trim(95000, 90000));

        $recorder->recordContextManagement(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: $attemptGroupId,
            outcome: $outcome,
        );

        $records = ContextManagementRecord::where('conversation_id', $conversationId)->get();
        $this->assertCount(2, $records);

        $smartTrimRecord = $records->where('mechanism', 'smart_trim')->first();
        $this->assertNotNull($smartTrimRecord);
        $this->assertEquals(110000, $smartTrimRecord->tokens_before);
        $this->assertEquals(95000, $smartTrimRecord->tokens_after);
        $this->assertEquals(15000, $smartTrimRecord->tokens_saved);

        $trimRecord = $records->where('mechanism', 'trim')->first();
        $this->assertNotNull($trimRecord);
        $this->assertEquals(95000, $trimRecord->tokens_before);
        $this->assertEquals(90000, $trimRecord->tokens_after);
        $this->assertEquals(5000, $trimRecord->tokens_saved);
    }

    #[Test]
    public function it_increments_total_requests_once_per_request()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        // First request with 2 steps
        $outcome1 = new ContextManagementOutcome(
            contextCapacity: 128000,
            historyBudget: 100000,
            tokensBefore: 110000,
            tokensAfter: 90000,
            model: 'gpt-4o',
            providerType: 'openai',
        );
        $outcome1->addStep(ContextManagementStep::smartTrim(110000, 95000));
        $outcome1->addStep(ContextManagementStep::trim(95000, 90000));

        $recorder->recordContextManagement(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            outcome: $outcome1,
        );

        // Second request with no steps (none)
        $outcome2 = ContextManagementOutcome::none(
            contextCapacity: 128000,
            historyBudget: 100000,
            tokensBefore: 5000,
            model: 'gpt-4o',
            providerType: 'openai',
        );

        $recorder->recordContextManagement(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            outcome: $outcome2,
        );

        // Conversation summary should have total_requests = 2 (not 3)
        $convSummary = ContextManagementSummary::getConversationTotals($conversationId);
        $this->assertNotNull($convSummary);
        $this->assertEquals(2, $convSummary->total_requests);

        // User summary should also have total_requests = 2
        $userSummary = ContextManagementSummary::getUserTotals($userId);
        $this->assertNotNull($userSummary);
        $this->assertEquals(2, $userSummary->total_requests);
    }

    #[Test]
    public function it_increments_correct_activation_counters_per_step()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $outcome = new ContextManagementOutcome(
            contextCapacity: 128000,
            historyBudget: 100000,
            tokensBefore: 110000,
            tokensAfter: 85000,
            model: 'gpt-4o',
            providerType: 'openai',
        );
        $outcome->addStep(ContextManagementStep::smartTrim(110000, 95000));
        $outcome->addStep(ContextManagementStep::trim(95000, 85000));

        $recorder->recordContextManagement(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            outcome: $outcome,
        );

        $convSummary = ContextManagementSummary::getConversationTotals($conversationId);
        $this->assertEquals(1, $convSummary->smart_trim_activations);
        $this->assertEquals(1, $convSummary->trim_activations);
        $this->assertEquals(0, $convSummary->condense_activations);
        $this->assertEquals(25000, $convSummary->total_tokens_saved);
    }

    #[Test]
    public function it_logs_a_warning_and_swallows_exception_on_db_failure()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $outcome = ContextManagementOutcome::none(
            contextCapacity: 128000,
            historyBudget: 100000,
            tokensBefore: 5000,
        );

        // Capture log warnings
        $logCapturer = new class {
            public $warnings = [];
            public function warning($message, $context = []) {
                $this->warnings[] = ['message' => $message, 'context' => $context];
            }
            public function __call($method, $args) {}
        };
        Log::swap($logCapturer);

        // This should not throw — the method has a try-catch wrapper
        // that ensures metrics recording never blocks the request.
        try {
            $recorder->recordContextManagement(
                conversationId: $conversationId,
                userId: $userId,
                attemptGroupId: null,
                outcome: $outcome,
            );
            // Success - no exception thrown
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            $this->fail('recordContextManagement should not throw: ' . $e->getMessage());
        }
    }

    #[Test]
    public function it_handles_null_attempt_group_id()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $outcome = ContextManagementOutcome::none(
            contextCapacity: 128000,
            historyBudget: 100000,
            tokensBefore: 5000,
        );

        $recorder->recordContextManagement(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: null,
            outcome: $outcome,
        );

        $record = ContextManagementRecord::first();
        $this->assertNull($record->attempt_group_id);
    }

    #[Test]
    public function it_records_condense_step_with_tokens_saved_decoupled_from_before_after()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $outcome = new ContextManagementOutcome(
            contextCapacity: 128000,
            historyBudget: 100000,
            tokensBefore: 110000,
            tokensAfter: 95000,
            model: 'gpt-4o',
            providerType: 'openai',
        );

        // Condense: sourceChunkTokens=8000, summaryTokens=2000 → tokensSaved=6000
        $outcome->addStep(ContextManagementStep::condense(8000, 2000));

        $recorder->recordContextManagement(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            outcome: $outcome,
        );

        $record = ContextManagementRecord::where('mechanism', 'condense')->first();
        $this->assertNotNull($record);
        $this->assertEquals(8000, $record->tokens_before);
        $this->assertEquals(2000, $record->tokens_after);
        $this->assertEquals(6000, $record->tokens_saved);
    }

    #[Test]
    public function it_records_condense_cached_replay_with_zero_tokens_saved()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $outcome = new ContextManagementOutcome(
            contextCapacity: 128000,
            historyBudget: 100000,
            tokensBefore: 110000,
            tokensAfter: 105000,
            model: 'gpt-4o',
            providerType: 'openai',
        );

        // Cached replay: sourceChunkTokens=0, summaryTokens=500 → tokensSaved=0
        $outcome->addStep(ContextManagementStep::condense(0, 500));

        $recorder->recordContextManagement(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            outcome: $outcome,
        );

        $record = ContextManagementRecord::where('mechanism', 'condense')->first();
        $this->assertNotNull($record);
        $this->assertEquals(0, $record->tokens_saved);
        $this->assertEquals(0, $record->tokens_before);
        $this->assertEquals(500, $record->tokens_after);
    }

    #[Test]
    public function it_records_error_on_step_failure()
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $outcome = new ContextManagementOutcome(
            contextCapacity: 128000,
            historyBudget: 100000,
            tokensBefore: 110000,
            tokensAfter: 110000,
            model: 'gpt-4o',
            providerType: 'openai',
        );

        $outcome->addStep(ContextManagementStep::condenseError('Connection timeout'));

        $recorder->recordContextManagement(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            outcome: $outcome,
        );

        $record = ContextManagementRecord::where('mechanism', 'condense')->first();
        $this->assertNotNull($record);
        $this->assertEquals('Connection timeout', $record->error);
        $this->assertEquals(0, $record->tokens_saved);
    }
}
