<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Services\McpTransport;
use ClarionApp\LlmClient\Services\StdioMcpTransport;
use ClarionApp\LlmClient\Services\StreamableHttpMcpTransport;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ReferenceMcpServer\Protocol;
use Tests\Fixtures\ReferenceMcpServer\ReferenceMcpServer;
use Tests\TestCase;

/**
 * The shared-assertions dual-implementation contract test: identical
 * initialize() -> listTools() -> callTool() assertions run against both
 * StreamableHttpMcpTransport and StdioMcpTransport, each talking to the
 * same ReferenceMcpServer happy_path fixture over real loopback I/O --
 * proving the two McpTransport implementations are interchangeable from
 * a caller's own point of view. Mirrors GitDefinitionFileReaderTest's own
 * approach of exercising real subprocess/filesystem behavior rather than
 * mocking the boundary under test.
 */
class McpTransportContractTest extends TestCase
{
    private ?ReferenceMcpServer $referenceServer = null;

    protected function tearDown(): void
    {
        $this->referenceServer?->stopHttp();
        $this->referenceServer = null;

        parent::tearDown();
    }

    #[Test]
    public function streamable_http_round_trips_initialize_list_tools_and_call_tool(): void
    {
        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp(Protocol::MODE_HAPPY_PATH);

        $this->assertContractHolds(new StreamableHttpMcpTransport(
            url: $url,
            credential: null,
            callTimeoutSeconds: 5,
            handshakeTimeoutSeconds: 5,
        ));
    }

    #[Test]
    public function stdio_round_trips_initialize_list_tools_and_call_tool(): void
    {
        $referenceServer = new ReferenceMcpServer();

        $this->assertContractHolds(new StdioMcpTransport(
            command: $referenceServer->stdioCommand(Protocol::MODE_HAPPY_PATH),
            env: $referenceServer->stdioEnv(Protocol::MODE_HAPPY_PATH),
            callTimeoutSeconds: 5,
            handshakeTimeoutSeconds: 5,
        ));
    }

    /**
     * The identical assertion sequence run against whichever McpTransport
     * implementation the caller built -- the point of this test is that
     * neither implementation needs its own separate assertions.
     */
    private function assertContractHolds(McpTransport $transport): void
    {
        // initialize() must complete without throwing.
        $transport->initialize();

        $tools = $transport->listTools();
        $this->assertCount(2, $tools);
        $names = array_column($tools, 'name');
        $this->assertContains('reference_echo', $names);
        $this->assertContains('reference_fail', $names);

        $echo = $transport->callTool('reference_echo', ['text' => 'hello from the contract test']);
        $this->assertIsArray($echo);
        $this->assertFalse($echo['isError']);
        $this->assertSame('hello from the contract test', $echo['content'][0]['text']);

        $fail = $transport->callTool('reference_fail', []);
        $this->assertTrue($fail['isError']);
    }
}
