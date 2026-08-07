<?php

namespace Tests\Feature;

use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\Services\RunTraceQuery;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * User Story 3 end-to-end (contracts/usage-accounting.md §3 R4; FR-010,
 * FR-011; spec.md Acceptance Scenarios 1-2 for User Story 3).
 *
 * A row inserted with only the legacy columns set (simulating a row written
 * before this migration ever ran) and a row written through the real
 * post-feature write path (MetricsRecorder::recordUsage(), with an agentId
 * and reuse-bearing provider usage) coexist in the same conversation and
 * the same run. Both must read successfully, and without any read-path code
 * change, through every existing lookup this feature's contract names:
 * UsageRecord::forConversation(), UsageRecord::find(), and
 * RunTraceQuery::usageRecordsForRun(). The pre-existing row's four new
 * columns must show as not-recorded/unknown (NULL/false), never a
 * fabricated value — this is what an additive-nullable-column migration
 * does to old rows by construction (research.md D6), so no production code
 * change is expected to make this test pass.
 */
class UsagePreExistingRecordCompatibilityTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::forget('run_id');
        DB::table('usage_records')->delete();
        DB::table('agent_runs')->delete();

        parent::tearDown();
    }

    private function insertRun(string $userId, string $conversationId): string
    {
        $runId = (string) Str::uuid();

        DB::table('agent_runs')->insert([
            'id' => $runId,
            'kind' => 'interactive',
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'source' => null,
            'end_state' => 'completed',
            'end_reason' => null,
            'started_at' => now()->toDateTimeString(),
            'ended_at' => now()->toDateTimeString(),
            'duration_ms' => 10,
            'step_count' => 1,
            'created_at' => now()->toDateTimeString(),
        ]);

        return $runId;
    }

    /**
     * Simulates a usage_records row written before this feature's migration
     * ever ran: only the columns that existed pre-feature are set. The four
     * new columns (reused_input_tokens, reused_input_estimated,
     * reused_input_adjusted, agent_id) are deliberately absent from this
     * insert, so the row relies entirely on the migration's column
     * defaults/nullability (data-model.md §1.5) to read back correctly.
     */
    private function insertLegacyShapedRow(
        string $conversationId,
        string $userId,
        string $runId,
    ): string {
        $id = (string) Str::uuid();

        DB::table('usage_records')->insert([
            'id' => $id,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'attempt_group_id' => (string) Str::uuid(),
            'run_id' => $runId,
            'input_tokens' => 40,
            'output_tokens' => 15,
            'total_tokens' => 55,
            'input_estimated' => false,
            'output_estimated' => false,
            'model' => 'legacy-model',
            'provider_type' => 'legacy-provider',
            'created_at' => now()->toDateTimeString(),
            // Deliberately NOT set: reused_input_tokens, reused_input_estimated,
            // reused_input_adjusted, agent_id — this is the point of the test.
        ]);

        return $id;
    }

    #[Test]
    public function pre_existing_and_post_feature_rows_coexist_and_both_read_correctly(): void
    {
        $userId = (string) Str::uuid();
        $conversationId = (string) Str::uuid();
        $runId = $this->insertRun($userId, $conversationId);

        $legacyId = $this->insertLegacyShapedRow($conversationId, $userId, $runId);

        // Write the post-feature row through the real write path, inside
        // the same run's Context scope, so it carries a run_id the same way
        // production code would (UsageRecord's creating listener, per spec
        // 069) — not by hand-setting run_id on the insert.
        Context::add('run_id', $runId);

        $recorder = new MetricsRecorder();
        $recorder->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: [
                'prompt_tokens' => 100,
                'completion_tokens' => 20,
                'total_tokens' => 120,
                'cache_read_input_tokens' => 30,
            ],
            inputText: 'post-feature input',
            outputText: 'post-feature output',
            model: 'new-model',
            providerType: 'anthropic',
            agentId: 'ResearchAgent',
        );

        Context::forget('run_id');

        $newRecord = UsageRecord::where('conversation_id', $conversationId)
            ->where('id', '!=', $legacyId)
            ->first();
        $this->assertNotNull($newRecord, 'The post-feature record must have been written');
        $newId = $newRecord->id;

        // ---- 1. UsageRecord::forConversation() (scopeForConversation) ----

        $viaConversation = UsageRecord::forConversation($conversationId)->get();
        $this->assertCount(2, $viaConversation, 'Both the legacy-shaped and post-feature rows must appear in the same conversation view');

        $legacyViaConversation = $viaConversation->firstWhere('id', $legacyId);
        $newViaConversation = $viaConversation->firstWhere('id', $newId);
        $this->assertNotNull($legacyViaConversation);
        $this->assertNotNull($newViaConversation);

        $this->assertLegacyRowReadsAsUnknown($legacyViaConversation);
        $this->assertPostFeatureRowReadsAsPopulated($newViaConversation);

        // ---- 2. UsageRecord::find() ----

        $legacyViaFind = UsageRecord::find($legacyId);
        $newViaFind = UsageRecord::find($newId);
        $this->assertNotNull($legacyViaFind, 'A pre-existing record must remain readable via UsageRecord::find()');
        $this->assertNotNull($newViaFind);

        $this->assertLegacyRowReadsAsUnknown($legacyViaFind);
        $this->assertPostFeatureRowReadsAsPopulated($newViaFind);

        // ---- 3. RunTraceQuery::usageRecordsForRun() ----

        $query = $this->app->make(RunTraceQuery::class);
        $viaRun = $query->usageRecordsForRun($userId, $runId);

        $this->assertIsArray($viaRun, 'The run must be found and owned by the caller');
        $this->assertCount(2, $viaRun, 'Both records belong to the same run');

        $ids = array_map(fn ($record) => $record->id, $viaRun);
        $this->assertContains($legacyId, $ids);
        $this->assertContains($newId, $ids);

        $legacyViaRun = null;
        $newViaRun = null;
        foreach ($viaRun as $record) {
            if ($record->id === $legacyId) {
                $legacyViaRun = $record;
            } elseif ($record->id === $newId) {
                $newViaRun = $record;
            }
        }

        $this->assertNotNull($legacyViaRun);
        $this->assertNotNull($newViaRun);

        $this->assertLegacyRowReadsAsUnknown($legacyViaRun);
        $this->assertPostFeatureRowReadsAsPopulated($newViaRun);
    }

    /**
     * Shared assertion block for the legacy-shaped row's new fields,
     * regardless of which read path produced the model instance
     * (contracts §3 R4).
     */
    private function assertLegacyRowReadsAsUnknown(UsageRecord $record): void
    {
        $this->assertNull($record->reused_input_tokens, 'A pre-existing row must show reused_input_tokens as unknown (NULL), never 0');
        $this->assertFalse($record->reused_input_estimated, 'A pre-existing row must show reused_input_estimated as false (its column default)');
        $this->assertFalse($record->reused_input_adjusted, 'A pre-existing row must show reused_input_adjusted as false (its column default)');
        $this->assertNull($record->agent_id, 'A pre-existing row must show agent_id as unknown (NULL), never a guessed agent');
        $this->assertNull($record->getFreshInputTokensAttribute(), 'Fresh input cannot be derived when reused_input_tokens is unknown');

        // The pre-existing legacy fields must be unaffected by the new columns' presence.
        $this->assertSame(40, $record->input_tokens);
        $this->assertSame(15, $record->output_tokens);
        $this->assertSame(55, $record->total_tokens);
    }

    /**
     * Shared assertion block confirming the newly-written record's reuse and
     * agent-attribution figures are correctly populated, distinguishing it
     * from the legacy row read alongside it in the same view (Acceptance
     * Scenario 2).
     */
    private function assertPostFeatureRowReadsAsPopulated(UsageRecord $record): void
    {
        $this->assertSame(30, $record->reused_input_tokens);
        $this->assertFalse($record->reused_input_adjusted, 'An in-range reported reuse figure is not clamped/adjusted');
        $this->assertSame('ResearchAgent', $record->agent_id);
        $this->assertSame(70, $record->getFreshInputTokensAttribute(), '100 input_tokens - 30 reused = 70 fresh');
    }
}
