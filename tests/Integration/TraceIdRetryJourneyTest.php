<?php

namespace Tests\Integration;

use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
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
 * FR-009/SC-006 (US4 scenario 3): a retried deferred continuation — whether
 * SendHttpStreamRequest self-redispatches on a transient failure, or the
 * queue connection's own `--tries` mechanism re-releases the identical job
 * payload — carries the ORIGINAL run's run_id forward, never minting a
 * fresh one (research.md D2).
 *
 * How the two sub-cases are driven, and why:
 *
 * Sub-case A (self-redispatch) needs SendHttpStreamRequest::handle() to hit
 * a transient \RuntimeException while reading the stream and call
 * `SendHttpStreamRequest::dispatch($this->request, $this->callback_name, $this->data)`
 * from its own catch block (http-queue/src/Jobs/SendHttpStreamRequest.php:76).
 * That method hard-codes `new Client(['timeout' => ...])` with no test
 * seam, so actually running it here would require a real, unmocked network
 * call — not available to this suite (see TraceIdDeferredWorkJourneyTest's
 * docblock for the full reasoning, and http-queue/tests/Unit/
 * SendHttpStreamRequestTest.php for how http-queue's own suite handles this:
 * an anonymous subclass swapping the Client, which cannot itself be
 * dispatched through a real queue, because PHP forbids serializing
 * `class@anonymous` instances — and dispatch-and-serialize is exactly the
 * mechanism under test here).
 *
 * http-queue's own test suite (T015 there) already proves that exact
 * `SendHttpStreamRequest::dispatch(...)` line fires when a genuine
 * \RuntimeException is thrown mid-stream — that is http-queue's contract,
 * not 069's. What 069 owns is narrower and is what this test actually
 * proves: that when a job's own already-hydrated Context is active and
 * something inside it — whether http-queue's real retry branch or this
 * test standing in for it — issues that identical dispatch call, the
 * result carries the SAME run_id forward via Queue::createPayloadUsing,
 * automatically, with no code in this feature doing anything to make that
 * happen. This test therefore issues that literal production line itself,
 * from within a job whose Context was rehydrated by a REAL, genuine
 * JobProcessing crossing (not a value the test set), and inspects the
 * SECOND job that results.
 *
 * Sub-case B (the queue's own --tries) needs no such substitution: it is
 * the same payload, popped, released back onto the queue, and popped again
 * — no new dispatch, no serialization of anything this suite cannot
 * serialize.
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
     * Pop the next SendHttpStreamRequest genuinely queued on the real
     * `database` connection's `http` queue, fire the real JobProcessing
     * event (D2's rehydration hook), and hand back both the job (so the
     * caller can release() or delete() it) and its constructor arguments
     * recovered via reflection off the unserialized command.
     *
     * @return array{job: QueueJobContract, request: \ClarionApp\HttpQueue\HttpRequest, callback_name: string, data: array}
     */
    private function popAndRehydrateStreamJob(): array
    {
        $job = Queue::connection('database')->pop('http');
        $this->assertNotNull($job, 'Expected a genuinely queued SendHttpStreamRequest job on the database connection\'s "http" queue');

        $payload = $job->payload();
        $command = unserialize($payload['data']['command']);
        $this->assertInstanceOf(SendHttpStreamRequest::class, $command);

        event(new JobProcessing('database', $job));

        $reflector = new \ReflectionClass($command);

        $requestProperty = $reflector->getProperty('request');
        $requestProperty->setAccessible(true);

        $callbackProperty = $reflector->getProperty('callback_name');
        $callbackProperty->setAccessible(true);

        $dataProperty = $reflector->getProperty('data');
        $dataProperty->setAccessible(true);
        $rawData = $dataProperty->getValue($command);

        return [
            'job' => $job,
            'request' => $requestProperty->getValue($command),
            'callback_name' => $callbackProperty->getValue($command),
            'data' => is_string($rawData) ? json_decode($rawData, true) : (array) $rawData,
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
     * FR-009/SC-006, US4 scenario 3, self-redispatch sub-case: the retry
     * dispatch SendHttpStreamRequest issues from inside its own handle() on
     * a transient failure carries the ORIGINAL run_id, never a fresh one —
     * because it fires while that job's own (genuinely rehydrated) Context
     * is still active.
     */
    public function test_self_redispatch_retry_preserves_the_original_run_id(): void
    {
        $this->scenario = 'self_redispatch_retry_preserves_original_run_id';
        $this->entryPath = 'stream';

        $fixture = $this->fixture()->build();
        Message::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $fixture->conversation->id,
            'role' => 'user',
            'content' => 'A question whose first stream attempt will fail transiently',
        ]);

        $this->app->make(AgentLoopService::class)->start($fixture->conversation);

        $run = DB::table('agent_runs')->where('conversation_id', $fixture->conversation->id)->first();
        $this->assertNotNull($run);
        $originalRunId = $run->id;

        // --- Attempt 1: pops, rehydrates, then (standing in for a real
        // transient \RuntimeException inside handle()'s stream read —
        // see class docblock) issues the exact retry-dispatch line
        // production code runs from its catch block, while attempt 1's
        // rehydrated Context is still the active one. ---
        Context::flush();
        $attempt1 = $this->popAndRehydrateStreamJob();
        $this->assertSame($originalRunId, Context::get('run_id'), 'Attempt 1 must rehydrate the originating run_id');

        SendHttpStreamRequest::dispatch($attempt1['request'], $attempt1['callback_name'], $attempt1['data']);
        $attempt1['job']->delete();

        // --- Attempt 2 (the retry): pop the job the line above produced.
        // It must carry the SAME run_id — proving Queue::createPayloadUsing
        // dehydrated it from attempt 1's still-active, rehydrated Context,
        // not from a fresh mint. ---
        Context::flush();
        $this->assertNull(Context::get('run_id'), 'Precondition: Context must be empty before the retry is processed');

        $attempt2 = $this->popAndRehydrateStreamJob();
        $this->assertSame(
            $originalRunId,
            Context::get('run_id'),
            'FR-009: the self-redispatched retry must rehydrate to the SAME run_id as the original attempt, not a fresh one'
        );
        $this->assertSame(
            $originalRunId,
            $attempt2['data']['run_id'] ?? null,
            'The pre-069 manual run_id channel in the retried job\'s data must also carry the original id'
        );

        $this->driveStreamHandlerToFinalAnswer($attempt2['data'], 'The answer, after one transient retry.');

        $replyMessage = Message::where('conversation_id', $fixture->conversation->id)
            ->where('role', 'assistant')
            ->first();
        $this->assertNotNull($replyMessage, 'The retried attempt must have eventually written the reply');
        $this->assertSame(
            $originalRunId,
            $replyMessage->run_id,
            'SC-006: the record written after a successful retry must carry the ORIGINAL run_id, never a new one'
        );

        $usageRecord = UsageRecord::where('conversation_id', $fixture->conversation->id)->first();
        $this->assertNotNull($usageRecord);
        $this->assertSame($originalRunId, $usageRecord->run_id, 'SC-006: usage recorded after the retry must carry the original run_id');

        // There must be exactly one run for this conversation throughout —
        // the retry never opened a second one.
        $runCount = DB::table('agent_runs')->where('conversation_id', $fixture->conversation->id)->count();
        $this->assertSame(1, $runCount, 'FR-009: a retry must never mint a second run for the same continuation');
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
