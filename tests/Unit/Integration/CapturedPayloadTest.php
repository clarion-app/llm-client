<?php

namespace Tests\Unit\Integration;

use ClarionApp\HttpQueue\HttpRequest;
use GuzzleHttp\Psr7\Request;
use Tests\Integration\Harness\CapturedPayload;
use Tests\TestCase;

class CapturedPayloadTest extends TestCase
{
    /* ------------------------------------------------------------------ */
    /*  T005: Both constructors normalize equivalent sync/stream requests  */
    /* ------------------------------------------------------------------ */

    public function test_sync_and_stream_guzzle_requests_normalize_to_equal_payload()
    {
        $messages = [
            ['role' => 'user', 'content' => 'Hello, how are you?'],
        ];
        $tools = [
            ['type' => 'function', 'function' => ['name' => 'search', 'parameters' => []]],
        ];

        // Sync request body (stream => false)
        $syncBody = json_encode([
            'model' => 'gpt-4',
            'messages' => $messages,
            'tools' => $tools,
            'temperature' => 1.0,
            'stream' => false,
        ]);
        $syncRequest = new Request('POST', 'https://api.openai.com/v1/chat/completions', [
            'Content-Type' => 'application/json',
        ], $syncBody);

        // Stream request body (stream => true)
        $streamBody = json_encode([
            'model' => 'gpt-4',
            'messages' => $messages,
            'tools' => $tools,
            'temperature' => 1.0,
            'stream' => true,
        ]);
        $streamRequest = new Request('POST', 'https://api.openai.com/v1/chat/completions', [
            'Content-Type' => 'application/json',
        ], $streamBody);

        $syncPayload = CapturedPayload::fromGuzzleRequest($syncRequest);
        $streamPayload = CapturedPayload::fromGuzzleRequest($streamRequest);

        // Both should have same messages, tools, model
        $this->assertEquals($syncPayload->messages, $streamPayload->messages);
        $this->assertEquals($syncPayload->tools, $streamPayload->tools);
        $this->assertEquals($syncPayload->model, $streamPayload->model);
        $this->assertEquals('chat', $syncPayload->kind);
        $this->assertEquals('chat', $streamPayload->kind);
        // Source should differ
        $this->assertEquals('sync', $syncPayload->source);
        $this->assertEquals('stream', $streamPayload->source);
    }

    public function test_guzzle_and_httprequest_normalize_to_equal_payload()
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => 'What is the weather?'],
        ];
        $tools = [
            ['type' => 'function', 'function' => ['name' => 'get_weather', 'parameters' => []]],
        ];

        // Guzzle request
        $body = json_encode([
            'model' => 'gpt-3.5-turbo',
            'messages' => $messages,
            'tools' => $tools,
            'temperature' => 0.7,
            'stream' => false,
        ]);
        $guzzleRequest = new Request('POST', 'https://api.openai.com/v1/chat/completions', [
            'Content-Type' => 'application/json',
        ], $body);

        // HttpRequest (from http-queue)
        $httpRequest = new HttpRequest();
        $httpRequest->url = 'https://api.openai.com/v1/chat/completions';
        $httpRequest->method = 'POST';
        $httpRequest->body = (object) [
            'model' => 'gpt-3.5-turbo',
            'messages' => $messages,
            'tools' => $tools,
        ];

        $guzzlePayload = CapturedPayload::fromGuzzleRequest($guzzleRequest);
        $httpPayload = CapturedPayload::fromHttpRequest($httpRequest);

        // The assertion surface normalizes across both entry paths (FR-007a),
        // so payload assertions can be written once.
        $this->assertEquals($guzzlePayload->messages, $httpPayload->messages);
        $this->assertEquals($guzzlePayload->tools, $httpPayload->tools);
        $this->assertEquals($guzzlePayload->model, $httpPayload->model);

        // `source` is the deliberate exception: it records which path produced
        // the payload. HttpRequests only come from dispatched stream jobs.
        $this->assertEquals('sync', $guzzlePayload->source);
        $this->assertEquals('stream', $httpPayload->source);
    }

    /* ------------------------------------------------------------------ */
    /*  Helper method tests                                                */
    /* ------------------------------------------------------------------ */

    public function test_containsText_returns_true_when_match()
    {
        $body = json_encode([
            'messages' => [
                ['role' => 'user', 'content' => 'What is the capital of France?'],
            ],
            'stream' => false,
        ]);
        $request = new Request('POST', 'https://api.openai.com/v1/chat/completions', [], $body);
        $payload = CapturedPayload::fromGuzzleRequest($request);

        $this->assertTrue($payload->containsText('capital'));
        $this->assertTrue($payload->containsText('France'));
        $this->assertFalse($payload->containsText('Germany'));
    }

    public function test_containsText_checks_all_messages()
    {
        $body = json_encode([
            'messages' => [
                ['role' => 'system', 'content' => 'You are an assistant.'],
                ['role' => 'user', 'content' => 'Search for billing errors'],
                ['role' => 'assistant', 'content' => 'I found some results.'],
            ],
            'stream' => false,
        ]);
        $request = new Request('POST', 'https://api.openai.com/v1/chat/completions', [], $body);
        $payload = CapturedPayload::fromGuzzleRequest($request);

        $this->assertTrue($payload->containsText('billing'));
        $this->assertTrue($payload->containsText('results'));
    }

    public function test_systemContains_returns_true_when_match()
    {
        $body = json_encode([
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
            ],
            'stream' => false,
        ]);
        $request = new Request('POST', 'https://api.openai.com/v1/chat/completions', [], $body);
        $payload = CapturedPayload::fromGuzzleRequest($request);

        // No system message set explicitly, so system should be null or empty
        $this->assertFalse($payload->systemContains('nonexistent'));
    }

    public function test_systemContains_with_system_in_http_request()
    {
        $httpRequest = new HttpRequest();
        $httpRequest->body = (object) [
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
            ],
            'system' => 'You are a billing assistant for Acme Corp.',
        ];

        $payload = CapturedPayload::fromHttpRequest($httpRequest);
        $this->assertTrue($payload->systemContains('billing'));
        $this->assertTrue($payload->systemContains('Acme'));
        $this->assertFalse($payload->systemContains('Google'));
    }

    public function test_messageCount_returns_correct_count()
    {
        $body = json_encode([
            'messages' => [
                ['role' => 'system', 'content' => 'Be helpful.'],
                ['role' => 'user', 'content' => 'Hello'],
                ['role' => 'assistant', 'content' => 'Hi there!'],
                ['role' => 'user', 'content' => 'What is 2+2?'],
            ],
            'stream' => false,
        ]);
        $request = new Request('POST', 'https://api.openai.com/v1/chat/completions', [], $body);
        $payload = CapturedPayload::fromGuzzleRequest($request);

        $this->assertEquals(4, $payload->messageCount());
    }

    public function test_messageCount_with_empty_messages()
    {
        $body = json_encode([
            'messages' => [],
            'stream' => false,
        ]);
        $request = new Request('POST', 'https://api.openai.com/v1/chat/completions', [], $body);
        $payload = CapturedPayload::fromGuzzleRequest($request);

        $this->assertEquals(0, $payload->messageCount());
    }

    public function test_estimatedTokens_applies_estimator_to_each_message()
    {
        $body = json_encode([
            'messages' => [
                ['role' => 'user', 'content' => 'Hello world'],
                ['role' => 'assistant', 'content' => 'Hi there'],
            ],
            'stream' => false,
        ]);
        $request = new Request('POST', 'https://api.openai.com/v1/chat/completions', [], $body);
        $payload = CapturedPayload::fromGuzzleRequest($request);

        // Simple word count estimator
        $estimator = function ($content) {
            return is_string($content) ? str_word_count($content) : 0;
        };

        // "Hello world" = 2 words, "Hi there" = 2 words
        $this->assertEquals(4, $payload->estimatedTokens($estimator));
    }

    public function test_estimatedTokens_with_complex_estimator()
    {
        $body = json_encode([
            'messages' => [
                ['role' => 'user', 'content' => 'Test message'],
            ],
            'stream' => false,
        ]);
        $request = new Request('POST', 'https://api.openai.com/v1/chat/completions', [], $body);
        $payload = CapturedPayload::fromGuzzleRequest($request);

        // Character length estimator
        $estimator = function ($content) {
            return is_string($content) ? strlen($content) : 0;
        };

        $this->assertEquals(strlen('Test message'), $payload->estimatedTokens($estimator));
    }

    public function test_indexOfText_returns_first_matching_index()
    {
        $body = json_encode([
            'messages' => [
                ['role' => 'system', 'content' => 'You are helpful.'],
                ['role' => 'user', 'content' => 'Search for billing service'],
                ['role' => 'assistant', 'content' => 'I will search.'],
                ['role' => 'user', 'content' => 'Also check timezones'],
            ],
            'stream' => false,
        ]);
        $request = new Request('POST', 'https://api.openai.com/v1/chat/completions', [], $body);
        $payload = CapturedPayload::fromGuzzleRequest($request);

        $this->assertEquals(1, $payload->indexOfText('billing'));
        $this->assertEquals(3, $payload->indexOfText('timezones'));
        // billing appears before timezones
        $this->assertLessThan($payload->indexOfText('timezones'), $payload->indexOfText('billing'));
    }

    public function test_indexOfText_returns_minus_one_when_not_found()
    {
        $body = json_encode([
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
            ],
            'stream' => false,
        ]);
        $request = new Request('POST', 'https://api.openai.com/v1/chat/completions', [], $body);
        $payload = CapturedPayload::fromGuzzleRequest($request);

        $this->assertEquals(-1, $payload->indexOfText('nonexistent text'));
    }

    public function test_embedding_kind_from_guzzle()
    {
        $body = json_encode([
            'input' => 'Hello world',
            'model' => 'text-embedding-3-small',
        ]);
        $request = new Request('POST', 'https://api.openai.com/v1/embeddings', [], $body);
        $payload = CapturedPayload::fromGuzzleRequest($request, 'embedding');

        $this->assertEquals('embedding', $payload->kind);
        $this->assertEquals('text-embedding-3-small', $payload->model);
    }

    public function test_no_tools_when_absent()
    {
        $body = json_encode([
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
            ],
            'stream' => false,
        ]);
        $request = new Request('POST', 'https://api.openai.com/v1/chat/completions', [], $body);
        $payload = CapturedPayload::fromGuzzleRequest($request);

        $this->assertEquals([], $payload->tools);
    }
}
