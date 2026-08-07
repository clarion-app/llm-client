<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\UsageRecord;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

/**
 * contracts/usage-accounting.md §3 R3 / data-model.md §2 — scopeForAgent()
 * performs an exact WHERE agent_id = ? match, matching the existing
 * scopeForConversation/scopeForUser idiom. A NULL agent_id is excluded from
 * every forAgent(...) call, including the empty-string case (NULL and ''
 * are distinct in SQL and must stay distinct here).
 */
class UsageRecordForAgentScopeTest extends TestCase
{
    private function makeRecord(?string $agentId, int $inputTokens = 100): UsageRecord
    {
        return UsageRecord::create([
            'conversation_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'attempt_group_id' => (string) Str::uuid(),
            'agent_id' => $agentId,
            'input_tokens' => $inputTokens,
            'output_tokens' => 50,
            'total_tokens' => $inputTokens + 50,
        ]);
    }

    #[Test]
    public function returns_only_matching_rows(): void
    {
        $this->makeRecord('agent-alpha', 100);
        $this->makeRecord('agent-alpha', 20);
        $this->makeRecord('agent-beta', 999);

        $records = UsageRecord::forAgent('agent-alpha')->get();

        $this->assertCount(2, $records);
        foreach ($records as $record) {
            $this->assertSame('agent-alpha', $record->agent_id);
        }
    }

    #[Test]
    public function null_agent_id_is_excluded_from_every_forAgent_call(): void
    {
        $unattributed = $this->makeRecord(null, 100);

        $this->assertCount(
            0,
            UsageRecord::forAgent('agent-alpha')->get(),
            'A NULL agent_id row must never satisfy a named-agent forAgent() lookup'
        );

        $this->assertCount(
            0,
            UsageRecord::forAgent('')->get(),
            'forAgent("") must not match a NULL agent_id row — NULL and empty string are distinct'
        );

        // Confirm the row genuinely exists and is genuinely NULL (not '')
        // so the assertions above are proving exclusion, not absence.
        $this->assertNotNull(UsageRecord::find($unattributed->id));
        $this->assertNull(UsageRecord::find($unattributed->id)->agent_id);
    }

    #[Test]
    public function forAgent_empty_string_matches_a_row_explicitly_stored_as_empty_string(): void
    {
        $this->makeRecord('', 5);
        $this->makeRecord(null, 100);

        $records = UsageRecord::forAgent('')->get();

        $this->assertCount(
            1,
            $records,
            'forAgent("") must match a row whose agent_id is genuinely an empty string, while still excluding NULL rows'
        );
        $this->assertSame('', $records->first()->agent_id);
    }
}
