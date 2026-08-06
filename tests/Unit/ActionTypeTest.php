<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\ValueObjects\ActionType;
use Tests\TestCase;

class ActionTypeTest extends TestCase
{
    /** @test */
    public function has_llm_request_case(): void
    {
        $this->assertEquals('llm_request', ActionType::LlmRequest->value);
    }

    /** @test */
    public function has_tool_invocation_case(): void
    {
        $this->assertEquals('tool_invocation', ActionType::ToolInvocation->value);
    }

    /** @test */
    public function has_context_reshape_case(): void
    {
        $this->assertEquals('context_reshape', ActionType::ContextReshape->value);
    }

    /** @test */
    public function has_exactly_three_cases(): void
    {
        $cases = ActionType::cases();
        $this->assertCount(3, $cases);
    }

    /** @test */
    public function cases_are_backed_by_string_values(): void
    {
        $this->assertIsString(ActionType::LlmRequest->value);
        $this->assertIsString(ActionType::ToolInvocation->value);
        $this->assertIsString(ActionType::ContextReshape->value);
    }

    /** @test */
    public function from_string_value_returns_correct_case(): void
    {
        $this->assertSame(ActionType::LlmRequest, ActionType::from('llm_request'));
        $this->assertSame(ActionType::ToolInvocation, ActionType::from('tool_invocation'));
        $this->assertSame(ActionType::ContextReshape, ActionType::from('context_reshape'));
    }

    /** @test */
    public function try_from_invalid_string_returns_null(): void
    {
        $result = ActionType::tryFrom('unknown_type');
        $this->assertNull($result);
    }
}
