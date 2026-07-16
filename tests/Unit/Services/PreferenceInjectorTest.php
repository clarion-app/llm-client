<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\PreferenceInjector;
use ClarionApp\LlmClient\Models\DeclarativeMemory;
use ClarionApp\Backend\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

use PHPUnit\Framework\Attributes\Test;

class PreferenceInjectorTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $injector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->injector = new PreferenceInjector();
    }

    protected function tearDown(): void
    {
        Config::set('llm-client.preferences_injection', null);
        parent::tearDown();
    }

    /**
     * Insert a preference with an exact id, bypassing Eloquent.
     *
     * EloquentMultiChainBridge hooks `creating` and replaces $model->id with a
     * random UUID, so tests that need to pin the id — the tiebreaker tests —
     * cannot go through the model.
     */
    private function insertPreference(string $id, string $content, string $updatedAt): void
    {
        DB::table('declarative_memories')->insert([
            'id' => $id,
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => $content,
            'source' => 'user_stated',
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  T003: Empty store returns null                                     */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function empty_store_returns_null()
    {
        $result = $this->injector->assemble($this->user->id);
        $this->assertNull($result);
    }

    /* ------------------------------------------------------------------ */
    /*  T003: Disabled config returns null                                 */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function disabled_config_returns_null()
    {
        Config::set('llm-client.preferences_injection.enabled', false);

        DeclarativeMemory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'Prefer dark mode',
            'source' => 'user_stated',
        ]);

        $result = $this->injector->assemble($this->user->id);
        $this->assertNull($result);
    }

    /* ------------------------------------------------------------------ */
    /*  T003: Soft preference formatting                                   */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function soft_preference_formatting()
    {
        DeclarativeMemory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'Prefer concise responses',
            'source' => 'user_stated',
        ]);

        $result = $this->injector->assemble($this->user->id);

        $this->assertStringContainsString('## User Preferences', $result);
        $this->assertStringContainsString('### Preferences (defaults — always yield if I explicitly ask for something different)', $result);
        $this->assertStringContainsString('- Prefer concise responses', $result);
    }

    /* ------------------------------------------------------------------ */
    /*  T003: Binding rule formatting                                      */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function binding_rule_formatting()
    {
        DeclarativeMemory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'rule',
            'content' => 'Always respond in Python',
            'source' => 'user_stated',
        ]);

        $result = $this->injector->assemble($this->user->id);

        $this->assertStringContainsString('## User Preferences', $result);
        $this->assertStringContainsString('### Binding Rules (MUST follow unless I explicitly override in my current message)', $result);
        $this->assertStringContainsString('- Always respond in Python', $result);
    }

    /* ------------------------------------------------------------------ */
    /*  T003: Binding rules prioritized over soft preferences              */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function binding_rules_prioritized_over_soft_preferences()
    {
        DeclarativeMemory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'rule',
            'content' => 'Always respond in Python',
            'source' => 'user_stated',
        ]);

        DeclarativeMemory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'Prefer concise responses',
            'source' => 'user_stated',
        ]);

        $result = $this->injector->assemble($this->user->id);

        // Binding rules section appears before preferences section
        $rulePos = strpos($result, '### Binding Rules');
        $prefPos = strpos($result, '### Preferences');
        $this->assertLessThan($prefPos, $rulePos);
    }

    /* ------------------------------------------------------------------ */
    /*  T003: Recency ordering                                             */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function recency_ordering()
    {
        $old = (new DeclarativeMemory())->forceFill([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'Old preference',
            'source' => 'user_stated',
            'updated_at' => '2025-01-01 00:00:00',
        ])->save();

        $new = (new DeclarativeMemory())->forceFill([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'New preference',
            'source' => 'user_stated',
            'updated_at' => '2026-01-01 00:00:00',
        ])->save();

        $result = $this->injector->assemble($this->user->id);

        // New preference listed before old preference
        $newPos = strpos($result, '- New preference');
        $oldPos = strpos($result, '- Old preference');
        $this->assertLessThan($oldPos, $newPos);
    }

    /* ------------------------------------------------------------------ */
    /*  T003: UUID tiebreaker for identical timestamps                     */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function uuid_tiebreaker_for_identical_timestamps()
    {
        $uuidA = '11111111-1111-1111-1111-111111111111';
        $uuidB = '22222222-2222-2222-2222-222222222222';

        // Insert via the query builder: EloquentMultiChainBridge's `creating`
        // hook overwrites $model->id with a random UUID, so the id cannot be
        // pinned through the model.
        $this->insertPreference($uuidB, 'Preference B', '2025-06-01 00:00:00');
        $this->insertPreference($uuidA, 'Preference A', '2025-06-01 00:00:00');

        $result = $this->injector->assemble($this->user->id);

        $posA = strpos($result, '- Preference A');
        $posB = strpos($result, '- Preference B');
        $this->assertNotFalse($posA);
        $this->assertNotFalse($posB);

        // FR-011: the tiebreaker is `id` descending, so the higher UUID (B)
        // sorts first. Pinning the direction — not merely "some stable order" —
        // is what makes this a regression test.
        $this->assertLessThan($posA, $posB);
    }

    /* ------------------------------------------------------------------ */
    /*  T013: Whole-entry truncation when exceeding budget                 */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function whole_entry_truncation_when_exceeding_budget()
    {
        // Set very small token budget (50 tokens = ~200 chars)
        Config::set('llm-client.preferences_injection.max_tokens', 50);

        (new DeclarativeMemory())->forceFill([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'First preference that is reasonably long',
            'source' => 'user_stated',
            'updated_at' => '2026-01-01 00:00:00',
        ])->save();

        (new DeclarativeMemory())->forceFill([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'Second preference that is also reasonably long',
            'source' => 'user_stated',
            'updated_at' => '2025-01-01 00:00:00',
        ])->save();

        $result = $this->injector->assemble($this->user->id);

        $this->assertStringContainsString('## User Preferences', $result);

        // The newer entry fits; the older one does not and is dropped whole —
        // no partial content, no ellipsis.
        $this->assertStringContainsString('- First preference that is reasonably long', $result);
        $this->assertStringNotContainsString('Second preference', $result);

        // SC-004: the block never exceeds the budget (no tolerance — soft
        // preferences are droppable, so there is no reason to overflow).
        $this->assertLessThanOrEqual(50, strlen($result) / 4);
    }

    /* ------------------------------------------------------------------ */
    /*  T013: Headers counted against budget                               */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function headers_counted_against_budget()
    {
        // The section + preferences headers cost ~27.5 tokens together, so a
        // 20-token budget cannot fit them plus any entry.
        Config::set('llm-client.preferences_injection.max_tokens', 20);

        DeclarativeMemory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'A',
            'source' => 'user_stated',
        ]);

        $result = $this->injector->assemble($this->user->id);

        // Headers count against the budget, so nothing fits — and a lone header
        // with no entries beneath it is not worth injecting.
        $this->assertNull($result);
    }

    /* ------------------------------------------------------------------ */
    /*  T014: Binding rules included before soft preferences when budget   */
    /*          is tight                                                   */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function binding_rules_included_before_soft_preferences_when_budget_tight()
    {
        Config::set('llm-client.preferences_injection.max_tokens', 50);

        DeclarativeMemory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'rule',
            'content' => 'Always respond in Python',
            'source' => 'user_stated',
        ]);

        DeclarativeMemory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'Prefer very long and verbose responses with lots of detail',
            'source' => 'user_stated',
        ]);

        $result = $this->injector->assemble($this->user->id);

        // Binding rule should still be present even when budget is tight
        $this->assertStringContainsString('Always respond in Python', $result);
    }

    /* ------------------------------------------------------------------ */
    /*  T014: Binding rules alone exceed budget — all kept in full, no     */
    /*          soft preferences injected                                  */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function binding_rules_alone_exceed_budget_overflow()
    {
        // Very small budget
        Config::set('llm-client.preferences_injection.max_tokens', 20);

        DeclarativeMemory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'rule',
            'content' => 'This is a binding rule that is long enough to exceed the small budget on its own',
            'source' => 'user_stated',
        ]);

        DeclarativeMemory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'Soft preference that should not appear',
            'source' => 'user_stated',
        ]);

        $result = $this->injector->assemble($this->user->id);

        // Binding rules are kept in full — never partially truncated — even
        // though that overflows the budget (FR-006).
        $this->assertStringContainsString(
            '- This is a binding rule that is long enough to exceed the small budget on its own',
            $result
        );

        // Soft preferences are dropped entirely, header included.
        $this->assertStringNotContainsString('Soft preference that should not appear', $result);
        $this->assertStringNotContainsString('### Preferences', $result);
    }

    /* ------------------------------------------------------------------ */
    /*  Regression: no empty Preferences header when rules leave no room   */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function no_empty_preferences_header_when_rules_consume_budget()
    {
        // 30 tokens = 120 chars. The rules section (118 chars) fits, but leaves
        // no room for the preferences header (88) plus an entry.
        Config::set('llm-client.preferences_injection.max_tokens', 30);

        DeclarativeMemory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'rule',
            'content' => 'Use Python',
            'source' => 'user_stated',
        ]);

        DeclarativeMemory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'Be concise',
            'source' => 'user_stated',
        ]);

        $result = $this->injector->assemble($this->user->id);

        $this->assertStringContainsString('- Use Python', $result);

        // The preferences header must not be emitted with nothing beneath it.
        $this->assertStringNotContainsString('### Preferences', $result);
        $this->assertStringNotContainsString('Be concise', $result);

        // And emitting no preferences must not push the block over budget.
        $this->assertLessThanOrEqual(30, strlen($result) / 4);
    }

    /* ------------------------------------------------------------------ */
    /*  Regression: preferences-only + budget too small returns null       */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function returns_null_rather_than_lone_section_header()
    {
        // Too small for the headers plus any entry, and there are no rules to
        // carry the section — inject nothing rather than a bare header.
        Config::set('llm-client.preferences_injection.max_tokens', 20);

        DeclarativeMemory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'Be concise',
            'source' => 'user_stated',
        ]);

        $this->assertNull($this->injector->assemble($this->user->id));
    }

    /* ------------------------------------------------------------------ */
    /*  T015: All preferences included when within budget                  */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function all_preferences_included_when_within_budget()
    {
        Config::set('llm-client.preferences_injection.max_tokens', 500);

        DeclarativeMemory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'Prefer concise responses',
            'source' => 'user_stated',
        ]);

        DeclarativeMemory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'Use dark mode',
            'source' => 'user_stated',
        ]);

        DeclarativeMemory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'rule',
            'content' => 'Always respond in Python',
            'source' => 'user_stated',
        ]);

        $result = $this->injector->assemble($this->user->id);

        $this->assertStringContainsString('Prefer concise responses', $result);
        $this->assertStringContainsString('Use dark mode', $result);
        $this->assertStringContainsString('Always respond in Python', $result);
    }

    /* ------------------------------------------------------------------ */
    /*  T018: Deleted preference not included (fresh read)                 */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function deleted_preference_not_included()
    {
        $entry = DeclarativeMemory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'Will be deleted',
            'source' => 'user_stated',
        ]);

        // Initially present
        $result = $this->injector->assemble($this->user->id);
        $this->assertStringContainsString('Will be deleted', $result);

        // Delete it
        $entry->delete();

        // Next call should not include it
        $result = $this->injector->assemble($this->user->id);
        $this->assertTrue($result === null || !str_contains($result, 'Will be deleted'));
    }

    /* ------------------------------------------------------------------ */
    /*  T019: Edited preference reflects updated content                   */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function edited_preference_reflects_updated_content()
    {
        $entry = DeclarativeMemory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'Original content',
            'source' => 'user_stated',
        ]);

        $result = $this->injector->assemble($this->user->id);
        $this->assertStringContainsString('Original content', $result);

        // Update content
        $entry->update(['content' => 'Updated content']);

        $result = $this->injector->assemble($this->user->id);
        $this->assertStringContainsString('Updated content', $result);
        $this->assertStringNotContainsString('Original content', $result);
    }

    /* ------------------------------------------------------------------ */
    /*  T020: New preference included on next assemble call                */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function new_preference_included_on_next_assemble()
    {
        $result = $this->injector->assemble($this->user->id);
        $this->assertNull($result);

        DeclarativeMemory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'Newly added preference',
            'source' => 'user_stated',
        ]);

        $result = $this->injector->assemble($this->user->id);
        $this->assertStringContainsString('Newly added preference', $result);
    }

    /* ------------------------------------------------------------------ */
    /*  T023: Most recently updated preference listed first (ordering)     */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function most_recently_updated_preference_listed_first()
    {
        (new DeclarativeMemory())->forceFill([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'First preference',
            'source' => 'user_stated',
            'updated_at' => '2025-01-01 00:00:00',
        ])->save();

        (new DeclarativeMemory())->forceFill([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'Second preference',
            'source' => 'user_stated',
            'updated_at' => '2025-06-01 00:00:00',
        ])->save();

        (new DeclarativeMemory())->forceFill([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'Third preference',
            'source' => 'user_stated',
            'updated_at' => '2026-01-01 00:00:00',
        ])->save();

        $result = $this->injector->assemble($this->user->id);

        // Third (most recent) listed first, then second, then first
        $posFirst = strpos($result, '- First preference');
        $posSecond = strpos($result, '- Second preference');
        $posThird = strpos($result, '- Third preference');

        $this->assertLessThan($posSecond, $posThird);
        $this->assertLessThan($posFirst, $posSecond);
    }

    /* ------------------------------------------------------------------ */
    /*  T024: Deterministic UUID tiebreaker for identical timestamps       */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function deterministic_uuid_tiebreaker()
    {
        $uuidLow = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $uuidHigh = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

        $this->insertPreference($uuidLow, 'Low UUID preference', '2025-06-01 00:00:00');
        $this->insertPreference($uuidHigh, 'High UUID preference', '2025-06-01 00:00:00');

        $result = $this->injector->assemble($this->user->id);

        // `id` descending: the higher UUID sorts first.
        $this->assertLessThan(
            strpos($result, '- Low UUID preference'),
            strpos($result, '- High UUID preference')
        );

        // And the ordering is stable across calls.
        $this->assertEquals($result, $this->injector->assemble($this->user->id));
    }

    /* ------------------------------------------------------------------ */
    /*  T003: Token budget truncation (whole-entry, headers counted)       */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function token_budget_truncation_whole_entry_with_headers()
    {
        // 40 tokens = 160 chars: section + prefs headers (110) leave room for
        // the two newest entries but not the third.
        Config::set('llm-client.preferences_injection.max_tokens', 40);

        (new DeclarativeMemory())->forceFill([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'First preference item',
            'source' => 'user_stated',
            'updated_at' => '2026-01-01 00:00:00',
        ])->save();

        (new DeclarativeMemory())->forceFill([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'Second preference item',
            'source' => 'user_stated',
            'updated_at' => '2025-06-01 00:00:00',
        ])->save();

        (new DeclarativeMemory())->forceFill([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'Third preference item',
            'source' => 'user_stated',
            'updated_at' => '2025-01-01 00:00:00',
        ])->save();

        $result = $this->injector->assemble($this->user->id);

        $this->assertNotNull($result);
        $this->assertStringContainsString('## User Preferences', $result);

        // Entries are dropped lowest-recency-first: the two newest survive,
        // the oldest is dropped whole.
        $this->assertStringContainsString('- First preference item', $result);
        $this->assertStringContainsString('- Second preference item', $result);
        $this->assertStringNotContainsString('Third preference item', $result);

        // SC-004: headers included, the block stays within budget.
        $this->assertLessThanOrEqual(40, strlen($result) / 4);
    }
}
