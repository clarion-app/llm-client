<?php

namespace ClarionApp\LlmClient\Tests\Unit\Jobs;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Jobs\RunManagedTaskStepJob;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentLoopService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 103-manager-agent, Phase 3 (US1), tasks.md T017.
 *
 * Unit tests for the not-yet-built `RunManagedTaskStepJob` (data-model.md
 * §9, research.md D6, contracts/manager-agent-meta-tools.md §5). Mirrors
 * RunDelegationBatchMemberJobTest.php's own convention of calling
 * `handle()` directly with a Mockery double injected exactly as Laravel's
 * queue worker would method-inject it -- this file cares only about
 * handle()'s own re-dispatch-vs-stop decision, never AgentLoopService::
 * run()'s own internals (covered by that class's dedicated tests
 * elsewhere).
 *
 * Written before RunManagedTaskStepJob exists -- every test below is
 * expected to FAIL red (class not found) until T024 creates it.
 */
class RunManagedTaskStepJobTest extends TestCase
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

    private function makeTaskAndConversation(string $status = 'in_progress'): array
    {
        $this->user = $this->user ?? User::factory()->create();

        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'channel' => 'managed-task',
        ]);

        $task = ManagedTask::create([
            'conversation_id' => $conversation->id,
            'owner_user_id' => $this->user->id,
            'manager_agent_id' => null,
            'original_request' => 'Do the thing.',
            'status' => $status,
            'round_ceiling' => 30,
            'rounds_used' => 0,
            'max_seconds' => 1800,
            'last_progress_at' => now()->subMinutes(5),
            'started_at' => now()->subMinutes(5),
        ]);

        return [$task, $conversation];
    }

    #[Test]
    public function handle_calls_agent_loop_run_with_the_configured_step_max_iterations(): void
    {
        [$task, $conversation] = $this->makeTaskAndConversation();

        config(['llm-client.manager.step_max_iterations' => 4]);

        $agentLoopService = Mockery::mock(AgentLoopService::class);
        $agentLoopService->shouldReceive('run')
            ->once()
            ->withArgs(function ($conv, $message, $options) use ($conversation) {
                return $conv->id === $conversation->id
                    && ($options['max_iterations'] ?? null) === 4;
            })
            ->andReturn(['status' => 'completed', 'content' => 'ok']);

        Queue::fake();

        (new RunManagedTaskStepJob($task->id))->handle($agentLoopService);

        Queue::assertPushed(RunManagedTaskStepJob::class);
    }

    #[Test]
    public function handle_updates_last_progress_at(): void
    {
        [$task] = $this->makeTaskAndConversation();
        $originalProgress = $task->last_progress_at;

        $agentLoopService = Mockery::mock(AgentLoopService::class);
        $agentLoopService->shouldReceive('run')->once()->andReturn(['status' => 'completed', 'content' => 'ok']);

        Queue::fake();

        (new RunManagedTaskStepJob($task->id))->handle($agentLoopService);

        $task->refresh();
        $this->assertTrue($task->last_progress_at->greaterThan($originalProgress), 'last_progress_at must advance after a step runs');
    }

    #[Test]
    public function re_dispatches_a_fresh_job_for_the_same_task_while_still_in_progress(): void
    {
        [$task] = $this->makeTaskAndConversation('in_progress');

        $agentLoopService = Mockery::mock(AgentLoopService::class);
        $agentLoopService->shouldReceive('run')->once()->andReturn(['status' => 'completed', 'content' => 'ok']);

        Queue::fake();

        (new RunManagedTaskStepJob($task->id))->handle($agentLoopService);

        Queue::assertPushed(RunManagedTaskStepJob::class, function (RunManagedTaskStepJob $job) use ($task) {
            return $job->managedTaskId === $task->id;
        });
    }

    #[Test]
    public function does_not_re_dispatch_once_the_task_has_reached_a_terminal_status(): void
    {
        [$task, $conversation] = $this->makeTaskAndConversation('in_progress');

        // The model called finalize_task DURING the turn -- simulate its
        // effect (a real finalize_task handler is a later phase, T042) by
        // having the mocked run() call itself flip the task terminal,
        // exactly as the real tool handler would from inside run()'s own
        // tool-call loop.
        $agentLoopService = Mockery::mock(AgentLoopService::class);
        $agentLoopService->shouldReceive('run')
            ->once()
            ->andReturnUsing(function () use ($task) {
                $task->status = 'completed';
                $task->final_response = 'All done.';
                $task->completed_at = now();
                $task->save();

                return ['status' => 'completed', 'content' => 'All done.'];
            });

        Queue::fake();

        (new RunManagedTaskStepJob($task->id))->handle($agentLoopService);

        Queue::assertNotPushed(RunManagedTaskStepJob::class);
    }

    #[Test]
    public function is_a_clean_no_op_for_a_task_already_terminal_before_handle_runs(): void
    {
        [$task] = $this->makeTaskAndConversation('completed');

        $agentLoopService = Mockery::mock(AgentLoopService::class);
        $agentLoopService->shouldReceive('run')->never();

        Queue::fake();

        (new RunManagedTaskStepJob($task->id))->handle($agentLoopService);

        Queue::assertNotPushed(RunManagedTaskStepJob::class);
    }

    #[Test]
    public function seeds_the_first_turn_with_the_original_request_and_later_turns_with_a_continuation_message(): void
    {
        [$task, $conversation] = $this->makeTaskAndConversation();

        $seenMessages = [];
        $agentLoopService = Mockery::mock(AgentLoopService::class);
        $agentLoopService->shouldReceive('run')
            ->twice()
            ->andReturnUsing(function ($conv, $message) use (&$seenMessages) {
                $seenMessages[] = $message;
                // Simulate the message-creation side effect run() itself
                // performs, so the SECOND call sees a non-empty history.
                Message::create([
                    'conversation_id' => $conv->id,
                    'content' => $message,
                    'role' => 'user',
                    'user' => 'User',
                    'responseTime' => 0,
                ]);

                return ['status' => 'completed', 'content' => 'ok'];
            });

        Queue::fake();

        (new RunManagedTaskStepJob($task->id))->handle($agentLoopService);
        (new RunManagedTaskStepJob($task->id))->handle($agentLoopService);

        $this->assertSame($task->original_request, $seenMessages[0]);
        $this->assertNotSame($task->original_request, $seenMessages[1], 'a later turn must not repeat the original request verbatim as a fresh user message');
    }
}
