<?php

namespace Tests\Support;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Tests\Integration\Harness\OperationCatalogue;
use Tests\Integration\Harness\ResponseScript;
use Tests\Integration\Harness\ScriptedStream;
use Tests\Integration\Harness\ScriptedTransport;

/**
 * A thin facade over the ScriptedTransport/ScriptedStream harness for the
 * resumable-task scenarios (research D11/D21). It scripts a model turn as
 * plain text, tool calls, a transient provider failure (a 429/503 response
 * or a wrapped ConnectException), or a non-transient failure (a 400 response)
 * — the failure kinds ProviderFailureKind names — and registers the two fake
 * ledger operations that back the EffectLedger.
 *
 * Turn semantics: each call (a text/tool step or a failure) scripts the next
 * model turn, in order. A failure turn is a one-shot: it fires on that turn's
 * provider call and is then consumed, so a retry (the following provider call)
 * falls through to the script and serves the next scripted step. The failure
 * middleware sits in front of the transport's own handler, so a failed turn
 * neither advances the transport's turn index nor the script cursor — the
 * script steps are served in order with the failure slot skipped.
 *
 * The two fake operations are executed through the real execute_operation path
 * (McpToolExecutor); a test must bind a McpToolExecutor with a fixed token
 * factory (as OperationRecallJourneyTest does) so the call never touches
 * Passport. seedLedgerOperations() only seeds the catalogue and fakes the host
 * API; the executor binding is the test's concern.
 */
class ScriptedProvider
{
    private ResponseScript $script;
    private ScriptedTransport $transport;
    private ScriptedStream $stream;

    /** @var array<int, \Throwable> Failures keyed by 1-based chat turn. */
    private array $failures = [];

    /** @var int Next 1-based chat turn to be scripted. */
    private int $nextTurn = 1;

    /** @var int Chat requests seen by the failure middleware. */
    private int $served = 0;

    /** @var bool Whether the failure middleware has been pushed onto the stack. */
    private bool $failureMiddlewarePushed = false;

    public function __construct(?ScriptedTransport $transport = null, ?ScriptedStream $stream = null)
    {
        $this->script = new ResponseScript();
        $this->transport = $transport ?? new ScriptedTransport($this->script);
        $this->stream = $stream ?? new ScriptedStream();
    }

    /**
     * The HandlerStack to bind as 'llm-client.http_handler'. Carries the
     * failure middleware in front of the transport's own handler.
     */
    public function handlerStack(): HandlerStack
    {
        $stack = $this->transport->handlerStack();
        // The binding closure can resolve this more than once; push the
        // middleware only once so $served is not double-counted.
        if ($this->failureMiddlewarePushed) {
            return $stack;
        }
        $this->failureMiddlewarePushed = true;
        $self = $this;
        $stack->push(function ($handler) use ($self) {
            return function ($request, $options) use ($handler, $self) {
                $path = $request->getUri()->getPath();
                if (str_contains($path, '/chat')) {
                    $self->served++;
                    $failure = $self->failures[$self->served] ?? null;
                    if ($failure !== null) {
                        throw $failure;
                    }
                }
                return $handler($request, $options);
            };
        }, 'scripted-failure');
        return $stack;
    }

    /**
     * The next model turn returns plain text.
     */
    public function text(string $content): self
    {
        $this->script->finalAnswer($content);
        $this->nextTurn++;
        return $this;
    }

    /**
     * The next model turn makes one tool call.
     */
    public function toolCall(string $name, array $arguments = []): self
    {
        $this->script->toolRequest($name, $arguments);
        $this->nextTurn++;
        return $this;
    }

    /**
     * The next model turn makes several tool calls at once, in the order given.
     *
     * @param list<array{name: string, arguments: array<string, mixed>, id?: string}> $calls
     */
    public function toolCalls(array $calls): self
    {
        $this->script->toolRequests($calls);
        $this->nextTurn++;
        return $this;
    }

    /**
     * Attach usage figures to the last scripted turn.
     */
    public function usage(int $in, int $out): self
    {
        $this->script->usage($in, $out);
        return $this;
    }

    /**
     * The next model turn fails transiently: a 429 (rate limited) or 503
     * (service unavailable) response — the two HTTP statuses
     * ProviderFailureKind::isTransient() covers.
     */
    public function transientFailure(int $status = 429): self
    {
        $this->failures[$this->nextTurn] = $this->transientException($status);
        $this->nextTurn++;
        return $this;
    }

    /**
     * The next model turn fails with a connection error (a wrapped
     * GuzzleHttp ConnectException) — ProviderFailureKind::Connection.
     */
    public function connectionFailure(): self
    {
        $this->failures[$this->nextTurn] = new ConnectException(
            'Connection refused (scripted)',
            new Request('POST', '/chat/completions')
        );
        $this->nextTurn++;
        return $this;
    }

    /**
     * The next model turn fails non-transiently: a 400 response —
     * ProviderFailureKind::Rejected, for which isTransient() is false.
     */
    public function rejectedFailure(): self
    {
        $this->failures[$this->nextTurn] = $this->rejectedException();
        $this->nextTurn++;
        return $this;
    }

    /**
     * Register the two fake ledger operations and wire their host-API calls to
     * increment $ledger. ledger.write is POST (effectful); ledger.read is GET
     * (effect-free). Both are counted. The paths are seeded without the '/api'
     * prefix — McpToolExecutor::executeHttpCall() prepends it itself.
     */
    public function seedLedgerOperations(EffectLedger $ledger): self
    {
        $catalogue = new OperationCatalogue();
        $catalogue->seed([
            'openapi' => '3.0.0',
            'info' => ['title' => 'Ledger API', 'version' => '1.0.0'],
            'paths' => [
                '/ledger/write' => [
                    'post' => [
                        'operationId' => 'ledger.write',
                        'summary' => 'Write to the ledger (effectful)',
                        'parameters' => [],
                        'responses' => ['200' => ['description' => 'Success']],
                    ],
                ],
                '/ledger/read' => [
                    'get' => [
                        'operationId' => 'ledger.read',
                        'summary' => 'Read from the ledger (effect-free)',
                        'parameters' => [],
                        'responses' => ['200' => ['description' => 'Success']],
                    ],
                ],
            ],
        ]);

        $catalogue->fakeHostApi([
            '/ledger/write' => function () use ($ledger) {
                $ledger->record('ledger.write');
                return Http::response(['ok' => true, 'operation' => 'ledger.write'], 200, [
                    'Content-Type' => 'application/json',
                ]);
            },
            '/ledger/read' => function () use ($ledger) {
                $ledger->record('ledger.read');
                return Http::response(['ok' => true, 'operation' => 'ledger.read'], 200, [
                    'Content-Type' => 'application/json',
                ]);
            },
            '*' => Http::response(['ok' => true], 200, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        return $this;
    }

    /**
     * The underlying ResponseScript (for advanced reactive scripting).
     */
    public function script(): ResponseScript
    {
        return $this->script;
    }

    /**
     * The underlying ScriptedTransport (for captured payloads).
     */
    public function transport(): ScriptedTransport
    {
        return $this->transport;
    }

    /**
     * The underlying ScriptedStream (for the streaming path).
     */
    public function stream(): ScriptedStream
    {
        return $this->stream;
    }

    /**
     * Whether every scripted turn has been served (no unconsumed steps).
     */
    public function hasUnconsumedSteps(): bool
    {
        return $this->transport->hasUnconsumedSteps();
    }

    /**
     * A 429/503 response, the shape a real provider surfaces for a rate limit
     * or a service outage.
     */
    private function transientException(int $status): \Throwable
    {
        $isRateLimit = $status === 429;
        $message = $isRateLimit ? 'Rate limited (scripted)' : 'Service unavailable (scripted)';
        $response = new Response($status, [], json_encode([
            'error' => [
                'message' => $message,
                'type' => $isRateLimit ? 'rate_limit' : 'service_unavailable',
            ],
        ]));
        return new RequestException($message, new Request('POST', '/chat/completions'), $response);
    }

    /**
     * A 400 response — a non-transient rejection.
     */
    private function rejectedException(): \Throwable
    {
        $response = new Response(400, [], json_encode([
            'error' => [
                'message' => 'Bad request (scripted)',
                'type' => 'invalid_request_error',
            ],
        ]));
        return new RequestException('Bad request (scripted)', new Request('POST', '/chat/completions'), $response);
    }
}
