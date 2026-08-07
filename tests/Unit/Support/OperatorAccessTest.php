<?php

namespace ClarionApp\LlmClient\Tests\Unit\Support;

use ClarionApp\LlmClient\Support\OperatorAccess;
use Illuminate\Support\Str;
use Tests\TestCase;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for OperatorAccess::isOperator() — the config-driven operator
 * allow-list (research.md D4).
 */
class OperatorAccessTest extends TestCase
{
    #[Test]
    public function it_returns_true_for_a_user_id_present_in_the_operator_allow_list()
    {
        $userId = (string) Str::uuid();

        config(['llm-client.cost.operator_user_ids' => [$userId]]);

        $this->assertTrue(OperatorAccess::isOperator($userId));
    }

    #[Test]
    public function it_returns_false_for_a_user_id_not_present_in_the_allow_list()
    {
        config(['llm-client.cost.operator_user_ids' => [(string) Str::uuid()]]);

        $this->assertFalse(OperatorAccess::isOperator((string) Str::uuid()));
    }

    #[Test]
    public function it_returns_false_for_null()
    {
        config(['llm-client.cost.operator_user_ids' => [(string) Str::uuid()]]);

        $this->assertFalse(OperatorAccess::isOperator(null));
    }

    #[Test]
    public function it_returns_false_when_the_config_array_is_empty()
    {
        config(['llm-client.cost.operator_user_ids' => []]);

        $this->assertFalse(OperatorAccess::isOperator((string) Str::uuid()));
    }
}
