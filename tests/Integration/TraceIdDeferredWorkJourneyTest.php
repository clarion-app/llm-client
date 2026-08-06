<?php

namespace Tests\Integration;

use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\ToolInvocationRecord;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Services\AgentLoopService;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\TestHandler;

/**
 * US4 (FR-007/FR-008, SC-005): a run's deferred continuation — a
 * SendHttpStreamRequest job queued by AgentLoopService::start()/
 * AgentLoopStreamHandler's own re-dispatch on the next iteration — carries
 * the originating run's run_id into every Message/ToolInvocationRecord/
 * UsageRecord and log line it produces, across a REAL queue-job boundary
 * (research.md D2).
 *
 * Why this test does not use the suite's usual ScriptedStream harness:
 * every other stream-path journey test in this directory calls
 * `Queue::fake([SendHttpStreamRequest::class])` (set up in
 * AssembledSystemTestCase::setUp()) and then reads the captured job back out
 * of the fake queue with `Queue::pushed(...)`. That is the right tool for
 * asserting *what got dispatched*, but QueueFake never calls
 * Queue::createPayload() and never fires JobProcessing — it stores the job
 * object in memory and hands it straight back. Neither of D2's two hooks
 * (Queue::createPayloadUsing, registered by
 * Illuminate\Log\Context\ContextServiceProvider::boot(), and the
 * JobProcessing listener the same provider registers) ever fires under a
 * fake queue, so a suite that only ever used Queue::fake() could not tell
 * the difference between "run_id survives a real queue-job boundary" and
 * "run_id was never asked to leave the process in the first place".
 *
 * This file undoes that fake and dispatches for real, against the
 * `database` queue connection (the `sync` connection was rejected: it
 * executes the job inline during dispatch, which would require
 * SendHttpStreamRequest::handle() to actually run — and that method
 * hard-codes `new Client(['timeout' => ...])` with no test seam
 * (http-queue/src/Jobs/SendHttpStreamRequest.php:40), unlike llm-client's
 * own sync HTTP call sites, which honour app('llm-client.http_handler')
 * (LlmClientServiceProvider::httpClientFor()). Real network is not
 * available to this suite, so the deferred job is popped off a real,
 * non-executing queue backend and its callback (AgentLoopStreamHandler,
 * the same class SendHttpStreamRequest::handle() would itself construct
 * and drive) is invoked directly with scripted content — exactly what
 * ScriptedStream already does elsewhere in this suite, except here the pop
 * is preceded by a genuine `Illuminate\Queue\Events\JobProcessing` dispatch
 * against a genuinely-queued, genuinely-serialized job payload, with
 * Context explicitly flushed beforehand so nothing can survive by process
 * accident.
 */
class TraceIdDeferredWorkJourneyTest extends AssembledSystemTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.run_trace.enabled', true);

        // Undo AssembledSystemTestCase::setUp()'s Queue::fake([SendHttpStreamRequest::class])
        // — see class docblock. Facade::clearResolvedInstance() is required in
        // addition to forgetInstance(): Queue::fake() calls Facade::swap(),
        // which caches the fake on the Facade's own static resolved-instance
        // table, not just the container binding.
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

        // Captured Monolog handler for the log-line assertions — identical
        // technique to TraceIdLogCorrelationJourneyTest (Phase 4, T021):
        // Context values land in Monolog's `extra` array, not `context`.
        $this->app['config']->set('logging.channels.testing', [
            'driver' => 'monolog',
            'handler' => TestHandler::class,
            'level' => 'debug',
        ]);
        $this->app['config']->set('logging.default', 'testing');
    }

    /**
     * @return \Monolog\LogRecord[]
     */
    private function capturedLogRecords(): array
    {
        $logger = Log::channel('testing')->getLogger();
        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof TestHandler) {
                return $handler->getRecords();
            }
        }
        return [];
    }

    /**
     * @return \Monolog\LogRecord[]
     */
    private function logRecordsForRun(string $runId): array
    {
        return array_values(array_filter(
            $this->capturedLogRecords(),
            fn ($record) => ($record->extra['run_id'] ?? null) === $runId
        ));
    }

    /**
     * Pop the next SendHttpStreamRequest genuinely queued on the real
     * `database` connection's `http` queue (SendHttpStreamRequest's own
     * default — its constructor's fourth argument), fire the real
     * JobProcessing event (what a worker does immediately before invoking a
     * job — this is D2's rehydration hook), and hand back the job's
     * constructor arguments recovered via reflection off the unserialized
     * command — the same technique ScriptedStream::extractDispatchedJobs()
     * already uses for the faked-queue case.
     *
     * @return array{job: QueueJobContract, command: SendHttpStreamRequest, data: array}
     */
    private function popAndRehydrateStreamJob(): array
    {
        $job = Queue::connection('database')->pop('http');
        $this->assertNotNull($job, 'Expected a genuinely queued SendHttpStreamRequest job on the database connection\'s "http" queue');

        $payload = $job->payload();
        $command = unserialize($payload['data']['command']);
        $this->assertInstanceOf(
            SendHttpStreamRequest::class,
            $command,
            'Sanity: the queued job is genuinely the production SendHttpStreamRequest class, not a stand-in'
        );

        // The exact hook research.md D2 names: ContextServiceProvider's
        // JobProcessing listener rehydrates Context from the payload's
        // `illuminate:log:context` key, written by Queue::createPayloadUsing
        // at dispatch time (Illuminate\Log\Context\ContextServiceProvider::boot()).
        event(new JobProcessing('database', $job));

        $reflector = new \ReflectionClass($command);
        $dataProperty = $reflector->getProperty('data');
        $dataProperty->setAccessible(true);
        $rawData = $dataProperty->getValue($command);
        $data = is_string($rawData) ? json_decode($rawData, true) : (array) $rawData;

        return ['job' => $job, 'command' => $command, 'data' => $data];
    }

    /**
     * Drive the real AgentLoopStreamHandler — the same class
     * SendHttpStreamRequest::handle() would itself instantiate as its
     * callback — with scripted SSE content, exactly as if it were being fed
     * by a real streamed HTTP response inside the deferred job.
     */
    private function driveStreamHandler(array $sseChunks, array $data): AgentLoopStreamHandler
    {
        $handler = $this->app->make(AgentLoopStreamHandler::class);
        $elapsed = 0.0;
        foreach ($sseChunks as $chunk) {
            $elapsed += 0.01;
            $handler->handle($chunk, $data, $elapsed);
        }
        $handler->finish($data, $elapsed);

        return $handler;
    }

    private function toolCallResponse(string $toolName, array $arguments): array
    {
        return [
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'tool_calls' => [
                            [
                                'id' => 'call_' . bin2hex(random_bytes(8)),
                                'type' => 'function',
                                'function' => [
                                    'name' => $toolName,
                                    'arguments' => json_encode($arguments),
                                ],
                            ],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
        ];
    }

    private function finalAnswerResponse(string $content): array
    {
        return [
            'choices' => [
                [
                    'message' => ['role' => 'assistant', 'content' => $content],
                    'finish_reason' => 'stop',
                ],
            ],
        ];
    }

    /**
     * US4 scenarios 1-2: a single-round deferred continuation (no tool
     * calls) produces a Message and a UsageRecord, and log lines, that all
     * carry the originating run's run_id — recovered only via a real
     * dispatch -> Context::flush() -> real JobProcessing rehydration cycle.
     */
    public function test_deferred_job_message_usage_record_and_log_lines_carry_the_originating_run_id(): void
    {
        $this->scenario = 'deferred_job_carries_originating_run_id';
        $this->entryPath = 'stream';

        $fixture = $this->fixture()->build();
        Message::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $fixture->conversation->id,
            'role' => 'user',
            'content' => 'A question whose answer arrives via a deferred job',
        ]);

        // The real production entry point: opens the run (Context::add
        // fires inside RunTraceRecorder::openRun(), Phase 3) and genuinely
        // dispatches SendHttpStreamRequest.
        $this->app->make(AgentLoopService::class)->start($fixture->conversation);

        $run = DB::table('agent_runs')->where('conversation_id', $fixture->conversation->id)->first();
        $this->assertNotNull($run, 'start() must have opened a run before dispatching');
        $runId = $run->id;

        // Prove the crossing is genuine, not a process accident: flush
        // Context before "leaving" for the deferred job, exactly as a real
        // worker process would never have inherited it in the first place.
        Context::flush();
        $this->assertNull(Context::get('run_id'), 'Precondition: Context must be empty before the deferred job is processed');

        ['data' => $data] = $this->popAndRehydrateStreamJob();

        $this->assertSame(
            $runId,
            Context::get('run_id'),
            'D2: the real JobProcessing rehydration must restore the originating run_id after a genuine queue-payload crossing'
        );
        $this->assertSame($runId, $data['run_id'] ?? null, 'The pre-069 manual run_id channel in the job data must also carry the same id (D2, redundant channel)');

        $this->driveStreamHandler($this->buildSseChunks($this->finalAnswerResponse('The deferred answer.')), $data);

        $replyMessage = Message::where('conversation_id', $fixture->conversation->id)
            ->where('role', 'assistant')
            ->first();
        $this->assertNotNull($replyMessage, 'The deferred job must have written the assistant reply');
        $this->assertSame($runId, $replyMessage->run_id, 'FR-007: the message the deferred job writes must carry the originating run_id');

        $usageRecord = UsageRecord::where('conversation_id', $fixture->conversation->id)->first();
        $this->assertNotNull($usageRecord, 'The deferred job must have recorded usage for the completed stream');
        $this->assertSame($runId, $usageRecord->run_id, 'FR-007/SC-005: the usage record the deferred job writes must carry the originating run_id');

        $runLogLines = $this->logRecordsForRun($runId);
        $this->assertNotEmpty($runLogLines, 'FR-008: the deferred job must have produced at least one log line carrying the originating run_id');
        $messages = array_map(fn ($r) => $r->message, $runLogLines);
        $this->assertContains(
            'AgentLoopStreamHandler: finish called',
            $messages,
            'A log line the deferred job\'s own finish() call produces must be among those carrying the run_id'
        );
    }

    /**
     * US4 scenarios 1-2, multi-hop: a tool-call round dispatches its OWN
     * second SendHttpStreamRequest from inside the first deferred job's
     * finish() (AgentLoopStreamHandler::handleToolCalls() ->
     * AgentLoopService::start($conversation, $iteration + 1, $this->runId)).
     * That second dispatch happens while the first job's rehydrated Context
     * is still live, so it is dehydrated the same way the very first
     * dispatch was — this test crosses the real queue boundary TWICE and
     * asserts every record from both hops, including the ToolInvocationRecord
     * the tool round produces, carries the one originating run_id throughout.
     */
    public function test_deferred_tool_round_across_two_queue_crossings_carries_run_id_throughout(): void
    {
        $this->scenario = 'deferred_tool_round_two_crossings_carries_run_id';
        $this->entryPath = 'stream';

        $fixture = $this->fixture()->build();
        Message::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $fixture->conversation->id,
            'role' => 'user',
            'content' => 'List my contacts',
        ]);

        $this->app->make(AgentLoopService::class)->start($fixture->conversation);

        $run = DB::table('agent_runs')->where('conversation_id', $fixture->conversation->id)->first();
        $this->assertNotNull($run);
        $runId = $run->id;

        // --- First crossing: the tool-call round. ---
        Context::flush();
        ['data' => $firstHopData] = $this->popAndRehydrateStreamJob();
        $this->assertSame($runId, Context::get('run_id'), 'First crossing must rehydrate the originating run_id');

        $this->driveStreamHandler(
            $this->buildToolCallSseChunks($this->toolCallResponse('search_operations', ['query' => 'list contacts'])),
            $firstHopData,
        );

        $toolInvocation = ToolInvocationRecord::where('conversation_id', $fixture->conversation->id)->first();
        $this->assertNotNull($toolInvocation, 'The first deferred hop must have executed the tool and recorded the invocation');
        $this->assertSame($runId, $toolInvocation->run_id, 'FR-007: the tool invocation the first deferred hop records must carry the originating run_id');

        $firstHopUsage = UsageRecord::where('conversation_id', $fixture->conversation->id)->first();
        $this->assertNotNull($firstHopUsage);
        $this->assertSame($runId, $firstHopUsage->run_id);

        // handleToolCalls() dispatched a SECOND SendHttpStreamRequest for
        // the continuation, from inside AgentLoopStreamHandler::finish()
        // above, while this test process's Context was the rehydrated one
        // from the first pop — flush it again before crossing the second time.
        Context::flush();
        $this->assertNull(Context::get('run_id'), 'Precondition: Context must be empty before the second deferred hop is processed');

        ['data' => $secondHopData] = $this->popAndRehydrateStreamJob();
        $this->assertSame(
            $runId,
            Context::get('run_id'),
            'D2: the second, job-triggered dispatch must dehydrate/rehydrate the SAME originating run_id, not a fresh one'
        );

        $this->driveStreamHandler($this->buildSseChunks($this->finalAnswerResponse('I found some contacts for you.')), $secondHopData);

        $finalReply = Message::where('conversation_id', $fixture->conversation->id)
            ->where('role', 'assistant')
            ->latest('created_at')
            ->first();
        $this->assertNotNull($finalReply);
        $this->assertSame($runId, $finalReply->run_id, 'The final reply written by the second deferred hop must carry the originating run_id');

        $usageRecords = UsageRecord::where('conversation_id', $fixture->conversation->id)->get();
        $this->assertGreaterThanOrEqual(2, $usageRecords->count(), 'One usage record per hop: the tool round and the final answer');
        foreach ($usageRecords as $usage) {
            $this->assertSame($runId, $usage->run_id, "Usage record {$usage->id} must carry the originating run_id regardless of which hop wrote it");
        }

        $runLogLines = $this->logRecordsForRun($runId);
        $loggedMessages = array_map(fn ($r) => $r->message, $runLogLines);
        $this->assertContains(
            'AgentLoopStreamHandler: executing tool',
            $loggedMessages,
            'A log line from the FIRST deferred hop must carry the run_id'
        );
        $this->assertContains(
            'AgentLoopStreamHandler: finish called',
            $loggedMessages,
            'A log line from the SECOND deferred hop must also carry the run_id'
        );
    }
}
