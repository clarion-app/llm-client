<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\ValueObjects\ToolFailureCategory;
use PHPUnit\Framework\Attributes\Test;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\ClientException;

class ToolFailureCategoryTest extends TestCase
{
    #[Test]
    public function it_has_all_expected_enum_values()
    {
        $cases = ToolFailureCategory::cases();
        $values = array_map(fn ($c) => $c->value, $cases);

        $expected = [
            'timeout',
            'connection_failure',
            'authentication_failure',
            'invalid_input',
            'server_error',
            'other',
        ];

        $this->assertEquals($expected, $values);
    }

    #[Test]
    public function it_classifies_connect_exception_as_connection_failure()
    {
        $psr7Request = new \GuzzleHttp\Psr7\Request('GET', 'https://example.com');
        $exception = new ConnectException('Connection refused', $psr7Request);

        $category = ToolFailureCategory::fromException($exception);
        $this->assertSame(ToolFailureCategory::ConnectionFailure, $category);
    }

    #[Test]
    public function it_classifies_server_exception_as_server_error()
    {
        $psr7Request = new \GuzzleHttp\Psr7\Request('GET', 'https://example.com');
        $psr7Response = new \GuzzleHttp\Psr7\Response(500);
        $exception = new ServerException('Server error', $psr7Request, $psr7Response);

        $category = ToolFailureCategory::fromException($exception);
        $this->assertSame(ToolFailureCategory::ServerError, $category);
    }

    #[Test]
    public function it_classifies_401_as_authentication_failure()
    {
        $psr7Request = new \GuzzleHttp\Psr7\Request('GET', 'https://example.com');
        $psr7Response = new \GuzzleHttp\Psr7\Response(401);
        $exception = new ClientException('Unauthorized', $psr7Request, $psr7Response);

        $category = ToolFailureCategory::fromException($exception);
        $this->assertSame(ToolFailureCategory::AuthenticationFailure, $category);
    }

    #[Test]
    public function it_classifies_403_as_authentication_failure()
    {
        $psr7Request = new \GuzzleHttp\Psr7\Request('GET', 'https://example.com');
        $psr7Response = new \GuzzleHttp\Psr7\Response(403);
        $exception = new ClientException('Forbidden', $psr7Request, $psr7Response);

        $category = ToolFailureCategory::fromException($exception);
        $this->assertSame(ToolFailureCategory::AuthenticationFailure, $category);
    }

    #[Test]
    public function it_classifies_timeout_message_as_timeout()
    {
        $exception = new \RuntimeException('Request timed out after 30s');

        $category = ToolFailureCategory::fromException($exception);
        $this->assertSame(ToolFailureCategory::Timeout, $category);
    }

    #[Test]
    public function it_classifies_connect_timeout_as_connection_failure()
    {
        // Connection-level timeouts are ConnectExceptions classified as ConnectionFailure.
        $psr7Request = new \GuzzleHttp\Psr7\Request('GET', 'https://example.com');
        $exception = new ConnectException('Connection timed out', $psr7Request);

        $category = ToolFailureCategory::fromException($exception);
        $this->assertSame(ToolFailureCategory::ConnectionFailure, $category);
    }

    #[Test]
    public function it_classifies_unknown_exception_as_other()
    {
        $exception = new \RuntimeException('Something went wrong');

        $category = ToolFailureCategory::fromException($exception);
        $this->assertSame(ToolFailureCategory::Other, $category);
    }

    #[Test]
    public function it_converts_from_string_value()
    {
        $category = ToolFailureCategory::from('timeout');
        $this->assertSame(ToolFailureCategory::Timeout, $category);
    }

    #[Test]
    public function it_throws_on_invalid_string_value()
    {
        $this->expectException(\ValueError::class);
        ToolFailureCategory::from('unknown_category');
    }
}
