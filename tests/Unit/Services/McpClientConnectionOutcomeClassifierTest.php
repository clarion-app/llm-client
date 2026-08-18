<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Exceptions\McpAuthenticationException;
use ClarionApp\LlmClient\Exceptions\McpProtocolException;
use ClarionApp\LlmClient\Exceptions\McpTransportException;
use ClarionApp\LlmClient\Exceptions\McpTransportTimeoutException;
use ClarionApp\LlmClient\Services\McpClientConnectionOutcomeClassifier;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * McpClientConnectionOutcomeClassifier::classify() -- the single shared
 * definition of what a given transport outcome means (data-model.md's
 * decision table), consumed by both McpClientToolDiscoveryService::
 * discover() and the new TestMcpClientConnectionJob so the two call
 * sites can never disagree about a given exception's category.
 *
 * Written before McpClientConnectionOutcomeClassifier/McpConnectionOutcome
 * exist -- expected to FAIL red (class not found) until they are created.
 */
class McpClientConnectionOutcomeClassifierTest extends TestCase
{
    private function classifier(): McpClientConnectionOutcomeClassifier
    {
        return new McpClientConnectionOutcomeClassifier();
    }

    #[Test]
    public function a_null_throwable_classifies_as_reachable_with_an_empty_message(): void
    {
        $outcome = $this->classifier()->classify(null);

        $this->assertSame('reachable', $outcome->category);
        $this->assertSame('', $outcome->message);
    }

    #[Test]
    public function an_authentication_exception_classifies_as_auth_failed_with_its_message_passed_through(): void
    {
        $outcome = $this->classifier()->classify(new McpAuthenticationException('credential rejected'));

        $this->assertSame('auth_failed', $outcome->category);
        $this->assertSame('credential rejected', $outcome->message);
    }

    #[Test]
    public function a_protocol_exception_classifies_as_protocol_error_with_its_message_passed_through(): void
    {
        $outcome = $this->classifier()->classify(new McpProtocolException('malformed tools/list response'));

        $this->assertSame('protocol_error', $outcome->category);
        $this->assertSame('malformed tools/list response', $outcome->message);
    }

    #[Test]
    public function a_transport_exception_classifies_as_unreachable_with_its_message_passed_through(): void
    {
        $outcome = $this->classifier()->classify(new McpTransportException('connection refused'));

        $this->assertSame('unreachable', $outcome->category);
        $this->assertSame('connection refused', $outcome->message);
    }

    #[Test]
    public function a_transport_timeout_exception_classifies_as_unreachable_with_its_message_passed_through(): void
    {
        $outcome = $this->classifier()->classify(new McpTransportTimeoutException('timed out after 15s'));

        $this->assertSame('unreachable', $outcome->category);
        $this->assertSame('timed out after 15s', $outcome->message);
    }

    #[Test]
    public function an_arbitrary_other_throwable_classifies_as_unreachable_with_its_message_passed_through(): void
    {
        $outcome = $this->classifier()->classify(new \RuntimeException('something else entirely'));

        $this->assertSame('unreachable', $outcome->category);
        $this->assertSame('something else entirely', $outcome->message);
    }
}
