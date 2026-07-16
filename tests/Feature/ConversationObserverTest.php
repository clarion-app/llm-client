<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\OperationCache;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

class ConversationObserverTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        // Create minimal tables needed for Conversation model.
        // Parent creates users table.

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('llm_servers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('url');
            $table->string('model')->nullable();
            $table->boolean('is_default')->default(false);
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('server_id')->nullable();
            $table->foreign('server_id')->references('id')->on('llm_servers')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('user_id')->nullable();
            $table->string('title')->nullable();
            $table->string('model')->default('gpt-4');
            $table->string('character')->default('default');
        });
    }

    /**
     * Seed the cache through OperationCache itself, so the key format and the
     * configured store are the real ones rather than a copy pinned here.
     */
    private function seedCachedOperation(Conversation $conversation, string $operationId): OperationCache
    {
        $cache = app(OperationCache::class);
        $cache->put($conversation->id, $operationId, [
            'operationId' => $operationId,
            'summary'     => 'Test',
            'method'      => 'GET',
            'path'        => '/test',
            'paramSchema' => null,
        ]);

        $this->assertNotSame([], $cache->getSummaries($conversation->id), 'precondition: operation is cached');

        return $cache;
    }

    #[Test]
    public function deleting_conversation_clears_operation_cache()
    {
        $conversation = Conversation::create(['title' => 'Test Conversation']);
        $this->seedCachedOperation($conversation, 'op-1');

        // Drive a real delete so observer registration is covered too.
        $conversation->forceDelete();

        // Read through a fresh instance: the seeding instance's memo would
        // otherwise mask a store that was never actually cleared.
        $this->assertSame([], $this->freshCache()->getSummaries($conversation->id));
    }

    #[Test]
    public function soft_delete_also_clears_cache()
    {
        $conversation = Conversation::create(['title' => 'Soft Delete Test']);
        $this->seedCachedOperation($conversation, 'op-2');

        $conversation->delete();

        $this->assertTrue($conversation->trashed(), 'precondition: delete() must soft delete, not hard delete');
        $this->assertSame([], $this->freshCache()->getSummaries($conversation->id));
    }

    #[Test]
    public function deleting_one_conversation_leaves_others_cached()
    {
        $doomed = Conversation::create(['title' => 'Doomed']);
        $keeper = Conversation::create(['title' => 'Keeper']);
        $this->seedCachedOperation($doomed, 'op-doomed');
        $this->seedCachedOperation($keeper, 'op-keeper');

        $doomed->delete();

        $cache = $this->freshCache();
        $this->assertSame([], $cache->getSummaries($doomed->id));
        $this->assertNotSame([], $cache->getSummaries($keeper->id), 'unrelated conversation must survive');
    }

    private function freshCache(): OperationCache
    {
        return new OperationCache(null, app('cache')->store(
            config('llm-client.operation_cache.store')
        ));
    }
}
