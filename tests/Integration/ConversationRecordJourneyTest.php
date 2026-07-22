<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\EpisodicMemory;
use ClarionApp\LlmClient\Services\ConversationLifecycleService;
use Tests\Integration\Harness\ConversationScript;
use Tests\Integration\Harness\PlayedConversation;
use Tests\Integration\Harness\RequestLane;
use Tests\Integration\Harness\Responses;
use Tests\Integration\Harness\TurnRecord;

/**
 * US4: The record of a finished conversation covers the whole conversation.
 *
 * Integration scenarios that verify the transcript handed to the summarizer
 * covers the whole conversation — not only its opening — including across a
 * conversation that ends more than once, where a stale prefix-covering record
 * must be regenerated rather than retained.
 *
 * These tests verify:
 * - A single ending's transcript covers both opening and final-third content (FR-008)
 * - Resuming and ending again regenerates the record over the full transcript (FR-008a)
 * - Resuming with nothing new correctly skips regeneration, not an error (FR-008a, edge case)
 * - A retained prefix-only record is detected and the missing region named (FR-008b, FR-014)
 *
 * No assertion in this file depends on the wording of the scripted summary text
 * (T047/FR-008b/SC-001a): every property check reads either the transcript body
 * *delivered to* the summarizer (PlayedConversation::summarizerTranscripts(), which
 * echoes the conversation's own message content — never the model's reply) or the
 * regeneration signal (BoundaryWitness::recordRegenerated(), which compares
 * word_count/updated_at, never the summary string).
 */
class ConversationRecordJourneyTest extends MultiTurnTestCase
{
    /* --------------------------------------------------------------------------
     * T043: A single ending's transcript covers opening and final-third content
     * --------------------------------------------------------------------------
     * Six explicit turns: a distinctive marker in turn 1 (opening) and another
     * in turn 6 (final third of six). Ended via ConversationLifecycleService::end()
     * (contract B4) — never by setting ended_at directly. The transcript actually
     * delivered to the summarizer must contain both.
     *
     * Acceptance scenario 1; FR-008
     */
    public function test_single_ending_transcript_covers_opening_and_final_third(): void
    {
        $this->scenario = 'single_ending_full_transcript';

        $fixture = $this->fixture()->build();

        $script = ConversationScript::make()
            ->turn(
                'Kickoff note: remember the reference code OPEN-8841 for this project.',
                fn ($r) => $r->finalAnswer('Noted.'),
                marker: 'OPEN-8841',
            )
            ->turn('Let\'s review the requirements document together.', fn ($r) => $r->finalAnswer('Sure, let\'s go through it.'))
            ->turn('Here are the timeline constraints we need to work within.', fn ($r) => $r->finalAnswer('Understood, I\'ll factor those in.'))
            ->turn('Now let\'s talk about the resourcing plan.', fn ($r) => $r->finalAnswer('Good, resourcing sounds reasonable.'))
            ->turn('One more thing before we wrap up.', fn ($r) => $r->finalAnswer('Go ahead.'))
            ->turn(
                'Final decision: the wrap-up code for this session is FINAL-3390.',
                fn ($r) => $r->finalAnswer('Recorded.'),
                marker: 'FINAL-3390',
            )
            ->rule(RequestLane::EpisodicSummary, fn () => Responses::episodicSummary(), label: 'episodic_summary_response')
            ->maxTurns(6);

        $played = $this->driver()->play($script, $fixture->conversation);

        $ended = $this->endConversationAndCapture($played, $fixture->conversation);
        $this->assertTrue($ended, 'end() should have ended a fresh conversation.');

        // Witness first: without it, "covers the whole conversation" could pass
        // vacuously because no record was ever captured at all.
        $this->assertTrue(
            $this->witness($fixture->conversation)->recordCaptured(),
            'INCONCLUSIVE: no EpisodicMemory record was captured for this conversation.'
        );

        // The property: the transcript actually sent to the summarizer — never
        // the summary text the scripted boundary happened to return — contains
        // both the opening and final-third markers.
        $this->assertRecordCoversRegions($played->summarizerTranscripts(), [
            'opening' => 'OPEN-8841',
            'final_third' => 'FINAL-3390',
        ]);
    }

    /* --------------------------------------------------------------------------
     * T044: Resuming and ending again regenerates the record over the full transcript
     * --------------------------------------------------------------------------
     * End once, resume (AgentLoopService::run() clears ended_at as a side effect
     * of processing the next turn — the product's own resume path, not a direct
     * ended_at write), add further distinctive content, end again. The record
     * must be regenerated (word_count/updated_at moved) and the new summarizer
     * transcript must contain content added after the first capture.
     *
     * Acceptance scenarios 2-3; FR-008a
     */
    public function test_resume_and_end_again_regenerates_record_over_full_transcript(): void
    {
        $this->scenario = 'resume_and_end_again_regenerates';

        $fixture = $this->fixture()->build();

        $openingScript = ConversationScript::make()
            ->turn(
                'Kickoff note: remember the reference code OPEN-5210 for this project.',
                fn ($r) => $r->finalAnswer('Noted.'),
                marker: 'OPEN-5210',
            )
            ->turn('Let\'s review the requirements together.', fn ($r) => $r->finalAnswer('Sure.'))
            ->turn('Here are some early notes on scope.', fn ($r) => $r->finalAnswer('Got it.'))
            ->rule(RequestLane::EpisodicSummary, fn () => Responses::episodicSummary(), label: 'episodic_summary_response')
            ->maxTurns(3);

        $played1 = $this->driver()->play($openingScript, $fixture->conversation);
        $this->endConversationAndCapture($played1, $fixture->conversation);

        $this->assertTrue(
            $this->witness($fixture->conversation)->recordCaptured(),
            'INCONCLUSIVE: no EpisodicMemory record was captured by the first end().'
        );

        // Snapshot the record as it stood right after the first capture — the
        // baseline recordRegenerated() compares against.
        $before = EpisodicMemory::where('conversation_id', $fixture->conversation->id)->first();
        $this->assertNotNull($before, 'A record must exist to snapshot before resuming.');

        $resumeScript = ConversationScript::make()
            ->turn('Picking back up where we left off.', fn ($r) => $r->finalAnswer('Welcome back.'))
            ->turn('Let\'s finalize the remaining details.', fn ($r) => $r->finalAnswer('Sounds good.'))
            ->turn(
                'Final decision: the new wrap-up code is FINAL-9187.',
                fn ($r) => $r->finalAnswer('Recorded.'),
                marker: 'FINAL-9187',
            )
            ->maxTurns(3);

        // Playing further turns is the resume: AgentLoopService::run() clears
        // ended_at (src/Services/AgentLoopService.php) exactly as it does for
        // any other turn — no direct ended_at write here (contract B4).
        $played2 = $this->driver()->play($resumeScript, $fixture->conversation);
        $this->assertNull(
            $fixture->conversation->refresh()->ended_at,
            'Playing a further turn should have cleared ended_at (the product\'s own resume path).'
        );

        $this->endConversationAndCapture($played2, $fixture->conversation);

        // Witness before property: without confirming regeneration actually
        // happened, "the new transcript contains post-first-capture content"
        // could pass vacuously against a record that was never touched again.
        $this->assertTrue(
            $this->witness($fixture->conversation)->recordRegenerated($before),
            'INCONCLUSIVE: the record was not regenerated after further turns were played and the conversation ended again.'
        );

        // The property: the regenerated transcript covers the whole
        // conversation — both the original opening content and the content
        // added after the first capture — not just the delta.
        $this->assertRecordCoversRegions($played2->summarizerTranscripts(), [
            'opening' => 'OPEN-5210',
            'post_first_capture' => 'FINAL-9187',
        ]);
    }

    /* --------------------------------------------------------------------------
     * T045: Resuming with nothing new correctly skips regeneration
     * --------------------------------------------------------------------------
     * Resume (ConversationLifecycleService::markActive() — the same "user
     * returned" signal the entry points send, without playing a turn that
     * would itself add new content) and end again with zero further turns.
     * Regeneration must be correctly skipped: no new boundary request, no
     * change to the stored record, and — critically — this is not a failure.
     *
     * Acceptance scenario 4; edge case ("must distinguish correctly skipped
     * from wrongly skipped while new content exists" — see T046 for the latter)
     */
    public function test_resume_with_nothing_new_correctly_skips_regeneration(): void
    {
        $this->scenario = 'resume_nothing_new_skips_regeneration';

        $fixture = $this->fixture()->build();

        $script = ConversationScript::make()
            ->turn(
                'Kickoff note: remember the reference code OPEN-2076 for this project.',
                fn ($r) => $r->finalAnswer('Noted.'),
                marker: 'OPEN-2076',
            )
            ->turn('Let\'s review the requirements together.', fn ($r) => $r->finalAnswer('Sure.'))
            ->turn('Here are some early notes on scope.', fn ($r) => $r->finalAnswer('Got it.'))
            ->rule(RequestLane::EpisodicSummary, fn () => Responses::episodicSummary(), label: 'episodic_summary_response')
            ->maxTurns(3);

        $played = $this->driver()->play($script, $fixture->conversation);
        $this->endConversationAndCapture($played, $fixture->conversation);

        $this->assertTrue(
            $this->witness($fixture->conversation)->recordCaptured(),
            'INCONCLUSIVE: no EpisodicMemory record was captured by the first end().'
        );

        $before = EpisodicMemory::where('conversation_id', $fixture->conversation->id)->first();
        $this->assertNotNull($before, 'A record must exist to snapshot before resuming.');

        // Resume without saying anything new. markActive() is the product's own
        // "the user is back" signal (ConversationLifecycleService::markActive());
        // no turn is played, so nothing has genuinely been said since capture.
        app(ConversationLifecycleService::class)->markActive($fixture->conversation);
        $this->assertNull(
            $fixture->conversation->refresh()->ended_at,
            'markActive() should have cleared ended_at.'
        );

        $payloadCountBeforeSecondEnd = count($this->getCapturedChatPayloads());
        $endedAgain = app(ConversationLifecycleService::class)->end($fixture->conversation);
        $payloadCountAfterSecondEnd = count($this->getCapturedChatPayloads());

        $this->assertTrue($endedAgain, 'The second end() call should succeed (ended_at was null after markActive()).');

        // Correctly skipped means no boundary request at all — the job's
        // word_count guard returned before ever reaching the summarizer.
        $this->assertSame(
            $payloadCountBeforeSecondEnd,
            $payloadCountAfterSecondEnd,
            'Skipping regeneration should mean no new request reached the model boundary.'
        );

        // Not a failure: the witness reports false plainly, no exception.
        $this->assertFalse(
            $this->witness($fixture->conversation)->recordRegenerated($before),
            'Regeneration should have been skipped — nothing was said since the record was captured.'
        );

        $this->assertTrue(
            $this->witness($fixture->conversation)->recordCaptured(),
            'The existing record should still be present after a correctly-skipped regeneration.'
        );
    }

    /* --------------------------------------------------------------------------
     * T046: A retained prefix-only record fails naming the missing region
     * --------------------------------------------------------------------------
     * Mirrors the quickstart mutation-procedure row for this story — "make the
     * existing-record check in GenerateEpisodicMemoryJob return unconditionally"
     * — but src/ may not be touched by this feature (plan, Complexity Tracking).
     *
     * Instead of mutating the guard, this reproduces the exact reachable defect
     * through a *data* precondition: after the first end() captures a real
     * record, its stored word_count is tampered upward (an EpisodicMemory row
     * with a wrong word_count is indistinguishable, to the guard, from a
     * genuinely-covering one — that is precisely the fragility the spec's
     * narrative describes). The real, unmutated
     * `$existing && (int) $existing->word_count >= $wordCount` guard in
     * src/Jobs/GenerateEpisodicMemoryJob.php then — correctly, given the data it
     * was handed — skips regeneration on the second end(), reproducing "a record
     * summarising only the conversation's opening survives as the authoritative
     * record of the whole thing" without a single line of src/ changing.
     *
     * The verification property under test is then exercised for real: the
     * suite's own coverage check (assertRecordCoversRegions(), the same helper
     * T043 uses to pass) is run against the transcript that was actually
     * captured — which is only the opening, because no second summarizer
     * request ever happened — and must fail, naming the missing region.
     *
     * Acceptance scenario 5; FR-008b, FR-014
     */
    public function test_prefix_only_record_fails_naming_missing_region(): void
    {
        $this->scenario = 'prefix_only_record_reports_missing_region';

        $fixture = $this->fixture()->build();

        $openingScript = ConversationScript::make()
            ->turn(
                'Kickoff note: remember the reference code OPEN-6614 for this project.',
                fn ($r) => $r->finalAnswer('Noted.'),
                marker: 'OPEN-6614',
            )
            ->turn('Let\'s review the requirements together.', fn ($r) => $r->finalAnswer('Sure.'))
            ->turn('Here are some early notes on scope.', fn ($r) => $r->finalAnswer('Got it.'))
            ->rule(RequestLane::EpisodicSummary, fn () => Responses::episodicSummary(), label: 'episodic_summary_response')
            ->maxTurns(3);

        $played1 = $this->driver()->play($openingScript, $fixture->conversation);
        $this->endConversationAndCapture($played1, $fixture->conversation);

        $this->assertTrue(
            $this->witness($fixture->conversation)->recordCaptured(),
            'INCONCLUSIVE: no EpisodicMemory record was captured by the first end().'
        );

        // Tamper with the stored word_count so the real, unmutated guard in
        // GenerateEpisodicMemoryJob will judge the record as already covering
        // more than any transcript this test could plausibly grow to — the
        // data-level equivalent of the guard being wrong, without touching src/.
        $firstRecord = EpisodicMemory::where('conversation_id', $fixture->conversation->id)->firstOrFail();
        $firstRecord->update(['word_count' => $firstRecord->word_count + 100000]);

        // Snapshot the (now-tampered) state immediately before the second
        // end() — this is what recordRegenerated() below measures against.
        $before = EpisodicMemory::where('conversation_id', $fixture->conversation->id)->firstOrFail();

        $resumeScript = ConversationScript::make()
            ->turn('Picking back up where we left off.', fn ($r) => $r->finalAnswer('Welcome back.'))
            ->turn('Let\'s finalize the remaining details.', fn ($r) => $r->finalAnswer('Sounds good.'))
            ->turn(
                'Final decision: the new wrap-up code is FINAL-4402.',
                fn ($r) => $r->finalAnswer('Recorded.'),
                marker: 'FINAL-4402',
            )
            ->maxTurns(3);

        $played2 = $this->driver()->play($resumeScript, $fixture->conversation);
        $this->endConversationAndCapture($played2, $fixture->conversation);

        // With the guard tricked into believing the record already covers
        // everything, the real code skips regeneration — reproducing the
        // reachable defect. This is a setup confirmation, not the property
        // under test.
        $this->assertFalse(
            $this->witness($fixture->conversation)->recordRegenerated($before),
            'Setup check: the tampered word_count should have made the real guard skip regeneration.'
        );
        $this->assertEmpty(
            $played2->summarizerTranscripts(),
            'Setup check: no second summarizer request should have been made — the record was retained, not regenerated.'
        );

        // The property under test: verification built from what actually
        // reached the summarizer (never the retained record's own summary
        // text) must fail, and must name the missing region.
        $transcripts = array_merge($played1->summarizerTranscripts(), $played2->summarizerTranscripts());

        $exception = null;
        try {
            $this->assertRecordCoversRegions($transcripts, [
                'opening' => 'OPEN-6614',
                'final_third' => 'FINAL-4402',
            ]);
        } catch (\RuntimeException $e) {
            $exception = $e;
        }

        $this->assertNotNull(
            $exception,
            'Verification should fail when the retained record covers only a prefix of the conversation.'
        );
        $this->assertStringContainsString(
            'final_third',
            $exception->getMessage(),
            'Failure should name the missing region.'
        );
    }

    /* --------------------------------------------------------------------------
     * Helpers
     * -------------------------------------------------------------------------- */

    /**
     * End a conversation through the product (ConversationLifecycleService::end()
     * — never a direct ended_at write, contract B4) and, since episodic capture
     * runs inline (queue.default=sync), fold any boundary request it triggered
     * into $played as a trailing synthetic turn. Without this,
     * PlayedConversation::summarizerTranscripts() — which only scans
     * $played->turns — would never see the EpisodicSummary-lane request, because
     * end() happens after driver()->play() has already returned.
     *
     * @return bool Whether this call actually ended the session (ConversationLifecycleService::end()'s own return value).
     */
    protected function endConversationAndCapture(PlayedConversation $played, Conversation $conversation): bool
    {
        $before = count($this->getCapturedChatPayloads());
        $ended = app(ConversationLifecycleService::class)->end($conversation);
        $newPayloads = array_slice($this->getCapturedChatPayloads(), $before);

        if (!empty($newPayloads)) {
            $played->turns[] = TurnRecord::completed(
                index: count($played->turns) + 1,
                userMessage: '[conversation ended]',
                payloads: $newPayloads,
                rulesFired: [],
                assistantContent: null,
            );
        }

        return $ended;
    }

    /**
     * Assert the transcript actually delivered to the summarizer — the last
     * EpisodicSummary-lane request body, never the summary text the scripted
     * boundary returned (contract S8, FR-008b) — contains every named region
     * marker. Fails naming every missing region (FR-014).
     *
     * @param list<string> $transcripts PlayedConversation::summarizerTranscripts()
     * @param array<string, string> $regionMarkers region label => marker text expected in that region
     */
    protected function assertRecordCoversRegions(array $transcripts, array $regionMarkers): void
    {
        if (empty($transcripts)) {
            throw new \RuntimeException(
                'No transcript was ever delivered to the summarizer — nothing to verify coverage against.'
            );
        }

        // The most recent capture is authoritative — a conversation can end
        // more than once, and only the latest transcript reflects the current
        // full conversation.
        $transcript = end($transcripts);

        $missingRegions = [];
        foreach ($regionMarkers as $region => $marker) {
            if (!str_contains($transcript, $marker)) {
                $missingRegions[] = $region;
            }
        }

        if (!empty($missingRegions)) {
            throw new \RuntimeException(
                'Captured record was built from a transcript missing region(s): ' .
                implode(', ', $missingRegions) . '.'
            );
        }
    }
}
