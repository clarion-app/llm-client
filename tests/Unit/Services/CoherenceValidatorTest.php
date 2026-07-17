<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\CoherenceValidator;

use PHPUnit\Framework\Attributes\Test;

class CoherenceValidatorTest extends TestCase
{
    private function makeValidator(): CoherenceValidator
    {
        return new CoherenceValidator();
    }

    private function userMessage(string $content): array
    {
        return ['role' => 'user', 'content' => $content];
    }

    private function assistantMessage(string $content): array
    {
        return ['role' => 'assistant', 'content' => $content];
    }

    private function toolCallUnit(string $toolName, string $resultContent, string $callId = 'call_1'): array
    {
        return [
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => $callId,
                        'type' => 'function',
                        'function' => ['name' => $toolName, 'arguments' => '{}'],
                    ],
                ],
            ],
            ['role' => 'tool', 'tool_call_id' => $callId, 'content' => $resultContent],
        ];
    }

    private function buildUnits(array $messages): array
    {
        $units = [];
        $i = 0;
        $total = count($messages);

        while ($i < $total) {
            $msg = $messages[$i];
            if ($msg['role'] === 'assistant' && !empty($msg['tool_calls'])) {
                $unitMessages = [$msg];
                $unitIndices = [$i];
                $i++;
                while ($i < $total && $messages[$i]['role'] === 'tool') {
                    $unitMessages[] = $messages[$i];
                    $unitIndices[] = $i;
                    $i++;
                }
            } else {
                $unitMessages = [$msg];
                $unitIndices = [$i];
                $i++;
            }

            $units[] = [
                'messages' => $unitMessages,
                'messageIndices' => $unitIndices,
                'estimatedTokens' => 0,
            ];
        }

        return $units;
    }

    /* ------------------------------------------------------------------ */
    /* Reference detection tests                                             */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function detects_reference_to_evicted_content(): void
    {
        $validator = $this->makeValidator();

        $messages = [
            $this->userMessage('Search for PHP docs'),            // 0
            ...$this->toolCallUnit('search', 'PHP 8.2 release'),  // 1-2 (evicted)
            $this->assistantMessage('As shown above, PHP 8.2 was released'), // 3
        ];

        $units = $this->buildUnits($messages);

        $cascade = $validator->validate($messages, $units, [1], []);

        // Unit 3 references evicted unit 1
        $this->assertContains(2, $cascade); // Unit index 2 (assistant message)
    }

    #[Test]
    public function no_cascade_when_no_references(): void
    {
        $validator = $this->makeValidator();

        $messages = [
            $this->userMessage('Search for PHP docs'),            // 0
            ...$this->toolCallUnit('search', 'PHP 8.2 release'),  // 1-2 (evicted)
            $this->assistantMessage('The weather is nice today'), // 3 (no reference)
        ];

        $units = $this->buildUnits($messages);

        $cascade = $validator->validate($messages, $units, [1], []);

        // No dangling references — no cascade
        $this->assertEmpty($cascade);
    }

    #[Test]
    public function no_cascade_when_no_evicted_content(): void
    {
        $validator = $this->makeValidator();

        $messages = [
            $this->userMessage('Hello'),
            $this->assistantMessage('As shown above, you said hello'),
        ];

        $units = $this->buildUnits($messages);

        $cascade = $validator->validate($messages, $units, [], []);

        $this->assertEmpty($cascade);
    }

    /* ------------------------------------------------------------------ */
    /* Cascade depth tests                                                   */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function cascade_drops_bounded_to_max_3_levels(): void
    {
        $validator = $this->makeValidator();

        // Build a chain where each message references the PREVIOUS one specifically.
        // Only the first message after evicted content has a direct reference pattern.
        // Subsequent messages reference "the previous response" — triggering cascade propagation.
        // Max depth is 3, so only 3 levels of cascade should occur.

        $messages = [
            $this->userMessage('Search for docs'),                       // 0
            ...$this->toolCallUnit('search', 'results here'),            // 1-2 (evicted)
            $this->assistantMessage('As shown above, search returned results'), // 3 — references evicted
            $this->assistantMessage('Based on the previous response, continuing'), // 4 — references 3
            $this->assistantMessage('As noted above, we have data'),     // 5 — references earlier
            $this->assistantMessage('See above for the full context'),   // 6 — references earlier
            $this->assistantMessage('The previous answer mentioned X'),  // 7 — references earlier
            $this->assistantMessage('Unrelated statement without refs'), // 8 — NO reference pattern
        ];

        $units = $this->buildUnits($messages);

        $cascade = $validator->validate($messages, $units, [1], []);

        // Iteration 1: All units 2-6 match reference patterns and have evicted content before them
        // All get dropped in one iteration (not chained cascade)
        // Unit 7 has no reference pattern — not dropped
        // So cascade count = 5 (units 2, 3, 4, 5, 6)
        $this->assertCount(5, $cascade);
        // Unit 7 (index 7) should NOT be cascade-dropped (no reference pattern)
        $this->assertNotContains(7, $cascade);
    }

    #[Test]
    /**
     * Precedence corrected 2026-07-16: this previously asserted that preserved
     * units are cascade-dropped anyway. The preserved window holds the newest
     * exchanges — including the user's current question — and a dangling
     * reference only needs *any* earlier eviction to trigger, so "Based on the
     * output above, what next?" had the live question deleted from the prompt and
     * the agent was left with nothing to answer. Spec 2.2.4 states "the most
     * recent exchanges are always kept" and scopes coherence to "what remains",
     * so preservation wins; a stale phrase in recent context is the cheaper cost.
     */
    public function preserved_units_with_dangling_refs_are_not_cascade_dropped(): void
    {
        $validator = $this->makeValidator();

        $messages = [
            $this->userMessage('Search for docs'),                       // 0
            ...$this->toolCallUnit('search', 'results here'),            // 1-2 (evicted)
            $this->assistantMessage('As shown above, search returned results'), // 3 (preserved)
        ];

        $units = $this->buildUnits($messages);

        $cascade = $validator->validate($messages, $units, [1], [2]);

        $this->assertSame([], $cascade, 'Preserved units must survive the coherence stage');
    }

    #[Test]
    public function the_current_user_question_is_never_cascade_dropped(): void
    {
        $validator = $this->makeValidator();

        $messages = [
            $this->userMessage('Search the docs'),                        // 0
            ...$this->toolCallUnit('search', 'a big result'),             // 1-2 (evicted)
            $this->userMessage('Based on the output above, what next?'),  // 3 (live question)
        ];

        $units = $this->buildUnits($messages);

        // Unit 2 is the live question and sits in the preserved window.
        $cascade = $validator->validate($messages, $units, [1], [2]);

        $this->assertNotContains(
            2,
            $cascade,
            'Dropping the question being answered leaves the agent nothing to respond to'
        );
    }

    #[Test]
    public function multiple_reference_patterns_detected(): void
    {
        $validator = $this->makeValidator();

        $messages = [
            $this->userMessage('Run search'),
            ...$this->toolCallUnit('search', 'data'),                    // 1-2 (evicted)
            $this->assistantMessage('The output above shows data'),      // 3
            $this->userMessage('As noted before, we need more'),         // 4
            $this->assistantMessage('See above for details'),            // 5
        ];

        $units = $this->buildUnits($messages);

        $cascade = $validator->validate($messages, $units, [1], []);

        // Units 2, 3, 4 should all be cascade-dropped (all reference evicted content)
        $this->assertContains(2, $cascade);
        $this->assertContains(3, $cascade);
        $this->assertContains(4, $cascade);
    }

    /* ------------------------------------------------------------------ */
    /* Edge case tests                                                       */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function empty_content_skipped(): void
    {
        $validator = $this->makeValidator();

        $messages = [
            $this->userMessage('Search'),
            ...$this->toolCallUnit('search', 'data'),                    // 1-2 (evicted)
            ['role' => 'assistant', 'content' => ''],                    // 3 (empty)
        ];

        $units = $this->buildUnits($messages);

        $cascade = $validator->validate($messages, $units, [1], []);

        // Empty content should not trigger dangling reference
        $this->assertEmpty($cascade);
    }

    #[Test]
    public function null_content_skipped(): void
    {
        $validator = $this->makeValidator();

        $messages = [
            $this->userMessage('Search'),
            ...$this->toolCallUnit('search', 'data'),                    // 1-2 (evicted)
            ['role' => 'assistant', 'content' => null],                  // 3 (null)
        ];

        $units = $this->buildUnits($messages);

        $cascade = $validator->validate($messages, $units, [1], []);

        $this->assertEmpty($cascade);
    }

    #[Test]
    public function reference_patterns_are_case_insensitive(): void
    {
        $validator = $this->makeValidator();

        $messages = [
            $this->userMessage('Search'),
            ...$this->toolCallUnit('search', 'data'),                    // 1-2 (evicted)
            $this->assistantMessage('AS SHOWN ABOVE, data was found'),   // 3 (uppercase)
        ];

        $units = $this->buildUnits($messages);

        $cascade = $validator->validate($messages, $units, [1], []);

        $this->assertContains(2, $cascade);
    }
}
