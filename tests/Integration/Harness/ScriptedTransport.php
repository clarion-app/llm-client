<?php

namespace Tests\Integration\Harness;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * Wraps Guzzle HandlerStack + Middleware::history() for integration tests.
 *
 * Routes chat and embedding traffic by request path. Chat requests are served
 * from the ResponseScript, embedding requests are served from the DeterministicEmbedder.
 * When the embedder is null (embeddings-disabled mode), a transport-level failure
 * is returned so the product's real no-embedding fallback executes.
 */
class ScriptedTransport
{
    private ResponseScript $script;
    private ?DeterministicEmbedder $embedder;
    private array $history;
    /** @var array<string, string> Body content cached by request object hash */
    private array $bodyCache;
    /** @var int Current 1-based turn number for lane-aware serving (S2) */
    private int $turnIndex;

    private HandlerStack $handlerStack;

    public function __construct(ResponseScript $script, ?DeterministicEmbedder $embedder = null)
    {
        $this->script = $script;
        $this->embedder = $embedder;
        $this->history = [];
        $this->bodyCache = [];
        $this->turnIndex = 0;

        // Build handler stack with a callable handler (not MockHandler)
        // This allows dynamic routing based on request path
        $self = $this;
        $handler = function (RequestInterface $request, array $options) use ($self): FulfilledPromise {
            // Capture body content BEFORE it's consumed. The PSR-7 body stream
            // is consumed during handleRequest, so we cache it for later replay
            // in capturedPayloads().
            $requestHash = spl_object_hash($request);
            $self->bodyCache[$requestHash] = $request->getBody()->getContents();
            $request->getBody()->seek(0);

            try {
                $response = $self->handleRequest($request);
                return new FulfilledPromise($response);
            } catch (ScriptExhaustedError $e) {
                // Script exhaustion is a harness error — throw as-is
                throw $e;
            } catch (EmbeddingsDisabledError $e) {
                // Embeddings disabled: wrap in RequestException so Guzzle treats it as a network error
                // RequestException signature: __construct($message, $request, $response = null, $previous = null, $handlerContext = [])
                // $e extends RuntimeException which is Throwable — pass as $previous (4th arg).
                $fakeResponse = new Response(503, [], json_encode([
                    'error' => [
                        'message' => $e->getMessage(),
                        'type' => 'service_unavailable',
                    ],
                ]));
                $requestException = new RequestException($e->getMessage(), $request, $fakeResponse, $e);
                throw $requestException;
            } catch (RuntimeException $e) {
                // Other runtime errors propagate as-is
                throw $e;
            }
        };

        $this->handlerStack = HandlerStack::create($handler);
        $this->handlerStack->push(Middleware::history($this->history), 'history');
    }

    /**
     * Return the HandlerStack for use with Guzzle Client.
     */
    public function handlerStack(): HandlerStack
    {
        return $this->handlerStack;
    }

    /**
     * Switch the boundary into embeddings-disabled mode (contract C4).
     *
     * Subsequent embedding requests fail at the transport, so the product's
     * own no-embedding fallback executes. This is deliberately not a config
     * toggle or a stubbed service — either would bypass the fallback path the
     * scenario exists to prove.
     */
    public function disableEmbeddings(): void
    {
        $this->embedder = null;
    }

    /**
     * Whether the boundary is currently serving embeddings.
     */
    public function embeddingsEnabled(): bool
    {
        return $this->embedder !== null;
    }

    /**
     * Return list of CapturedPayload from history.
     *
     * @return CapturedPayload[]
     */
    public function capturedPayloads(): array
    {
        $payloads = [];
        foreach ($this->history as $entry) {
            $request = $entry['request'];
            $payloads[] = $this->extractPayload($request);
        }
        return $payloads;
    }

    /**
     * Captured chat requests only.
     *
     * capturedPayloads() deliberately includes embedding traffic (contract C2),
     * so callers reasoning about conversation turns must filter — otherwise the
     * first payload is whichever request happened to fire first.
     *
     * @return CapturedPayload[]
     */
    public function capturedChatPayloads(): array
    {
        return array_values(array_filter(
            $this->capturedPayloads(),
            fn (CapturedPayload $payload) => $payload->kind === 'chat'
        ));
    }

    /**
     * Captured payloads for a specific lane (S2).
     *
     * Filters chat payloads by the lane classification derived from the request body.
     *
     * @param RequestLane $lane The lane to filter by.
     * @return CapturedPayload[]
     */
    public function capturedPayloadsForLane(RequestLane $lane): array
    {
        return array_values(array_filter(
            $this->capturedChatPayloads(),
            fn (CapturedPayload $payload) => $payload->lane() === $lane
        ));
    }

    /**
     * Get the current turn index.
     */
    public function getTurnIndex(): int
    {
        return $this->turnIndex;
    }

    /**
     * Reset the turn index (for re-use between conversations).
     */
    public function reset(): void
    {
        $this->turnIndex = 0;
    }

    /**
     * Check if there are unconsumed steps remaining in the script.
     */
    public function hasUnconsumedSteps(): bool
    {
        return $this->script->hasUnconsumedSteps();
    }

    /**
     * Route a request by path and return the appropriate response.
     *
     * @param RequestInterface $request The incoming HTTP request.
     * @return Response The HTTP response.
     * @throws RuntimeException If the script is exhausted or embeddings are disabled.
     */
    public function handleRequest(RequestInterface $request): Response
    {
        $path = $request->getUri()->getPath();

        // Route by path
        if (str_contains($path, '/chat/completions') || str_contains($path, '/chat')) {
            return $this->handleChatRequest($request);
        }

        if (str_contains($path, '/embeddings')) {
            return $this->handleEmbeddingRequest($request);
        }

        // Unknown path - return 404
        return new Response(404, [], json_encode(['error' => 'Unknown endpoint: ' . $path]));
    }

    /**
     * Handle a chat completion request.
     *
     * Classifies the request into a lane (S2), evaluates rules for that lane,
     * then serves the next step from the lane's queue (or the legacy steps array
     * for agent_turn).
     */
    private function handleChatRequest(RequestInterface $request): Response
    {
        // Extract request info for error messages
        $body = json_decode($request->getBody()->getContents(), true);
        $requestInfo = [
            'message_count' => count($body['messages'] ?? []),
            'entry_path' => ($body['stream'] ?? false) ? 'stream' : 'sync',
            'iteration' => $body['iteration'] ?? 1,
            'tool_names' => $this->extractToolNames($body['messages'] ?? []),
        ];

        // Build a captured payload for rule evaluation and lane classification
        $payload = CapturedPayload::fromGuzzleBody(
            json_encode($body),
            'chat'
        );

        // Classify the lane from the payload
        $lane = RequestLane::classify($payload);

        // Increment turn index on each chat request
        $this->turnIndex++;

        try {
            $responseBody = $this->script->serveFor($lane, $payload, $this->turnIndex);
        } catch (RuntimeException $e) {
            // Re-throw as ScriptExhaustedError (harness error, not network error)
            throw new ScriptExhaustedError(
                $e->getMessage() . "\n\nTurn: {$this->turnIndex}, Lane: {$lane->value}",
                0,
                $e
            );
        }

        return new Response(200, [
            'Content-Type' => 'application/json',
        ], json_encode($responseBody));
    }

    /**
     * Handle an embeddings request.
     */
    private function handleEmbeddingRequest(RequestInterface $request): Response
    {
        // If embedder is null, return transport-level failure
        if ($this->embedder === null) {
            throw new EmbeddingsDisabledError(
                'Embeddings are disabled — no embedding provider available. ' .
                'This is a transport-level failure so the product\'s real no-embedding fallback executes.'
            );
        }

        // Extract input from request body
        $body = json_decode($request->getBody()->getContents(), true);
        $inputs = $body['input'] ?? [];

        if (!is_array($inputs)) {
            $inputs = [$inputs];
        }

        // Generate embeddings
        $embeddings = $this->embedder->embedBatch($inputs);

        // Build response in OpenAI format
        $data = [];
        foreach ($embeddings as $index => $embedding) {
            $data[] = [
                'object' => 'embedding',
                'index' => $index,
                'embedding' => $embedding,
            ];
        }

        return new Response(200, [
            'Content-Type' => 'application/json',
        ], json_encode([
            'object' => 'list',
            'data' => $data,
            'usage' => [
                'prompt_tokens' => 0,
                'total_tokens' => 0,
            ],
        ]));
    }

    /**
     * Extract a CapturedPayload from a Guzzle request.
     * Uses cached body content because the stream may have been consumed.
     */
    private function extractPayload(RequestInterface $request): CapturedPayload
    {
        $path = $request->getUri()->getPath();
        $kind = str_contains($path, '/embeddings') ? 'embedding' : 'chat';
        $requestHash = spl_object_hash($request);

        // Use cached body content if available (stream may be consumed)
        $bodyContent = $this->bodyCache[$requestHash] ?? $request->getBody()->getContents();

        return CapturedPayload::fromGuzzleBody($bodyContent, $kind);
    }

    /**
     * Extract tool names from messages array.
     *
     * @param array $messages Messages array.
     * @return string[] Tool names found.
     */
    private function extractToolNames(array $messages): array
    {
        $names = [];
        foreach ($messages as $message) {
            if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
                foreach ($message['tool_calls'] as $toolCall) {
                    if (isset($toolCall['function']['name'])) {
                        $names[] = $toolCall['function']['name'];
                    }
                }
            }
        }
        return $names;
    }
}
