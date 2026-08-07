<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\UsageRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

/**
 * User Story 2 end-to-end (FR-005, FR-006, FR-013, SC-004): two
 * differently-configured agents (Conversation::character) produce usage
 * records with distinct agent_id; an omitted agentId records NULL without
 * any recording failure; UsageRecord::forAgent() isolates one agent's
 * records from another's inside the same conversation history.
 *
 * The call-site wiring that derives agentId from $conversation->character
 * (AgentLoopService::recordUsageMetric(), the two streaming handlers'
 * finish()) does not exist yet — this test drives MetricsRecorder directly
 * with the eventual derivation (contracts/usage-accounting.md §5 L1:
 * agentId = $conversation->character ?: null) so it exercises the
 * recordUsage() contract itself, independent of whichever call site lands
 * it.
 */
class UsageAgentAttributionJourneyTest extends TestCase
{
    private function makeConversation(?string $character): Conversation
    {
        return Conversation::create([
            'id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'character' => $character,
        ]);
    }

    #[Test]
    public function two_conversations_with_different_characters_produce_distinct_agent_ids(): void
    {
        $recorder = new MetricsRecorder();

        $researcher = $this->makeConversation('Researcher');
        $summarizer = $this->makeConversation('Summarizer');

        $recorder->recordUsage(
            conversationId: $researcher->id,
            userId: $researcher->user_id,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
            inputText: 'researcher input',
            outputText: 'researcher output',
            agentId: $researcher->character ?: null,
        );

        $recorder->recordUsage(
            conversationId: $summarizer->id,
            userId: $summarizer->user_id,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: ['prompt_tokens' => 300, 'completion_tokens' => 40, 'total_tokens' => 340],
            inputText: 'summarizer input',
            outputText: 'summarizer output',
            agentId: $summarizer->character ?: null,
        );

        $researcherRecord = UsageRecord::forConversation($researcher->id)->first();
        $summarizerRecord = UsageRecord::forConversation($summarizer->id)->first();

        $this->assertNotNull($researcherRecord);
        $this->assertNotNull($summarizerRecord);
        $this->assertSame('Researcher', $researcherRecord->agent_id);
        $this->assertSame('Summarizer', $summarizerRecord->agent_id);
        $this->assertNotSame($researcherRecord->agent_id, $summarizerRecord->agent_id);
    }

    #[Test]
    public function omitted_agent_id_records_null_with_zero_increase_in_recording_failures(): void
    {
        Log::spy();

        $recorder = new MetricsRecorder();
        $conversation = $this->makeConversation(null);

        $recorder->recordUsage(
            conversationId: $conversation->id,
            userId: $conversation->user_id,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: ['prompt_tokens' => 50, 'completion_tokens' => 10, 'total_tokens' => 60],
            inputText: 'unattributed input',
            outputText: 'unattributed output',
            agentId: $conversation->character ?: null,
        );

        $record = UsageRecord::forConversation($conversation->id)->first();
        $this->assertNotNull(
            $record,
            'A request with no agent configuration in effect must still record usage successfully (SC-004)'
        );
        $this->assertNull($record->agent_id);

        Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    public function forAgent_isolates_one_agent_from_another_within_the_same_conversation_history(): void
    {
        $recorder = new MetricsRecorder();
        $conversation = $this->makeConversation('Researcher');

        // Two turns of the *same* conversation, first under one agent
        // configuration, then under a different one (e.g. the character was
        // changed mid-conversation) — forAgent() must still isolate them.
        $recorder->recordUsage(
            conversationId: $conversation->id,
            userId: $conversation->user_id,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
            inputText: 'turn one',
            outputText: 'turn one output',
            agentId: 'Researcher',
        );

        $recorder->recordUsage(
            conversationId: $conversation->id,
            userId: $conversation->user_id,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: ['prompt_tokens' => 200, 'completion_tokens' => 30, 'total_tokens' => 230],
            inputText: 'turn two',
            outputText: 'turn two output',
            agentId: 'Editor',
        );

        $researcherRecords = UsageRecord::forConversation($conversation->id)->forAgent('Researcher')->get();
        $editorRecords = UsageRecord::forConversation($conversation->id)->forAgent('Editor')->get();

        $this->assertCount(1, $researcherRecords);
        $this->assertCount(1, $editorRecords);
        $this->assertSame(100, $researcherRecords->first()->input_tokens);
        $this->assertSame(200, $editorRecords->first()->input_tokens);
    }
}
