<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use PHPUnit\Framework\TestCase;

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

    /** @test FR-001 — prompt instructs search_operations for task requests */
    public function prompt_contains_search_operations_instruction()
    {
        $this->assertNotEmpty($this->prompt, 'System prompt must not be empty');
        $this->assertStringContainsString('search_operations', $this->prompt);
    }

    /** @test FR-002 — prompt instructs execute_operation after search */
    public function prompt_contains_execute_operation_instruction()
    {
        $this->assertStringContainsString('execute_operation', $this->prompt);
    }

    /** @test FR-003 — prompt instructs list_applications for broad discovery */
    public function prompt_contains_list_applications_instruction()
    {
        $this->assertStringContainsString('list_applications', $this->prompt);
    }

    /** @test FR-004 — prompt contains recovery instructions for empty search results */
    public function prompt_contains_empty_results_recovery()
    {
        $this->assertStringContainsString('no results', strtolower($this->prompt));
    }

    /** @test FR-005 — prompt contains inline structured examples */
    public function prompt_contains_inline_example()
    {
        $this->assertStringContainsString('Example', $this->prompt);
    }

    /** @test FR-007 — prompt does NOT contain specific API endpoint paths */
    public function prompt_does_not_contain_api_endpoint_paths()
    {
        $this->assertDoesNotMatchRegularExpression(
            '|/api/[\w/.-]+|',
            $this->prompt,
            'System prompt must not contain specific API endpoint paths'
        );
    }

    /** @test FR-008 — prompt contains silent on success instruction */
    public function prompt_contains_silent_on_success_instruction()
    {
        $this->assertTrue(
            stripos($this->prompt, 'do not summarize') !== false ||
            stripos($this->prompt, 'no summarize') !== false ||
            stripos($this->prompt, 'silent') !== false,
            'System prompt must contain silent-on-success guidance'
        );
    }

    /** @test FR-009 — prompt contains multi-operation sequential handling */
    public function prompt_contains_multi_operation_instruction()
    {
        $this->assertTrue(
            stripos($this->prompt, 'multi') !== false ||
            stripos($this->prompt, 'sequential') !== false ||
            stripos($this->prompt, 'chain') !== false,
            'System prompt must contain multi-operation handling guidance'
        );
    }

    /** @test FR-010 — prompt contains index unavailable recovery */
    public function prompt_contains_index_unavailable_recovery()
    {
        $this->assertTrue(
            stripos($this->prompt, 'unavailable') !== false ||
            stripos($this->prompt, 'not available') !== false,
            'System prompt must contain index-unavailable recovery guidance'
        );
    }

    /** @test FR-011 — prompt contains poor match retry instruction */
    public function prompt_contains_poor_match_retry()
    {
        $this->assertTrue(
            stripos($this->prompt, 'retry') !== false ||
            stripos($this->prompt, 'rephrased') !== false ||
            stripos($this->prompt, 'broader') !== false,
            'System prompt must contain poor-match retry guidance'
        );
    }
}
