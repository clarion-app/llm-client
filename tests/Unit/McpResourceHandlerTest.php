<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\McpResourceHandler;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\UrlValidator;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class McpResourceHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        Mockery::close();
        parent::tearDown();
    }

    // --- US3 Tests: Conversation Resources ---

    /** @test */
    public function listResources_returns_users_conversations_as_mcp_resource_entries_with_conversation_uri()
    {
        $conversation = Conversation::factory()->create([
            'title' => 'Test Chat',
            'user_id' => 'test-user-id',
        ]);

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Hello',
        ]);

        $handler = new McpResourceHandler();
        $result = $handler->listResources('test-user-id');

        $this->assertArrayHasKey('resources', $result);
        $this->assertCount(1, $result['resources']);
        $this->assertStringStartsWith('conversation://', $result['resources'][0]['uri']);
        $this->assertEquals('Test Chat', $result['resources'][0]['name']);
        $this->assertEquals('application/json', $result['resources'][0]['mimeType']);
        $this->assertArrayHasKey('nextCursor', $result);
    }

    /** @test */
    public function listResources_returns_paginated_results_using_base64_cursor()
    {
        $userId = 'test-user-id';
        $conversations = Conversation::factory()->count(51)->create([
            'user_id' => $userId,
        ]);
        foreach ($conversations as $conv) {
            Message::factory()->create([
                'conversation_id' => $conv->id,
                'role' => 'user',
                'content' => 'Hello',
            ]);
        }

        $handler = new McpResourceHandler();
        $result = $handler->listResources($userId);

        $this->assertCount(50, $result['resources']);
        $this->assertNotNull($result['nextCursor']);

        $decoded = json_decode(base64_decode($result['nextCursor']), true);
        $this->assertArrayHasKey('offset', $decoded);
    }

    /** @test */
    public function listResources_only_returns_conversations_owned_by_authenticated_user()
    {
        $userId = 'test-user-id';

        $handler = new McpResourceHandler();
        $result = $handler->listResources($userId);

        $this->assertEmpty($result['resources']);
    }

    /** @test */
    public function readResource_with_conversation_uri_returns_message_history_as_json_with_metadata()
    {
        $userId = 'test-user-id';
        $conversation = Conversation::factory()->create([
            'user_id' => $userId,
            'title' => 'Test Chat',
            'model' => 'gpt-4',
        ]);

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Hello',
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Hi there!',
        ]);

        $uri = "conversation://{$conversation->id}";
        $handler = new McpResourceHandler();
        $result = $handler->readResource($userId, $uri);

        $this->assertArrayHasKey('contents', $result);
        $this->assertCount(1, $result['contents']);
        $this->assertEquals($uri, $result['contents'][0]['uri']);
        $this->assertEquals('application/json', $result['contents'][0]['mimeType']);

        $data = json_decode($result['contents'][0]['text'], true);
        $this->assertArrayHasKey('conversation', $data);
        $this->assertArrayHasKey('messages', $data);
        $this->assertArrayHasKey('pagination', $data);
        $this->assertCount(2, $data['messages']);
    }

    /** @test */
    public function readResource_paginates_messages_at_100_per_page_with_cursor()
    {
        $userId = 'test-user-id';
        $conversation = Conversation::factory()->create([
            'user_id' => $userId,
        ]);

        $messages = Message::factory()->count(101)->create([
            'conversation_id' => $conversation->id,
        ]);

        $uri = "conversation://{$conversation->id}";
        $handler = new McpResourceHandler();
        $result = $handler->readResource($userId, $uri);

        $data = json_decode($result['contents'][0]['text'], true);
        $this->assertCount(100, $data['messages']);
        $this->assertNotNull($data['pagination']['nextCursor']);
    }

    /** @test */
    public function readResource_returns_32002_error_for_conversation_owned_by_different_user()
    {
        $userId = 'test-user-id';
        $otherUserId = 'other-user-id';
        $conversation = Conversation::factory()->create([
            'user_id' => $otherUserId,
        ]);

        $uri = "conversation://{$conversation->id}";
        $handler = new McpResourceHandler();
        $result = $handler->readResource($userId, $uri);

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals(-32002, $result['error']['code']);
    }

    /** @test */
    public function readResource_returns_32002_error_for_nonexistent_conversation_uuid()
    {
        $userId = 'test-user-id';
        $convId = (string) Str::uuid();
        $uri = "conversation://{$convId}";

        $handler = new McpResourceHandler();
        $result = $handler->readResource($userId, $uri);

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals(-32002, $result['error']['code']);
    }

    // --- US4 Tests: Page Resources ---

    /** @test */
    public function listResourceTemplates_returns_page_resource_template_with_page_uri_template()
    {
        $handler = new McpResourceHandler();
        $result = $handler->listResourceTemplates();

        $this->assertArrayHasKey('resourceTemplates', $result);
        $this->assertCount(1, $result['resourceTemplates']);
        $this->assertEquals('page://{url}', $result['resourceTemplates'][0]['uriTemplate']);
        $this->assertEquals('Web Page Text', $result['resourceTemplates'][0]['name']);
        $this->assertEquals('text/plain', $result['resourceTemplates'][0]['mimeType']);
        $this->assertArrayHasKey('nextCursor', $result);
    }

    /** @test */
    public function readResource_with_page_uri_validates_url_and_returns_extracted_text()
    {
        $uri = 'page://https://example.com/article';

        $urlMock = Mockery::mock('alias:' . UrlValidator::class);
        $urlMock->shouldReceive('validate')
            ->with('https://example.com/article')
            ->once()
            ->andReturn(['valid' => true]);

        $handler = Mockery::mock(McpResourceHandler::class)->makePartial();
        $handler->shouldReceive('fetchPageText')
            ->with('https://example.com/article')
            ->once()
            ->andReturn('Extracted page text content');

        $result = $handler->readResource('any-user-id', $uri);

        $this->assertArrayHasKey('contents', $result);
        $this->assertEquals($uri, $result['contents'][0]['uri']);
        $this->assertEquals('text/plain', $result['contents'][0]['mimeType']);
        $this->assertEquals('Extracted page text content', $result['contents'][0]['text']);
    }

    /** @test */
    public function readResource_with_page_uri_rejects_private_reserved_ips_with_32602_error()
    {
        $uri = 'page://http://192.168.1.1/admin';

        $urlMock = Mockery::mock('alias:' . UrlValidator::class);
        $urlMock->shouldReceive('validate')
            ->with('http://192.168.1.1/admin')
            ->once()
            ->andReturn(['valid' => false, 'reason' => 'Private IP address']);

        $handler = new McpResourceHandler();
        $result = $handler->readResource('any-user-id', $uri);

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals(-32602, $result['error']['code']);
        $this->assertStringContainsString('URL validation failed', $result['error']['message']);
    }

    /** @test */
    public function readResource_returns_32002_error_for_unsupported_uri_scheme()
    {
        $uri = 'unknown://something';

        $handler = new McpResourceHandler();
        $result = $handler->readResource('any-user-id', $uri);

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals(-32002, $result['error']['code']);
        $this->assertStringContainsString('Unsupported resource URI scheme', $result['error']['message']);
    }

    /** @test */
    public function readResource_with_page_uri_returns_32603_error_when_chrome_driver_unavailable()
    {
        $uri = 'page://https://example.com/article';

        $urlMock = Mockery::mock('alias:' . UrlValidator::class);
        $urlMock->shouldReceive('validate')
            ->with('https://example.com/article')
            ->once()
            ->andReturn(['valid' => true]);

        $handler = Mockery::mock(McpResourceHandler::class)->makePartial();
        $handler->shouldReceive('fetchPageText')
            ->with('https://example.com/article')
            ->once()
            ->andThrow(new \Exception('ChromeDriver connection refused'));

        $result = $handler->readResource('any-user-id', $uri);

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals(-32603, $result['error']['code']);
    }
}
