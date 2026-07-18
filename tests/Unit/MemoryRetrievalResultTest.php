<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\ValueObjects\MemoryHit;
use ClarionApp\LlmClient\ValueObjects\MemoryRetrievalResult;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MemoryRetrievalResultTest extends TestCase
{
    /* ------------------------------------------------------------------ */
    /*  T008: Construction                                                 */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function constructs_with_defaults()
    {
        $result = new MemoryRetrievalResult();
        $this->assertEquals([], $result->hits);
        $this->assertEquals(0, $result->totalTokens);
        $this->assertEquals([], $result->degradationEvents);
        $this->assertEquals(0.0, $result->retrievalTime);
    }

    #[Test]
    public function constructs_with_custom_values()
    {
        $hit = MemoryHit::fromRule('r-1', 'Rule content', 1.0);
        $result = new MemoryRetrievalResult(
            hits: [$hit],
            totalTokens: 50,
            degradationEvents: ['episodic store unavailable'],
            retrievalTime: 120.5,
        );

        $this->assertCount(1, $result->hits);
        $this->assertEquals(50, $result->totalTokens);
        $this->assertCount(1, $result->degradationEvents);
        $this->assertEquals(120.5, $result->retrievalTime);
    }

    /* ------------------------------------------------------------------ */
    /*  T008: addHit                                                       */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function addHit_appends_hit_and_updates_tokens()
    {
        $result = new MemoryRetrievalResult();
        $hit = MemoryHit::fromRule('r-1', 'Always use 24h time', 1.0);

        $result->addHit($hit);

        $this->assertCount(1, $result->hits);
        $this->assertEquals('r-1', $result->hits[0]->id);
        $this->assertGreaterThan(0, $result->totalTokens);
    }

    #[Test]
    public function addHit_accumulates_tokens()
    {
        $result = new MemoryRetrievalResult();
        $hit1 = MemoryHit::fromRule('r-1', 'Rule one', 1.0);
        $hit2 = MemoryHit::fromDeclarative('f-1', 'Fact two', 'fact', 0.8);

        $result->addHit($hit1);
        $tokensAfterFirst = $result->totalTokens;

        $result->addHit($hit2);

        $this->assertGreaterThan($tokensAfterFirst, $result->totalTokens);
        $this->assertCount(2, $result->hits);
    }

    /* ------------------------------------------------------------------ */
    /*  T008: addDegradationEvent                                          */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function addDegradationEvent_appends_event()
    {
        $result = new MemoryRetrievalResult();
        $result->addDegradationEvent('embedding provider unavailable');

        $this->assertCount(1, $result->degradationEvents);
        $this->assertEquals('embedding provider unavailable', $result->degradationEvents[0]);
    }

    #[Test]
    public function addDegradationEvent_accumulates_events()
    {
        $result = new MemoryRetrievalResult();
        $result->addDegradationEvent('event-1');
        $result->addDegradationEvent('event-2');
        $result->addDegradationEvent('event-3');

        $this->assertCount(3, $result->degradationEvents);
    }

    /* ------------------------------------------------------------------ */
    /*  T008: isEmpty                                                      */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function isEmpty_returns_true_for_empty_result()
    {
        $result = new MemoryRetrievalResult();
        $this->assertTrue($result->isEmpty());
    }

    #[Test]
    public function isEmpty_returns_false_after_hit_added()
    {
        $result = new MemoryRetrievalResult();
        $result->addHit(MemoryHit::fromRule('r-1', 'content', 1.0));
        $this->assertFalse($result->isEmpty());
    }

    /* ------------------------------------------------------------------ */
    /*  T008: hitsBySource and hitsByType                                  */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function hitsBySource_filters_correctly()
    {
        $result = new MemoryRetrievalResult();
        $result->addHit(MemoryHit::fromRule('r-1', 'rule', 1.0));
        $result->addHit(MemoryHit::fromEpisodic('e-1', 'episodic', 0.7));
        $result->addHit(MemoryHit::fromLongTerm('lt-1', 'long-term', 0.6));

        $declarative = $result->hitsBySource('declarative');
        $this->assertCount(1, $declarative);
        $this->assertEquals('r-1', $declarative[0]->id);

        $episodic = $result->hitsBySource('episodic');
        $this->assertCount(1, $episodic);
        $this->assertEquals('e-1', $episodic[0]->id);

        $longTerm = $result->hitsBySource('long-term');
        $this->assertCount(1, $longTerm);
        $this->assertEquals('lt-1', $longTerm[0]->id);
    }

    #[Test]
    public function hitsByType_filters_correctly()
    {
        $result = new MemoryRetrievalResult();
        $result->addHit(MemoryHit::fromRule('r-1', 'rule', 1.0));
        $result->addHit(MemoryHit::fromDeclarative('f-1', 'fact', 'fact', 0.8));
        $result->addHit(MemoryHit::fromDeclarative('p-1', 'preference', 'preference', 0.7));

        $rules = $result->hitsByType('rule');
        $this->assertCount(1, $rules);
        $this->assertEquals('r-1', $rules[0]->id);

        $facts = $result->hitsByType('fact');
        $this->assertCount(1, $facts);
        $this->assertEquals('f-1', $facts[0]->id);

        $prefs = $result->hitsByType('preference');
        $this->assertCount(1, $prefs);
        $this->assertEquals('p-1', $prefs[0]->id);
    }

    /* ------------------------------------------------------------------ */
    /*  T008: setRetrievalTime and recalculateTokens                       */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function setRetrievalTime_updates_time()
    {
        $result = new MemoryRetrievalResult();
        $result->setRetrievalTime(250.5);
        $this->assertEquals(250.5, $result->retrievalTime);
    }

    #[Test]
    public function recalculateTokens_computes_from_hits()
    {
        $result = new MemoryRetrievalResult();
        $result->addHit(MemoryHit::fromRule('r-1', 'Hello World', 1.0));
        $result->totalTokens = 999; // Set wrong value

        $result->recalculateTokens();

        // "Hello World" = 11 chars / 4 = 3 tokens (ceil)
        $this->assertEquals(3, $result->totalTokens);
    }
}
