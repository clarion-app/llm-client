<?php

namespace Tests\Feature;

use Tests\TestCase;
use ClarionApp\LlmClient\Contracts\MemoryScope;
use ClarionApp\LlmClient\Services\MemoryService;
use ClarionApp\LlmClient\Models\MemoryEntry;
use ClarionApp\LlmClient\Events\AgentTurnCompleted;
use ClarionApp\LlmClient\Events\ConversationEnded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;

class MemoryLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function getService(): MemoryService
    {
        return app(MemoryService::class);
    }

    // ─── Scratch Lifecycle (US1) ───

    public function test_scratch_cleanup_on_turn_completed(): void
    {
        $service = $this->getService();
        $agentId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();
        $turnId = '1';

        // Create scratch entries for turn 1
        $service->create(MemoryScope::SCRATCH, $agentId, $userId, $convId, $turnId, 'key1', 'content1');
        $service->create(MemoryScope::SCRATCH, $agentId, $userId, $convId, $turnId, 'key2', 'content2');

        // Also create scratch entries for turn 2 (should survive turn 1 cleanup)
        $service->create(MemoryScope::SCRATCH, $agentId, $userId, $convId, '2', 'key3', 'content3');

        // Verify entries exist
        $this->assertEquals(3, MemoryEntry::where('scope', MemoryScope::SCRATCH->value)->where('agent_id', $agentId)->count());

        // Fire AgentTurnCompleted for turn 1
        EventFacade::dispatch(new AgentTurnCompleted($turnId, $convId));

        // Turn 1 entries should be gone, turn 2 entries should remain
        $this->assertEquals(1, MemoryEntry::where('scope', MemoryScope::SCRATCH->value)->where('agent_id', $agentId)->count());

        $remaining = MemoryEntry::where('scope', MemoryScope::SCRATCH->value)->where('agent_id', $agentId)->first();
        $this->assertEquals('2', $remaining->turn_id);
        $this->assertEquals('key3', $remaining->key);
    }

    /**
     * Regression: cleanup filtered on turn_id alone. turn_id is not unique across
     * conversations ("1" is everyone's first turn) and MemoryEntry has no per-user
     * global scope, so finishing turn 1 of one conversation deleted turn-1 scratch
     * memory belonging to every other conversation — and every other user.
     */
    public function test_scratch_cleanup_does_not_cross_conversations_or_users(): void
    {
        $service = $this->getService();
        $turnId = '1';

        $agentA = (string) \Illuminate\Support\Str::uuid();
        $userA  = (string) \Illuminate\Support\Str::uuid();
        $convA  = (string) \Illuminate\Support\Str::uuid();

        $agentB = (string) \Illuminate\Support\Str::uuid();
        $userB  = (string) \Illuminate\Support\Str::uuid();
        $convB  = (string) \Illuminate\Support\Str::uuid();

        // Same turn_id, different conversations and different users.
        $service->create(MemoryScope::SCRATCH, $agentA, $userA, $convA, $turnId, 'a_key', 'a content');
        $service->create(MemoryScope::SCRATCH, $agentB, $userB, $convB, $turnId, 'b_key', 'b content');

        // Conversation A finishes its turn 1.
        EventFacade::dispatch(new AgentTurnCompleted($turnId, $convA));

        $this->assertEquals(
            0,
            MemoryEntry::where('scope', MemoryScope::SCRATCH->value)->where('conversation_id', $convA)->count(),
            'Own conversation scratch should be cleaned'
        );
        $this->assertEquals(
            1,
            MemoryEntry::where('scope', MemoryScope::SCRATCH->value)->where('conversation_id', $convB)->count(),
            'Another user\'s scratch memory must never be deleted by this conversation\'s turn'
        );
    }

    public function test_scratch_cleanup_only_affects_scratch_scope(): void
    {
        $service = $this->getService();
        $agentId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();
        $turnId = '1';

        // Create entries in all scopes
        $service->create(MemoryScope::SCRATCH, $agentId, $userId, $convId, $turnId, 'scratch_key', 'scratch');
        $service->create(MemoryScope::SHORT_TERM, $agentId, $userId, $convId, null, 'short_key', 'short_term');
        $service->create(MemoryScope::LONG_TERM, $agentId, $userId, $convId, null, 'long_key', 'long_term');

        // Fire turn completed
        EventFacade::dispatch(new AgentTurnCompleted($turnId, $convId));

        // Only scratch should be gone
        $this->assertEquals(0, MemoryEntry::where('scope', MemoryScope::SCRATCH->value)->where('agent_id', $agentId)->count());
        $this->assertEquals(1, MemoryEntry::where('scope', MemoryScope::SHORT_TERM->value)->where('agent_id', $agentId)->count());
        $this->assertEquals(1, MemoryEntry::where('scope', MemoryScope::LONG_TERM->value)->where('agent_id', $agentId)->count());
    }

    // ─── Short-Term Lifecycle (US2) ───

    public function test_short_term_cleanup_on_conversation_ended(): void
    {
        $service = $this->getService();
        $agentId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();

        // Create short-term entries
        $service->create(MemoryScope::SHORT_TERM, $agentId, $userId, $convId, null, 'st_key1', 'st1');
        $service->create(MemoryScope::SHORT_TERM, $agentId, $userId, $convId, null, 'st_key2', 'st2');

        // Create short-term entries for a different conversation (should survive)
        $otherConvId = (string) \Illuminate\Support\Str::uuid();
        $service->create(MemoryScope::SHORT_TERM, $agentId, $userId, $otherConvId, null, 'st_other', 'other_conv');

        $this->assertEquals(3, MemoryEntry::where('scope', MemoryScope::SHORT_TERM->value)->where('agent_id', $agentId)->count());

        // Fire ConversationEnded for first conversation
        EventFacade::dispatch(new ConversationEnded($convId, $agentId));

        // Only entries for $convId should be gone
        $this->assertEquals(1, MemoryEntry::where('scope', MemoryScope::SHORT_TERM->value)->where('agent_id', $agentId)->count());

        $remaining = MemoryEntry::where('scope', MemoryScope::SHORT_TERM->value)->where('agent_id', $agentId)->first();
        $this->assertEquals($otherConvId, $remaining->conversation_id);
    }

    public function test_conversation_ended_only_affects_short_term(): void
    {
        $service = $this->getService();
        $agentId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();

        $service->create(MemoryScope::SCRATCH, $agentId, $userId, $convId, '1', 'scratch_key', 'scratch');
        $service->create(MemoryScope::SHORT_TERM, $agentId, $userId, $convId, null, 'short_key', 'short');
        $service->create(MemoryScope::LONG_TERM, $agentId, $userId, $convId, null, 'long_key', 'long');

        EventFacade::dispatch(new ConversationEnded($convId, $agentId));

        // Short-term should be gone, scratch and long-term should remain
        $this->assertEquals(1, MemoryEntry::where('scope', MemoryScope::SCRATCH->value)->where('agent_id', $agentId)->count());
        $this->assertEquals(0, MemoryEntry::where('scope', MemoryScope::SHORT_TERM->value)->where('agent_id', $agentId)->count());
        $this->assertEquals(1, MemoryEntry::where('scope', MemoryScope::LONG_TERM->value)->where('agent_id', $agentId)->count());
    }

    // ─── Long-Term Persistence (US3) ───

    public function test_long_term_survives_conversation_end(): void
    {
        $service = $this->getService();
        $agentId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();

        // Create long-term entries
        $service->create(MemoryScope::LONG_TERM, $agentId, $userId, $convId, null, 'persist1', 'persistent data');
        $service->create(MemoryScope::LONG_TERM, $agentId, $userId, $convId, null, 'persist2', 'more persistent data');

        // Fire conversation ended (simulating session end)
        EventFacade::dispatch(new ConversationEnded($convId, $agentId));

        // Long-term entries should still exist
        $this->assertEquals(2, MemoryEntry::where('scope', MemoryScope::LONG_TERM->value)->where('agent_id', $agentId)->count());

        // Verify entries are accessible
        $entry = $service->read(MemoryScope::LONG_TERM, $agentId, 'persist1');
        $this->assertNotNull($entry);
        $this->assertEquals('persistent data', $entry->content);
    }

    public function test_long_term_accessible_across_sessions(): void
    {
        $service = $this->getService();
        $agentId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();

        // Session 1: Create long-term entries
        $convId1 = (string) \Illuminate\Support\Str::uuid();
        $service->create(MemoryScope::LONG_TERM, $agentId, $userId, $convId1, null, 'fact1', 'user likes coffee');

        // Session 1 ends
        EventFacade::dispatch(new ConversationEnded($convId1, $agentId));

        // Session 2: New conversation — entries should still be accessible
        $convId2 = (string) \Illuminate\Support\Str::uuid();

        $entry = $service->read(MemoryScope::LONG_TERM, $agentId, 'fact1');
        $this->assertNotNull($entry);
        $this->assertEquals('user likes coffee', $entry->content);

        // Can create new long-term entries in new session
        $service->create(MemoryScope::LONG_TERM, $agentId, $userId, $convId2, null, 'fact2', 'user is allergic to peanuts');

        $this->assertEquals(2, MemoryEntry::where('scope', MemoryScope::LONG_TERM->value)->where('agent_id', $agentId)->count());
    }
}
