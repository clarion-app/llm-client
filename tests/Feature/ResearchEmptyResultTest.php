<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\LlmClient\Services\AgentLoopService;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * US2 (P1) — the agent is plain about what it could not find (FR-005/006/007).
 *
 * The outcomes are statements in the answer text (data-model.md §5), driven by
 * the template instructions; the envelope/flow supplies the signals the agent
 * relies on to tell them apart. This is a confirm-or-fix phase: the template
 * (T005) already carries the honest-limits wording and the distinct
 * retrieval_failed vs nothing_usable outcomes, so these tests prove the
 * guarantee holds by construction and would go red if the wording regressed.
 */
class ResearchEmptyResultTest extends TestCase
{
    private function templateInstructions(): string
    {
        $path = __DIR__ . '/../../src/Templates/research.yaml';

        return (string) (Yaml::parseFile($path)['instructions'] ?? '');
    }

    #[Test]
    public function retrieval_failed_and_nothing_usable_are_distinct_outcomes(): void
    {
        $instructions = $this->templateInstructions();

        // Both outcomes must be named in the closed outcome vocabulary.
        $this->assertStringContainsString('retrieval_failed', $instructions);
        $this->assertStringContainsString('nothing_usable', $instructions);

        // They must be triggered by distinct conditions, not conflated: a fetch
        // that errored / was blocked / was unusable (retrieval_failed) versus a
        // fetch that worked but yielded nothing relevant (nothing_usable).
        $this->assertMatchesRegularExpression(
            '/retrieval_failed:.*?(errored|blocked|unusable)/s',
            $instructions,
        );
        $this->assertMatchesRegularExpression(
            '/nothing_usable:.*?(worked|nothing relevant|yielded nothing)/s',
            $instructions,
        );
    }

    #[Test]
    public function general_knowledge_is_never_presented_as_a_sourced_citation(): void
    {
        // FR-007: no outcome may present general knowledge as retrieved research.
        $instructions = $this->templateInstructions();

        $this->assertMatchesRegularExpression(
            '/general knowledge.*?(as if it came from a source|inference|never as research)/is',
            $instructions,
        );
    }

    #[Test]
    public function an_ambiguous_question_is_answered_by_stating_the_reading_chosen(): void
    {
        // Spec edge case: the agent researches the most reasonable reading of an
        // ambiguous question and states that reading in its answer, so a user who
        // meant something else can see the mismatch and re-ask. The contract
        // (contracts/research-agent-template.md) carries this as an explicit
        // template instruction; the implemented template must carry it too.
        $instructions = $this->templateInstructions();

        $this->assertMatchesRegularExpression(
            '/ambiguous.*?state the reading.*?proceed/is',
            $instructions,
        );
    }

    #[Test]
    public function an_empty_fetch_yields_a_source_envelope_with_nothing_usable_to_cite(): void
    {
        Cache::flush();

        $url = 'https://example.com/empty';
        $envelope = AgentLoopService::buildPageTextEnvelope($url, null, '', 'conv-empty');

        // The source is the URL actually fetched — never fabricated for a page
        // that had nothing usable on it ...
        $this->assertSame($url, $envelope['source']['url']);

        // ... and the content is the untrusted block wrapping the (empty) body:
        // there is no substantive content to cite, which is the nothing_usable
        // signal, distinct from a synthesized answer that carries content.
        $this->assertStringContainsString('--- BEGIN RESPONSE UNDER EVALUATION ---', $envelope['content']);
        $this->assertStringContainsString('--- END RESPONSE UNDER EVALUATION ---', $envelope['content']);
    }

    /**
     * US6 (P3) — an honest statement of limits (FR-012/FR-013).
     *
     * A question that needs a paid or credentialed source is "out of reach" —
     * a distinct, named outcome — not "nothing found". The agent must say
     * plainly that the source is out of its reach and why, rather than fabricate
     * or silently degrade the answer.
     */
    #[Test]
    public function out_of_reach_is_a_named_outcome_for_paid_or_credentialed_sources(): void
    {
        $instructions = $this->templateInstructions();

        // out_of_reach is a named outcome in the closed vocabulary ...
        $this->assertStringContainsString('out_of_reach', $instructions);

        // ... and it is the outcome for a question that needs a paid/credentialed
        // source the agent does not consult (FR-013) ...
        $this->assertMatchesRegularExpression(
            '/out_of_reach:.*?paid\/credentialed source/is',
            $instructions,
            'out_of_reach must trigger when the question needs a paid/credentialed source',
        );

        // ... and the instructions state the agent cannot reach paid,
        // credentialed, or otherwise restricted sources (FR-013) ...
        $this->assertMatchesRegularExpression(
            '/paid, credentialed, or otherwise restricted sources/is',
            $instructions,
        );

        // ... and, when a question needs one, say plainly that the source is out
        // of its reach and why (FR-012).
        $this->assertMatchesRegularExpression(
            '/say plainly that the source is out of your reach and\s+why/is',
            $instructions,
        );
    }

    /**
     * US6 (P3) — a request to act is declined, staying read-only (FR-014).
     *
     * When asked to act on what it finds, the agent states that acting is
     * outside its purpose and performs no action. The read-only posture is
     * stated in the template, and out_of_reach is the named outcome for the
     * "asks you to act" case.
     */
    #[Test]
    public function a_request_to_act_is_declined_staying_read_only(): void
    {
        $instructions = $this->templateInstructions();

        // The agent only reads and never changes anything ...
        $this->assertMatchesRegularExpression(
            '/You only read\./is',
            $instructions,
        );

        // ... and does not perform actions on the user's behalf (FR-014) ...
        $this->assertMatchesRegularExpression(
            "/do not\s+perform actions on the user's behalf/is",
            $instructions,
        );

        // ... and out_of_reach is the named outcome when the question asks the
        // agent to act (FR-014) — the acting part is declined with a statement
        // of the limit, not silently dropped.
        $this->assertMatchesRegularExpression(
            '/out_of_reach:.*?asks you to act/is',
            $instructions,
        );
    }
}
