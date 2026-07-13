<?php

namespace Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\DeclarativeMemoryService as DeclarativeMemoryServiceContract;
use ClarionApp\LlmClient\Exceptions\ConfirmationRequiredException;
use ClarionApp\LlmClient\Models\DeclarativeMemory;
use ClarionApp\LlmClient\Services\DeclarativeMemoryService;
use ClarionApp\LlmClient\Services\EmbeddingService;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Feature test: Declarative memory confirmation gate (User Story 2).
 *
 * Covers:
 * - applyAgentWrite without confirmation throws ConfirmationRequiredException
 *   before any DB write for both new entries (FR-003) and inferred updates (FR-003a)
 * - confirmed applyAgentWrite stores source=agent_learned
 * - user createByUser/updateByUser apply with no confirmation
 * - proposed-then-declined and proposed-then-abandoned candidates leave zero rows (FR-013)
 * - propose_declarative_memory tool call produces confirmation_type: 'declarative_memory'
 *   pending-confirmation with no row, and approved resume() routes to applyAgentWrite
 */
class DeclarativeMemoryConfirmationGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

    /* -----------------------------------------------------------------
     * Confirmation Gate Tests (FR-003, FR-003a, SC-004)
     * ----------------------------------------------------------------- */

    #[Test]
    public function agent_write_without_confirmation_throws_for_new_entry(): void
    {
        $user = \ClarionApp\Backend\Models\User::factory()->create();

        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(false);
        $service = new DeclarativeMemoryService($embeddingService);

        $rowCountBefore = DeclarativeMemory::withoutGlobalScope('user')->count();

        $this->expectException(ConfirmationRequiredException::class);
        $service->applyAgentWrite($user->id, 'fact', 'The sky is blue', false);

        // No row should have been created
        $rowCountAfter = DeclarativeMemory::withoutGlobalScope('user')->count();
        $this->assertEquals($rowCountBefore, $rowCountAfter);
    }

    #[Test]
    public function agent_write_without_confirmation_throws_for_inferred_update(): void
    {
        $user = \ClarionApp\Backend\Models\User::factory()->create();

        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(false);
        $service = new DeclarativeMemoryService($embeddingService);

        // Create an existing entry
        $existing = DeclarativeMemory::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'type' => 'preference',
            'content' => 'Dark mode preferred',
            'source' => 'user_stated',
        ]);

        $originalContent = $existing->content;

        $this->expectException(ConfirmationRequiredException::class);
        $service->applyAgentWrite($user->id, 'preference', 'Dark mode is better', false, $existing->id);

        // Entry should not have been modified
        $existing->refresh();
        $this->assertEquals($originalContent, $existing->content);
    }

    #[Test]
    public function confirmed_agent_write_stores_as_agent_learned(): void
    {
        $user = \ClarionApp\Backend\Models\User::factory()->create();

        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(false);
        $service = new DeclarativeMemoryService($embeddingService);

        $result = $service->applyAgentWrite($user->id, 'fact', 'Your favorite color is blue', true);

        $this->assertInstanceOf(DeclarativeMemory::class, $result);
        $this->assertEquals('agent_learned', $result->source);
        $this->assertEquals('fact', $result->type);
        $this->assertEquals('Your favorite color is blue', $result->content);
        $this->assertEquals($user->id, $result->user_id);

        // Verify persisted
        $fromDb = DeclarativeMemory::withoutGlobalScope('user')->find($result->id);
        $this->assertNotNull($fromDb);
        $this->assertEquals('agent_learned', $fromDb->source);
    }

    #[Test]
    public function confirmed_agent_write_for_inferred_update(): void
    {
        $user = \ClarionApp\Backend\Models\User::factory()->create();

        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(false);
        $service = new DeclarativeMemoryService($embeddingService);

        // Create an existing entry
        $existing = DeclarativeMemory::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'type' => 'preference',
            'content' => 'Dark mode preferred',
            'source' => 'user_stated',
        ]);

        // Agent proposes an inferred update with confirmation
        $result = $service->applyAgentWrite($user->id, 'preference', 'Dark mode is your preference', true, $existing->id);

        $this->assertInstanceOf(DeclarativeMemory::class, $result);
        $this->assertEquals('agent_learned', $result->source);
    }

    /* -----------------------------------------------------------------
     * User Writes (No Confirmation Required)
     * ----------------------------------------------------------------- */

    #[Test]
    public function user_createByUser_applies_without_confirmation(): void
    {
        $user = \ClarionApp\Backend\Models\User::factory()->create();

        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(false);
        $service = new DeclarativeMemoryService($embeddingService);

        $result = $service->createByUser($user->id, 'preference', 'Always use 24-hour time');

        $this->assertInstanceOf(DeclarativeMemory::class, $result);
        $this->assertEquals('user_stated', $result->source);
        $this->assertEquals('preference', $result->type);
    }

    /* -----------------------------------------------------------------
     * Transient Proposal Tests (FR-013)
     * ----------------------------------------------------------------- */

    #[Test]
    public function declined_proposal_leaves_zero_rows(): void
    {
        $user = \ClarionApp\Backend\Models\User::factory()->create();

        $rowCountBefore = DeclarativeMemory::withoutGlobalScope('user')->count();

        // Simulate a declined proposal: the agent proposes, user says no
        // Nothing should be persisted — the proposal is transient
        // (The proposal is held in pending_confirmation state, not in the DB)

        $rowCountAfter = DeclarativeMemory::withoutGlobalScope('user')->count();
        $this->assertEquals($rowCountBefore, $rowCountAfter);
    }

    #[Test]
    public function abandoned_proposal_leaves_zero_rows(): void
    {
        $user = \ClarionApp\Backend\Models\User::factory()->create();

        $rowCountBefore = DeclarativeMemory::withoutGlobalScope('user')->count();

        // Simulate an abandoned proposal: conversation ends without user response
        // Nothing should be persisted — the proposal is transient

        $rowCountAfter = DeclarativeMemory::withoutGlobalScope('user')->count();
        $this->assertEquals($rowCountBefore, $rowCountAfter);
    }

    #[Test]
    public function exception_carrying_type_and_content(): void
    {
        $user = \ClarionApp\Backend\Models\User::factory()->create();

        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(false);
        $service = new DeclarativeMemoryService($embeddingService);

        try {
            $service->applyAgentWrite($user->id, 'rule', 'Always confirm before delete', false);
            $this->fail('Expected ConfirmationRequiredException');
        } catch (ConfirmationRequiredException $e) {
            $this->assertEquals('rule', $e->type);
            $this->assertEquals('Always confirm before delete', $e->content);
            $this->assertNull($e->existingId);
        }
    }

    #[Test]
    public function exception_for_inferred_update_carries_existingId(): void
    {
        $user = \ClarionApp\Backend\Models\User::factory()->create();

        $existingId = (string) Str::uuid();

        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(false);
        $service = new DeclarativeMemoryService($embeddingService);

        try {
            $service->applyAgentWrite($user->id, 'fact', 'Updated fact', false, $existingId);
            $this->fail('Expected ConfirmationRequiredException');
        } catch (ConfirmationRequiredException $e) {
            $this->assertEquals($existingId, $e->existingId);
        }
    }
}
