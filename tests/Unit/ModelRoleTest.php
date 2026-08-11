<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ClarionApp\LlmClient\ValueObjects\ModelRole;

use PHPUnit\Framework\Attributes\Test;

/**
 * Pure unit tests for the ModelRole enum.
 * No database required.
 */
class ModelRoleTest extends TestCase
{
    // ========== whatBreaksWhenUnassigned() ==========

    #[Test]
    public function inference_returns_non_empty_what_breaks_message(): void
    {
        $message = ModelRole::Inference->whatBreaksWhenUnassigned();
        $this->assertNotEmpty($message);
        $this->assertIsString($message);
    }

    #[Test]
    public function embedding_returns_non_empty_what_breaks_message(): void
    {
        $message = ModelRole::Embedding->whatBreaksWhenUnassigned();
        $this->assertNotEmpty($message);
        $this->assertIsString($message);
    }

    #[Test]
    public function image_returns_non_empty_what_breaks_message(): void
    {
        $message = ModelRole::Image->whatBreaksWhenUnassigned();
        $this->assertNotEmpty($message);
        $this->assertIsString($message);
    }

    #[Test]
    public function judge_returns_non_empty_what_breaks_message(): void
    {
        $message = ModelRole::Judge->whatBreaksWhenUnassigned();
        $this->assertNotEmpty($message);
        $this->assertIsString($message);
    }

    // ========== Enum values match string storage ==========

    #[Test]
    public function inference_enum_value_is_inference_string(): void
    {
        $this->assertEquals('inference', ModelRole::Inference->value);
    }

    #[Test]
    public function embedding_enum_value_is_embedding_string(): void
    {
        $this->assertEquals('embedding', ModelRole::Embedding->value);
    }

    #[Test]
    public function image_enum_value_is_image_string(): void
    {
        $this->assertEquals('image', ModelRole::Image->value);
    }

    #[Test]
    public function judge_enum_value_is_judge_string(): void
    {
        $this->assertEquals('judge', ModelRole::Judge->value);
    }

    // ========== tryFrom round-trip ==========

    #[Test]
    public function tryFrom_returns_correct_case_for_inference(): void
    {
        $this->assertEquals(ModelRole::Inference, ModelRole::tryFrom('inference'));
    }

    #[Test]
    public function tryFrom_returns_correct_case_for_embedding(): void
    {
        $this->assertEquals(ModelRole::Embedding, ModelRole::tryFrom('embedding'));
    }

    #[Test]
    public function tryFrom_returns_correct_case_for_image(): void
    {
        $this->assertEquals(ModelRole::Image, ModelRole::tryFrom('image'));
    }

    #[Test]
    public function tryFrom_returns_correct_case_for_judge(): void
    {
        $this->assertEquals(ModelRole::Judge, ModelRole::tryFrom('judge'));
    }

    #[Test]
    public function tryFrom_returns_null_for_unrecognised_string(): void
    {
        $this->assertNull(ModelRole::tryFrom('translation'));
    }

    // ========== Enum has exactly four cases ==========

    #[Test]
    public function enum_has_exactly_four_cases(): void
    {
        $cases = ModelRole::cases();
        $this->assertCount(4, $cases);
    }
}
