<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Models\ChunkSummary;
use ClarionApp\LlmClient\Models\CondensationState;
use ClarionApp\LlmClient\Services\CondensationSummaryStore;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CondensationSummaryStoreTest extends TestCase
{
    private CondensationSummaryStore $store;
    private string $conversationA;
    private string $conversationB;

    protected function setUp(): void
    {
        parent::setUp();

        // Run the condensation tables migration
        $this->loadLlmClientMigrations();

        $this->store = new CondensationSummaryStore(
            Cache::store('array'),
            ['failure_threshold' => 3, 'cooldown_seconds' => 300]
        );

        $this->conversationA = (string) \Illuminate\Support\Str::uuid();
        $this->conversationB = (string) \Illuminate\Support\Str::uuid();
    }

    #[Test]
    public function get_returns_null_when_no_summary_exists(): void
    {
        $result = $this->store->get($this->conversationA, 0, 'some-hash');
        $this->assertNull($result);
    }

    #[Test]
    public function get_returns_summary_when_hash_matches(): void
    {
        ChunkSummary::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $this->conversationA,
            'chunk_index' => 0,
            'source_hash' => 'abc123',
            'source_message_count' => 20,
            'summary' => ['decisions' => ['use blue color']],
            'summary_tokens' => 50,
            'condensation_model' => 'gpt-4o',
            'condensation_provider' => 'openai',
        ]);

        $result = $this->store->get($this->conversationA, 0, 'abc123');
        $this->assertNotNull($result);
        $this->assertEquals('abc123', $result->source_hash);
        $this->assertEquals(['decisions' => ['use blue color']], $result->summary);
    }

    #[Test]
    public function get_returns_null_when_hash_is_stale(): void
    {
        ChunkSummary::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $this->conversationA,
            'chunk_index' => 0,
            'source_hash' => 'old-hash',
            'source_message_count' => 20,
            'summary' => ['decisions' => ['old decision']],
            'summary_tokens' => 50,
            'condensation_model' => 'gpt-4o',
            'condensation_provider' => 'openai',
        ]);

        $result = $this->store->get($this->conversationA, 0, 'new-hash');
        $this->assertNull($result);
    }

    #[Test]
    public function remember_runs_produce_exactly_once_and_persists(): void
    {
        $produceCallCount = 0;
        $produce = function () use (&$produceCallCount) {
            $produceCallCount++;
            return [
                'summary' => ['decisions' => ['decide A']],
                'summary_tokens' => 45,
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            ];
        };

        $result = $this->store->remember($this->conversationA, 0, 'hash-xyz', $produce);

        $this->assertEquals(1, $produceCallCount);
        $this->assertNotNull($result);
        $this->assertEquals('hash-xyz', $result->source_hash);
        $this->assertEquals(['decisions' => ['decide A']], $result->summary);
    }

    #[Test]
    public function remember_returns_existing_when_already_cached(): void
    {
        ChunkSummary::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $this->conversationA,
            'chunk_index' => 0,
            'source_hash' => 'existing-hash',
            'source_message_count' => 20,
            'summary' => ['decisions' => ['existing decision']],
            'summary_tokens' => 40,
            'condensation_model' => 'gpt-4o',
            'condensation_provider' => 'openai',
        ]);

        $produceCallCount = 0;
        $produce = function () use (&$produceCallCount) {
            $produceCallCount++;
            return ['summary' => ['decisions' => ['new decision']]];
        };

        $result = $this->store->remember($this->conversationA, 0, 'existing-hash', $produce);

        $this->assertEquals(0, $produceCallCount);
        $this->assertNotNull($result);
        $this->assertEquals(['decisions' => ['existing decision']], $result->summary);
    }

    #[Test]
    public function remember_returns_null_on_produce_failure(): void
    {
        $produce = function () {
            throw new \RuntimeException('LLM error');
        };

        $result = $this->store->remember($this->conversationA, 0, 'fail-hash', $produce);
        $this->assertNull($result);

        // Failure should be recorded
        $this->assertTrue($this->store->recordFailureWasCalled());
    }

    #[Test]
    public function in_cooldown_returns_false_when_no_failures(): void
    {
        $this->assertFalse($this->store->inCooldown($this->conversationA));
    }

    #[Test]
    public function in_cooldown_returns_true_after_threshold_failures(): void
    {
        $this->store->recordFailure($this->conversationA);
        $this->store->recordFailure($this->conversationA);
        $this->assertFalse($this->store->inCooldown($this->conversationA));

        // Third failure should trip cooldown (threshold = 3)
        $this->store->recordFailure($this->conversationA);
        $this->assertTrue($this->store->inCooldown($this->conversationA));
    }

    #[Test]
    public function record_success_resets_failures_and_clears_cooldown(): void
    {
        $this->store->recordFailure($this->conversationA);
        $this->store->recordFailure($this->conversationA);
        $this->store->recordFailure($this->conversationA);
        $this->assertTrue($this->store->inCooldown($this->conversationA));

        $this->store->recordSuccess($this->conversationA);
        $this->assertFalse($this->store->inCooldown($this->conversationA));
    }

    #[Test]
    public function per_conversation_isolation_summary(): void
    {
        ChunkSummary::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $this->conversationA,
            'chunk_index' => 0,
            'source_hash' => 'hash-a',
            'source_message_count' => 20,
            'summary' => ['decisions' => ['decision A']],
            'summary_tokens' => 50,
            'condensation_model' => 'gpt-4o',
            'condensation_provider' => 'openai',
        ]);

        // Conversation B should not see A's summary
        $result = $this->store->get($this->conversationB, 0, 'hash-a');
        $this->assertNull($result);
    }

    #[Test]
    public function per_conversation_isolation_cooldown(): void
    {
        $this->store->recordFailure($this->conversationA);
        $this->store->recordFailure($this->conversationA);
        $this->store->recordFailure($this->conversationA);

        $this->assertTrue($this->store->inCooldown($this->conversationA));
        $this->assertFalse($this->store->inCooldown($this->conversationB));
    }

    private function loadLlmClientMigrations(): void
    {
        $migrationFile = __DIR__ . '/../../src/Migrations/2026_07_14_000000_create_condensation_tables.php';
        $migration = include $migrationFile;
        $migration->up();
    }
}
