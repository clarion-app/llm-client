<?php

namespace ClarionApp\LlmClient\Tests\Unit\Exceptions;

use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionResolutionErrorKind;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AgentDefinitionResolutionException (data-model.md §5, contracts §3) —
 * the second of the two typed exceptions AgentDefinitionParser::parse()
 * throws, one level down from AgentDefinitionParseException: semantic
 * problems in an otherwise structurally valid document.
 */
class AgentDefinitionResolutionExceptionTest extends TestCase
{
    #[Test]
    public function unknown_model_message_names_the_model_and_points_at_llm_settings(): void
    {
        $exception = new AgentDefinitionResolutionException(
            kind: AgentDefinitionResolutionErrorKind::UnknownModel,
            value: 'gpt-5-turbo-mega',
        );

        $this->assertStringContainsString('gpt-5-turbo-mega', $exception->getMessage());
        $this->assertStringContainsString('LLM settings', $exception->getMessage());
    }

    #[Test]
    public function unknown_capability_message_names_the_capability_and_lists_all_five_available(): void
    {
        $exception = new AgentDefinitionResolutionException(
            kind: AgentDefinitionResolutionErrorKind::UnknownCapability,
            value: 'web_browsing',
        );

        $this->assertStringContainsString('web_browsing', $exception->getMessage());
        $this->assertStringContainsString('memory_create', $exception->getMessage());
        $this->assertStringContainsString('memory_read', $exception->getMessage());
        $this->assertStringContainsString('memory_search', $exception->getMessage());
        $this->assertStringContainsString('memory_delete', $exception->getMessage());
        $this->assertStringContainsString('propose_declarative_memory', $exception->getMessage());
    }

    #[Test]
    public function empty_operation_pattern_message_names_the_pattern(): void
    {
        $exception = new AgentDefinitionResolutionException(
            kind: AgentDefinitionResolutionErrorKind::EmptyOperationPattern,
            value: 'contakts.*',
        );

        $this->assertStringContainsString('contakts.*', $exception->getMessage());
    }

    #[Test]
    public function properties_are_readable_and_match_what_was_passed(): void
    {
        $exception = new AgentDefinitionResolutionException(
            kind: AgentDefinitionResolutionErrorKind::UnknownModel,
            value: 'gpt-5-turbo-mega',
        );

        $this->assertSame(AgentDefinitionResolutionErrorKind::UnknownModel, $exception->kind);
        $this->assertSame('gpt-5-turbo-mega', $exception->value);
    }

    #[Test]
    public function extends_runtime_exception(): void
    {
        $exception = new AgentDefinitionResolutionException(
            kind: AgentDefinitionResolutionErrorKind::UnknownModel,
            value: 'gpt-5-turbo-mega',
        );

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }
}
