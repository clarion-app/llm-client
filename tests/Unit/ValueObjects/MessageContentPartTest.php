<?php

namespace ClarionApp\LlmClient\Tests\Unit\ValueObjects;

use ClarionApp\LlmClient\ValueObjects\Messaging\MessageContentPart;
use Tests\TestCase;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for MessageContentPart — the atomic unit of "content that may
 * or may not be externally sourced" (107-agent-message-protocol,
 * data-model.md §2.1, research.md D4). `external: true` is the
 * External-Content Marker from spec.md's Key Entities — a first-class
 * boolean on structured data, not a text convention — so it must round-trip
 * through toArray()/fromArray() with no loss, and default to `false` both
 * as a constructor default and when the key is absent from an array
 * (forward-compat, research.md D2's "open struct" design).
 */
class MessageContentPartTest extends TestCase
{
    #[Test]
    public function to_array_and_from_array_round_trip_with_external_true(): void
    {
        $part = new MessageContentPart('a search result from the web', true);

        $array = $part->toArray();
        $roundTripped = MessageContentPart::fromArray($array);

        $this->assertSame(['text' => 'a search result from the web', 'external' => true], $array);
        $this->assertSame('a search result from the web', $roundTripped->text);
        $this->assertTrue($roundTripped->external);
    }

    #[Test]
    public function to_array_and_from_array_round_trip_with_external_false(): void
    {
        $part = new MessageContentPart('summarize the following', false);

        $array = $part->toArray();
        $roundTripped = MessageContentPart::fromArray($array);

        $this->assertSame(['text' => 'summarize the following', 'external' => false], $array);
        $this->assertSame('summarize the following', $roundTripped->text);
        $this->assertFalse($roundTripped->external);
    }

    #[Test]
    public function external_defaults_to_false_in_the_constructor(): void
    {
        $part = new MessageContentPart('ordinary agent-authored text');

        $this->assertFalse($part->external);
        $this->assertSame(['text' => 'ordinary agent-authored text', 'external' => false], $part->toArray());
    }

    #[Test]
    public function from_array_defaults_external_to_false_when_the_key_is_absent(): void
    {
        $part = MessageContentPart::fromArray(['text' => 'no external key at all']);

        $this->assertSame('no external key at all', $part->text);
        $this->assertFalse($part->external);
    }
}
