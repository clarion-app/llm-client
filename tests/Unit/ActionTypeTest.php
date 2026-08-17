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
    public function has_delegation_case(): void
    {
        $this->assertEquals('delegation', ActionType::Delegation->value);
    }

    /** @test */
    public function has_notification_case(): void
    {
        $this->assertEquals('notification', ActionType::Notification->value);
    }

    /**
     * Deliberately exact. Adding a case must break this test, because a new
     * case is never a PHP-only change: agent_run_actions.action_type is an
     * ENUM in production and a CHECK-constrained column in the suite's two
     * hand-declared SQLite schemas, and a case added without widening all
     * three fails its INSERT silently inside openAction()'s try/catch. That
     * has already happened once here, to ActionType::Delegation.
     *
     * @test
     */
    public function has_exactly_five_cases(): void
    {
        $cases = ActionType::cases();
        $this->assertCount(5, $cases);
    }

    /** @test */
    public function cases_are_backed_by_string_values(): void
    {
        $this->assertIsString(ActionType::LlmRequest->value);
        $this->assertIsString(ActionType::ToolInvocation->value);
        $this->assertIsString(ActionType::ContextReshape->value);
        $this->assertIsString(ActionType::Delegation->value);
        $this->assertIsString(ActionType::Notification->value);
    }

    /** @test */
    public function from_string_value_returns_correct_case(): void
    {
        $this->assertSame(ActionType::LlmRequest, ActionType::from('llm_request'));
        $this->assertSame(ActionType::ToolInvocation, ActionType::from('tool_invocation'));
        $this->assertSame(ActionType::ContextReshape, ActionType::from('context_reshape'));
        $this->assertSame(ActionType::Delegation, ActionType::from('delegation'));
        $this->assertSame(ActionType::Notification, ActionType::from('notification'));
    }

    /** @test */
    public function try_from_invalid_string_returns_null(): void
    {
        $result = ActionType::tryFrom('unknown_type');
        $this->assertNull($result);
    }
}
