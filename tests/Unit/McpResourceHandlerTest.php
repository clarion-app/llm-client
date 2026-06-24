<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\McpResourceHandler;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\UrlValidator;
use Illuminate\Support\Str;
use Mockery;

class McpResourceHandlerTest extends TestCase
{
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
        $userId = (string) Str::uuid();
        $convId = (string) Str::uuid();

        $conversation = $this->makeMockConversation($convId, $userId, 'Test Chat', 'gpt-4', 5);

        $mockQuery = Mockery::mock('Illuminate\Database\Eloquent\Builder');
        $mockQuery->shouldReceive('where')->with('user_id', $userId)->andReturnSelf();
        $mockQuery->shouldReceive('withCount')->with('messages')->andReturnSelf();
        $mockQuery->shouldReceive('orderBy')->with('updated_at', 'desc')->andReturnSelf();
        $mockQuery->shouldReceive('skip')->with(0)->andReturnSelf();
        $mockQuery->shouldReceive('take')->with(51)->andReturn($mockQuery);
        $mockQuery->shouldReceive('get')->andReturn(collect([$conversation]));

        $mock = Mockery::mock('alias:' . Conversation::class);
        $mock->shouldReceive('query')->andReturn($mockQuery);

        $handler = new McpResourceHandler();
        $result = $handler->listResources($userId);

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
        $userId = (string) Str::uuid();
        $conversations = collect();
        for ($i = 0; $i < 51; $i++) {
            $conversations->push($this->makeMockConversation((string) Str::uuid(), $userId, "Chat {$i}", 'gpt-4', $i + 1));
        }

        $mockQuery = Mockery::mock('Illuminate\Database\Eloquent\Builder');
        $mockQuery->shouldReceive('where')->with('user_id', $userId)->andReturnSelf();
        $mockQuery->shouldReceive('withCount')->with('messages')->andReturnSelf();
        $mockQuery->shouldReceive('orderBy')->with('updated_at', 'desc')->andReturnSelf();
        $mockQuery->shouldReceive('skip')->with(0)->andReturnSelf();
        $mockQuery->shouldReceive('take')->with(51)->andReturn($mockQuery);
        $mockQuery->shouldReceive('get')->andReturn($conversations);

        $mock = Mockery::mock('alias:' . Conversation::class);
        $mock->shouldReceive('query')->andReturn($mockQuery);

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
        $userId = (string) Str::uuid();

        $mockQuery = Mockery::mock('Illuminate\Database\Eloquent\Builder');
        $mockQuery->shouldReceive('where')->with('user_id', $userId)->once()->andReturnSelf();
        $mockQuery->shouldReceive('withCount')->with('messages')->andReturnSelf();
        $mockQuery->shouldReceive('orderBy')->with('updated_at', 'desc')->andReturnSelf();
        $mockQuery->shouldReceive('skip')->with(0)->andReturnSelf();
        $mockQuery->shouldReceive('take')->with(51)->andReturn($mockQuery);
        $mockQuery->shouldReceive('get')->andReturn(collect());

        $mock = Mockery::mock('alias:' . Conversation::class);
        $mock->shouldReceive('query')->andReturn($mockQuery);

        $handler = new McpResourceHandler();
        $result = $handler->listResources($userId);

        $this->assertEmpty($result['resources']);
    }

    /** @test */
    public function readResource_with_conversation_uri_returns_message_history_as_json_with_metadata()
    {
        $userId = (string) Str::uuid();
        $convId = (string) Str::uuid();
        $uri = "conversation://{$convId}";

        $message1 = (object) ['role' => 'user', 'content' => 'Hello', 'created_at' => '2026-04-09 10:00:00'];
        $message2 = (object) ['role' => 'assistant', 'content' => 'Hi there!', 'created_at' => '2026-04-09 10:00:05'];

        $conversation = $this->makeMockConversation($convId, $userId, 'Test Chat', 'gpt-4', 2);

        $messagesQuery = Mockery::mock('Illuminate\Database\Eloquent\Builder');
        $messagesQuery->shouldReceive('orderBy')->with('created_at', 'asc')->andReturnSelf();
        $messagesQuery->shouldReceive('skip')->with(0)->andReturnSelf();
        $messagesQuery->shouldReceive('take')->with(101)->andReturn($messagesQuery);
        $messagesQuery->shouldReceive('get')->andReturn(collect([$message1, $message2]));

        $conversation->shouldReceive('messages')->andReturn($messagesQuery);

        $mock = Mockery::mock('alias:' . Conversation::class);
        $mock->shouldReceive('where')->with('id', $convId)->andReturnSelf();
        $mock->shouldReceive('first')->andReturn($conversation);

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
        $userId = (string) Str::uuid();
        $convId = (string) Str::uuid();
        $uri = "conversation://{$convId}";

        $messages = collect();
        for ($i = 0; $i < 101; $i++) {
            $messages->push((object) ['role' => 'user', 'content' => "Message {$i}", 'created_at' => "2026-04-09 10:00:{$i}"]);
        }

        $conversation = $this->makeMockConversation($convId, $userId, 'Test Chat', 'gpt-4', 150);

        $messagesQuery = Mockery::mock('Illuminate\Database\Eloquent\Builder');
        $messagesQuery->shouldReceive('orderBy')->with('created_at', 'asc')->andReturnSelf();
        $messagesQuery->shouldReceive('skip')->with(0)->andReturnSelf();
        $messagesQuery->shouldReceive('take')->with(101)->andReturn($messagesQuery);
        $messagesQuery->shouldReceive('get')->andReturn($messages);

        $conversation->shouldReceive('messages')->andReturn($messagesQuery);

        $mock = Mockery::mock('alias:' . Conversation::class);
        $mock->shouldReceive('where')->with('id', $convId)->andReturnSelf();
        $mock->shouldReceive('first')->andReturn($conversation);

        $handler = new McpResourceHandler();
        $result = $handler->readResource($userId, $uri);

        $data = json_decode($result['contents'][0]['text'], true);
        $this->assertCount(100, $data['messages']);
        $this->assertNotNull($data['pagination']['nextCursor']);
    }

    /** @test */
    public function readResource_returns_32002_error_for_conversation_owned_by_different_user()
    {
        $userId = (string) Str::uuid();
        $otherUserId = (string) Str::uuid();
        $convId = (string) Str::uuid();
        $uri = "conversation://{$convId}";

        $conversation = $this->makeMockConversation($convId, $otherUserId, 'Other User Chat', 'gpt-4', 5);

        $mock = Mockery::mock('alias:' . Conversation::class);
        $mock->shouldReceive('where')->with('id', $convId)->andReturnSelf();
        $mock->shouldReceive('first')->andReturn($conversation);

        $handler = new McpResourceHandler();
        $result = $handler->readResource($userId, $uri);

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals(-32002, $result['error']['code']);
    }

    /** @test */
    public function readResource_returns_32002_error_for_nonexistent_conversation_uuid()
    {
        $userId = (string) Str::uuid();
        $convId = (string) Str::uuid();
        $uri = "conversation://{$convId}";

        $mock = Mockery::mock('alias:' . Conversation::class);
        $mock->shouldReceive('where')->with('id', $convId)->andReturnSelf();
        $mock->shouldReceive('first')->andReturn(null);

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

    // --- Helpers ---

    private function makeMockConversation(string $id, string $userId, string $title, string $model, int $messagesCount)
    {
        $conv = Mockery::mock('ClarionApp\LlmClient\Models\Conversation');
        $conv->shouldReceive('getAttribute')->with('id')->andReturn($id);
        $conv->shouldReceive('getAttribute')->with('user_id')->andReturn($userId);
        $conv->shouldReceive('getAttribute')->with('title')->andReturn($title);
        $conv->shouldReceive('getAttribute')->with('model')->andReturn($model);
        $conv->shouldReceive('getAttribute')->with('channel')->andReturn('web');
        $conv->shouldReceive('getAttribute')->with('messages_count')->andReturn($messagesCount);
        $conv->shouldReceive('getAttribute')->with('updated_at')->andReturn('2026-04-09 10:00:00');
        $conv->id = $id;
        $conv->user_id = $userId;
        $conv->title = $title;
        $conv->model = $model;
        $conv->channel = 'web';
        $conv->messages_count = $messagesCount;
        $conv->updated_at = '2026-04-09 10:00:00';
        return $conv;
    }
}
