<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\Models\UsageRecord;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

/**
 * FR-013 — retry independence. Two recordUsage() calls sharing one
 * attemptGroupId (simulating a retried attempt of the same turn) but
 * different providerUsage/agentId produce two fully independent records:
 * neither the reuse figures (US1, already implemented) nor the agent
 * attribution (US2, under test here) of the first attempt leak into, merge
 * with, or influence the second's (contracts/usage-accounting.md §1 W7).
 */
class UsageRetryAttemptIndependenceTest extends TestCase
{
    #[Test]
    public function second_attempt_does_not_inherit_first_attempts_reuse_or_agent_figures(): void
    {
        $recorder = new MetricsRecorder();
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $attemptGroupId = (string) Str::uuid();

        // First attempt: Anthropic-shaped, heavy cache reuse, attributed to one agent.
        $recorder->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: $attemptGroupId,
            providerUsage: [
                'prompt_tokens' => 1000,
                'completion_tokens' => 50,
                'total_tokens' => 1050,
                'cache_read_input_tokens' => 900,
            ],
            inputText: 'attempt one input',
            outputText: 'attempt one output',
            agentId: 'agent-alpha',
        );

        // Second attempt (a retry of the same turn, same attemptGroupId):
        // no reuse reported at all, and attributed to a different agent.
        $recorder->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: $attemptGroupId,
            providerUsage: [
                'prompt_tokens' => 300,
                'completion_tokens' => 25,
                'total_tokens' => 325,
            ],
            inputText: 'attempt two input',
            outputText: 'attempt two output',
            agentId: 'agent-beta',
        );

        $records = UsageRecord::where('attempt_group_id', $attemptGroupId)
            ->orderBy('input_tokens')
            ->get();

        $this->assertCount(
            2,
            $records,
            'Two recordUsage() calls sharing one attemptGroupId must produce two independent rows, never a merge'
        );

        $second = $records->first();
        $first = $records->last();

        $this->assertSame(300, $second->input_tokens);
        $this->assertSame(1000, $first->input_tokens);

        $this->assertSame(
            900,
            $first->reused_input_tokens,
            'The first attempt keeps its own reused figure'
        );
        $this->assertNull(
            $second->reused_input_tokens,
            "The second attempt's reused figure must stay unknown — it must not inherit the first attempt's 900"
        );

        $this->assertSame('agent-alpha', $first->agent_id);
        $this->assertSame(
            'agent-beta',
            $second->agent_id,
            "The second attempt's agent attribution must not be overwritten or merged with the first's"
        );
    }
}
