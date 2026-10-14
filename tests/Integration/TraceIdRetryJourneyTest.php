<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Services\AgentLoopService;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * FR-009/SC-006 (US4 scenario 3): a redelivered stream step — the queue
 * connection's own `--tries` mechanism re-releasing the identical
 * `AgentStreamStepJob` payload — carries the ORIGINAL run's run_id forward,
 * never minting a fresh one (research.md D2).
 *
 * The stream step deliberately does not self-redispatch: `AgentStreamStepJob`
 * has `$tries = 1` and, on a rejected request, an incomplete stream, or a
 * mid-stream exception, neither re-dispatches nor records a model response it
 * did not receive (lease contract §5). A failed stream is instead detected by
 * lease expiry and resumed by `RepeatModelCall` (Phase 5) — a fresh dispatch
 * that reads the run's durable state, not a retry of the same job. So the
 * only redelivery of the identical serialized payload this test can exercise
 * is the queue's own native retry: the same payload, popped, released back
 * onto the queue, and popped again — no new dispatch, no serialization of
 * anything this suite cannot serialize.
 *
 * The test undoes the suite's fake queue and dispatches for real, against
 * the `database` connection (the `sync` connection executes the job inline
 * during dispatch, in the same process with the same Context, so there is no
 * real queue-payload boundary for D2's rehydration to cross). The deferred
 * job is popped off a real, non-executing queue backend, its `JobProcessing`
 * event fired (D2's rehydration hook), and its `AgentLoopStreamHandler`
 * callback driven directly with scripted content.
 */
class TraceIdRetryJourneyTest extends AssembledSystemTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.run_trace.enabled', true);

        // See TraceIdDeferredWorkJourneyTest's docblock: undo the fake queue
        // so dispatch and processing genuinely cross Queue::createPayloadUsing
        // / JobProcessing (research.md D2).
        Facade::clearResolvedInstance('queue');
        $this->app->forgetInstance('queue');
        $this->app->forgetInstance('queue.connection');
        $this->app['config']->set('queue.default', 'database');
        $this->app['config']->set('queue.connections.database.connection', null);

        if (!Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }
    }

    /**
     * Pop the next AgentStreamStepJob genuinely queued on the real
     * `database` connection's `http` queue, fire the real JobProcessing
     * event (D2's rehydration hook), and hand back both the job (so the
     * caller can release() or delete() it) and its public constructor
     * arguments recovered off the unserialized command.
     *
     * @return array{job: QueueJobContract, request: \ClarionApp\HttpQueue\HttpRequest, data: array}
     */
    private function popAndRehydrateStreamJob(): array
    {
        $job = Queue::connection('database')->pop('http');
        $this->assertNotNull($job, 'Expected a genuinely queued AgentStreamStepJob job on the database connection\'s "http" queue');

        $payload = $job->payload();
        $command = unserialize($payload['data']['command']);
        $this->assertInstanceOf(AgentStreamStepJob::class, $command);

        event(new JobProcessing('database', $job));

        return [
            'job' => $job,
            'request' => $command->request,
            'data' => $command->data,
        ];
    }

    private function driveStreamHandlerToFinalAnswer(array $data, string $content): void
    {
        $handler = $this->app->make(AgentLoopStreamHandler::class);
        $response = [
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop'],
            ],
        ];
        $elapsed = 0.0;
        foreach ($this->buildSseChunks($response) as $chunk) {
            $elapsed += 0.01;
            $handler->handle($chunk, $data, $elapsed);
        }
        $handler->finish($data, $elapsed);
    }

    /**
     * FR-009/SC-006, US4 scenario 3, queue-native retry sub-case: the SAME
     * job payload, released back onto the queue and popped again (no new
     * dispatch — Laravel's own `--tries` mechanism re-processing the
     * identical serialized payload), rehydrates to the SAME run_id both
     * times and the eventually-written records carry the original id.
     */
    public function test_queue_native_retry_of_the_same_payload_preserves_the_original_run_id(): void
    {
        $this->scenario = 'queue_native_retry_preserves_original_run_id';
        $this->entryPath = 'stream';

        $fixture = $this->fixture()->build();
        Message::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $fixture->conversation->id,
            'role' => 'user',
            'content' => 'A question whose worker will be re-tried by the queue connection itself',
        ]);

        $this->app->make(AgentLoopService::class)->start($fixture->conversation);

        $run = DB::table('agent_runs')->where('conversation_id', $fixture->conversation->id)->first();
        $this->assertNotNull($run);
        $originalRunId = $run->id;

        Context::flush();
        $firstTry = $this->popAndRehydrateStreamJob();
        $this->assertSame($originalRunId, Context::get('run_id'), 'The first delivery attempt must rehydrate the originating run_id');
        $firstPayload = $firstTry['job']->getRawBody();

        // Simulate a worker crash/timeout on this attempt: release the job
        // back onto the queue exactly as Laravel's own retry_after/--tries
        // machinery would (DatabaseJob::release() -> DatabaseQueue::deleteAndRelease(),
        // same row, attempts incremented) — no new dispatch, no new payload.
        Context::flush();
        $firstTry['job']->release(0);

        $secondTry = $this->popAndRehydrateStreamJob();
        $this->assertSame(
            $firstPayload,
            $secondTry['job']->getRawBody(),
            'Precondition: the queue\'s native retry must redeliver the byte-identical payload, not a new dispatch'
        );
        $this->assertGreaterThan(
            1,
            $secondTry['job']->attempts(),
            'Precondition: this must genuinely be a second delivery attempt of the same job'
        );
        $this->assertSame(
            $originalRunId,
            Context::get('run_id'),
            'FR-009: the queue\'s own native retry of the identical payload must rehydrate to the SAME run_id'
        );
        $this->assertSame($originalRunId, $secondTry['data']['run_id'] ?? null);

        $this->driveStreamHandlerToFinalAnswer($secondTry['data'], 'The answer, after a queue-native retry.');

        $replyMessage = Message::where('conversation_id', $fixture->conversation->id)
            ->where('role', 'assistant')
            ->first();
        $this->assertNotNull($replyMessage);
        $this->assertSame(
            $originalRunId,
            $replyMessage->run_id,
            'SC-006: the record written after the queue\'s own retry must carry the ORIGINAL run_id, never a new one'
        );

        $runCount = DB::table('agent_runs')->where('conversation_id', $fixture->conversation->id)->count();
        $this->assertSame(1, $runCount, 'FR-009: a queue-native retry must never mint a second run for the same continuation');
    }
}
