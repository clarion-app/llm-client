<?php

namespace ClarionApp\LlmClient\Tests\Unit\Models;

use ClarionApp\LlmClient\Models\Conversation;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * research.md D1: Conversation::scopeOwnedByRealUser() — a local, opt-in,
 * unconditional (no auth() dependency) `whereNotNull('user_id')` scope.
 * This is the SQL-fragment-correctness half of D1/D2's guarantee; whether
 * every call site actually applies it is SystemConversationIsolationTest's
 * job (T033), not this file's.
 */
class ConversationScopeTest extends TestCase
{
    #[Test]
    public function it_includes_a_conversation_with_a_real_user_id_and_excludes_one_owned_by_no_one(): void
    {
        $owned = Conversation::create([
            'user_id' => (string) Str::uuid(),
            'title' => 'A real user conversation',
        ]);

        $systemOwned = Conversation::create([
            'user_id' => null,
            'title' => 'An eval-run conversation',
        ]);

        $results = Conversation::query()->ownedByRealUser()->get();

        $this->assertTrue(
            $results->contains('id', $owned->id),
            'a conversation with a real user_id must be included',
        );
        $this->assertFalse(
            $results->contains('id', $systemOwned->id),
            'a conversation with user_id = null must be excluded',
        );
        $this->assertCount(1, $results, 'exactly the one real-user row must be returned');
    }

    #[Test]
    public function the_scope_is_a_plain_where_not_null_clause_with_no_auth_dependency(): void
    {
        // A raw row-count assertion in isolation, no controller/HTTP
        // context, no authenticated user at all — the scope must not
        // silently depend on auth() being set (research.md D1's explicit
        // rejection of the codebase's own auth()-gated global-scope
        // idiom).
        $this->assertNull(auth()->user());

        Conversation::create(['user_id' => (string) Str::uuid(), 'title' => 'A']);
        Conversation::create(['user_id' => (string) Str::uuid(), 'title' => 'B']);
        Conversation::create(['user_id' => null, 'title' => 'C (system-owned)']);

        $count = Conversation::query()->ownedByRealUser()->count();

        $this->assertSame(2, $count);
    }

    #[Test]
    public function the_generated_sql_carries_an_explicit_not_null_check_on_user_id(): void
    {
        $sql = strtolower(Conversation::query()->ownedByRealUser()->toSql());

        $this->assertStringContainsString('user_id', $sql);
        $this->assertStringContainsString('not null', $sql);
    }
}
