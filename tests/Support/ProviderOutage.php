<?php

namespace Tests\Support;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Carbon;

/**
 * A model provider that is down for a while: a deterministic way to make the
 * agent's model calls fail, in the shape a real provider fails, on either
 * execution path.
 *
 * The synchronous path goes through the provider's Guzzle client, so a
 * failure is a middleware on the shared handler stack that throws before the
 * scripted transport is reached (the request is never served, so the script
 * does not advance). The streaming path sends its request from the stream
 * job itself, so a failure there is a handler (see streamHandler()) whose
 * promise is rejected, exactly as Guzzle's own stack rejects a refused or
 * 5xx request.
 *
 * Only model turns count (a request carrying a `tools` list): titles,
 * condensation and embeddings are never failed and never counted.
 *
 * The outage is described by what it fails:
 *  - failNext($n)           the next $n model calls;
 *  - failUntil($when)       every model call made before the instant;
 *  - failRequests([2, 5])   the model calls with those 1-based positions
 *                           (a failed call keeps its position).
 */
class ProviderOutage
{
    private int $remaining = 0;

    private ?Carbon $until = null;

    /** @var list<int> */
    private array $positions = [];

    /** Model calls seen so far, failed ones included. */
    public int $calls = 0;

    /** Model calls this outage failed. */
    public int $failures = 0;

    /** @var \Closure(): \Throwable */
    private \Closure $failure;

    /** Text that must never reach a user, a model, a record or a log. */
    public const PROVIDER_TEXT = 'upstream says: capacity exhausted for key sk-test-LEAK-CHECK';

    public function __construct()
    {
        $this->failure = fn () => $this->statusFailure(503);
    }

    public function failNext(int $times): self
    {
        $this->remaining = $times;

        return $this;
    }

    public function failUntil(Carbon $when): self
    {
        $this->until = $when->copy();

        return $this;
    }

    /** @param list<int> $positions */
    public function failRequests(array $positions): self
    {
        $this->positions = $positions;

        return $this;
    }

    /** Stop failing. */
    public function recover(): self
    {
        $this->remaining = 0;
        $this->until = null;
        $this->positions = [];

        return $this;
    }

    /** Fail with an HTTP status: 429, 503, 400... */
    public function withStatus(int $status): self
    {
        $this->failure = fn () => $this->statusFailure($status);

        return $this;
    }

    /** Fail with a refused connection. */
    public function withConnectionRefused(): self
    {
        $this->failure = fn () => new ConnectException('Connection refused: '.self::PROVIDER_TEXT, new Request('POST', 'https://provider.example/v1/chat/completions'));

        return $this;
    }

    /** Push the failing middleware onto the synchronous path's handler stack. */
    public function attach(HandlerStack $stack): HandlerStack
    {
        $stack->push(function (callable $next) {
            return function ($request, $options) use ($next) {
                if (str_contains($request->getUri()->getPath(), '/chat') && $this->isModelTurn($request)) {
                    if ($this->shouldFail()) {
                        throw ($this->failure)();
                    }
                }

                return $next($request, $options);
            };
        }, 'provider-outage');

        return $stack;
    }

    /**
     * A Guzzle handler for the streaming path: it fails as described, and
     * serves each of $turns (a list of SSE chunk lists) in order otherwise.
     *
     * Only model turns (requests carrying a `tools` list) are counted and
     * served from $turns; every other request the process makes through the
     * same seam (an embedding, a title) goes to $others, as it would have
     * without the outage.
     *
     * @param list<list<string>> $turns
     * @param (callable(\Psr\Http\Message\RequestInterface, array): mixed)|null $others
     */
    public function streamHandler(array $turns, ?callable $others = null): \Closure
    {
        $served = 0;

        return function ($request, $options) use (&$served, $turns, $others) {
            if ($others !== null && !$this->isModelTurn($request)) {
                return $others($request, $options);
            }
            $this->calls++;
            if ($this->shouldFailCurrent()) {
                $this->failures++;

                return new RejectedPromise(($this->failure)());
            }

            $chunks = $turns[$served++] ?? throw new \LogicException('the stream outage has no more scripted turns');

            return new FulfilledPromise(new Response(200, ['Content-Type' => 'text/event-stream'], Utils::streamFor(implode('', $chunks))));
        };
    }

    private function isModelTurn($request): bool
    {
        $body = json_decode((string) $request->getBody(), true);
        $request->getBody()->rewind();

        return is_array($body) && array_key_exists('tools', $body);
    }

    private function shouldFail(): bool
    {
        $this->calls++;
        if ($this->shouldFailCurrent()) {
            $this->failures++;

            return true;
        }

        return false;
    }

    /** Decides for the call whose position is $this->calls. */
    private function shouldFailCurrent(): bool
    {
        if (in_array($this->calls, $this->positions, true)) {
            return true;
        }
        if ($this->until !== null && now()->lessThan($this->until)) {
            return true;
        }
        if ($this->remaining > 0) {
            $this->remaining--;

            return true;
        }

        return false;
    }

    private function statusFailure(int $status): \Throwable
    {
        $response = new Response($status, [], json_encode(['error' => ['message' => self::PROVIDER_TEXT]]));

        return new RequestException(self::PROVIDER_TEXT, new Request('POST', 'https://provider.example/v1/chat/completions'), $response);
    }
}
