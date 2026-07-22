<?php

namespace Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Integration\Harness\BoundaryWitness;
use ClarionApp\LlmClient\Models\ContextManagementRecord;
use ClarionApp\LlmClient\Models\ChunkSummary;
use ClarionApp\LlmClient\Models\EpisodicMemory;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase as BaseTestCase;

/**
 * T005: BoundaryWitness unit tests.
 *
 * Each of the six BoundaryWitness methods:
 * contextManagementActed(), contextManagementActedAtLeast(n),
 * actedOnAlreadyReducedHistory(), condensationRan(),
 * recordCaptured(), recordRegenerated()
 *
 * A failed witness fails as "inconclusive" with observed counts.
 */
class BoundaryWitnessTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->createWitnessTables();
    }

    private function createWitnessTables(): void
    {
        if (!Schema::hasTable('context_management_records')) {
            Schema::create('context_management_records', function ($table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->index();
                $table->uuid('user_id')->index();
                $table->uuid('attempt_group_id')->index();
                $table->string('mechanism')->nullable();
                $table->unsignedInteger('history_budget')->nullable();
                $table->unsignedInteger('context_capacity')->nullable();
                $table->unsignedInteger('tokens_before')->nullable();
                $table->unsignedInteger('tokens_after')->nullable();
                $table->unsignedInteger('request_tokens_before')->nullable();
                $table->unsignedInteger('tokens_saved')->nullable();
                $table->string('model')->nullable();
                $table->string('provider_type')->nullable();
                $table->text('error')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('chunk_summaries')) {
            Schema::create('chunk_summaries', function ($table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->index();
                $table->unsignedInteger('chunk_index');
                $table->string('source_hash', 64);
                $table->unsignedInteger('source_message_count');
                $table->json('summary');
                $table->unsignedInteger('summary_tokens')->nullable();
                $table->string('condensation_model')->nullable();
                $table->string('condensation_provider')->nullable();
                $table->timestamps();
                $table->unique(['conversation_id', 'chunk_index']);
            });
        }

        if (!Schema::hasTable('episodic_memories')) {
            Schema::create('episodic_memories', function ($table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->uuid('conversation_id');
                $table->text('summary');
                $table->json('topics');
                $table->boolean('protected')->default(false);
                $table->unsignedInteger('word_count');
                $table->unsignedInteger('summary_word_count');
                $table->timestamps();
                $table->softDeletes();
                $table->index('conversation_id');
            });
        }
    }

    /* ------------------------------------------------------------------ */
    /*  contextManagementActed()                                           */
    /* ------------------------------------------------------------------ */

    public function test_contextManagementActed_returns_true_when_record_exists(): void
    {
        $convId = (string) \Illuminate\Support\Str::uuid();
        ContextManagementRecord::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $convId,
            'user_id' => (string) \Illuminate\Support\Str::uuid(),
            'attempt_group_id' => (string) \Illuminate\Support\Str::uuid(),
            'mechanism' => 'condense',
            'tokens_before' => 5000,
            'tokens_after' => 3000,
            'tokens_saved' => 2000,
        ]);

        $witness = new BoundaryWitness($convId);
        $result = $witness->contextManagementActed();
        $this->assertTrue($result);
    }

    public function test_contextManagementActed_returns_false_when_no_record(): void
    {
        $convId = (string) \Illuminate\Support\Str::uuid();
        $witness = new BoundaryWitness($convId);
        $result = $witness->contextManagementActed();
        $this->assertFalse($result);
    }

    public function test_contextManagementActed_fails_as_inconclusive(): void
    {
        $convId = (string) \Illuminate\Support\Str::uuid();
        $witness = new BoundaryWitness($convId);

        try {
            $witness->assertContextManagementActed();
            $this->fail('Expected exception');
        } catch (\Exception $e) {
            $this->assertStringContainsString('inconclusive', strtolower($e->getMessage()));
        }
    }

    /* ------------------------------------------------------------------ */
    /*  contextManagementActedAtLeast(n)                                   */
    /* ------------------------------------------------------------------ */

    public function test_contextManagementActedAtLeast_true_when_enough_records(): void
    {
        $convId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();

        for ($i = 0; $i < 3; $i++) {
            ContextManagementRecord::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'conversation_id' => $convId,
                'user_id' => $userId,
                'attempt_group_id' => (string) \Illuminate\Support\Str::uuid(),
                'mechanism' => 'condense',
                'tokens_before' => 5000 - ($i * 1000),
                'tokens_after' => 3000 - ($i * 1000),
                'tokens_saved' => 2000,
            ]);
        }

        $witness = new BoundaryWitness($convId);
        $this->assertTrue($witness->contextManagementActedAtLeast(2));
        $this->assertTrue($witness->contextManagementActedAtLeast(3));
        $this->assertFalse($witness->contextManagementActedAtLeast(4));
    }

    /* ------------------------------------------------------------------ */
    /*  actedOnAlreadyReducedHistory()                                     */
    /* ------------------------------------------------------------------ */

    public function test_actedOnAlreadyReducedHistory_detects_second_reduction(): void
    {
        $convId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $groupId = (string) \Illuminate\Support\Str::uuid();

        // First reduction: 5000 -> 3000
        ContextManagementRecord::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $convId,
            'user_id' => $userId,
            'attempt_group_id' => $groupId,
            'mechanism' => 'condense',
            'tokens_before' => 5000,
            'tokens_after' => 3000,
            'tokens_saved' => 2000,
        ]);

        // Second reduction on already-reduced history: 3500 -> 2000
        // tokens_before (3500) < peak tokens_before (5000)
        ContextManagementRecord::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $convId,
            'user_id' => $userId,
            'attempt_group_id' => (string) \Illuminate\Support\Str::uuid(),
            'mechanism' => 'condense',
            'tokens_before' => 3500,
            'tokens_after' => 2000,
            'tokens_saved' => 1500,
        ]);

        $witness = new BoundaryWitness($convId);
        $this->assertTrue($witness->actedOnAlreadyReducedHistory());
    }

    public function test_actedOnAlreadyReducedHistory_false_when_single_reduction(): void
    {
        $convId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();

        ContextManagementRecord::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $convId,
            'user_id' => $userId,
            'attempt_group_id' => (string) \Illuminate\Support\Str::uuid(),
            'mechanism' => 'condense',
            'tokens_before' => 5000,
            'tokens_after' => 3000,
            'tokens_saved' => 2000,
        ]);

        $witness = new BoundaryWitness($convId);
        $this->assertFalse($witness->actedOnAlreadyReducedHistory());
    }

    /* ------------------------------------------------------------------ */
    /*  condensationRan()                                                  */
    /* ------------------------------------------------------------------ */

    public function test_condensationRan_true_when_chunk_summary_exists(): void
    {
        $convId = (string) \Illuminate\Support\Str::uuid();
        ChunkSummary::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $convId,
            'chunk_index' => 0,
            'source_hash' => 'abc123',
            'source_message_count' => 10,
            'summary' => json_encode(['text' => 'summary']),
            'summary_tokens' => 50,
        ]);

        $witness = new BoundaryWitness($convId);
        $this->assertTrue($witness->condensationRan());
    }

    public function test_condensationRan_false_when_no_chunk_summary(): void
    {
        $convId = (string) \Illuminate\Support\Str::uuid();
        $witness = new BoundaryWitness($convId);
        $this->assertFalse($witness->condensationRan());
    }

    /* ------------------------------------------------------------------ */
    /*  recordCaptured()                                                   */
    /* ------------------------------------------------------------------ */

    public function test_recordCaptured_true_when_episodic_memory_exists(): void
    {
        $convId = (string) \Illuminate\Support\Str::uuid();
        EpisodicMemory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $convId,
            'user_id' => (string) \Illuminate\Support\Str::uuid(),
            'summary' => 'test summary',
            'topics' => json_encode(['test']),
            'word_count' => 100,
            'summary_word_count' => 50,
        ]);

        $witness = new BoundaryWitness($convId);
        $this->assertTrue($witness->recordCaptured());
    }

    public function test_recordCaptured_false_when_no_episodic_memory(): void
    {
        $convId = (string) \Illuminate\Support\Str::uuid();
        $witness = new BoundaryWitness($convId);
        $this->assertFalse($witness->recordCaptured());
    }

    /* ------------------------------------------------------------------ */
    /*  recordRegenerated()                                                */
    /* ------------------------------------------------------------------ */

    public function test_recordRegenerated_true_when_word_count_changed(): void
    {
        $convId = (string) \Illuminate\Support\Str::uuid();
        $record = EpisodicMemory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $convId,
            'user_id' => (string) \Illuminate\Support\Str::uuid(),
            'summary' => 'test summary',
            'topics' => json_encode(['test']),
            'word_count' => 100,
            'summary_word_count' => 50,
        ]);

        $before = $record->fresh();

        // Simulate regeneration
        $record->update(['word_count' => 200]);

        $witness = new BoundaryWitness($convId);
        $this->assertTrue($witness->recordRegenerated($before));
    }

    public function test_recordRegenerated_false_when_no_change(): void
    {
        $convId = (string) \Illuminate\Support\Str::uuid();
        $record = EpisodicMemory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $convId,
            'user_id' => (string) \Illuminate\Support\Str::uuid(),
            'summary' => 'test summary',
            'topics' => json_encode(['test']),
            'word_count' => 100,
            'summary_word_count' => 50,
        ]);

        $before = $record->fresh();

        $witness = new BoundaryWitness($convId);
        $this->assertFalse($witness->recordRegenerated($before));
    }
}
