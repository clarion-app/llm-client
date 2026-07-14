<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\ToolResultCondenser;
use ClarionApp\LlmClient\Services\StructureReducer;
use ClarionApp\LlmClient\Events\ToolResultCondensed;
use Mockery;

/**
 * Integration tests for Tool Result Condensation.
 *
 * Tests end-to-end condensation pipeline: tool result generation → condensation
 * → cache storage → retrieval via reference_id → event dispatch → agent context integration.
 */
class ToolResultCondensationIntegrationTest extends TestCase
{
    private User $user;
    private Server $server;
    private Conversation $conversation;

    protected function defineDatabaseMigrations(): void
    {
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
            $table->string('server_url');
            $table->string('provider_type')->nullable();
            $table->text('token')->nullable();
            $table->timestamps();
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('server_id');
            $table->string('title')->nullable();
            $table->string('model')->nullable();
            $table->string('character')->nullable();
            $table->string('provider_override')->nullable();
            $table->boolean('is_processing')->default(false);
            $table->string('channel')->nullable();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->string('role');
            $table->text('content');
            $table->unsignedInteger('token_count')->nullable();
            $table->json('tool_data')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Use array cache for tests.
        $this->app->singleton('cache', function () {
            return new \Illuminate\Cache\CacheManager($this->app);
        });
        $this->app->singleton('cache.store', function ($app) {
            return $app['cache']->store('array');
        });

        // Enable condensation with test config.
        $this->app['config']->set('llm-client.tool_result_condensation', [
            'enabled' => true,
            'threshold_tokens' => 2000,
            'max_condensed_tokens' => 500,
            'sample_items' => 5,
            'summarization_timeout_seconds' => 5,
            'cache_ttl_minutes' => 240,
        ]);

        $this->user = User::factory()->create();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'https://api.openai.com',
            'provider_type' => 'openai',
            'token' => 'test-token',
        ]);

        $this->conversation = Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'title' => 'Test Conversation',
            'model' => 'gpt-4',
            'character' => 'Clarion',
        ]);
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\DB::table('messages')->delete();
        \Illuminate\Support\Facades\DB::table('conversations')->delete();
        \Illuminate\Support\Facades\DB::table('llm_servers')->delete();

        Mockery::close();
        parent::tearDown();
    }

    // T028: Full pipeline integration tests

    public function test_full_pipeline_json_condensation_with_cache_retrieval(): void
    {
        // Create a large JSON array that exceeds threshold (~5000 tokens).
        $items = [];
        for ($i = 0; $i < 100; $i++) {
            $items[] = [
                'id' => sprintf('item-%d', $i),
                'uuid' => sprintf('xxxxxxxx-xxxx-4xxx-yxxx-%012d', $i, $i, $i, $i),
                'name' => 'Item ' . str_repeat('Name ', $i % 5 + 1),
                'description' => str_repeat('Description text for item ', 20),
                'path' => '/some/deep/nested/path/to/item/' . $i,
                'email' => sprintf('user%d@example.com', $i),
            ];
        }
        $largeJson = json_encode($items);
        $tokenCount = ToolResultCondenser::estimateTokens($largeJson);
        $this->assertGreaterThan(2000, $tokenCount, 'Test data should exceed threshold');

        // Step 1: Condense the result.
        $condenser = new ToolResultCondenser(
            new StructureReducer(),
            null,
            null,
            $this->app['config']->get('llm-client.tool_result_condensation')
        );

        Event::fake(ToolResultCondensed::class);

        $result = $condenser->condense(
            $this->conversation->id,
            'list_files',
            $largeJson
        );

        // Step 2: Verify condensation occurred.
        $this->assertTrue($result['condensed'], 'Result should be condensed');
        $this->assertArrayHasKey('reference_id', $result);
        $this->assertSame('deterministic', $result['method']);
        $this->assertGreaterThan(0, $result['original_tokens']);
        $this->assertLessThan($result['original_tokens'], $result['condensed_tokens']);

        // Step 3: Verify condensed content preserves structure.
        $condensedData = json_decode($result['content'], true);
        $this->assertIsArray($condensedData);
        $this->assertArrayHasKey('_meta', $condensedData);
        $this->assertArrayHasKey('total_count', $condensedData['_meta']);

        // Step 4: Verify cache storage and retrieval.
        $referenceId = $result['reference_id'];
        $cachedContent = $condenser->get($this->conversation->id, $referenceId);

        $this->assertNotNull($cachedContent, 'Full content should be cached');
        $this->assertSame($largeJson, $cachedContent, 'Cached content should match original');

        // Step 5: Verify event was dispatched.
        Event::assertDispatched(ToolResultCondensed::class, function ($event) use ($tokenCount) {
            return $event->toolName === 'list_files'
                && $event->method === 'deterministic'
                && $event->originalTokens === $tokenCount
                && $event->tokensSaved > 0;
        });
    }

    public function test_full_pipeline_small_result_passthrough(): void
    {
        // Small JSON result (~10 tokens) — should pass through unchanged.
        $smallJson = json_encode([
            'id' => 'item-1',
            'name' => 'Small Item',
            'count' => 5,
        ]);

        $condenser = new ToolResultCondenser(
            new StructureReducer(),
            null,
            null,
            $this->app['config']->get('llm-client.tool_result_condensation')
        );

        Event::fake(ToolResultCondensed::class);

        $result = $condenser->condense(
            $this->conversation->id,
            'get_item',
            $smallJson
        );

        // Verify passthrough.
        $this->assertFalse($result['condensed']);
        $this->assertSame($smallJson, $result['content']);
        $this->assertArrayNotHasKey('reference_id', $result);

        // No event should be dispatched for passthrough.
        Event::assertNotDispatched(ToolResultCondensed::class);

        // Nothing should be cached.
        $cachedContent = Cache::get('tool_result:' . $this->conversation->id . ':nonexistent');
        $this->assertNull($cachedContent);
    }

    public function test_condensed_content_stored_in_message_tool_data(): void
    {
        // Simulate the AgentLoopService integration pattern.
        $items = [];
        for ($i = 0; $i < 100; $i++) {
            $items[] = [
                'id' => sprintf('file-%d', $i),
                'path' => '/uploads/documents/file_' . str_repeat('name', $i % 3 + 1) . '.txt',
                'size' => rand(1000, 99999),
                'modified' => date('Y-m-d H:i:s', strtotime('-' . $i . ' days')),
                'description' => str_repeat('This is a longer description text for the file entry ', 5),
            ];
        }
        $largeResult = json_encode($items);

        $condenser = new ToolResultCondenser(
            new StructureReducer(),
            null,
            null,
            $this->app['config']->get('llm-client.tool_result_condensation')
        );

        // Condense (as AgentLoopService would do at storage time).
        $condensed = $condenser->condense(
            $this->conversation->id,
            'list_files',
            $largeResult
        );

        // Store message with condensed content in tool_data.
        $toolData = [
            'tool_name' => 'list_files',
            'content' => $condensed['content'],
            'condensed' => $condensed['condensed'],
            'reference_id' => $condensed['reference_id'] ?? null,
            'original_tokens' => $condensed['original_tokens'] ?? null,
            'condensed_tokens' => $condensed['condensed_tokens'] ?? null,
            'method' => $condensed['method'] ?? null,
        ];

        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'tool',
            'content' => $condensed['content'],
            'tool_data' => $toolData,
        ]);

        // Verify message was stored with condensed content.
        $retrievedMessage = Message::findOrFail($message->id);
        $this->assertSame($condensed['content'], $retrievedMessage->content);

        $storedToolData = $retrievedMessage->tool_data;
        $this->assertTrue($storedToolData['condensed']);
        $this->assertNotNull($storedToolData['reference_id']);

        // Verify full content retrievable via reference_id.
        $fullContent = $condenser->get(
            $this->conversation->id,
            $storedToolData['reference_id']
        );
        $this->assertSame($largeResult, $fullContent);
    }

    public function test_identifier_preservation_through_full_pipeline(): void
    {
        // Create structured data with identifiers that must be preserved.
        $data = [
            'results' => [],
            'metadata' => [
                'request_id' => 'req-abc-123-def-456',
                'user_id' => '550e8400-e29b-41d4-a716-446655440000',
                'source_url' => 'https://api.example.com/v2/data',
                'email' => 'admin@example.com',
            ],
        ];

        // Add 100 items with unique identifiers.
        for ($i = 0; $i < 100; $i++) {
            $data['results'][] = [
                'id' => sprintf('id-%04d', $i),
                'uuid' => sprintf('xxxxxxxx-xxxx-4xxx-yxxx-%012d', $i, $i, $i, $i),
                'name' => 'Record ' . $i,
                'path' => '/data/records/' . sprintf('%04d', $i) . '/details',
                'email' => sprintf('user%d@example.com', $i),
                'amount' => sprintf('$%d.00', $i * 100),
                'filler' => str_repeat('x', 100), // Pad to exceed threshold.
            ];
        }

        $largeJson = json_encode($data);

        $condenser = new ToolResultCondenser(
            new StructureReducer(),
            null,
            null,
            $this->app['config']->get('llm-client.tool_result_condensation')
        );

        $result = $condenser->condense(
            $this->conversation->id,
            'query_database',
            $largeJson
        );

        $this->assertTrue($result['condensed']);

        // Verify condensed content preserves key identifiers.
        $condensedData = json_decode($result['content'], true);

        // Metadata identifiers should be preserved.
        $this->assertStringContainsString('req-abc-123-def-456', $result['content']);
        $this->assertStringContainsString('550e8400-e29b-41d4-a716-446655440000', $result['content']);
        $this->assertStringContainsString('admin@example.com', $result['content']);

        // Sample items should preserve their identifiers.
        $this->assertStringContainsString('id-0000', $result['content']);
        $this->assertStringContainsString('user0@example.com', $result['content']);
    }

    public function test_binary_content_passthrough_through_pipeline(): void
    {
        // Binary content with null bytes should pass through unchanged.
        $binaryContent = 'Binary data: ' . "\x00\x01\x02\x03" . str_repeat('x', 10000);

        $condenser = new ToolResultCondenser(
            new StructureReducer(),
            null,
            null,
            $this->app['config']->get('llm-client.tool_result_condensation')
        );

        Event::fake(ToolResultCondensed::class);

        $result = $condenser->condense(
            $this->conversation->id,
            'download_file',
            $binaryContent
        );

        // Binary should pass through without condensation.
        $this->assertFalse($result['condensed']);
        $this->assertSame($binaryContent, $result['content']);
        Event::assertNotDispatched(ToolResultCondensed::class);
    }

    public function test_config_disabled_skips_condensation(): void
    {
        // Large JSON result.
        $items = [];
        for ($i = 0; $i < 100; $i++) {
            $items[] = ['id' => $i, 'data' => str_repeat('x', 100)];
        }
        $largeJson = json_encode($items);

        // Disable condensation via config.
        $disabledConfig = [
            'enabled' => false,
            'threshold_tokens' => 2000,
        ];

        $condenser = new ToolResultCondenser(
            new StructureReducer(),
            null,
            null,
            $disabledConfig
        );

        Event::fake(ToolResultCondensed::class);

        $result = $condenser->condense(
            $this->conversation->id,
            'list_items',
            $largeJson
        );

        // Should pass through when disabled.
        $this->assertFalse($result['condensed']);
        $this->assertSame($largeJson, $result['content']);
        Event::assertNotDispatched(ToolResultCondensed::class);
    }

    public function test_cache_retrieval_returns_null_for_expired_key(): void
    {
        $condenser = new ToolResultCondenser(
            new StructureReducer(),
            null,
            null,
            $this->app['config']->get('llm-client.tool_result_condensation')
        );

        // Try to retrieve with non-existent reference ID.
        $result = $condenser->get($this->conversation->id, 'nonexistent-ref-id');
        $this->assertNull($result);

        // Try to retrieve with wrong conversation ID.
        $result = $condenser->get('wrong-conversation-id', 'some-ref');
        $this->assertNull($result);
    }

    public function test_prose_condensation_with_truncation_fallback(): void
    {
        // Large prose text (no LLM provider configured → truncation fallback).
        $proseContent = str_repeat(
            'This is a paragraph of text that describes something in detail. '
            . 'It contains information about various topics and subjects. '
            . 'The text goes on and on with lots of details.\n\n',
            200
        );

        $tokenCount = ToolResultCondenser::estimateTokens($proseContent);
        $this->assertGreaterThan(2000, $tokenCount, 'Prose should exceed threshold');

        $condenser = new ToolResultCondenser(
            new StructureReducer(),
            null, // No LLM provider → truncation fallback
            null,
            $this->app['config']->get('llm-client.tool_result_condensation')
        );

        Event::fake(ToolResultCondensed::class);

        $result = $condenser->condense(
            $this->conversation->id,
            'read_document',
            $proseContent
        );

        // Should use truncation fallback.
        $this->assertTrue($result['condensed']);
        $this->assertSame('truncation', $result['method']);
        $this->assertArrayHasKey('reference_id', $result);

        // Truncated content should include reference indicator.
        $this->assertStringContainsString('condensed', $result['content']);
        $this->assertStringContainsString($result['reference_id'], $result['content']);

        // Full content should be retrievable.
        $cachedContent = $condenser->get(
            $this->conversation->id,
            $result['reference_id']
        );
        $this->assertSame($proseContent, $cachedContent);

        Event::assertDispatched(ToolResultCondensed::class, function ($event) use ($tokenCount) {
            return $event->method === 'truncation'
                && $event->fallback === true
                && $event->originalTokens === $tokenCount;
        });
    }

    public function test_multiple_tool_results_independent_caching(): void
    {
        // Generate multiple large tool results (each ~2500+ tokens).
        $results = [];
        for ($i = 0; $i < 3; $i++) {
            $items = [];
            for ($j = 0; $j < 100; $j++) {
                $items[] = [
                    'id' => sprintf('batch-%d-item-%d', $i, $j),
                    'data' => str_repeat('x', 100),
                    'description' => str_repeat('This is a description that adds more content to each item ', 3),
                ];
            }
            $results[] = json_encode($items);
        }

        $condenser = new ToolResultCondenser(
            new StructureReducer(),
            null,
            null,
            $this->app['config']->get('llm-client.tool_result_condensation')
        );

        // Condense all three results.
        $condensedResults = [];
        foreach ($results as $idx => $rawResult) {
            $condensedResults[] = $condenser->condense(
                $this->conversation->id,
                'batch_tool_' . $idx,
                $rawResult
            );
        }

        // Verify each result is independently cached.
        foreach ($condensedResults as $idx => $condensed) {
            $this->assertTrue($condensed['condensed']);

            $referenceId = $condensed['reference_id'];
            $cachedContent = $condenser->get(
                $this->conversation->id,
                $referenceId
            );

            $this->assertSame(
                $results[$idx],
                $cachedContent,
                "Cached content for batch {$idx} should match original"
            );

            // Reference IDs should be unique.
            $uniqueRefIds = array_column($condensedResults, 'reference_id');
            $this->assertCount(
                count($uniqueRefIds),
                array_unique($uniqueRefIds),
                'All reference IDs should be unique'
            );
        }
    }
}
