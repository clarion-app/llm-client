<?php

namespace Tests\Unit\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\DeterministicEmbedder;
use Tests\Integration\Harness\ResponseScript;
use Tests\Integration\Harness\ScriptedTransport;

class ScriptedTransportTest extends TestCase
{
    /* ------------------------------------------------------------------ */
    /*  T011: ScriptedTransport unit tests                                 */
    /* ------------------------------------------------------------------ */

    public function test_chat_routing_by_request_path()
    {
        $script = new ResponseScript();
        $script->finalAnswer('Hello from chat');

        $transport = new ScriptedTransport($script);
        $client = new Client(['handler' => $transport->handlerStack()]);

        $request = new Request('POST', 'https://api.openai.com/v1/chat/completions', [
            'Content-Type' => 'application/json',
        ], json_encode([
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'model' => 'gpt-4',
        ]));

        $response = $client->send($request);
        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertEquals('Hello from chat', $body['choices'][0]['message']['content']);
        $this->assertEquals('stop', $body['choices'][0]['finish_reason']);
    }

    public function test_embedding_routing_by_request_path()
    {
        $script = new ResponseScript();
        $embedder = new DeterministicEmbedder(768);

        $transport = new ScriptedTransport($script, $embedder);
        $client = new Client(['handler' => $transport->handlerStack()]);

        $request = new Request('POST', 'https://api.openai.com/v1/embeddings', [
            'Content-Type' => 'application/json',
        ], json_encode([
            'input' => ['test embedding content'],
            'model' => 'text-embedding-ada-002',
        ]));

        $response = $client->send($request);
        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertArrayHasKey('data', $body);
        $this->assertCount(1, $body['data']);
        $this->assertCount(768, $body['data'][0]['embedding']);
    }

    public function test_capture_is_non_destructive()
    {
        $script = new ResponseScript();
        $script->finalAnswer('Response served');

        $transport = new ScriptedTransport($script);
        $client = new Client(['handler' => $transport->handlerStack()]);

        $request = new Request('POST', 'https://api.openai.com/v1/chat/completions', [
            'Content-Type' => 'application/json',
        ], json_encode([
            'messages' => [['role' => 'user', 'content' => 'Test']],
            'model' => 'gpt-4',
        ]));

        // Send request
        $response = $client->send($request);

        // Response should be served correctly
        $body = json_decode($response->getBody()->getContents(), true);
        $this->assertEquals('Response served', $body['choices'][0]['message']['content']);

        // Capture should also work
        $payloads = $transport->capturedPayloads();
        $this->assertCount(1, $payloads);
        $this->assertEquals('chat', $payloads[0]->kind);
        $this->assertEquals('gpt-4', $payloads[0]->model);
        $this->assertEquals(1, $payloads[0]->messageCount());
    }

    public function test_exhaustion_throws_with_rich_error_message()
    {
        $script = new ResponseScript();
        $script->finalAnswer('First response');

        $transport = new ScriptedTransport($script);
        $client = new Client(['handler' => $transport->handlerStack()]);

        // First request succeeds
        $request1 = new Request('POST', 'https://api.openai.com/v1/chat/completions', [
            'Content-Type' => 'application/json',
        ], json_encode([
            'messages' => [['role' => 'user', 'content' => 'First']],
            'model' => 'gpt-4',
        ]));
        $client->send($request1);

        // Second request should fail with rich error
        $request2 = new Request('POST', 'https://api.openai.com/v1/chat/completions', [
            'Content-Type' => 'application/json',
        ], json_encode([
            'messages' => [
                ['role' => 'user', 'content' => 'Second'],
                ['role' => 'assistant', 'content' => 'First response'],
            ],
            'model' => 'gpt-4',
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Response script exhausted/i');

        try {
            $client->send($request2);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            // Check for rich error info - includes lane and turn number
            $this->assertStringContainsString('agent_turn', $message);
            $this->assertStringContainsString('Turn:', $message);
            throw $e;
        }
    }

    public function test_embeddings_disabled_surfaces_transport_level_failure()
    {
        $script = new ResponseScript();
        // No embedder passed (null) = embeddings disabled
        $transport = new ScriptedTransport($script, null);
        $client = new Client(['handler' => $transport->handlerStack()]);

        $request = new Request('POST', 'https://api.openai.com/v1/embeddings', [
            'Content-Type' => 'application/json',
        ], json_encode([
            'input' => ['test'],
        ]));

        // Should throw a transport-level error when embedder is null
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/embeddings.*disabled|no.*embedding.*provider/i');

        $client->send($request);
    }

    public function test_has_unconsumed_steps()
    {
        $script = new ResponseScript();
        $script->finalAnswer('First')
            ->finalAnswer('Second');

        $transport = new ScriptedTransport($script);

        // Initially has unconsumed steps
        $this->assertTrue($transport->hasUnconsumedSteps());

        $client = new Client(['handler' => $transport->handlerStack()]);

        // Consume one step
        $request = new Request('POST', 'https://api.openai.com/v1/chat/completions', [
            'Content-Type' => 'application/json',
        ], json_encode([
            'messages' => [['role' => 'user', 'content' => 'Test']],
        ]));
        $client->send($request);

        // Still has unconsumed steps
        $this->assertTrue($transport->hasUnconsumedSteps());

        // Consume second step
        $request2 = new Request('POST', 'https://api.openai.com/v1/chat/completions', [
            'Content-Type' => 'application/json',
        ], json_encode([
            'messages' => [['role' => 'user', 'content' => 'Test2']],
        ]));
        $client->send($request2);

        // No more unconsumed steps
        $this->assertFalse($transport->hasUnconsumedSteps());
    }

    public function test_multiple_requests_captured_in_order()
    {
        $script = new ResponseScript();
        $script->finalAnswer('First')
            ->finalAnswer('Second')
            ->finalAnswer('Third');

        $transport = new ScriptedTransport($script);
        $client = new Client(['handler' => $transport->handlerStack()]);

        // Send three requests
        for ($i = 1; $i <= 3; $i++) {
            $request = new Request('POST', 'https://api.openai.com/v1/chat/completions', [
                'Content-Type' => 'application/json',
            ], json_encode([
                'messages' => [['role' => 'user', 'content' => "Message {$i}"]],
                'model' => 'gpt-4',
            ]));
            $client->send($request);
        }

        $payloads = $transport->capturedPayloads();
        $this->assertCount(3, $payloads);
        $this->assertEquals('Message 1', $payloads[0]->messages[0]['content']);
        $this->assertEquals('Message 2', $payloads[1]->messages[0]['content']);
        $this->assertEquals('Message 3', $payloads[2]->messages[0]['content']);
    }

    public function test_tool_request_step_is_served_correctly()
    {
        $script = new ResponseScript();
        $script->toolRequest('search', ['query' => 'test query'])
            ->finalAnswer('Search complete');

        $transport = new ScriptedTransport($script);
        $client = new Client(['handler' => $transport->handlerStack()]);

        // First request - should get tool call
        $request = new Request('POST', 'https://api.openai.com/v1/chat/completions', [
            'Content-Type' => 'application/json',
        ], json_encode([
            'messages' => [['role' => 'user', 'content' => 'Search for something']],
        ]));
        $response = $client->send($request);
        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertEquals('tool_calls', $body['choices'][0]['finish_reason']);
        $this->assertEquals('search', $body['choices'][0]['message']['tool_calls'][0]['function']['name']);

        // Second request - should get final answer
        $request2 = new Request('POST', 'https://api.openai.com/v1/chat/completions', [
            'Content-Type' => 'application/json',
        ], json_encode([
            'messages' => [
                ['role' => 'user', 'content' => 'Search for something'],
                ['role' => 'tool', 'content' => 'Results'],
            ],
        ]));
        $response2 = $client->send($request2);
        $body2 = json_decode($response2->getBody()->getContents(), true);

        $this->assertEquals('stop', $body2['choices'][0]['finish_reason']);
        $this->assertEquals('Search complete', $body2['choices'][0]['message']['content']);
    }

    public function test_embedding_response_includes_usage()
    {
        $script = new ResponseScript();
        $embedder = new DeterministicEmbedder(768);

        $transport = new ScriptedTransport($script, $embedder);
        $client = new Client(['handler' => $transport->handlerStack()]);

        $request = new Request('POST', 'https://api.openai.com/v1/embeddings', [
            'Content-Type' => 'application/json',
        ], json_encode([
            'input' => ['test content'],
        ]));

        $response = $client->send($request);
        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertArrayHasKey('usage', $body);
        $this->assertArrayHasKey('prompt_tokens', $body['usage']);
    }
}
