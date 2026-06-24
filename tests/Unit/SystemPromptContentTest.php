<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Test;

class SystemPromptContentTest extends TestCase
{
    protected string $prompt;

    protected function setUp(): void
    {
        parent::setUp();
        // Load config directly from PHP file (no Laravel app needed)
        $config = require __DIR__ . '/../../config/llm-client.php';
        $this->prompt = $config['agent_loop']['system_prompt'] ?? '';
    }

    // FR-001 — prompt instructs search_operations for task requests

    #[Test]
    public function prompt_contains_search_operations_instruction()
    {
        $this->assertNotEmpty($this->prompt, 'System prompt must not be empty');
        $this->assertStringContainsString('search_operations', $this->prompt);
    }

    // FR-002 — prompt instructs execute_operation after search

    #[Test]
    public function prompt_contains_execute_operation_instruction()
    {
        $this->assertStringContainsString('execute_operation', $this->prompt);
    }

    // FR-003 — prompt instructs list_applications for broad discovery

    #[Test]
    public function prompt_contains_list_applications_instruction()
    {
        $this->assertStringContainsString('list_applications', $this->prompt);
    }

    // FR-004 — prompt contains recovery instructions for empty search results

    #[Test]
    public function prompt_contains_empty_results_recovery()
    {
        $this->assertStringContainsString('no results', strtolower($this->prompt));
    }

    // FR-005 — prompt contains inline structured examples

    #[Test]
    public function prompt_contains_inline_example()
    {
        $this->assertStringContainsString('Example', $this->prompt);
    }

    // FR-007 — prompt does NOT contain specific API endpoint paths

    #[Test]
    public function prompt_does_not_contain_api_endpoint_paths()
    {
        $this->assertDoesNotMatchRegularExpression(
            '|/api/[\w/.-]+|',
            $this->prompt,
            'System prompt must not contain specific API endpoint paths'
        );
    }

    // FR-008 — prompt contains silent on success instruction

    #[Test]
    public function prompt_contains_silent_on_success_instruction()
    {
        $this->assertTrue(
            stripos($this->prompt, 'do not summarize') !== false ||
            stripos($this->prompt, 'no summarize') !== false ||
            stripos($this->prompt, 'silent') !== false,
            'System prompt must contain silent-on-success guidance'
        );
    }

    // FR-009 — prompt contains multi-operation sequential handling

    #[Test]
    public function prompt_contains_multi_operation_instruction()
    {
        $this->assertTrue(
            stripos($this->prompt, 'multi') !== false ||
            stripos($this->prompt, 'sequential') !== false ||
            stripos($this->prompt, 'chain') !== false,
            'System prompt must contain multi-operation handling guidance'
        );
    }

    // FR-010 — prompt contains index unavailable recovery

    #[Test]
    public function prompt_contains_index_unavailable_recovery()
    {
        $this->assertTrue(
            stripos($this->prompt, 'unavailable') !== false ||
            stripos($this->prompt, 'not available') !== false,
            'System prompt must contain index-unavailable recovery guidance'
        );
    }

    // FR-011 — prompt contains poor match retry instruction

    #[Test]
    public function prompt_contains_poor_match_retry()
    {
        $this->assertTrue(
            stripos($this->prompt, 'retry') !== false ||
            stripos($this->prompt, 'rephrased') !== false ||
            stripos($this->prompt, 'broader') !== false,
            'System prompt must contain poor-match retry guidance'
        );
    }

    /* ------------------------------------------------------------------ */
    /* New tests added by feature 025-agent-prompt-tool-selection          */
    /* ------------------------------------------------------------------ */

    // T004 [US1] — prompt contains direct execution rule for known operations

    #[Test]
    public function prompt_contains_direct_execution_rule()
    {
        $this->assertTrue(
            stripos($this->prompt, 'execute_operation directly') !== false ||
            stripos($this->prompt, 'direct execution') !== false,
            'System prompt must contain direct execution guidance for known operations'
        );
    }

    // T005 [US1] — prompt contains concrete example of direct execute flow with real operationId

    #[Test]
    public function prompt_contains_direct_execute_example()
    {
        $this->assertTrue(
            stripos($this->prompt, 'execute_operation') !== false &&
            (stripos($this->prompt, 'contacts.store') !== false ||
             stripos($this->prompt, 'weather.forecast') !== false),
            'System prompt must contain a concrete direct-execute example with a real operationId'
        );
    }

    // T008a [Edge Case] — prompt contains ambiguity handling rule

    #[Test]
    public function prompt_contains_ambiguity_handling_rule()
    {
        $this->assertTrue(
            stripos($this->prompt, 'clarif') !== false &&
            stripos($this->prompt, 'multiple') !== false,
            'System prompt must contain ambiguity handling when multiple operations match'
        );
    }

    // T009 [US2] — prompt contains fallback to search_operations for unknown operations

    #[Test]
    public function prompt_contains_search_fallback_rule()
    {
        $this->assertTrue(
            stripos($this->prompt, 'search_operations') !== false &&
            (stripos($this->prompt, 'fallback') !== false ||
             stripos($this->prompt, 'otherwise') !== false ||
             stripos($this->prompt, 'if no') !== false ||
             stripos($this->prompt, 'if the request does not match') !== false),
            'System prompt must contain fallback to search_operations for unknown operations'
        );
    }

    // T009a [US2] — prompt contains preference for known operations over search (FR-003 coverage)

    #[Test]
    public function prompt_contains_prefer_known_operations()
    {
        $this->assertTrue(
            stripos($this->prompt, 'prefer') !== false ||
            stripos($this->prompt, 'reduce latenc') !== false ||
            stripos($this->prompt, 'skip search') !== false,
            'System prompt must indicate preference for known operations over search (latency reduction)'
        );
    }

    // T013 [US3] — prompt still contains list_applications (capability discovery preserved)

    #[Test]
    public function prompt_preserves_list_applications_rule()
    {
        $this->assertStringContainsString('list_applications', $this->prompt);
    }

    // T014 [US3] — prompt still contains Recovery Rules section

    #[Test]
    public function prompt_preserves_recovery_rules_section()
    {
        $this->assertStringContainsString('Recovery Rules', $this->prompt);
    }

    // T015 [US3] — prompt still contains Response Style section

    #[Test]
    public function prompt_preserves_response_style_section()
    {
        $this->assertStringContainsString('Response Style', $this->prompt);
    }

    // T016 [US3] — prompt still contains search-then-execute example

    #[Test]
    public function prompt_preserves_search_then_execute_example()
    {
        $this->assertTrue(
            stripos($this->prompt, 'search-then-execute') !== false ||
            (stripos($this->prompt, 'search_operations') !== false &&
             stripos($this->prompt, 'Example') !== false),
            'System prompt must still contain search-then-execute example'
        );
    }
}
