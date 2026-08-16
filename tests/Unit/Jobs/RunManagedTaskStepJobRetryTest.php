<?php

namespace ClarionApp\LlmClient\Tests\Unit\Jobs;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Jobs\RunManagedTaskStepJob;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Services\AgentLoopService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 110-delegation-deadlock-timeout, Phase 7 (Polish), tasks.md T043.
 *
 * FR-012/research.md D5: a chain stopped for depth/time exhaustion must
 * not be silently reconstructed by Laravel's own automatic queue retry.
 * `RunDelegationBatchMemberJob` already guards this with an explicit
 * `$tries = 1` (plus a `retryUntil()` override needed ONLY because that
 * job also uses deliberate `release()` for admission-race retries --
 * tests/Feature/RunDelegationBatchMemberJobQueueRetryTest.php proves that
 * half, driven through a real `database` queue connection and Laravel's
 * own `Worker::process()` loop rather than `Queue::fake()`, which never
 * exercises attempts()/tries bookkeeping at all). `RunManagedTaskStepJob`
 * never calls release() itself -- its retry concern is narrower: a
 * worker-level failure thrown out of handle() (a timeout mid-delegation,
 * or any other uncaught exception) must fail the job PERMANENTLY on its
 * first attempt rather than being silently redelivered by Laravel's own
 * attempts/tries bookkeeping into re-running the same step of the same
 * managed task -- which would replay the exact delegation calls the
 * depth/chain-time bounds may have already refused once.
 *
 * Written before `RunManagedTaskStepJob` declares `$tries` -- both tests
 * below are expected to FAIL red against the current source (no `$tries`
 * override at all, so the job inherits whatever `--tries` value a real
 * worker process was started with; a worker configured with tries > 1,
 * entirely plausible for the manager queue in production since other job
 * types on the same connection may legitimately want retries, would keep
 * redelivering this job).
 */
class RunManagedTaskStepJobRetryTest extends TestCase
{
    private ?User $user = null;

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();

        Mockery::close();
        DB::table('managed_task_parts')->delete();
        DB::table('managed_tasks')->delete();
        DB::table('messages')->delete();
        DB::table('conversations')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function makeTaskAndConversation(): ManagedTask
    {
        $this->user = $this->user ?? User::factory()->create();

        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'channel' => 'managed-task',
        ]);

        return ManagedTask::create([
            'conversation_id' => $conversation->id,
            'owner_user_id' => $this->user->id,
            'manager_agent_id' => null,
            'original_request' => 'Do the thing.',
            'status' => 'in_progress',
            'round_ceiling' => 30,
            'rounds_used' => 0,
            'max_seconds' => 1800,
            'last_progress_at' => now(),
            'started_at' => now(),
        ]);
    }

    // -----------------------------------------------------------------
    // The direct, cheap check: the job declares $tries = 1 at all.
    // -----------------------------------------------------------------

    #[Test]
    public function the_job_declares_tries_as_one_so_it_can_never_be_auto_retried_past_a_single_attempt(): void
    {
        $task = $this->makeTaskAndConversation();

        $job = new RunManagedTaskStepJob($task->id);

        $this->assertTrue(
            property_exists($job, 'tries'),
            'RunManagedTaskStepJob must declare its own $tries property (mirroring RunDelegationBatchMemberJob) rather than inheriting whatever --tries value a real worker process happens to be started with -- FR-012/research.md D5.',
        );
        $this->assertSame(
            1,
            $job->tries,
            'RunManagedTaskStepJob::$tries must be exactly 1 so a worker-level failure mid-delegation is never automatically retried by Laravel into re-running the same step (FR-012).',
        );
    }

    // -----------------------------------------------------------------
    // The end-to-end proof: a real queue worker, a real uncaught
    // exception thrown mid-delegation, driven through Laravel's own
    // Worker::process() loop exactly like
    // RunDelegationBatchMemberJobQueueRetryTest.php does for the sibling
    // job -- not Queue::fake(), which never exercises attempts()/tries
    // bookkeeping at all.
    // -----------------------------------------------------------------

    private function worker(): Worker
    {
        return new Worker(
            app('queue'),
            app('events'),
            app(ExceptionHandler::class),
            fn () => false,
        );
    }

    #[Test]
    public function a_worker_level_exception_thrown_mid_delegation_is_not_automatically_redelivered_by_a_real_queue_worker(): void
    {
        // Undo the fake queue so dispatch/pop/fail genuinely cross a real,
        // persistent connection (RunDelegationBatchMemberJobQueueRetryTest's
        // own established precedent for this exact substitution).
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

        $task = $this->makeTaskAndConversation();

        // A worker-level failure thrown mid-delegation -- e.g. the
        // nested AgentLoopService::run() call timing out, or throwing for
        // any other uncaught reason.
        $agentLoopService = Mockery::mock(AgentLoopService::class);
        $agentLoopService->shouldReceive('run')
            ->andThrow(new \RuntimeException('Simulated worker-level failure mid-delegation.'));
        $this->app->instance(AgentLoopService::class, $agentLoopService);

        RunManagedTaskStepJob::dispatch($task->id)->onQueue('managed-tasks');

        $worker = $this->worker();
        // A real worker process is very plausibly started with --tries
        // greater than 1 for its OTHER job types sharing the same
        // connection; this must not let Laravel redeliver THIS job type
        // past its own one and only attempt.
        $options = new WorkerOptions(maxTries: 3);

        $job = Queue::connection('database')->pop('managed-tasks');
        $this->assertNotNull($job, 'expected the dispatched job to be genuinely poppable from the database connection');

        // Worker::process() performs its release/fail bookkeeping (the
        // thing this test actually cares about) BEFORE re-throwing the
        // job's own exception (Worker::handleJobException() always
        // `throw $e;`s at the end, by design, so a caller running the
        // worker loop itself -- Worker::runJob() -- can report/log it).
        // Driving process() directly, exactly like
        // RunDelegationBatchMemberJobQueueRetryTest does, means this test
        // must swallow that expected re-throw itself.
        try {
            $worker->process('database', $job, $options);
            $this->fail('expected the simulated worker-level exception to propagate out of Worker::process(), matching Laravel\'s own documented behavior');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated worker-level failure mid-delegation.', $e->getMessage());
        }

        $secondDelivery = Queue::connection('database')->pop('managed-tasks');

        $this->assertNull(
            $secondDelivery,
            'a worker-level failure mid-delegation must fail RunManagedTaskStepJob permanently on its first attempt -- it must never be redelivered by Laravel\'s own attempts/tries bookkeeping into re-running the same step of the same managed task (FR-012)',
        );
    }
}
