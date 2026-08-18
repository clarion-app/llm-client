<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\Models\McpClientTool;
use ClarionApp\LlmClient\Services\McpClientToolExecutor;
use ClarionApp\LlmClient\Services\McpTransport;
use ClarionApp\LlmClient\Services\McpTransportFactory;
use ClarionApp\LlmClient\ValueObjects\McpTransportKind;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ReferenceMcpServer\Protocol;
use Tests\Fixtures\ReferenceMcpServer\ReferenceMcpServer;
use Tests\TestCase;

/**
 * McpClientToolExecutor::execute() -- every transport-level failure
 * (unreachable, slow/timed-out, misbehaving) plus a generic thrown
 * exception from the transport itself is caught and converted into the
 * exact {content: [{type: "text", text: "Error: ..."}], isError: true}
 * shape McpToolExecutor::errorResult() already produces for a failed
 * built-in call -- never an uncaught exception escaping execute(), in
 * every case. A successful call's own raw content envelope (including a
 * server-reported tool-level isError: true) passes through unchanged,
 * since that is a legitimate response, not a transport failure.
 *
 * Written before McpClientToolExecutor exists -- expected to FAIL red
 * (class not found) until it is created.
 */
class McpClientToolExecutorFailureIsolationTest extends TestCase
{
    private ?ReferenceMcpServer $referenceServer = null;

    protected function tearDown(): void
    {
        $this->referenceServer?->stopHttp();
        $this->referenceServer = null;
        Mockery::close();

        parent::tearDown();
    }

    private function executor(): McpClientToolExecutor
    {
        return new McpClientToolExecutor(new McpTransportFactory());
    }

    private function makeServer(string $url): McpClientServer
    {
        return McpClientServer::create([
            'name' => 'Executor Test Server',
            'transport' => McpTransportKind::StreamableHttp,
            'url' => $url,
            'user_id' => (string) Str::uuid(),
        ]);
    }

    private function makeTool(McpClientServer $server, string $name): McpClientTool
    {
        return McpClientTool::create([
            'server_id' => $server->id,
            'synthetic_operation_id' => "mcp:{$server->id}:{$name}",
            'name' => $name,
            'input_schema' => ['type' => 'object'],
            'last_seen_at' => now(),
        ]);
    }

    #[Test]
    public function a_successful_call_returns_the_raw_content_envelope_unchanged(): void
    {
        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp(Protocol::MODE_HAPPY_PATH);
        $server = $this->makeServer($url);
        $tool = $this->makeTool($server, 'reference_echo');

        $result = $this->executor()->execute($server, $tool, ['text' => 'hello from the executor']);

        $this->assertFalse($result['isError']);
        $this->assertSame('hello from the executor', $result['content'][0]['text']);
    }

    #[Test]
    public function a_server_reported_tool_level_failure_passes_through_unchanged_not_rewrapped(): void
    {
        // reference_fail always reports isError: true from a well-formed
        // response -- a legitimate tool-level failure, not a transport
        // failure, so it must not be converted to the "Error: ..." shape.
        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp(Protocol::MODE_HAPPY_PATH);
        $server = $this->makeServer($url);
        $tool = $this->makeTool($server, 'reference_fail');

        $result = $this->executor()->execute($server, $tool, []);

        $this->assertTrue($result['isError']);
        $this->assertStringNotContainsString('Error: ', $result['content'][0]['text']);
        $this->assertSame('reference_fail always reports a tool-level failure.', $result['content'][0]['text']);
    }

    #[Test]
    public function an_unreachable_server_is_converted_to_the_standard_error_envelope(): void
    {
        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp(Protocol::MODE_UNREACHABLE);
        $server = $this->makeServer($url);
        $tool = $this->makeTool($server, 'reference_echo');

        $result = $this->executor()->execute($server, $tool, ['text' => 'x']);

        $this->assertTrue($result['isError']);
        $this->assertStringStartsWith('Error: ', $result['content'][0]['text']);
    }

    #[Test]
    public function a_slow_server_that_exceeds_the_call_timeout_is_converted_to_the_standard_error_envelope(): void
    {
        config(['llm-client.mcp_client.call_timeout_seconds' => 1]);

        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp(Protocol::MODE_SLOW);
        $server = $this->makeServer($url);
        $tool = $this->makeTool($server, 'reference_echo');

        $result = $this->executor()->execute($server, $tool, ['text' => 'x']);

        $this->assertTrue($result['isError']);
        $this->assertStringStartsWith('Error: ', $result['content'][0]['text']);
    }

    #[Test]
    public function a_misbehaving_server_returning_malformed_json_is_converted_to_the_standard_error_envelope(): void
    {
        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp(Protocol::MODE_MISBEHAVING);
        $server = $this->makeServer($url);
        $tool = $this->makeTool($server, 'reference_echo');

        $result = $this->executor()->execute($server, $tool, ['text' => 'x']);

        $this->assertTrue($result['isError']);
        $this->assertStringStartsWith('Error: ', $result['content'][0]['text']);
    }

    #[Test]
    public function a_generic_throwable_from_the_transport_is_converted_to_the_standard_error_envelope(): void
    {
        $server = McpClientServer::create([
            'name' => 'Mocked Transport Server',
            'transport' => McpTransportKind::StreamableHttp,
            'url' => 'https://example.test/mcp',
            'user_id' => (string) Str::uuid(),
        ]);
        $tool = $this->makeTool($server, 'reference_echo');

        $transport = Mockery::mock(McpTransport::class);
        $transport->shouldReceive('initialize')->once()->andThrow(new \RuntimeException('kaboom, unexpectedly'));

        $factory = Mockery::mock(McpTransportFactory::class);
        $factory->shouldReceive('for')->once()->andReturn($transport);

        $executor = new McpClientToolExecutor($factory);
        $result = $executor->execute($server, $tool, []);

        $this->assertTrue($result['isError']);
        $this->assertStringStartsWith('Error: ', $result['content'][0]['text']);
        $this->assertStringContainsString('kaboom, unexpectedly', $result['content'][0]['text']);
    }

    #[Test]
    public function no_exception_ever_escapes_execute_across_every_failure_mode(): void
    {
        $modes = [Protocol::MODE_UNREACHABLE, Protocol::MODE_MISBEHAVING];

        foreach ($modes as $mode) {
            $referenceServer = new ReferenceMcpServer();
            $url = $referenceServer->startHttp($mode);
            $server = $this->makeServer($url);
            $tool = $this->makeTool($server, 'reference_echo');

            $result = $this->executor()->execute($server, $tool, ['text' => 'x']);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('isError', $result);
            $referenceServer->stopHttp();
        }
    }
}
