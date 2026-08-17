<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Services\RetryEligibility;
use Illuminate\Http\Client\ConnectionException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RetryEligibility::isTransient() -- the one new, minimal, generic
 * transient-failure classifier a bounded per-action retry loop consults
 * before deciding whether a failed tool call is worth attempting again.
 * Scoped deliberately narrow: true only for a failure that never produced
 * a meaningful application response at all (a connection-level exception)
 * or one that explicitly says "try again later" (an HTTP 5xx or 429).
 * Every other outcome -- a well-formed 4xx, an application-error body, a
 * permission/authorization refusal shape -- is false, because retrying
 * something that failed for a reason a retry can never fix would not help
 * and could repeat a side effect that already (correctly) did not happen.
 */
class RetryEligibilityTest extends TestCase
{
    // -----------------------------------------------------------
    // Transient: a thrown connection/timeout exception -- the call
    // never reached the target server at all.
    // -----------------------------------------------------------

    #[Test]
    public function a_thrown_connection_exception_is_transient(): void
    {
        $this->assertTrue(
            RetryEligibility::isTransient(new ConnectionException('Connection timed out')),
        );
    }

    // -----------------------------------------------------------
    // Transient: an HTTP response that explicitly says "try again"
    // -----------------------------------------------------------

    #[Test]
    public function an_http_500_response_is_transient(): void
    {
        $this->assertTrue(RetryEligibility::isTransient(['status' => 500]));
    }

    #[Test]
    public function an_http_503_response_is_transient(): void
    {
        $this->assertTrue(RetryEligibility::isTransient(['status' => 503]));
    }

    #[Test]
    public function the_top_and_bottom_of_the_5xx_range_are_both_transient(): void
    {
        $this->assertTrue(RetryEligibility::isTransient(['status' => 599]));
    }

    #[Test]
    public function an_http_429_response_is_transient(): void
    {
        $this->assertTrue(RetryEligibility::isTransient(['status' => 429]));
    }

    // -----------------------------------------------------------
    // Not transient: a well-formed 4xx other than 429
    // -----------------------------------------------------------

    #[Test]
    public function an_http_400_response_is_not_transient(): void
    {
        $this->assertFalse(RetryEligibility::isTransient(['status' => 400]));
    }

    #[Test]
    public function an_http_404_response_is_not_transient(): void
    {
        $this->assertFalse(RetryEligibility::isTransient(['status' => 404]));
    }

    #[Test]
    public function an_http_422_response_is_not_transient(): void
    {
        $this->assertFalse(RetryEligibility::isTransient(['status' => 422]));
    }

    // -----------------------------------------------------------
    // Not transient: a permission/authorization refusal shape --
    // already handled unconditionally elsewhere, never reaches this
    // classifier in production, but must be classified false anyway
    // since 401/403 are never in the 5xx/429 set.
    // -----------------------------------------------------------

    #[Test]
    public function an_http_401_response_is_not_transient(): void
    {
        $this->assertFalse(RetryEligibility::isTransient(['status' => 401]));
    }

    #[Test]
    public function an_http_403_response_is_not_transient(): void
    {
        $this->assertFalse(RetryEligibility::isTransient(['status' => 403]));
    }

    // -----------------------------------------------------------
    // Not transient: a successful-status application-error body --
    // the call reached the server and produced an ordinary response,
    // it just was not the response the caller wanted.
    // -----------------------------------------------------------

    #[Test]
    public function a_well_formed_success_status_carrying_an_application_error_body_is_not_transient(): void
    {
        $this->assertFalse(RetryEligibility::isTransient(['status' => 200, 'body' => '{"error":"not found"}']));
    }

    // -----------------------------------------------------------
    // Not transient: any other thrown exception -- only a connection-
    // level failure is treated as transient, never an arbitrary throw.
    // -----------------------------------------------------------

    #[Test]
    public function an_arbitrary_thrown_exception_that_is_not_a_connection_exception_is_not_transient(): void
    {
        $this->assertFalse(RetryEligibility::isTransient(new \RuntimeException('something application-level went wrong')));
    }

    // -----------------------------------------------------------
    // Not transient: an outcome array with no recognizable status
    // -----------------------------------------------------------

    #[Test]
    public function an_outcome_array_with_no_status_key_is_not_transient(): void
    {
        $this->assertFalse(RetryEligibility::isTransient(['body' => 'no status at all']));
    }
}
