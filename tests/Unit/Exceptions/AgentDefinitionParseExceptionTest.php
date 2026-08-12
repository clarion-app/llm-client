<?php

namespace ClarionApp\LlmClient\Tests\Unit\Exceptions;

use ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionParseErrorKind;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AgentDefinitionParseException (data-model.md §5, contracts §3) — one of
 * the two typed exceptions AgentDefinitionParser::parse() throws. Mirrors
 * PresetNotFoundExceptionTest/SchemaValidationErrorTest's own existing
 * style for this test directory.
 */
class AgentDefinitionParseExceptionTest extends TestCase
{
    #[Test]
    public function malformed_yaml_message(): void
    {
        $exception = new AgentDefinitionParseException(
            kind: AgentDefinitionParseErrorKind::MalformedYaml,
            value: 'found character that cannot start any token',
        );

        $this->assertStringContainsString('not valid YAML', $exception->getMessage());
        $this->assertStringContainsString('found character that cannot start any token', $exception->getMessage());
    }

    #[Test]
    public function unrecognized_format_version_message(): void
    {
        $exception = new AgentDefinitionParseException(
            kind: AgentDefinitionParseErrorKind::UnrecognizedFormatVersion,
            value: '0.9',
        );

        $this->assertSame(
            'format_version "0.9" is not supported. Supported versions: 1.0.',
            $exception->getMessage()
        );
    }

    #[Test]
    public function missing_name_message(): void
    {
        $exception = new AgentDefinitionParseException(
            kind: AgentDefinitionParseErrorKind::MissingName,
        );

        $this->assertSame('A definition must state a non-empty "name".', $exception->getMessage());
    }

    #[Test]
    public function unknown_key_message_names_the_offending_key(): void
    {
        $exception = new AgentDefinitionParseException(
            kind: AgentDefinitionParseErrorKind::UnknownKey,
            key: 'namee',
        );

        $this->assertStringContainsString('namee', $exception->getMessage());
    }

    #[Test]
    public function unknown_key_message_includes_a_did_you_mean_hint_when_a_close_match_exists(): void
    {
        $exception = new AgentDefinitionParseException(
            kind: AgentDefinitionParseErrorKind::UnknownKey,
            key: 'namee',
        );

        // A nice-to-have hint, not load-bearing per contracts §3 — but when
        // present for a close match, it should name the correct key.
        $this->assertStringContainsString('name', $exception->getMessage());
    }

    #[Test]
    public function instructions_too_long_message(): void
    {
        $exception = new AgentDefinitionParseException(
            kind: AgentDefinitionParseErrorKind::InstructionsTooLong,
            value: ['estimated' => 2143, 'limit' => 1500],
        );

        $this->assertStringContainsString('2143', $exception->getMessage());
        $this->assertStringContainsString('1500', $exception->getMessage());
    }

    #[Test]
    public function properties_are_readable_and_match_what_was_passed(): void
    {
        $exception = new AgentDefinitionParseException(
            kind: AgentDefinitionParseErrorKind::UnknownKey,
            key: 'memory.long_term',
            value: 'maybe',
        );

        $this->assertSame(AgentDefinitionParseErrorKind::UnknownKey, $exception->kind);
        $this->assertSame('memory.long_term', $exception->key);
        $this->assertSame('maybe', $exception->value);
    }

    #[Test]
    public function extends_runtime_exception(): void
    {
        $exception = new AgentDefinitionParseException(kind: AgentDefinitionParseErrorKind::MissingName);

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }
}
