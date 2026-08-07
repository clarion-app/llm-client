<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\Models\UsageRecord;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

/**
 * contracts/usage-accounting.md §1 W1/W6/W7 — agent_id is written exactly
 * as passed via the new trailing $agentId parameter on recordUsage(), with
 * no fallback/derivation performed by MetricsRecorder itself (that is the
 * caller's responsibility), and no shared state leaking agent attribution
 * between calls (FR-013).
 */
class MetricsRecorderAgentAttributionTest extends TestCase
{
    private function baseProviderUsage(): array
    {
        return [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'total_tokens' => 150,
        ];
    }

    #[Test]
    public function agent_id_is_written_exactly_as_passed(): void
    {
        $recorder = new MetricsRecorder();

        $recorder->recordUsage(
            conversationId: (string) Str::uuid(),
            userId: (string) Str::uuid(),
            attemptGroupId: (string) Str::uuid(),
            providerUsage: $this->baseProviderUsage(),
            inputText: 'input text',
            outputText: 'output text',
            agentId: 'researcher-agent',
        );

        $record = UsageRecord::first();
        $this->assertNotNull($record);
        $this->assertSame('researcher-agent', $record->agent_id);
    }

    #[Test]
    public function omitting_agent_id_writes_null(): void
    {
        $recorder = new MetricsRecorder();

        $recorder->recordUsage(
            conversationId: (string) Str::uuid(),
            userId: (string) Str::uuid(),
            attemptGroupId: (string) Str::uuid(),
            providerUsage: $this->baseProviderUsage(),
            inputText: 'input text',
            outputText: 'output text',
        );

        $record = UsageRecord::first();
        $this->assertNotNull($record);
        $this->assertNull(
            $record->agent_id,
            'Omitting agentId must record NULL, never a fabricated/derived identifier'
        );
    }

    #[Test]
    public function two_calls_with_different_agent_ids_produce_distinct_isolated_records(): void
    {
        $recorder = new MetricsRecorder();

        $recorder->recordUsage(
            conversationId: (string) Str::uuid(),
            userId: (string) Str::uuid(),
            attemptGroupId: (string) Str::uuid(),
            providerUsage: ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            inputText: 'first',
            outputText: 'first output',
            agentId: 'agent-alpha',
        );

        $recorder->recordUsage(
            conversationId: (string) Str::uuid(),
            userId: (string) Str::uuid(),
            attemptGroupId: (string) Str::uuid(),
            providerUsage: ['prompt_tokens' => 200, 'completion_tokens' => 75, 'total_tokens' => 275],
            inputText: 'second',
            outputText: 'second output',
            agentId: 'agent-beta',
        );

        $this->assertCount(2, UsageRecord::all());

        $alpha = UsageRecord::where('agent_id', 'agent-alpha')->first();
        $beta = UsageRecord::where('agent_id', 'agent-beta')->first();

        $this->assertNotNull($alpha);
        $this->assertNotNull($beta);
        $this->assertNotSame($alpha->id, $beta->id);
        $this->assertSame('agent-alpha', $alpha->agent_id);
        $this->assertSame('agent-beta', $beta->agent_id);
        $this->assertSame(100, $alpha->input_tokens);
        $this->assertSame(200, $beta->input_tokens);
    }
}
