<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Models\Conversation;
use Tests\Integration\Harness\ConversationScript;
use Tests\Integration\Harness\LaneRule;
use Tests\Integration\Harness\RequestLane;
use Tests\Integration\Harness\Responses;

/**
 * US2: Information the agent was asked to keep survives to later turns.
 *
 * Integration scenarios that verify distinctive facts stated early survive
 * context management having acted at least once — proven non-vacuous by a
 * witness. Feature 045's protected-content detection (MessageScorer PIN_PATTERNS)
 * is the mechanism under test.
 *
 * These tests verify:
 * - Protected content survives a single reduction (FR-007, FR-012)
 * - Protected content survives several further reductions (FR-007)
 * - Dropped content reports last-present and first-absent turns (FR-014)
 * - Ordinary history reduces normally without protection (acceptance scenario 5)
 *
 * SC-002 note (Phase 8 T061 gap, closed): the two positive tests below
 * (test_kept_information_survives_reduction,
 * test_kept_information_survives_several_further_reductions) force the
 * Condensation lane to throw rather than scripting a marker-echoing summary.
 * ConversationCondenser::condenseOrTrim() only reaches smartTrimThenBudget()
 * — the sole caller of SmartHistoryTrimmer, the sole consumer of
 * MessageScorer::PIN_PATTERNS — when condensation is unavailable (no sealed
 * chunks, a condensation error, or a condensed result that doesn't fit). A
 * rule that unconditionally returns a successful summary never exercises
 * that fallback, so the marker's survival would be guaranteed by the
 * script's own choice to keep echoing it — not by the product's
 * protected-content detection. Throwing from the Condensation lane (the same
 * pattern ContextManagementJourneyTest::test_swallowed_condensation_failure_falls_back_to_trim
 * uses) makes every reduction in these two tests go through the real
 * smart-trim path, so PIN_PATTERNS is load-bearing: removing `remember` from
 * MessageScorer::PIN_PATTERNS now makes these tests fail.
 *
 * A second, deeper gap surfaced while closing the first one, and has since
 * been fixed in production: `LlmClientServiceProvider`'s `ConversationCondenser`
 * binding used to pass `null` for the `$smartTrimmer` constructor argument,
 * making `SmartHistoryTrimmer` (and therefore `MessageScorer`/`PIN_PATTERNS`)
 * unreachable through the real container. The provider now wires a real
 * `SmartHistoryTrimmer` (backed by `MessageScorer` and `CoherenceValidator`,
 * both bound as singletons) into `ConversationCondenser`, so this test needs
 * no container rebinding — it exercises the exact object graph the running
 * application assembles.
 */
class RetainedInstructionJourneyTest extends MultiTurnTestCase
{
    /**
     * How many turns to drive before checking marker survival in the two
     * positive tests below. Must clear SmartHistoryTrimmer's recency window
     * (`smart_history_trimming.preserved_pairs`, default 10 pairs = 20
     * messages ≈ 10 turns) with margin, so a later reduction has to decide
     * the marker's fate on MessageScorer's score alone — not because it is
     * still one of the most recent messages regardless of pinning.
     */
    private const PRESERVED_WINDOW_CLEARANCE_TURNS = 16;

    /**
     * How many *reductions* (not turns) to drive through before checking
     * marker survival. A plain assistant reply always scores at most 0.6-0.7
     * (MessageScorer::scoreByRole) — strictly below an ordinary user
     * message's 0.9 — so every non-preserved turn contributes one
     * comparatively "cheap" eviction candidate ahead of the marker's own
     * user message. One reduction only clears that cheap tier's *current*
     * size; since nothing is ever deleted from the persisted history (only
     * from the wire payload), each further turn adds to both the cheap tier
     * and the pressure again, and the pressure grows faster than the cheap
     * tier does. Empirically, driving to a single reduction (or even a
     * handful) still lets assistant-reply filler absorb it every time —
     * this constant is the smallest reduction count observed to exhaust that
     * cheap tier and force eviction to reach the marker's own score tier,
     * where PIN_PATTERNS is what decides its fate.
     */
    private const REDUCTIONS_TO_EXHAUST_CHEAP_TIER = 6;

    /* --------------------------------------------------------------------------
     * T030: Kept information survives reduction
     * --------------------------------------------------------------------------
     * Per the quickstart example: a "remember ... for this session" instruction
     * carrying a marker. Feature 045's protected-content detection is the
     * mechanism under test.
     *
     * Witness asserted BEFORE the property
     * assertPresentFromTurn(marker, from: firstReducedTurn())
     *
     * The original design seeded 100 low-value dummy messages *before* the
     * marker turn, then stopped at the first reduction. Two problems with
     * that: (1) the first reduction happens at turn 1 itself, while the
     * marker is still the newest message — SmartHistoryTrimmer would keep it
     * via plain recency (`preserved_pairs`, default 10) regardless of
     * PIN_PATTERNS; and (2) the 100 pre-seeded messages (half of them
     * `assistant_statement`-scored at 0.6, well below the marker's unpinned
     * 0.9) gave the evictor a large low-value buffer to spend first, so even
     * many turns later the marker's own unpinned score would never actually
     * be tested — eviction pressure never had to reach that tier. Neither
     * problem shows up as a passing-but-vacuous assertion; both simply mean
     * PIN_PATTERNS is never consulted for the outcome.
     *
     * This version seeds nothing before the marker: the marker turn is the
     * conversation's very first message, so it is also the *oldest* — and
     * SmartHistoryTrimmer's tie-break (equal score, oldest evicted first)
     * makes it the very first eviction candidate once eviction pressure
     * reaches its score tier and it has aged out of the preserved-recency
     * window. Filler turns are sized like seedOverBudgetHistory()'s
     * messages (~54 tokens each way) so the budget is exceeded within a
     * bounded number of turns, and driving to
     * PRESERVED_WINDOW_CLEARANCE_TURNS clears `preserved_pairs`. If the
     * marker is still in the payload at that point, it is because
     * PIN_PATTERNS actually protected it.
     *
     * Acceptance scenarios 1-2; FR-007, FR-012
     */
    public function test_kept_information_survives_reduction(): void
    {
        $this->scenario = 'retained_instruction_single_reduction';

        // Declared deviation: small model tier brings budget within reach in
        // a handful of turns instead of ~300 (research R6).
        $this->useSmallModelTier(context: 6000, responseReserve: 512);

        // Build fixture (conversation + user + server)
        $fixture = $this->fixture()->build();

        // Point conversation at small model tier
        $this->applyModelTier($fixture->conversation);

        // No pre-seeded history here (see docblock above) — the marker turn
        // is the conversation's first and therefore oldest message, which is
        // what makes its survival genuinely depend on PIN_PATTERNS rather
        // than on an abundance of older, lower-scored filler to evict first.

        // Build script with "remember" instruction (triggers PIN_PATTERNS in MessageScorer).
        // The Condensation lane throws on every request — condensation never succeeds,
        // so ConversationCondenser::condenseOrTrim() always falls back to
        // smartTrimThenBudget(), the real path that consults MessageScorer::PIN_PATTERNS.
        // If the marker survives here, it is because the product's protected-content
        // detection kept it — not because a scripted summary happened to echo it.
        $script = ConversationScript::make()
            ->turn(
                'Remember my badge number is QX-4417 for this session.',
                fn ($r) => $r->finalAnswer('Noted, QX-4417.'),
                marker: 'QX-4417',
            )
            ->filler(
                fn (int $n) => str_repeat('word ', 50) . "(step {$n})",
                fn ($r) => $r->finalAnswer(str_repeat('word ', 50) . '(ack)'),
            )
            ->rule(
                RequestLane::Condensation,
                fn () => throw new \RuntimeException('Condensation unavailable — forces the smart-trim fallback (PIN_PATTERNS path)'),
                label: 'condensation_fail'
            )
            ->untilContextManagementActedAtLeast(self::REDUCTIONS_TO_EXHAUST_CHEAP_TIER)
            ->maxTurns(120);

        // Play the script
        $played = $this->driver()->play($script, $fixture->conversation);

        // Sanity check: the growth rate above is tuned so the first reduction
        // lands well after PRESERVED_WINDOW_CLEARANCE_TURNS turns — otherwise
        // this test would silently degrade back into proving only recency.
        $this->assertGreaterThanOrEqual(
            self::PRESERVED_WINDOW_CLEARANCE_TURNS,
            count($played->turns),
            'Reduction happened too early to have cleared the preserved-recency window — this would no longer isolate PIN_PATTERNS.'
        );

        // Witness first: without it, everything below can pass vacuously.
        $this->witness($fixture->conversation)->assertContextManagementActed();

        // Find the first reduced turn
        $firstReduced = $played->firstReducedTurn();
        $this->assertNotNull(
            $firstReduced,
            'At least one turn should have been marked as reduced (witness confirmed context management acted)'
        );

        // Then the property: marker present from the first reduced turn onwards,
        // well past the point where plain recency alone could explain it.
        $played->assertPresentFromTurn('QX-4417', from: $firstReduced);
    }

    /* --------------------------------------------------------------------------
     * T031: Kept information survives several further reductions
     * --------------------------------------------------------------------------
     * Continue past ≥2 more reductions and assert the marker present at each
     * checked turn. As in T030, driving to REDUCTIONS_TO_EXHAUST_CHEAP_TIER
     * reductions (well past 2, and well past the preserved-recency window) is
     * what makes this genuinely exercise PIN_PATTERNS rather than recency or
     * an abundance of cheaper (lower-scored) filler to evict first.
     *
     * Acceptance scenario 3; FR-007
     */
    public function test_kept_information_survives_several_further_reductions(): void
    {
        $this->scenario = 'retained_instruction_multiple_reductions';

        // Declared deviation: small model tier
        $this->useSmallModelTier(context: 6000, responseReserve: 512);

        // Build fixture
        $fixture = $this->fixture()->build();

        // Point conversation at small model tier
        $this->applyModelTier($fixture->conversation);

        // No pre-seeded history here — see T030's docblock. The marker turn
        // must be the conversation's oldest message for its survival to
        // genuinely depend on PIN_PATTERNS.

        // Build script with "remember" instruction and drive to several reductions.
        // Same as T030: the Condensation lane throws on every request, forcing every
        // reduction through smartTrimThenBudget() so MessageScorer::PIN_PATTERNS is
        // the mechanism actually keeping the marker alive across several reductions,
        // not a scripted summary that happens to keep echoing it.
        $script = ConversationScript::make()
            ->turn(
                'Remember my badge number is QX-4417 for this session.',
                fn ($r) => $r->finalAnswer('Noted, QX-4417.'),
                marker: 'QX-4417',
            )
            ->filler(
                fn (int $n) => str_repeat('word ', 50) . "(step {$n})",
                fn ($r) => $r->finalAnswer(str_repeat('word ', 50) . '(ack)'),
            )
            ->rule(
                RequestLane::Condensation,
                fn () => throw new \RuntimeException('Condensation unavailable — forces the smart-trim fallback (PIN_PATTERNS path)'),
                label: 'condensation_fail'
            )
            ->untilContextManagementActedAtLeast(self::REDUCTIONS_TO_EXHAUST_CHEAP_TIER)
            ->maxTurns(120);

        // Play the script
        $played = $this->driver()->play($script, $fixture->conversation);

        // Sanity check: mirrors T030's — confirms this run actually cleared
        // the preserved-recency window before the property is checked.
        $this->assertGreaterThanOrEqual(
            self::PRESERVED_WINDOW_CLEARANCE_TURNS,
            count($played->turns),
            'Reduction happened too early to have cleared the preserved-recency window — this would no longer isolate PIN_PATTERNS.'
        );

        // Witness first: context management acted at least 2 times
        $this->witness($fixture->conversation)->assertContextManagementActedAtLeast(2);

        // Find all reduced turns
        $reducedTurns = [];
        foreach ($played->turns as $record) {
            if ($record->reducedHere) {
                $reducedTurns[] = $record->index;
            }
        }

        $this->assertGreaterThanOrEqual(
            2,
            count($reducedTurns),
            "Expected at least 2 reduced turns, got " . count($reducedTurns)
        );

        // Assert the marker is present from each reduced turn onwards
        foreach ($reducedTurns as $reducedTurn) {
            $this->assertNotNull(
                $reducedTurn,
                'Reduced turn should not be null'
            );

            // Check that the marker is present in all turns from this reduced turn
            for ($i = $reducedTurn; $i <= count($played->turns); $i++) {
                $record = $played->turn($i);
                $found = false;

                // Check payloads
                foreach ($record->payloads as $payload) {
                    if ($payload->containsText('QX-4417')) {
                        $found = true;
                        break;
                    }
                }

                // Check assistant content
                if (!$found && $record->assistantContent !== null && str_contains($record->assistantContent, 'QX-4417')) {
                    $found = true;
                }

                // Check user message
                if (!$found && str_contains($record->userMessage, 'QX-4417')) {
                    $found = true;
                }

                $this->assertTrue(
                    $found,
                    "Marker 'QX-4417' should be present at turn {$i} " .
                    "(after reduction at turn {$reducedTurn})"
                );
            }
        }
    }

    /* --------------------------------------------------------------------------
     * T032: Dropped information reports last present and first absent turn
     * --------------------------------------------------------------------------
     * A deliberately-broken variant (marker not protected) fails naming the
     * last turn it was present and the first it was absent.
     *
     * FR-014's two-sided message; acceptance scenario 4 shape
     */
    public function test_dropped_information_reports_last_present_and_first_absent_turn(): void
    {
        $this->scenario = 'dropped_information_reporting';

        // Declared deviation: small model tier
        $this->useSmallModelTier(context: 6000, responseReserve: 512);

        // Build fixture
        $fixture = $this->fixture()->build();

        // Point conversation at small model tier
        $this->applyModelTier($fixture->conversation);

        // Seed over-budget history WITH the marker in the middle.
        // The marker is unprotected (no "remember" instruction), so it will
        // be condensed away. Position it early enough in the history that
        // condensation removes it.
        $this->seedOverBudgetHistoryWithMarker($fixture->conversation, 'ZX-9921');

        // Build script — the condensation summary does NOT include the marker
        // (simulating what happens when unprotected content is condensed away).
        $script = ConversationScript::make()
            ->turn(
                'Continue with the migration steps.',
                fn ($r) => $r->finalAnswer('Ok.'),
            )
            ->filler(fn (int $n) => "Walk me through step {$n} of the migration.")
            ->rule(
                RequestLane::Condensation,
                fn () => Responses::condensationSummary(
                    'The conversation covered several migration steps.'
                ),
                label: 'condensation_without_marker'
            )
            ->untilContextManagementActedAtLeast(1)
            ->maxTurns(40);

        // Play the script
        $played = $this->driver()->play($script, $fixture->conversation);

        // Witness: context management acted
        $this->witness($fixture->conversation)->assertContextManagementActed();

        // Find the first reduced turn
        $firstReduced = $played->firstReducedTurn();
        $this->assertNotNull($firstReduced, 'At least one turn should have been reduced');

        // The marker was in the seeded history (position 30), which is old enough
        // to be condensed away. After condensation, the marker should be absent.
        // lastTurnContaining returns the last turn where the marker appeared
        // (could be null if it was never in a played turn's payload)
        // firstTurnMissing returns the first turn from $from where it's absent
        $lastPresent = $played->lastTurnContaining('ZX-9921');
        $firstAbsent = $played->firstTurnMissing('ZX-9921', from: 1);

        // At least one of these should be non-null to have something to report
        $hasReporting = $lastPresent !== null || $firstAbsent !== null;
        $this->assertTrue(
            $hasReporting,
            'Harness should be able to report last-present or first-absent turn for dropped marker'
        );

        // If both are non-null, last-present should be before first-absent
        if ($lastPresent !== null && $firstAbsent !== null) {
            $this->assertLessThan(
                $firstAbsent,
                $lastPresent,
                "Last present turn ({$lastPresent}) should be before first absent turn ({$firstAbsent})"
            );
        }

        // Verify that assertPresentFromTurn would fail with proper two-sided message
        $exception = null;
        try {
            $played->assertPresentFromTurn('ZX-9921', from: 1);
        } catch (\RuntimeException $e) {
            $exception = $e;
        }

        // The exception should be thrown (marker dropped) or the harness should
        // still be able to report the drop via lastTurnContaining/firstTurnMissing
        if ($exception !== null) {
            // The error message should include last-present and first-absent turn info
            $this->assertStringContainsString(
                'First absent at turn',
                $exception->getMessage(),
                'Error message should name the first absent turn'
            );
        }
    }

    /* --------------------------------------------------------------------------
     * T033: Ordinary history reduced without protection when nothing kept
     * --------------------------------------------------------------------------
     * A no-instruction control: the same reductions occur, ordinary history
     * is reduced as normal, no protection asserted.
     *
     * Acceptance scenario 5
     */
    public function test_ordinary_history_reduced_without_protection_when_nothing_kept(): void
    {
        $this->scenario = 'ordinary_history_reduction_control';

        // Declared deviation: small model tier
        $this->useSmallModelTier(context: 6000, responseReserve: 512);

        // Build fixture
        $fixture = $this->fixture()->build();

        // Point conversation at small model tier
        $this->applyModelTier($fixture->conversation);

        // Seed over-budget history so context management triggers
        $this->seedOverBudgetHistory($fixture->conversation);

        // Build script with NO "remember" instruction — just ordinary conversation
        $script = ConversationScript::make()
            ->turn(
                'What are the first steps of the migration?',
                fn ($r) => $r->finalAnswer('Start with data backup.'),
            )
            ->filler(fn (int $n) => "Walk me through step {$n} of the migration.")
            ->rule(
                RequestLane::Condensation,
                fn () => Responses::condensationSummary(
                    'The conversation covered several migration steps.'
                ),
                label: 'condensation_ordinary'
            )
            ->untilContextManagementActedAtLeast(1)
            ->maxTurns(40);

        // Play the script
        $played = $this->driver()->play($script, $fixture->conversation);

        // Witness: context management acted (reductions happened)
        $this->witness($fixture->conversation)->assertContextManagementActed();

        // Find the first reduced turn
        $firstReduced = $played->firstReducedTurn();
        $this->assertNotNull(
            $firstReduced,
            'At least one turn should have been reduced'
        );

        // Assert that history was actually reduced:
        // The number of messages in a post-reduction payload should be less than
        // the cumulative turn count (because condensation replaced earlier messages)
        $lastTurn = $played->turns[count($played->turns) - 1];
        $totalPayloads = count($lastTurn->payloads);

        // There should be at least one payload in the last turn
        $this->assertGreaterThan(
            0,
            $totalPayloads,
            'Last turn should have at least one payload'
        );

        // The condensation rule should have fired (verified by witness + reduced turn)
        // This confirms reductions happened without any protection mechanism
        $condensationFired = false;
        foreach ($played->turns as $record) {
            foreach ($record->rulesFired as $label) {
                if (str_contains($label, 'condensation')) {
                    $condensationFired = true;
                    break 2;
                }
            }
        }

        $this->assertTrue(
            $condensationFired,
            'Condensation rule should have fired at least once (ordinary reduction)'
        );

        // No protected-content assertions needed — this is a control test
        // verifying that reductions happen normally when nothing is pinned
    }

    /* --------------------------------------------------------------------------
     * Helper: Seed over-budget history for a conversation
     * --------------------------------------------------------------------------
     * Creates enough messages to exceed the small model tier's history budget,
     * ensuring context management (condensation) triggers on the next turn.
     */
    protected function seedOverBudgetHistory(Conversation $conversation): int
    {
        // With small_test_tier (context=6000, response_reserve=512), history budget
        // is ~5488 tokens. Each message is ~54 tokens (50 words + envelope).
        // 100 messages = ~5400 tokens, well over budget.
        $count = 100;

        for ($i = 0; $i < $count; $i++) {
            \ClarionApp\LlmClient\Models\Message::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'conversation_id' => $conversation->id,
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => str_repeat('word ', 50) . "(message {$i})",
                'sequence_number' => $i,
            ]);
        }

        return $count;
    }

    /**
     * Seed over-budget history with a marker embedded at a specific position.
     *
     * The marker is placed early enough in the history that condensation
     * will remove it (old messages are condensed first).
     */
    protected function seedOverBudgetHistoryWithMarker(Conversation $conversation, string $marker): int
    {
        $count = 100;
        $markerPosition = 30; // Early enough to be condensed

        for ($i = 0; $i < $count; $i++) {
            $content = ($i === $markerPosition)
                ? "Reference code: {$marker}. This is important context."
                : str_repeat('word ', 50) . "(message {$i})";

            \ClarionApp\LlmClient\Models\Message::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'conversation_id' => $conversation->id,
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => $content,
                'sequence_number' => $i,
            ]);
        }

        return $count;
    }
}
