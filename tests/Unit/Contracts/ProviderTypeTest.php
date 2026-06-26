<?php

namespace ClarionApp\LlmClient\Tests\Unit\Contracts;

use Tests\TestCase;
use ClarionApp\LlmClient\Contracts\ProviderType;

use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for ProviderType backed enum.
 *
 * Verifies enum cases, backed string values, and from()/tryFrom() behavior.
 */
class ProviderTypeTest extends TestCase
{
    #[Test]
    public function enum_has_three_provider_cases()
    {
        $cases = ProviderType::cases();
        $caseNames = array_map(fn ($c) => $c->name, $cases);

        $this->assertContains('OpenAI', $caseNames);
        $this->assertContains('Anthropic', $caseNames);
        $this->assertContains('LlamaCpp', $caseNames);
        $this->assertCount(3, $cases);
    }

    #[Test]
    public function enum_has_correct_backed_string_values()
    {
        $this->assertEquals('openai', ProviderType::OpenAI->value);
        $this->assertEquals('anthropic', ProviderType::Anthropic->value);
        $this->assertEquals('llama.cpp', ProviderType::LlamaCpp->value);
    }

    #[Test]
    public function from_returns_correct_case_for_valid_string()
    {
        $this->assertSame(ProviderType::OpenAI, ProviderType::from('openai'));
        $this->assertSame(ProviderType::Anthropic, ProviderType::from('anthropic'));
        $this->assertSame(ProviderType::LlamaCpp, ProviderType::from('llama.cpp'));
    }

    #[Test]
    public function from_throws_for_invalid_string()
    {
        $this->expectException(\ValueError::class);
        ProviderType::from('unknown-provider');
    }

    #[Test]
    public function tryFrom_returns_null_for_invalid_string()
    {
        $result = ProviderType::tryFrom('unknown-provider');
        $this->assertNull($result);
    }

    #[Test]
    public function tryFrom_returns_case_for_valid_string()
    {
        $result = ProviderType::tryFrom('openai');
        $this->assertSame(ProviderType::OpenAI, $result);
    }

    #[Test]
    public function exhaustive_match_covers_all_cases()
    {
        $labels = [];
        foreach (ProviderType::cases() as $type) {
            switch ($type) {
                case ProviderType::OpenAI:
                    $labels[] = 'OpenAI-compatible';
                    break;
                case ProviderType::Anthropic:
                    $labels[] = 'Anthropic';
                    break;
                case ProviderType::LlamaCpp:
                    $labels[] = 'llama.cpp';
                    break;
            }
        }

        $this->assertCount(3, $labels);
        $this->assertContains('OpenAI-compatible', $labels);
        $this->assertContains('Anthropic', $labels);
        $this->assertContains('llama.cpp', $labels);
    }

    #[Test]
    public function string_serialization_works()
    {
        $type = ProviderType::OpenAI;
        $serialized = (string) $type->value;

        $this->assertEquals('openai', $serialized);
        $this->assertIsString($serialized);
    }
}
