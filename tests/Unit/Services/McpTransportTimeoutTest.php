<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Exceptions\McpTransportTimeoutException;
use ClarionApp\LlmClient\Services\McpTransport;
use ClarionApp\LlmClient\Services\StdioMcpTransport;
use ClarionApp\LlmClient\Services\StreamableHttpMcpTransport;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ReferenceMcpServer\Protocol;
use Tests\Fixtures\ReferenceMcpServer\ReferenceMcpServer;
use Tests\TestCase;

/**
 * Proves McpTransportTimeoutException fires at each transport's own
 * configured bound, not merely "eventually" -- against ReferenceMcpServer's
 * slow mode, whose configured delay is set well past the bound under test
 * so a transport that waited for the full delay (rather than enforcing its
 * own timeout) would fail the elapsed-time assertion below.
 */
class McpTransportTimeoutTest extends TestCase
{
    private const BOUND_SECONDS = 1;
    private const DELAY_SECONDS = 4.0;

    private ?ReferenceMcpServer $referenceServer = null;

    protected function tearDown(): void
    {
        $this->referenceServer?->stopHttp();
        $this->referenceServer = null;

        parent::tearDown();
    }

    #[Test]
    public function streamable_http_times_out_at_the_configured_bound_not_later(): void
    {
        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp(Protocol::MODE_SLOW, ['delay_seconds' => self::DELAY_SECONDS]);

        $transport = new StreamableHttpMcpTransport(
            url: $url,
            credential: null,
            callTimeoutSeconds: self::BOUND_SECONDS,
            handshakeTimeoutSeconds: self::BOUND_SECONDS,
        );

        $this->assertTimesOutAtTheBound($transport);
    }

    #[Test]
    public function stdio_times_out_at_the_configured_bound_not_later(): void
    {
        $referenceServer = new ReferenceMcpServer();

        $transport = new StdioMcpTransport(
            command: $referenceServer->stdioCommand(Protocol::MODE_SLOW),
            env: $referenceServer->stdioEnv(Protocol::MODE_SLOW, ['delay_seconds' => self::DELAY_SECONDS]),
            callTimeoutSeconds: self::BOUND_SECONDS,
            handshakeTimeoutSeconds: self::BOUND_SECONDS,
        );

        $this->assertTimesOutAtTheBound($transport);
    }

    private function assertTimesOutAtTheBound(McpTransport $transport): void
    {
        $start = microtime(true);

        try {
            $transport->initialize();
            $this->fail('Expected McpTransportTimeoutException to be thrown.');
        } catch (McpTransportTimeoutException) {
            $elapsed = microtime(true) - $start;

            $this->assertLessThan(
                self::DELAY_SECONDS,
                $elapsed,
                'the timeout must fire at the configured bound, not after waiting for the full fixture delay'
            );
        }
    }
}
