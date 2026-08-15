<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\LlmClient\Jobs\RunDelegationBatchMemberJob;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Services\DelegationService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * 101-parallel-subagent-execution reconciliation (roadmap.implement step
 * 5): proves RunDelegationBatchMemberJob::retryUntil() actually lets a
 * member that loses the admission race retry on a REAL, non-`sync` queue
 * connection, driven through Laravel's own Worker::process() loop -- not
 * `Bus::fake()`, not the `sync` driver (SyncJob::release() never re-queues
 * at all, so the fast suite structurally cannot exercise Laravel's own
 * attempts/tries bookkeeping), and not a hand-driven direct `handle()`
 * call the way DelegationConcurrencyCeilingTest (Phase 5) exercises the
 * gate's own boundary logic. Mirrors TraceIdRetryJourneyTest's own
 * established pattern for standing up a genuine `database` queue
 * connection inside this suite. Lives in tests/Feature/, not
 * tests/Integration/, because it deliberately mocks DelegationService --
 * NoMocksGuardTest forbids Mockery::mock()/$app->instance() for
 * ClarionApp\LlmClient classes anywhere under tests/Integration/.
 *
 * Without RunDelegationBatchMemberJob::retryUntil() (added by this
 * reconciliation pass), this test fails: `$tries = 1` means
 * attempts()=2 on the job's SECOND delivery already exceeds maxTries=1,
 * so Worker::process() calls markJobAsFailedIfAlreadyExceedsMaxAttempts()
 * and throws MaxAttemptsExceededException BEFORE handle() -- and
 * therefore DelegationConcurrencyGate::tryAdmit() -- is ever invoked
 * again. Concretely: the very FIRST time a batch member loses the
 * admission race on a real queue connection, it is permanently recorded
 * 'failed' on its next delivery instead of ever getting a second chance
 * to be admitted -- silently breaking FR-006/US4's "members beyond the
 * ceiling wait and begin only as running slots free up" guarantee for
 * every real (non-sync) queue deployment, invisible to every other test
 * in this feature because none of them exercise a real queue/Worker
 * redelivery cycle for this job.
 */
class RunDelegationBatchMemberJobQueueRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->defineAgentDelegationSchema();

        // Undo the fake queue so dispatch/pop/release genuinely cross a
        // real, persistent connection (TraceIdRetryJourneyTest's own
        // established precedent for this exact substitution).
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

        if (!Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }

        config(['llm-client.delegation.concurrency.admission_retry_delay_seconds' => 0]);
    }

    private function worker(): Worker
    {
        return new Worker(
            app('queue'),
            app('events'),
            app(ExceptionHandler::class),
            fn () => false,
        );
    }

    public function test_a_member_that_loses_the_admission_race_twice_is_still_admitted_on_a_later_delivery_once_a_slot_frees_not_permanently_failed(): void
    {
        // A no-op DelegationService double -- this test only cares whether
        // the JOB gets a genuine chance to be admitted across several real
        // deliveries, not what a nested AgentLoopService::run() call does
        // once admitted (DelegationConcurrencyCeilingTest's own established
        // precedent for isolating exactly this).
        $service = Mockery::mock(DelegationService::class);
        // 106-multi-agent-run-view (US2, research.md D4a): the job calls
        // this immediately after the successful (third-delivery) admission,
        // before runBatchMember() -- a no-op double here, this test's own
        // concern is the admission retry mechanics, not the broadcast.
        $service->shouldReceive('broadcastDelegationAdmitted')->once();
        $service->shouldReceive('runBatchMember')->once()->andReturnNull();
        $this->app->instance(DelegationService::class, $service);

        $batchId = (string) Str::uuid();
        $delegation = Delegation::create([
            'parent_conversation_id' => (string) Str::uuid(),
            'helper_agent_id' => (string) Str::uuid(),
            'helper_conversation_id' => (string) Str::uuid(),
            'owner_user_id' => (string) Str::uuid(),
            'task' => 'A batch member that loses the admission race twice before a slot frees.',
            'depth' => 1,
            'status' => 'queued',
            'batch_id' => $batchId,
            'started_at' => now(),
        ]);

        // Ceiling of 0 -- tryAdmit() refuses unconditionally.
        config(['llm-client.delegation.concurrency.max_concurrent_per_batch' => 0]);

        RunDelegationBatchMemberJob::dispatch($delegation->id)->onQueue('delegation-batches');

        $worker = $this->worker();
        $options = new WorkerOptions(maxTries: 0);

        // First delivery: refused, released. Must not be failed.
        $job = Queue::connection('database')->pop('delegation-batches');
        $this->assertNotNull($job, 'expected the dispatched job to be genuinely poppable from the database connection');
        $worker->process('database', $job, $options);
        $delegation->refresh();
        $this->assertSame('queued', $delegation->status, 'after the FIRST refused admission attempt the row must still be queued, not failed');

        // Second delivery: the SAME underlying dispatch, redelivered. This
        // is the step that was broken before retryUntil() existed --
        // attempts() is now 2, exceeding the bare $tries=1, and only
        // retryUntil() keeps Worker::process() from auto-failing the job
        // here without ever calling handle() again.
        $job2 = Queue::connection('database')->pop('delegation-batches');
        $this->assertNotNull($job2, 'expected the released job to have been genuinely re-queued for a second delivery');
        $worker->process('database', $job2, $options);
        $delegation->refresh();
        $this->assertSame('queued', $delegation->status, 'after the SECOND refused admission attempt the row must STILL be queued, not permanently failed by the queue\'s own tries bookkeeping');

        // A slot finally frees -- raise the ceiling, exactly like a real
        // sibling member completing would.
        config(['llm-client.delegation.concurrency.max_concurrent_per_batch' => 1]);

        $job3 = Queue::connection('database')->pop('delegation-batches');
        $this->assertNotNull($job3, 'expected a third, genuine redelivery once released again');
        $worker->process('database', $job3, $options);
        $delegation->refresh();
        $this->assertSame(
            'in_progress',
            $delegation->status,
            'once a slot genuinely frees, a member that lost the admission race twice must still be admitted and run -- FR-006/US4\'s own "wait and begin only as running slots free up" guarantee, proven end to end on a real queue connection',
        );
    }
}
