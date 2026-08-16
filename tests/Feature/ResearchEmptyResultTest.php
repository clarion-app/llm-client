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
}
