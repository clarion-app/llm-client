<?php

namespace Tests\Feature\Resume;

use ClarionApp\HttpQueue\HttpRequest;
use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob;
use ClarionApp\LlmClient\Services\TaskResumeText;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use ClarionApp\LlmClient\Models\Conversation;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InjectedFault;
use Tests\Support\SnapshotFaultInjector;
use Tests\TestCase;

/**
 * AgentStreamStepJob — the resumable stream step (lease contract §5).
 *
 * The job claims the run's lease before it sends any request, guards that
 * its payload names the run's open step, streams the model response through
 * the handler (renewing the lease at most every 30 s), and — on a lost
 * claim, a rejected or incomplete stream, or a mid-stream fault — never
 * re-dispatches blindly and never records a model response it did not
 * receive. A redelivered job whose lease has since expired wins a takeover
 * and hands the run to the resumer rather than streaming its own payload.
 */
class AgentStreamStepJobTest extends TestCase
{
    private string $userId = '11111111-1111-1111-1111-111111111111';

    private int $requestCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['llm-client.resume.enabled' => true]);
        Queue::fake();
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /** Create a minimal conversation and return its id. */
    private function makeConversation(): string
    {
        $conversation = Conversation::create([
            'user_id' => $this->userId,
            'title' => 'resume stream',
            'model' => 'gpt-test',
            'channel' => 'web',
            'is_processing' => true,
        ]);

        return (string) $conversation->id;
    }

    /** Insert an in-progress run row carrying the resume columns. */
    private function makeRun(string $id, array $overrides = []): void
    {
        DB::table('agent_runs')->insert(array_merge([
            'id' => $id,
            'kind' => 'interactive',
            'user_id' => $this->userId,
            'conversation_id' => 'conv-1',
            'started_at' => now()->format('Y-m-d H:i:s.u'),
            'end_state' => 'in_progress',
            'checkpoint_version' => 1,
            'resume_mode' => 'stream',
            'resume_driver' => 'root',
            'resume_state' => 'working',
            'dispatch_id' => '10000000-0000-0000-0000-000000000001',
            'lease_holder' => null,
            'lease_expires_at' => now()->subSeconds(1)->format('Y-m-d H:i:s.u'),
            'consecutive_interruptions' => 0,
        ], $overrides));
    }

    /** Insert a step row; $checkpointState defaults to the run's open step. */
    private function makeStep(string $id, string $runId, int $position, ?string $checkpointState = 'open'): void
    {
        DB::table('agent_run_steps')->insert([
            'id' => $id,
            'run_id' => $runId,
            'position' => $position,
            'started_at' => now()->format('Y-m-d H:i:s.u'),
            'end_state' => 'in_progress',
            'iteration' => $position,
            'checkpoint_state' => $checkpointState,
            'checkpoint' => $checkpointState === 'open'
                ? json_encode(['v' => 1, 'iteration' => $position, 'model' => null, 'calls' => []])
                : null,
        ]);
    }

    /** The job's model request. */
    private function request(): HttpRequest
    {
        $request = new HttpRequest();
        $request->setUrl('https://provider.example/v1/chat/completions');
        $request->method = 'POST';
        $request->headers = ['Content-Type' => 'application/json'];
        $request->body = json_encode(['model' => 'gpt-test', 'stream' => true]);

        return $request;
    }

    /** The job's $data payload (lease contract §5). */
    private function data(string $runId, string $stepId, string $conversationId, string $dispatchId, int $iteration = 1): array
    {
        return [
            'conversation_id' => $conversationId,
            'iteration' => $iteration,
            'run_id' => $runId,
            'step_id' => $stepId,
            'action_id' => 'action-1',
            'dispatch_id' => $dispatchId,
        ];
    }

    /** @return object the run row */
    private function runRow(string $id): object
    {
        $row = DB::table('agent_runs')->where('id', $id)->first();
        $this->assertNotNull($row, "run {$id} must exist");

        return $row;
    }

    /** @return object the step row */
    private function stepRow(string $runId, string $stepId): object
    {
        $row = DB::table('agent_run_steps')->where('run_id', $runId)->where('id', $stepId)->first();
        $this->assertNotNull($row, "step {$stepId} must exist");

        return $row;
    }

    /**
     * Bind a Guzzle handler (the llm-client.http_handler seam) that serves
     * $chunks as a streaming body and counts the requests it sees. When
     * $throwOnSecondRead is set, the body's second read() throws — a
     * mid-stream failure.
     */
    private function bindStreamingHandler(array $chunks, bool $throwOnSecondRead = false): void
    {
        $this->requestCount = 0;
        $this->app->instance('llm-client.http_handler', function ($request, $options) use ($chunks, $throwOnSecondRead) {
            $this->requestCount++;

            if ($throwOnSecondRead) {
                $stream = new class(implode('', $chunks)) extends Stream {
                    private string $first;
                    private int $reads = 0;

                    public function __construct(string $first)
                    {
                        $this->first = $first;
                        parent::__construct(fopen('php://memory', 'r+'));
                    }

                    public function eof(): bool
                    {
                        return false; // keep the read loop going so the second read() is attempted
                    }

                    public function read($length): string
                    {
                        $this->reads++;
                        if ($this->reads === 1) {
                            return $this->first;
                        }
                        throw new \RuntimeException('mid-stream failure (scripted)');
                    }
                };

                return new FulfilledPromise(new Response(200, ['Content-Type' => 'text/event-stream'], $stream));
            }

            $stream = Utils::streamFor(implode('', $chunks));

            return new FulfilledPromise(new Response(200, ['Content-Type' => 'text/event-stream'], $stream));
        });
    }

    /** The SSE chunks for a content-only reply that finishes cleanly. */
    private function contentChunks(): array
    {
        return [
            "data: {\"choices\":[{\"delta\":{\"content\":\"Hel\"},\"finish_reason\":null}]}\n\n",
            "data: {\"choices\":[{\"delta\":{\"content\":\"lo\"},\"finish_reason\":null}]}\n\n",
            "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}]}\n\n",
            "data: [DONE]\n\n",
        ];
    }

    // ---------------------------------------------------------------
    // Job shape
    // ---------------------------------------------------------------

    #[Test]
    public function job_shape(): void
    {
        $conversationId = $this->makeConversation();
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);

        $job = new AgentStreamStepJob($this->request(), $this->data('run-1', 'step-1', $conversationId, '10000000-0000-0000-0000-000000000001'));

        $this->assertSame(
            1,
            $job->tries,
            'a retry would be a second, uncounted step',
        );
        $this->assertSame(
            (int) config('llm-client.resume.stream_job_timeout_seconds', 600),
            $job->timeout,
            'a long step is not killed by the worker default',
        );
        $this->assertSame(
            (string) config('llm-client.resume.stream_queue', 'http'),
            (string) $job->queue,
            'the step runs on the stream queue',
        );
    }

    // ---------------------------------------------------------------
    // The claim precedes the request
    // ---------------------------------------------------------------

    #[Test]
    public function claims_before_request(): void
    {
        // The lease is live (held by another process, not expired): the
        // claim inside the job loses, so no request is sent.
        $conversationId = $this->makeConversation();
        $this->makeRun('run-1', [
            'lease_holder' => '99999999-9999-9999-9999-999999999999',
            'lease_expires_at' => now()->addMinutes(5)->format('Y-m-d H:i:s.u'),
        ]);
        $this->makeStep('step-1', 'run-1', 1);
        $this->bindStreamingHandler($this->contentChunks());

        (new AgentStreamStepJob($this->request(), $this->data('run-1', 'step-1', $conversationId, '10000000-0000-0000-0000-000000000001')))->handle();

        $this->assertSame(0, $this->requestCount, 'a lost claim sends no request');
    }

    // ---------------------------------------------------------------
    // The step guard
    // ---------------------------------------------------------------

    #[Test]
    public function step_guard(): void
    {
        // The claim wins (the lease is released), but the payload names a
        // step that is not the run's open step: the job returns without a
        // request.
        $conversationId = $this->makeConversation();
        $this->makeRun('run-1');
        $this->makeStep('step-open', 'run-1', 1);
        $this->makeStep('step-other', 'run-1', 2, null);
        $this->bindStreamingHandler($this->contentChunks());

        (new AgentStreamStepJob($this->request(), $this->data('run-1', 'step-other', $conversationId, '10000000-0000-0000-0000-000000000001')))->handle();

        $this->assertSame(0, $this->requestCount, 'a payload that names a non-open step sends no request');
    }

    // ---------------------------------------------------------------
    // A complete stream
    // ---------------------------------------------------------------

    #[Test]
    public function streams_through_handler_and_finishes(): void
    {
        $conversationId = $this->makeConversation();
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $this->bindStreamingHandler($this->contentChunks());

        (new AgentStreamStepJob($this->request(), $this->data('run-1', 'step-1', $conversationId, '10000000-0000-0000-0000-000000000001')))->handle();

        $this->assertSame(1, $this->requestCount, 'the model request is sent once');

        // A complete plain-text stream records the model response (C2) and
        // then commits and closes the step (C5), so the checkpoint's final
        // state is committed and its model content is stripped; the streamed
        // content itself is what the persisted assistant message carries.
        $this->assertSame(
            'committed',
            $this->stepRow('run-1', 'step-1')->checkpoint_state,
            'a complete plain-text stream records the model response (C2) then commits and closes the step (C5)',
        );
        $message = DB::table('messages')->where('conversation_id', $conversationId)->where('role', 'assistant')->first();
        $this->assertNotNull($message, 'the streamed reply is persisted as the assistant message');
        $this->assertSame('Hello', $message->content, 'the streamed content is what the reply records');
    }

    // ---------------------------------------------------------------
    // Lease renewal over a long stream
    // ---------------------------------------------------------------

    #[Test]
    public function renews_lease_at_most_every_30s(): void
    {
        // A short base lease (100 s) and a stream that "lasts" 200 s:
        // without renewal the lease would expire mid-stream. The body's
        // read() advances the clock, so the job's at-most-every-30 s
        // renewal is what keeps the run alive.
        config(['llm-client.resume.detection_window_seconds' => 100]);
        $conversationId = $this->makeConversation();
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);

        $this->requestCount = 0;
        $this->app->instance('llm-client.http_handler', function ($request, $options) {
            $this->requestCount++;
            $stream = new class extends Stream {
                private int $reads = 0;

                public function __construct()
                {
                    parent::__construct(fopen('php://memory', 'r+'));
                }

                public function eof(): bool
                {
                    return $this->reads >= 8;
                }

                public function read($length): string
                {
                    $this->reads++;
                    // Advance the clock on every read so the stream "lasts"
                    // longer than the base lease window.
                    Carbon::setTestNow(Carbon::now()->addSeconds(25));

                    if ($this->reads === 8) {
                        return "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}]}\n\ndata: [DONE]\n\n";
                    }

                    return "data: {\"choices\":[{\"delta\":{\"content\":\"x\"},\"finish_reason\":null}]}\n\n";
                }
            };

            return new FulfilledPromise(new Response(200, ['Content-Type' => 'text/event-stream'], $stream));
        });

        (new AgentStreamStepJob($this->request(), $this->data('run-1', 'step-1', $conversationId, '10000000-0000-0000-0000-000000000001')))->handle();

        $this->assertSame(1, $this->requestCount, 'the model request is sent once');
        $leaseExpiry = Carbon::parse((string) $this->runRow('run-1')->lease_expires_at);
        $this->assertTrue(
            $leaseExpiry->isFuture(),
            'the lease was renewed so a stream longer than the base window does not expire it',
        );
    }

    // ---------------------------------------------------------------
    // The mid_stream fault point
    // ---------------------------------------------------------------

    #[Test]
    public function mid_stream_fault_point_reached_after_first_chunk(): void
    {
        $injector = new SnapshotFaultInjector();
        $this->app->instance(\ClarionApp\LlmClient\Contracts\FaultInjector::class, $injector);

        $conversationId = $this->makeConversation();
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $this->bindStreamingHandler($this->contentChunks());

        $injector->arm('mid_stream', 1, ['agent_runs', 'agent_run_steps']);
        $thrown = null;
        try {
            (new AgentStreamStepJob($this->request(), $this->data('run-1', 'step-1', $conversationId, '10000000-0000-0000-0000-000000000001')))->handle();
        } catch (InjectedFault $e) {
            $thrown = $e;
            $injector->restore();
        }

        $this->assertNotNull($thrown, 'the mid_stream fault point is reached after the first chunk');
    }

    // ---------------------------------------------------------------
    // A mid-stream failure: no blind re-dispatch, no finish()
    // ---------------------------------------------------------------

    #[Test]
    public function no_blind_redispatch(): void
    {
        $conversationId = $this->makeConversation();
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $this->bindStreamingHandler($this->contentChunks(), throwOnSecondRead: true);

        (new AgentStreamStepJob($this->request(), $this->data('run-1', 'step-1', $conversationId, '10000000-0000-0000-0000-000000000001')))->handle();

        Queue::assertNotPushed(AgentStreamStepJob::class, 'a mid-stream failure is not re-dispatched blindly');
        $this->assertNotSame(
            'model_recorded',
            $this->stepRow('run-1', 'step-1')->checkpoint_state,
            'an incomplete stream is not recorded as a model response (finish() is not called)',
        );
    }

    // ---------------------------------------------------------------
    // A redelivered job after lease expiry (quickstart M7)
    // ---------------------------------------------------------------

    #[Test]
    public function redelivered_job_after_expiry_wins_a_takeover(): void
    {
        // The lease has expired while held by another process: the job's
        // claim wins a takeover, is recorded once, and the run is handed to
        // the resumer rather than streamed by the job's own unit.
        $conversationId = $this->makeConversation();
        $this->makeRun('run-1', [
            'lease_holder' => '99999999-9999-9999-9999-999999999999',
            'lease_expires_at' => now()->subSeconds(1)->format('Y-m-d H:i:s.u'),
        ]);
        $this->makeStep('step-1', 'run-1', 1);
        $this->bindStreamingHandler($this->contentChunks());

        (new AgentStreamStepJob($this->request(), $this->data('run-1', 'step-1', $conversationId, '10000000-0000-0000-0000-000000000001')))->handle();

        $row = $this->runRow('run-1');
        $this->assertSame(1, (int) $row->consecutive_interruptions, 'the takeover is counted exactly once');

        $detected = DB::table('agent_run_resume_events')->where('run_id', 'run-1')->where('kind', 'interruption_detected')->get();
        $this->assertCount(1, $detected, 'one interruption_detected event');
        $payload = json_decode((string) $detected[0]->payload, true);
        $this->assertSame('takeover', $payload['detected_by'] ?? null, 'the detection is a takeover');

        $this->assertSame(1, DB::table('agent_run_resume_events')->where('run_id', 'run-1')->where('kind', 'takeover')->count(), 'one takeover event');
    }

    // ---------------------------------------------------------------
    // A failed request is reported to the retry scheduler, never finished
    // (quickstart M18, research F3)
    // ---------------------------------------------------------------

    /** A run, its open step and its in-flight model-request action, on a real conversation. */
    private function makeRunOnConversation(): string
    {
        $conversationId = $this->makeConversation();
        $this->makeRun('run-1', ['conversation_id' => $conversationId]);
        $this->makeStep('step-1', 'run-1', 1);
        DB::table('agent_run_actions')->insert([
            'id' => 'action-1',
            'run_id' => 'run-1',
            'step_id' => 'step-1',
            'action_type' => 'llm_request',
            'target' => 'gpt-test',
            'outcome' => 'in_progress',
            'started_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        return $conversationId;
    }

    /** The model request itself is refused: Guzzle rejects the promise, as its own stack does for a 5xx or a refused connection. */
    private function bindRejectingHandler(\Throwable $failure): void
    {
        $this->requestCount = 0;
        $this->app->instance('llm-client.http_handler', function () use ($failure) {
            $this->requestCount++;

            return new RejectedPromise($failure);
        });
    }

    private function statusFailure(int $status): RequestException
    {
        return new RequestException(
            'provider said no: sk-test-LEAK-CHECK',
            new Request('POST', 'https://provider.example/v1/chat/completions'),
            new Response($status, [], json_encode(['error' => ['message' => 'provider said no: sk-test-LEAK-CHECK']])),
        );
    }

    /** @return array<string, array{0: \Throwable, 1: string}> */
    public static function transientRejections(): array
    {
        return [
            '503 response' => [new RequestException('x', new Request('POST', 'https://provider.example/v1/chat/completions'), new Response(503)), 'unavailable'],
            '429 response' => [new RequestException('x', new Request('POST', 'https://provider.example/v1/chat/completions'), new Response(429)), 'rate_limited'],
            'connection refused' => [new ConnectException('Connection refused', new Request('POST', 'https://provider.example/v1/chat/completions')), 'connection'],
        ];
    }

    #[Test]
    #[DataProvider('transientRejections')]
    public function rejected_request_schedules_retry(\Throwable $failure, string $reason): void
    {
        $conversationId = $this->makeRunOnConversation();
        $this->bindRejectingHandler($failure);

        (new AgentStreamStepJob($this->request(), $this->data('run-1', 'step-1', $conversationId, '10000000-0000-0000-0000-000000000001')))->handle();

        $this->assertSame(1, $this->requestCount, 'the request was sent and refused');

        // The run waits: it is not finished, not failed, not left holding a lease.
        $run = $this->runRow('run-1');
        $this->assertSame('in_progress', $run->end_state, 'the run is not over');
        $this->assertSame('waiting_provider_retry', $run->resume_state, 'the failure was handed to the retry scheduler');
        $this->assertNotNull($run->next_attempt_at, 'a retry is due');
        $this->assertNull($run->lease_holder, 'no worker is held for the wait');
        $this->assertNotSame('10000000-0000-0000-0000-000000000001', $run->dispatch_id, 'a new dispatch id carries the retry');
        $this->assertSame(1, (int) $run->provider_attempts);

        $jobs = Queue::pushed(ResumeInterruptedRunJob::class);
        $this->assertCount(1, $jobs, 'a delayed resume job carries the retry');
        $this->assertSame($run->dispatch_id, $jobs->first()->dispatchId);
        $this->assertNotNull($jobs->first()->delay, 'and it is delayed');

        $events = DB::table('agent_run_resume_events')->where('run_id', 'run-1')->where('kind', 'provider_retry_scheduled')->get();
        $this->assertCount(1, $events);
        $this->assertSame($reason, json_decode((string) $events[0]->payload, true)['reason']);

        // finish() was not called: the step is still the open step, no reply
        // was written, and the conversation is still processing (F3: it used
        // to hang for an hour instead).
        $step = $this->stepRow('run-1', 'step-1');
        $this->assertSame('in_progress', $step->end_state, 'the step is not closed');
        $this->assertSame('open', $step->checkpoint_state, 'no model response was recorded');
        $this->assertSame(0, DB::table('messages')->where('conversation_id', $conversationId)->where('role', 'assistant')->count(), 'no reply was written for a response that never came');
        $this->assertTrue((bool) DB::table('conversations')->where('id', $conversationId)->value('is_processing'), 'the task is still running');
        $this->assertSame('failure', DB::table('agent_run_actions')->where('id', 'action-1')->value('outcome'), 'the model-request action records the failed attempt');
        Queue::assertNotPushed(AgentStreamStepJob::class, 'the job never re-dispatches itself');
    }

    #[Test]
    public function incomplete_stream_is_transient(): void
    {
        // Headers arrive, the body ends without a finish reason or [DONE]:
        // the provider cut the stream. That is a failed attempt, never a
        // finished reply.
        $conversationId = $this->makeRunOnConversation();
        $this->bindStreamingHandler([
            "data: {\"choices\":[{\"delta\":{\"content\":\"Hel\"},\"finish_reason\":null}]}\n\n",
            "data: {\"choices\":[{\"delta\":{\"content\":\"lo\"},\"finish_reason\":null}]}\n\n",
        ]);

        (new AgentStreamStepJob($this->request(), $this->data('run-1', 'step-1', $conversationId, '10000000-0000-0000-0000-000000000001')))->handle();

        $run = $this->runRow('run-1');
        $this->assertSame('waiting_provider_retry', $run->resume_state, 'a cut stream is retried');
        $this->assertSame('in_progress', $run->end_state);
        $events = DB::table('agent_run_resume_events')->where('run_id', 'run-1')->where('kind', 'provider_retry_scheduled')->get();
        $this->assertCount(1, $events);
        $this->assertSame('incomplete_stream', json_decode((string) $events[0]->payload, true)['reason']);
        $this->assertSame('open', $this->stepRow('run-1', 'step-1')->checkpoint_state, 'the half reply was not recorded as the model\'s response');
        $this->assertSame('in_progress', $this->stepRow('run-1', 'step-1')->end_state);
        $this->assertCount(1, Queue::pushed(ResumeInterruptedRunJob::class), 'the retry is carried by one delayed job');
        $this->assertTrue((bool) DB::table('conversations')->where('id', $conversationId)->value('is_processing'));
    }

    #[Test]
    public function rejected_400_fails_run_with_report(): void
    {
        // A request the provider will not accept is not retried: the run
        // fails, the conversation is released, and the user is not left
        // waiting for an hour (F3).
        $conversationId = $this->makeRunOnConversation();
        $this->bindRejectingHandler($this->statusFailure(400));

        (new AgentStreamStepJob($this->request(), $this->data('run-1', 'step-1', $conversationId, '10000000-0000-0000-0000-000000000001')))->handle();

        $run = $this->runRow('run-1');
        $this->assertSame('failed', $run->end_state, 'the run is closed failed');
        $this->assertNotEmpty($run->end_reason, 'with a reason');
        $this->assertStringNotContainsString('LEAK-CHECK', (string) $run->end_reason, 'the provider\'s own text is not the reason');
        $this->assertSame('failed', $this->stepRow('run-1', 'step-1')->end_state, 'the open step is closed failed');
        $this->assertFalse((bool) DB::table('conversations')->where('id', $conversationId)->value('is_processing'), 'the conversation is released');
        Queue::assertNotPushed(ResumeInterruptedRunJob::class, 'a rejected request is not retried');
        $this->assertSame(0, DB::table('agent_run_resume_events')->where('run_id', 'run-1')->where('kind', 'provider_retry_scheduled')->count());
        $this->assertNull($run->next_attempt_at);
        $this->assertNotEmpty(
            DB::table('messages')->where('conversation_id', $conversationId)->where('role', 'assistant')->pluck('content')->filter(fn ($c) => $c !== '' && $c !== null)->all(),
            'the user is told, in the conversation, that the task could not continue',
        );
    }
}
