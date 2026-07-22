<?php

namespace Tests\Integration;

use Carbon\Carbon;
use ClarionApp\LlmClient\Events\ConversationEnded;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\ConversationLifecycleService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Integration\Harness\ConversationScript;
use Tests\Integration\Harness\RequestLane;
use Tests\Integration\Harness\ResponseScript;
use Tests\Integration\Harness\Responses;

/**
 * Phase 7 (Boundary, roadmap): the turn boundary and the conversation
 * (session) boundary are asserted against different artifacts, per contract
 * session-boundary.md B1-B6 — fixing the 2026-07-16 conflation.
 *
 * | | Turn boundary | Conversation (session) boundary |
 * |---|---|---|
 * | Caused by | one run()/start()+finish() completing | ConversationLifecycleService::end() (explicit or idle sweep) |
 * | Event | AgentTurnCompleted | ConversationEnded |
 * | Product effect | scratch-scope memory for that turn_id deleted | short-term (session) memory deleted; episodic capture dispatched |
 * | Persisted marker | none | conversations.ended_at set |
 *
 * The write mechanism for both scopes is the real memory_create meta-tool
 * (AgentLoopService::executeMetaTool()/handleMemoryCreate()) — the same path
 * a real model uses. AgentTurnCompleted fires with turn_id = the current
 * tool-call iteration inside AgentLoopService::run() (it resets to "1" at
 * the start of every run() call), whenever a round of tool calls is not
 * entirely successful execute_operation calls (src/Services/AgentLoopService.php,
 * confirmed by WiringRegressionTest::test_two_turns_fire_agent_turn_completed_not_conversation_ended).
 * A memory_create call is exactly such a round, so scripting one is how
 * these scenarios observe the turn boundary for real, without dispatching
 * any event by hand (contract B4).
 *
 * These tests verify:
 * - Session-scoped memory survives past a reduction boundary and is gone
 *   only after end() — the two-sided claim (B2; FR-001, FR-004)
 * - Scratch-scope memory is gone once its turn completes, independent of
 *   the session ending (B3)
 * - The real idle sweep — never a hand-dispatched event — ends the session
 *   and clears short-term memory (B4)
 * - Ending more than once is idempotent while ended_at is set, clears
 *   ended_at between ends, and fires exactly one ConversationEnded per
 *   ending (B5)
 */
class SessionBoundaryJourneyTest extends MultiTurnTestCase
{
    /* --------------------------------------------------------------------------
     * T050: Session-scoped entry survives later turns and is gone after end()
     * --------------------------------------------------------------------------
     * The B2 two-sided claim in one test: an entry written at turn 2 is still
     * present after turn N, for N well past a reduction boundary (half one);
     * then, after ConversationLifecycleService::end() (contract B4 — never a
     * direct ended_at write), it is gone (half two). Half one alone would pass
     * against a product that wipes on the wrong boundary at turn 1; half two
     * alone would pass against a product that wipes on every turn. Only the
     * pair distinguishes correct behaviour from the 2026-07-16 defect.
     *
     * FR-001, FR-004
     */
    public function test_session_scoped_entry_survives_later_turns_and_is_gone_after_end(): void
    {
        $this->scenario = 'session_scoped_entry_survives_and_ends';

        // Declared deviation: a small model tier brings the reduction
        // boundary within a handful of filler turns instead of ~300
        // (research R6), matching the pattern LongConversationJourneyTest/
        // RetainedInstructionJourneyTest already established for this suite.
        $this->useSmallModelTier(context: 6000, responseReserve: 512);

        $fixture = $this->fixture()->build();
        $this->applyModelTier($fixture->conversation);
        $this->seedOverBudgetHistory($fixture->conversation);

        $script = ConversationScript::make()
            ->turn(
                'Just checking in before we get started.',
                fn (ResponseScript $r) => $r->finalAnswer('Ready when you are.'),
            )
            ->turn(
                'Please keep this for the rest of our session: SESSION-QX-7734.',
                fn (ResponseScript $r) => $r->toolRequest('memory_create', [
                    'scope' => 'short_term',
                    'key' => 'session-marker',
                    'content' => 'SESSION-QX-7734',
                ])->finalAnswer('Stored for this session.'),
                marker: 'SESSION-QX-7734',
            )
            ->filler(fn (int $n) => "Walk me through step {$n} of the migration.")
            ->rule(RequestLane::Condensation, fn () => Responses::condensationSummary(), label: 'condensation_response')
            ->rule(RequestLane::EpisodicSummary, fn () => Responses::episodicSummary(), label: 'episodic_summary_response')
            // seedOverBudgetHistory() already puts turn 1 over budget, so the
            // first reduction happens at turn 1 — before the marker is even
            // written. Driving to >=2 reductions guarantees turn 2 (the
            // marker write) actually plays, and that at least one more
            // reduction happens strictly after it.
            ->untilContextManagementActedAtLeast(2)
            ->maxTurns(40);

        $played = $this->driver()->play($script, $fixture->conversation);

        // Witness first: without it, "survives" could pass vacuously because
        // context management never acted at all.
        $this->witness($fixture->conversation)->assertContextManagementActedAtLeast(2);
        $this->assertNotNull($played->firstReducedTurn(), 'At least one turn should have been marked as reduced.');

        // Half one (B2): the session-scoped entry, written at turn 2, is
        // still present in the product's own artifact well past the
        // reduction boundary — read directly from storage, not inferred
        // from any model payload.
        $entries = $this->sessionArtifacts($fixture->conversation)->shortTermEntries();
        $this->assertTrue(
            $entries->pluck('content')->contains('SESSION-QX-7734'),
            'Session-scoped entry should survive turns played past the reduction boundary.'
        );

        // Half two (B2): after the session ends through the product (B4 —
        // ConversationLifecycleService::end(), never a direct ended_at
        // write or a hand-dispatched ConversationEnded), it is gone.
        $ended = app(ConversationLifecycleService::class)->end($fixture->conversation);
        $this->assertTrue($ended, 'end() should have ended a fresh, unended session.');

        $this->assertTrue(
            $this->sessionArtifacts($fixture->conversation)->shortTermEntries()->isEmpty(),
            'Session-scoped entries should be gone once the session has ended.'
        );
    }

    /* --------------------------------------------------------------------------
     * T051: Scratch entry is gone at turn end, independent of session end
     * --------------------------------------------------------------------------
     * B3: scratch entries written during turn N must be absent once turn N
     * completes, and their disappearance must not depend on the session
     * ending. The turn writes two scratch entries in the same tool-call
     * round — one under the turn_id that will match this turn's
     * AgentTurnCompleted, one under an unrelated turn_id — so the assertion
     * is non-vacuous: the cleanup is shown to target the completed turn's
     * own entries, not every scratch row indiscriminately.
     */
    public function test_scratch_entry_is_gone_at_turn_end_independent_of_session_end(): void
    {
        $this->scenario = 'scratch_entry_gone_at_turn_end';

        $fixture = $this->fixture()->build();

        $script = ConversationScript::make()
            ->turn(
                'Jot a quick scratch note for just this step.',
                function (ResponseScript $r) {
                    $r->steps[] = $this->multiToolCallStep([
                        ['name' => 'memory_create', 'arguments' => [
                            'scope' => 'scratch',
                            'content' => 'ephemeral-note',
                            'turn_id' => '1',
                        ]],
                        ['name' => 'memory_create', 'arguments' => [
                            'scope' => 'scratch',
                            'content' => 'control-note',
                            'turn_id' => 'control-turn',
                        ]],
                    ]);
                    $r->finalAnswer('Noted for this step only.');
                },
            )
            ->maxTurns(1);

        $played = $this->driver()->play($script, $fixture->conversation);

        $this->assertSame(
            'completed',
            $played->turn(1)->status,
            'The turn writing scratch memory should complete normally.'
        );

        // B3: scratch entries under the turn_id that matches this turn's own
        // AgentTurnCompleted are gone once the turn completes.
        $this->assertTrue(
            $this->sessionArtifacts($fixture->conversation)->scratchEntries('1')->isEmpty(),
            'Scratch entries for this turn should be cleared once the turn completes.'
        );

        // Non-vacuous: a scratch entry created in the same tool round under a
        // different turn_id was left alone — the cleanup targeted this
        // turn's entries specifically, it did not wipe every scratch row.
        $this->assertFalse(
            $this->sessionArtifacts($fixture->conversation)->scratchEntries('control-turn')->isEmpty(),
            'A scratch entry under a different turn_id should be unaffected by this turn ending.'
        );

        // B3: this cleanup did not depend on the session ending — the
        // session is still open.
        $this->assertNull(
            $this->sessionArtifacts($fixture->conversation)->endedAt(),
            'Scratch cleanup should not depend on the session having ended.'
        );
    }

    /* --------------------------------------------------------------------------
     * T052: The real idle sweep ends the session and clears short-term memory
     * --------------------------------------------------------------------------
     * Carbon::setTestNow() pushes the clock past the idle timeout, then the
     * real sweep entry point — ConversationLifecycleService::endIdleConversations()
     * — is called. No ConversationEnded is ever dispatched by hand (B4).
     */
    public function test_real_idle_sweep_ends_the_session_and_clears_short_term_memory(): void
    {
        $this->scenario = 'idle_sweep_ends_session';

        $fixture = $this->fixture()->build();

        $script = ConversationScript::make()
            ->turn(
                'Please keep this for later in the session: IDLE-QX-1122.',
                fn (ResponseScript $r) => $r->toolRequest('memory_create', [
                    'scope' => 'short_term',
                    'key' => 'idle-marker',
                    'content' => 'IDLE-QX-1122',
                ])->finalAnswer('Stored.'),
                marker: 'IDLE-QX-1122',
            )
            ->rule(RequestLane::EpisodicSummary, fn () => Responses::episodicSummary(), label: 'episodic_summary_response')
            ->maxTurns(1);

        $this->driver()->play($script, $fixture->conversation);

        // Setup check: the write happened and the session has not ended yet.
        $entries = $this->sessionArtifacts($fixture->conversation)->shortTermEntries();
        $this->assertTrue(
            $entries->pluck('content')->contains('IDLE-QX-1122'),
            'Setup check: short-term entry should exist before the idle sweep.'
        );
        $this->assertNull(
            $this->sessionArtifacts($fixture->conversation)->endedAt(),
            'Setup check: conversation should not be ended yet.'
        );

        // B4: end through the real idle sweep — never a hand-dispatched
        // ConversationEnded and never a direct ended_at write.
        Carbon::setTestNow(now()->addMinutes(31));
        $endedCount = app(ConversationLifecycleService::class)->endIdleConversations();
        Carbon::setTestNow(null);

        $this->assertGreaterThanOrEqual(
            1,
            $endedCount,
            'The idle sweep should have ended at least this conversation.'
        );
        $this->assertNotNull(
            $this->sessionArtifacts($fixture->conversation)->endedAt(),
            'ended_at should be set by the idle sweep.'
        );
        $this->assertTrue(
            $this->sessionArtifacts($fixture->conversation)->shortTermEntries()->isEmpty(),
            'Short-term memory should be cleared once the idle sweep ends the session.'
        );
    }

    /* --------------------------------------------------------------------------
     * T053: Ending more than once clears ended_at between and fires one
     * ConversationEnded per ending
     * --------------------------------------------------------------------------
     * B5: end() is idempotent while ended_at is set, and both entry points
     * clear ended_at when the user returns. end, resume, end again — all
     * through the product — must fire exactly one ConversationEnded per
     * ending and clear ended_at in between.
     *
     * Event::fake([ConversationEnded::class]) is scoped to this one event
     * class (never a blanket Event::fake(), never Queue::fake()/
     * Queue::assertPushed() — contract B6/FR-017). ended_at itself is set
     * and cleared by direct model writes inside ConversationLifecycleService
     * and AgentLoopService (unaffected by the fake), so that half of the
     * claim is still exercised for real; only the ConversationEnded
     * listeners' side effects (short-term cleanup, episodic capture — both
     * already covered by T050 and ConversationRecordJourneyTest
     * respectively) are not re-verified in this test.
     */
    public function test_ending_more_than_once_clears_ended_at_between_and_fires_one_conversation_ended_per_ending(): void
    {
        $this->scenario = 'end_resume_end_again';

        $fixture = $this->fixture()->build();

        Event::fake([ConversationEnded::class]);

        $firstScript = ConversationScript::make()
            ->turn('First exchange before ending.', fn (ResponseScript $r) => $r->finalAnswer('Sure.'))
            ->maxTurns(1);

        $this->driver()->play($firstScript, $fixture->conversation);

        // First end, through the product (B4).
        $endedFirst = app(ConversationLifecycleService::class)->end($fixture->conversation);
        $this->assertTrue($endedFirst, 'First end() call should succeed.');
        $this->assertNotNull(
            $this->sessionArtifacts($fixture->conversation)->endedAt(),
            'ended_at should be set after the first end().'
        );
        Event::assertDispatchedTimes(ConversationEnded::class, 1);

        // Idempotency (B5): ending again while already ended is a no-op —
        // no second event for the same ending.
        $noopEnd = app(ConversationLifecycleService::class)->end($fixture->conversation);
        $this->assertFalse($noopEnd, 'Calling end() while already ended should be a no-op.');
        Event::assertDispatchedTimes(ConversationEnded::class, 1);

        // Resume: play another turn through the real product entry point,
        // which clears ended_at as a side effect of processing (contract B4
        // — never a direct write).
        $resumeScript = ConversationScript::make()
            ->turn('Picking back up after the first end.', fn (ResponseScript $r) => $r->finalAnswer('Welcome back.'))
            ->maxTurns(1);

        $this->driver()->play($resumeScript, $fixture->conversation);

        $this->assertNull(
            $fixture->conversation->refresh()->ended_at,
            'Resuming (playing a further turn) should have cleared ended_at.'
        );

        // Second end.
        $endedSecond = app(ConversationLifecycleService::class)->end($fixture->conversation);
        $this->assertTrue($endedSecond, 'Second end() call should succeed after resuming.');
        $this->assertNotNull(
            $this->sessionArtifacts($fixture->conversation)->endedAt(),
            'ended_at should be set again after the second end().'
        );

        // B5: exactly one ConversationEnded per ending — two endings, two
        // events total (the idempotent no-op above added none).
        Event::assertDispatchedTimes(ConversationEnded::class, 2);
    }

    /* --------------------------------------------------------------------------
     * Helpers
     * -------------------------------------------------------------------------- */

    /**
     * Seed enough history to exceed the small model tier's history budget,
     * so context management (condensation) triggers on a later turn. Same
     * shape RetainedInstructionJourneyTest/LongConversationJourneyTest use.
     */
    protected function seedOverBudgetHistory(Conversation $conversation): int
    {
        $count = 100;

        for ($i = 0; $i < $count; $i++) {
            Message::create([
                'id' => (string) Str::uuid(),
                'conversation_id' => $conversation->id,
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => str_repeat('word ', 50) . "(message {$i})",
                'sequence_number' => $i,
            ]);
        }

        return $count;
    }

    /**
     * Build a wire-shaped (OpenAI chat completion) step carrying more than
     * one tool call in a single assistant turn — the same shape
     * ResponseScript::toolRequest() produces, extended to N calls, so a
     * turn can exercise AgentLoopService's real per-tool-call loop
     * (src/Services/AgentLoopService.php, `foreach ($toolCalls as $toolCall)`)
     * for two memory_create calls at once (T051).
     *
     * @param list<array{name: string, arguments: array<string, mixed>}> $calls
     * @return array<string, mixed>
     */
    protected function multiToolCallStep(array $calls): array
    {
        $toolCalls = [];
        foreach ($calls as $call) {
            $toolCalls[] = [
                'id' => 'call_' . bin2hex(random_bytes(8)),
                'type' => 'function',
                'function' => [
                    'name' => $call['name'],
                    'arguments' => json_encode($call['arguments'] ?? []),
                ],
            ];
        }

        return [
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'tool_calls' => $toolCalls,
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
        ];
    }
}
