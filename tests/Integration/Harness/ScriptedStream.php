<?php

namespace Tests\Integration\Harness;

use ClarionApp\HttpQueue\HttpRequest;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use Illuminate\Support\Facades\Queue;

/**
 * Captures HttpRequest objects from dispatched SendHttpStreamRequest jobs
 * and drives a container-resolved AgentLoopStreamHandler with scripted SSE chunks.
 *
 * When Queue::fake([SendHttpStreamRequest::class]) is active, this adapter:
 * 1. Captures the HttpRequest from each dispatched job.
 * 2. Provides emit() to drive SSE chunks into the real handler.
 * 3. Provides finish() to complete the stream.
 *
 * This is exercised through scenario use rather than its own unit test —
 * it is a thin adapter whose behavior is only observable when driving a
 * real handler.
 */
class ScriptedStream
{
    /** @var HttpRequest[] */
    private array $capturedRequests = [];

    /** @var array<int, array> Data payloads (conversation_id, iteration) per slot */
    private array $capturedData = [];

    /** @var array<int, AgentLoopStreamHandler> Handlers indexed by request slot */
    private array $handlers = [];

    /** @var int Current request slot being driven */
    private int $currentSlot = 0;

    /** @var float Elapsed seconds accumulator */
    private float $elapsed = 0.0;

    /**
     * Extract dispatched SendHttpStreamRequest jobs from the fake queue.
     *
     * Call this after Queue::fake() and after the dispatch has occurred.
     * Uses Queue::pushed() which iterates over the fake queue's stored jobs.
     * Captures both the HttpRequest and the $data payload (conversation_id, iteration).
     */
    public function extractDispatchedJobs(): self
    {
        // Idempotent: re-extracting rebuilds the list rather than appending, so
        // callers (including the base class's payload accessors) can extract
        // freely without duplicating captures.
        $this->capturedRequests = [];
        $this->capturedData = [];

        Queue::pushed(SendHttpStreamRequest::class, function (SendHttpStreamRequest $job) {
            $reflector = new \ReflectionClass($job);

            // Capture the HttpRequest from the job's protected $request property.
            $requestProperty = $reflector->getProperty('request');
            $requestProperty->setAccessible(true);
            /** @var HttpRequest $request */
            $request = $requestProperty->getValue($job);
            $this->capturedRequests[] = $request;

            // Capture the $data payload (conversation_id, iteration) — this is
            // passed to the handler's handle() and finish() methods, NOT the
            // request body.
            $dataProperty = $reflector->getProperty('data');
            $dataProperty->setAccessible(true);
            $data = $dataProperty->getValue($job);
            $this->capturedData[] = is_string($data) ? json_decode($data, true) : (array) $data;
        });

        return $this;
    }

    /**
     * Return the list of captured HttpRequest objects.
     *
     * @return HttpRequest[]
     */
    public function capturedRequests(): array
    {
        return $this->capturedRequests;
    }

    /**
     * Emit a batch of SSE chunks into the handler for the current request slot.
     *
     * Each chunk should be a string in SSE format (e.g. "data: {...}\n\n").
     * The chunks are fed to the container-resolved AgentLoopStreamHandler::handle().
     *
     * @param array<string> $sseChunks Array of SSE chunk strings.
     */
    public function emit(array $sseChunks): self
    {
        $handler = $this->resolveHandlerForSlot($this->currentSlot);

        foreach ($sseChunks as $chunk) {
            $this->elapsed += 0.01; // Simulate small time increment per chunk
            $handler->handle($chunk, $this->getDataForSlot($this->currentSlot), $this->elapsed);
        }

        return $this;
    }

    /**
     * Finish the current stream by calling finish() on the handler.
     */
    public function finish(): self
    {
        $handler = $this->resolveHandlerForSlot($this->currentSlot);
        $this->elapsed += 0.01;
        $handler->finish($this->getDataForSlot($this->currentSlot), $this->elapsed);

        return $this;
    }

    /**
     * Move to the next captured request slot (for multi-iteration streams).
     */
    public function nextSlot(): void
    {
        $this->currentSlot++;
    }

    /**
     * Reset state for a new test run.
     */
    public function reset(): void
    {
        $this->capturedRequests = [];
        $this->capturedData = [];
        $this->handlers = [];
        $this->currentSlot = 0;
        $this->elapsed = 0.0;
    }

    /**
     * Resolve (or create) the AgentLoopStreamHandler for a given request slot.
     * Uses the container to resolve the handler — never constructs directly.
     */
    private function resolveHandlerForSlot(int $slot): AgentLoopStreamHandler
    {
        if (!isset($this->handlers[$slot])) {
            $this->handlers[$slot] = app(AgentLoopStreamHandler::class);
        }
        return $this->handlers[$slot];
    }

    /**
     * Extract the data payload for a given slot.
     * The data (conversation_id, iteration) is a separate job property,
     * not the request body. It is passed to the handler's handle() and finish() methods.
     */
    private function getDataForSlot(int $slot): array
    {
        return $this->capturedData[$slot] ?? [];
    }

    /**
     * Return the captured requests normalized to the shared assertion surface,
     * so payload assertions are written once across sync and stream (FR-007a).
     *
     * @return CapturedPayload[]
     */
    public function capturedPayloads(): array
    {
        return array_map(
            fn (HttpRequest $request) => CapturedPayload::fromHttpRequest($request),
            $this->capturedRequests
        );
    }

    /**
     * Captured payloads for a specific lane (S2).
     *
     * Filters payloads by the lane classification derived from the request body.
     *
     * @param RequestLane $lane The lane to filter by.
     * @return CapturedPayload[]
     */
    public function capturedPayloadsForLane(RequestLane $lane): array
    {
        return array_values(array_filter(
            $this->capturedPayloads(),
            fn (CapturedPayload $payload) => $payload->lane() === $lane
        ));
    }

    /**
     * Return the cumulative count of captured requests (turns).
     */
    public function turnCount(): int
    {
        return count($this->capturedRequests);
    }
}
