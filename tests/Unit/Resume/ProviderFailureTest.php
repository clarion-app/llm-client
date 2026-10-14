<?php

namespace Tests\Unit\Resume;

use ClarionApp\LlmClient\Services\ProviderFailure;
use ClarionApp\LlmClient\Services\RetryEligibility;
use ClarionApp\LlmClient\ValueObjects\ProviderFailureKind;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * How a failed model call is classified (provider-retry contract §1):
 * what is worth waiting for (a rate limit, an overload, a refused or timed
 * out connection) and what is not (a request the provider rejects, which
 * no amount of waiting changes). The status the provider adapters put in
 * the exception code decides, and a transport failure is found anywhere in
 * the exception's `previous` chain. A Retry-After the provider sent is read
 * from the response of the wrapped request exception.
 *
 * Quickstart M16: treating a 400 as transient turns the `rejected` rows red.
 */
class ProviderFailureTest extends TestCase
{
    private function request(): Request
    {
        return new Request('POST', 'https://provider.example/v1/chat/completions');
    }

    /** What an adapter throws for an HTTP status: a RuntimeException carrying it as code. */
    private function adapterFailure(int $status, ?\Throwable $previous = null): \RuntimeException
    {
        return new \RuntimeException("LLM server returned {$status}", $status, $previous);
    }

    /** @return array<string, array{0: int}> */
    public static function rateLimitStatuses(): array
    {
        return ['429' => [429]];
    }

    /** @return array<string, array{0: int}> */
    public static function unavailableStatuses(): array
    {
        return ['500' => [500], '502' => [502], '503' => [503], '529 overloaded' => [529], '408 timeout' => [408]];
    }

    /** @return array<string, array{0: int}> */
    public static function rejectedStatuses(): array
    {
        return ['400' => [400], '401' => [401], '403' => [403], '404' => [404], '422' => [422]];
    }

    #[Test]
    #[DataProvider('rateLimitStatuses')]
    public function a_429_is_rate_limited(int $status): void
    {
        $kind = ProviderFailure::classify($this->adapterFailure($status));

        $this->assertSame(ProviderFailureKind::RateLimited, $kind);
        $this->assertTrue($kind->isTransient());
    }

    #[Test]
    #[DataProvider('unavailableStatuses')]
    public function a_server_error_or_408_is_unavailable(int $status): void
    {
        $kind = ProviderFailure::classify($this->adapterFailure($status));

        $this->assertSame(ProviderFailureKind::Unavailable, $kind);
        $this->assertTrue($kind->isTransient());
    }

    #[Test]
    #[DataProvider('rejectedStatuses')]
    public function a_rejected_request_is_not_transient(int $status): void
    {
        $kind = ProviderFailure::classify($this->adapterFailure($status));

        $this->assertSame(ProviderFailureKind::Rejected, $kind, "a {$status} is a request the provider will not accept: waiting cannot change it");
        $this->assertFalse($kind->isTransient());
    }

    #[Test]
    public function a_failure_that_is_not_a_transport_error_is_rejected(): void
    {
        $this->assertSame(ProviderFailureKind::Rejected, ProviderFailure::classify(new \LogicException('a bug in our own code')));
        $this->assertSame(ProviderFailureKind::Rejected, ProviderFailure::classify(new \RuntimeException('unknown model')));
        $this->assertSame(ProviderFailureKind::Rejected, ProviderFailure::classify(new \RuntimeException('unreadable response', 200)));
    }

    #[Test]
    public function a_refused_connection_anywhere_in_the_chain_is_a_connection_failure(): void
    {
        $connect = new ConnectException('Connection refused', $this->request());

        $direct = ProviderFailure::classify($connect);
        $wrapped = ProviderFailure::classify(new \RuntimeException('Connection to LLM server failed', 0, $connect));
        $deep = ProviderFailure::classify(new \RuntimeException('outer', 0, new \LogicException('middle', 0, $connect)));

        $this->assertSame(ProviderFailureKind::Connection, $direct);
        $this->assertSame(ProviderFailureKind::Connection, $wrapped, 'the adapter wraps the Guzzle exception');
        $this->assertSame(ProviderFailureKind::Connection, $deep, 'however deep it is wrapped');
        $this->assertTrue($direct->isTransient());
    }

    #[Test]
    public function a_transfer_failure_without_a_response_is_a_connection_failure(): void
    {
        $kind = ProviderFailure::classify(new \RuntimeException('LLM request failed', 0, new TransferException('the stream was cut')));

        $this->assertSame(ProviderFailureKind::Connection, $kind);
    }

    #[Test]
    public function curl_error_28_is_a_timeout(): void
    {
        $timeout = new ConnectException(
            'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received',
            $this->request(),
            null,
            ['errno' => 28],
        );

        $direct = ProviderFailure::classify($timeout);
        $wrapped = ProviderFailure::classify(new \RuntimeException('Connection to LLM server failed', 0, $timeout));

        $this->assertSame(ProviderFailureKind::Timeout, $direct);
        $this->assertSame(ProviderFailureKind::Timeout, $wrapped);
        $this->assertTrue($direct->isTransient(), 'a timeout is a connection failure, reported separately');
    }

    #[Test]
    public function the_laravel_connection_exception_is_a_connection_failure(): void
    {
        $this->assertSame(ProviderFailureKind::Connection, ProviderFailure::classify(new ConnectionException('cURL error 7')));
    }

    #[Test]
    public function a_response_that_reached_us_is_not_mistaken_for_a_transport_failure(): void
    {
        // A request exception WITH a response and a 400 code: the provider
        // answered, and what it answered is a refusal.
        $answered = new RequestException('Bad request', $this->request(), new Response(400));

        $this->assertSame(ProviderFailureKind::Rejected, ProviderFailure::classify($this->adapterFailure(400, $answered)));
    }

    // ---------------------------------------------------------------
    // Retry-After
    // ---------------------------------------------------------------

    private function failureWithRetryAfter(?string $header): \RuntimeException
    {
        $headers = $header === null ? [] : ['Retry-After' => $header];
        $previous = new RequestException('Too many requests', $this->request(), new Response(429, $headers));

        return $this->adapterFailure(429, $previous);
    }

    #[Test]
    public function retry_after_in_seconds_is_read_from_the_wrapped_response(): void
    {
        $this->assertSame(30, ProviderFailure::retryAfterSeconds($this->failureWithRetryAfter('30')));
    }

    #[Test]
    public function retry_after_as_an_http_date_is_read_as_the_seconds_until_then(): void
    {
        Carbon::setTestNow(Carbon::now()->startOfSecond());
        $date = Carbon::now()->addSeconds(45)->utc()->format('D, d M Y H:i:s').' GMT';

        $seconds = ProviderFailure::retryAfterSeconds($this->failureWithRetryAfter($date));

        $this->assertNotNull($seconds, 'an HTTP date is understood');
        $this->assertGreaterThanOrEqual(44, $seconds);
        $this->assertLessThanOrEqual(46, $seconds);
    }

    #[Test]
    public function an_absent_or_meaningless_retry_after_is_null(): void
    {
        $this->assertNull(ProviderFailure::retryAfterSeconds($this->failureWithRetryAfter(null)), 'no header');
        $this->assertNull(ProviderFailure::retryAfterSeconds($this->failureWithRetryAfter('soon')), 'not a number or a date');
        $this->assertNull(ProviderFailure::retryAfterSeconds($this->adapterFailure(503)), 'no wrapped response at all');
    }

    #[Test]
    public function a_past_retry_after_date_is_never_a_negative_wait(): void
    {
        Carbon::setTestNow(Carbon::now()->startOfSecond());
        $date = Carbon::now()->subMinutes(5)->utc()->format('D, d M Y H:i:s').' GMT';

        $seconds = ProviderFailure::retryAfterSeconds($this->failureWithRetryAfter($date));

        $this->assertTrue($seconds === null || $seconds >= 0, 'a date already past is not a negative delay');
    }

    // ---------------------------------------------------------------
    // The classifier the tool retry already uses
    // ---------------------------------------------------------------

    #[Test]
    public function the_provider_classifier_builds_on_retry_eligibility(): void
    {
        $this->assertTrue(RetryEligibility::isTransientProviderFailure($this->adapterFailure(503)));
        $this->assertTrue(RetryEligibility::isTransientProviderFailure($this->adapterFailure(429)));
        $this->assertTrue(RetryEligibility::isTransientProviderFailure(new ConnectException('refused', $this->request())));
        $this->assertFalse(RetryEligibility::isTransientProviderFailure($this->adapterFailure(400)));
        $this->assertFalse(RetryEligibility::isTransientProviderFailure(new \LogicException('bug')));
    }

    #[Test]
    public function the_tool_retry_classification_is_unchanged(): void
    {
        // dispatchExecuteOperationWithRetry's callers: only the framework's
        // connection exception is a transient throwable, and a response array
        // is transient on 429 / 5xx only.
        $this->assertTrue(RetryEligibility::isTransient(new ConnectionException('refused')));
        $this->assertFalse(RetryEligibility::isTransient(new ConnectException('refused', $this->request())), 'a Guzzle connect error is not a tool-call transient');
        $this->assertFalse(RetryEligibility::isTransient($this->adapterFailure(503)), 'a provider exception code is not read by the tool classifier');
        $this->assertTrue(RetryEligibility::isTransient(['status' => 503]));
        $this->assertTrue(RetryEligibility::isTransient(['status' => 429]));
        $this->assertFalse(RetryEligibility::isTransient(['status' => 408]), '408 is a provider-call transient only');
        $this->assertFalse(RetryEligibility::isTransient(['status' => 400]));
    }
}
