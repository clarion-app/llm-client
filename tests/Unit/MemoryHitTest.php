<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\ValueObjects\MemoryHit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MemoryHitTest extends TestCase
{
    /* ------------------------------------------------------------------ */
    /*  T007: Construction and immutability                                */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function constructs_with_valid_properties()
    {
        $hit = new MemoryHit(
            id: 'mem-1',
            content: 'User likes dark mode',
            score: 0.85,
            source: 'declarative',
            type: 'preference',
            metadata: ['confidence' => 90],
        );

        $this->assertEquals('mem-1', $hit->id);
        $this->assertEquals('User likes dark mode', $hit->content);
        $this->assertEquals(0.85, $hit->score);
        $this->assertEquals('declarative', $hit->source);
        $this->assertEquals('preference', $hit->type);
        $this->assertEquals(['confidence' => 90], $hit->metadata);
    }

    #[Test]
    public function defaults_metadata_to_empty_array()
    {
        $hit = new MemoryHit('id', 'content', 0.5, 'declarative', 'fact');
        $this->assertEquals([], $hit->metadata);
    }

    #[Test]
    public function rejects_invalid_source()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid source');

        new MemoryHit('id', 'content', 0.5, 'invalid-source', 'fact');
    }

    #[Test]
    public function rejects_invalid_type()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid type');

        new MemoryHit('id', 'content', 0.5, 'declarative', 'invalid-type');
    }

    #[Test]
    public function rejects_score_out_of_range_high()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Score must be between 0.0 and 1.0');

        new MemoryHit('id', 'content', 1.5, 'declarative', 'fact');
    }

    #[Test]
    public function rejects_score_out_of_range_low()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Score must be between 0.0 and 1.0');

        new MemoryHit('id', 'content', -0.1, 'declarative', 'fact');
    }

    #[Test]
    public function accepts_boundary_scores()
    {
        $low = new MemoryHit('id', 'content', 0.0, 'declarative', 'fact');
        $this->assertEquals(0.0, $low->score);

        $high = new MemoryHit('id', 'content', 1.0, 'declarative', 'fact');
        $this->assertEquals(1.0, $high->score);
    }

    /* ------------------------------------------------------------------ */
    /*  T007: Factory methods                                              */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function fromRule_creates_rule_hit()
    {
        $hit = MemoryHit::fromRule('r-1', 'Always use 24h time');
        $this->assertEquals('r-1', $hit->id);
        $this->assertEquals('Always use 24h time', $hit->content);
        $this->assertEquals('declarative', $hit->source);
        $this->assertEquals('rule', $hit->type);
        $this->assertEquals(1.0, $hit->score);
    }

    #[Test]
    public function fromRule_accepts_custom_score()
    {
        $hit = MemoryHit::fromRule('r-1', 'Always use 24h time', 0.9);
        $this->assertEquals(0.9, $hit->score);
    }

    #[Test]
    public function fromDeclarative_creates_fact_hit()
    {
        $hit = MemoryHit::fromDeclarative('f-1', 'Capital of France is Paris', 'fact', 0.75);
        $this->assertEquals('f-1', $hit->id);
        $this->assertEquals('fact', $hit->type);
        $this->assertEquals(0.75, $hit->score);
    }

    #[Test]
    public function fromDeclarative_creates_preference_hit()
    {
        $hit = MemoryHit::fromDeclarative('p-1', 'Prefer concise answers', 'preference', 0.8);
        $this->assertEquals('p-1', $hit->id);
        $this->assertEquals('preference', $hit->type);
        $this->assertEquals(0.8, $hit->score);
    }

    #[Test]
    public function fromDeclarative_rejects_rule_type()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Type must be');

        MemoryHit::fromDeclarative('id', 'content', 'rule', 0.5);
    }

    #[Test]
    public function fromEpisodic_creates_episodic_hit()
    {
        $hit = MemoryHit::fromEpisodic('e-1', 'Discussed deployment on Monday', 0.6);
        $this->assertEquals('e-1', $hit->id);
        $this->assertEquals('episodic', $hit->source);
        $this->assertEquals('episodic', $hit->type);
    }

    #[Test]
    public function fromLongTerm_creates_longTerm_hit()
    {
        $hit = MemoryHit::fromLongTerm('lt-1', 'Project uses Laravel', 0.9);
        $this->assertEquals('lt-1', $hit->id);
        $this->assertEquals('long-term', $hit->source);
        $this->assertEquals('long-term', $hit->type);
    }

    /* ------------------------------------------------------------------ */
    /*  T007: Helper methods                                               */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function contentLength_returns_byte_length()
    {
        $hit = new MemoryHit('id', 'Hello', 0.5, 'declarative', 'fact');
        $this->assertEquals(5, $hit->contentLength());
    }

    #[Test]
    public function estimatedTokens_uses_four_chars_per_token()
    {
        // 8 chars / 4 = 2 tokens
        $hit = new MemoryHit('id', 'Hello!!', 0.5, 'declarative', 'fact');
        $this->assertEquals(2, $hit->estimatedTokens());
    }

    #[Test]
    public function estimatedTokens_ceil_for_fractional()
    {
        // 5 chars / 4 = 1.25 → ceil = 2
        $hit = new MemoryHit('id', 'Hello', 0.5, 'declarative', 'fact');
        $this->assertEquals(2, $hit->estimatedTokens());
    }

    #[Test]
    public function estimatedTokens_zero_for_empty_content()
    {
        $hit = new MemoryHit('id', '', 0.5, 'declarative', 'fact');
        $this->assertEquals(0, $hit->estimatedTokens());
    }

    /* ------------------------------------------------------------------ */
    /*  T007: Immutability                                                 */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function properties_are_readonly()
    {
        $hit = new MemoryHit('id', 'content', 0.5, 'declarative', 'fact');

        // PHP readonly properties cannot be modified at runtime
        $this->expectException(\Error::class);

        $hit->id = 'new-id';
    }
}
