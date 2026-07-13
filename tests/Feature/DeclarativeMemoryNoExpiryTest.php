<?php

namespace Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\DeclarativeMemoryService as DeclarativeMemoryServiceContract;
use ClarionApp\LlmClient\Models\DeclarativeMemory;
use ClarionApp\LlmClient\Services\EmbeddingService;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Feature test: Declarative memory entries never expire (FR-005 / SC-003).
 *
 * Verifies that an entry with a far-backdated created_at is still returned
 * by list/recall — asserts no time-based removal path exists.
 */
class DeclarativeMemoryNoExpiryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create declarative_memories table in memory
        if (!\Illuminate\Support\Facades\Schema::hasTable('declarative_memories')) {
            \Illuminate\Support\Facades\Schema::create('declarative_memories', function ($table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('type');
                $table->text('content');
                $table->string('source');
                $table->json('embedding')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('user_id');
                $table->index(['user_id', 'type']);
                $table->index('deleted_at');
            });
        }
    }

    #[Test]
    public function backdated_entry_still_returned_by_recall(): void
    {
        $user = \ClarionApp\Backend\Models\User::factory()->create();

        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(false);
        $service = new \ClarionApp\LlmClient\Services\DeclarativeMemoryService($embeddingService);

        // Create an entry and backdate it far into the past
        $entry = DeclarativeMemory::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'type' => 'fact',
            'content' => 'This fact is very old but should never expire',
            'source' => 'user_stated',
            'created_at' => now()->subYears(5),
            'updated_at' => now()->subYears(5),
        ]);

        // Recall should still return it
        $recalled = $service->recall($user->id);
        $this->assertCount(1, $recalled['entries']);
        $this->assertEquals($entry->id, $recalled['entries'][0]->id);
    }

    #[Test]
    public function backdated_entry_still_returned_by_list(): void
    {
        $user = \ClarionApp\Backend\Models\User::factory()->create();

        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(false);
        $service = new \ClarionApp\LlmClient\Services\DeclarativeMemoryService($embeddingService);

        // Create an entry and backdate it
        DeclarativeMemory::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'type' => 'preference',
            'content' => 'Old preference that persists forever',
            'source' => 'user_stated',
            'created_at' => now()->subYears(10),
            'updated_at' => now()->subYears(10),
        ]);

        // List should still return it
        $paginator = $service->list($user->id);
        $this->assertCount(1, $paginator->items());
        $this->assertEquals('Old preference that persists forever', $paginator->items()[0]->content);
    }

    #[Test]
    public function no_cleanup_command_or_eviction_path_exists(): void
    {
        // Structural assertion: there is no eviction service, cleanup command,
        // or retention config for declarative memory.
        // This is verified by the absence of such code, not by runtime behavior.
        // The config deliberately has no retention/eviction/cap keys.
        $config = config('llm-client.declarative_memory');
        $this->assertArrayNotHasKey('retention_days', $config);
        $this->assertArrayNotHasKey('eviction', $config);
        $this->assertArrayNotHasKey('max_entries', $config);
        $this->assertArrayNotHasKey('cap', $config);
    }
}
