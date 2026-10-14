<?php

namespace Tests\Feature\Resume;

use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob;
use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\ProviderRetryScheduler;
use ClarionApp\LlmClient\Services\RunLease;
use ClarionApp\LlmClient\Services\TaskResumeText;
use ClarionApp\LlmClient\ValueObjects\ResumeDriver;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\Support\ProviderOutage;
use Tests\TestCase;

/**
 * ProviderRetryScheduler — what a failed model call turns into
 * (provider-retry contract §2). A request the provider rejects fails as it
 * always did. A transient failure of a root run defers: the run is marked
 * waiting, its lease released, and a delayed job — the only thing that
 * carries the retry — is dispatched under a fresh dispatch id; the user is
 * told nothing on the first failure, and told the task is waiting from the
 * second. A nested run is never deferred: it retries in place. Five failed
 * attempts, or a window of ten minutes from the first, end the run with the
 * provider-unavailable report and its completed work intact.
 */
class ProviderRetrySchedulerTest extends TestCase
{
    private string $userId = '11111111-1111-1111-1111-111111111111';

    private string $runId = 'run-retry-1';

    private string $stepId = 'step-retry-3';

    private string $dispatchId = '10000000-0000-0000-0000-000000000001';

    private Conversation $conversation;

    private RecordingChannelDelivery $telegram;

    private int $actions = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', 'UTC'));
        config(['llm-client.resume.enabled' => true]);
        Queue::fake([AgentStreamStepJob::class, ResumeInterruptedRunJob::class]);

        $this->telegram = new RecordingChannelDelivery('telegram');
        app(ConversationChannelRegistry::class)->register($this->telegram);
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /**
     * A run in its third step, two steps already completed, whose model call
     * is the thing that failed — claimed by this process, as the caller of
     * onFailure() holds it.
     */
    private function makeRun(string $driver = 'root', string $channel = 'telegram'): AgentRun
    {
        $this->conversation = Conversation::create([
            'user_id' => $this->userId,
            'title' => 'retry',
            'model' => 'gpt-test',
            'channel' => $channel,
            'is_processing' => true,
        ]);

        DB::table('agent_runs')->insert([
            'id' => $this->runId,
            'kind' => 'interactive',
            'user_id' => $this->userId,
            'conversation_id' => $this->conversation->id,
            'started_at' => now()->subMinutes(2)->format('Y-m-d H:i:s.u'),
            'end_state' => 'in_progress',
            'checkpoint_version' => 1,
            'resume_mode' => 'sync',
            'resume_driver' => $driver,
            'resume_state' => 'working',
            'dispatch_id' => $this->dispatchId,
            'consecutive_interruptions' => 0,
            'provider_attempts' => 0,
        ]);
        foreach ([1, 2] as $position) {
            DB::table('agent_run_steps')->insert([
                'id' => "step-retry-{$position}",
                'run_id' => $this->runId,
                'position' => $position,
                'started_at' => now()->subMinutes(2)->format('Y-m-d H:i:s.u'),
                'ended_at' => now()->subMinute()->format('Y-m-d H:i:s.u'),
                'end_state' => 'completed',
                'iteration' => $position,
                'checkpoint_state' => 'committed',
            ]);
        }
        DB::table('agent_run_steps')->insert([
            'id' => $this->stepId,
            'run_id' => $this->runId,
            'position' => 3,
            'started_at' => now()->format('Y-m-d H:i:s.u'),
            'end_state' => 'in_progress',
            'iteration' => 3,
            'checkpoint_state' => 'open',
            'checkpoint' => json_encode(['v' => 1, 'iteration' => 3, 'model' => null, 'calls' => []]),
        ]);
        $this->assertTrue(app(RunLease::class)->claim($this->runId, $this->dispatchId)->won, 'precondition: the caller holds the run');

        return AgentRun::findOrFail($this->runId);
    }

    /** A new in-flight model-request action in the open step; returns its id. */
    private function openLlmAction(): string
    {
        $id = 'action-retry-'.(++$this->actions);
        DB::table('agent_run_actions')->insert([
            'id' => $id,
            'run_id' => $this->runId,
            'step_id' => $this->stepId,
            'action_type' => 'llm_request',
            'target' => 'gpt-test',
            'outcome' => 'in_progress',
            'started_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        return $id;
    }

    /** What an adapter throws for a provider status, with the provider's own text inside. */
    private function failure(int $status = 503, ?string $retryAfter = null): \RuntimeException
    {
        $headers = $retryAfter === null ? [] : ['Retry-After' => $retryAfter];
        $previous = new RequestException(
            ProviderOutage::PROVIDER_TEXT,
            new Request('POST', 'https://provider.example/v1/chat/completions'),
            new Response($status, $headers, json_encode(['error' => ['message' => ProviderOutage::PROVIDER_TEXT]])),
        );

        return new \RuntimeException(ProviderOutage::PROVIDER_TEXT, $status, $previous);
    }

    private function scheduler(): ProviderRetryScheduler
    {
        return app(ProviderRetryScheduler::class);
    }

    private function onFailure(\Throwable $e, ?AgentRun $run = null, ?string $actionId = null)
    {
        $run ??= AgentRun::findOrFail($this->runId);

        return $this->scheduler()->onFailure($run, $this->stepId, $actionId ?? $this->openLlmAction(), $e);
    }

    /** The delayed job's on-time claim and its next model call failing again. */
    private function failAgain(\Throwable $e)
    {
        $row = $this->runRow();
        Carbon::setTestNow(Carbon::parse((string) $row->next_attempt_at, 'UTC'));
        $claim = app(RunLease::class)->claim($this->runId, (string) $row->dispatch_id);
        $this->assertTrue($claim->won, 'precondition: the delayed job claims the run on time');

        return $this->onFailure($e);
    }

    private function runRow(): object
    {
        return DB::table('agent_runs')->where('id', $this->runId)->firstOrFail();
    }

    private function events(?string $kind = null): array
    {
        $query = DB::table('agent_run_resume_events')->where('run_id', $this->runId)->orderBy('created_at');
        if ($kind !== null) {
            $query->where('kind', $kind);
        }

        return $query->get()->map(fn ($e) => ['kind' => $e->kind, 'attempt' => $e->attempt, 'payload' => json_decode((string) $e->payload, true)])->all();
    }

    /** @return \Illuminate\Support\Collection<int, Message> */
    private function notices(string $notice)
    {
        return Message::where('conversation_id', $this->conversation->id)->get()
            ->filter(fn (Message $m) => ($m->tool_data['kind'] ?? null) === 'task_resume_notice' && ($m->tool_data['notice'] ?? null) === $notice)
            ->values();
    }

    private function pushedResumeJobs()
    {
        return Queue::pushed(ResumeInterruptedRunJob::class);
    }

    private function delayOf(object $job): Carbon
    {
        $delay = $job->delay;
        $this->assertNotNull($delay, 'the retry job is delayed');

        return $delay instanceof \DateTimeInterface ? Carbon::instance($delay) : now()->addSeconds((int) $delay);
    }

    // ---------------------------------------------------------------
    // A rejected request fails as it always did
    // ---------------------------------------------------------------

    #[Test]
    public function rejected_fails(): void
    {
        $run = $this->makeRun();
        $before = $this->runRow();

        $decision = $this->onFailure($this->failure(400), $run);

        $this->assertNull($decision->deferredUntil(), 'a rejected request is not deferred');
        $this->assertNull($decision->inlineDelaySeconds(), 'and not retried in place');
        $after = $this->runRow();
        $this->assertSame('in_progress', $after->end_state, 'the caller closes the run as it always did');
        $this->assertSame($before->resume_state, $after->resume_state);
        $this->assertSame($before->dispatch_id, $after->dispatch_id);
        $this->assertSame(0, (int) $after->provider_attempts, 'no attempt is counted');
        $this->assertNull($after->next_attempt_at);
        $this->assertCount(0, $this->events(), 'no retry event');
        $this->assertCount(0, $this->pushedResumeJobs(), 'no delayed job');
        $this->assertTrue($this->conversation->fresh()->is_processing, 'the conversation is left to the caller');
    }

    // ---------------------------------------------------------------
    // The first transient failure of a root run defers
    // ---------------------------------------------------------------

    #[Test]
    public function first_transient_root_defers(): void
    {
        $run = $this->makeRun();
        $actionId = $this->openLlmAction();

        $decision = $this->scheduler()->onFailure($run, $this->stepId, $actionId, $this->failure(503));

        $due = now()->addSeconds(10);
        $this->assertNotNull($decision->deferredUntil(), 'the root run defers');
        $this->assertNull($decision->inlineDelaySeconds());
        $this->assertSame($due->format('Y-m-d H:i:s'), $decision->deferredUntil()->format('Y-m-d H:i:s'), 'the first delay is ten seconds');

        $row = $this->runRow();
        $this->assertSame('in_progress', $row->end_state, 'the task is not over');
        $this->assertSame('waiting_provider_retry', $row->resume_state);
        $this->assertSame($due->format('Y-m-d H:i:s'), Carbon::parse((string) $row->next_attempt_at, 'UTC')->format('Y-m-d H:i:s'));
        $this->assertNotSame($this->dispatchId, $row->dispatch_id, 'a new dispatch id: only the delayed job can claim');
        $this->assertNotNull($row->dispatch_id);
        $this->assertNull($row->lease_holder, 'no worker is held for the wait');
        $this->assertSame(1, (int) $row->provider_attempts);
        $this->assertSame(now()->format('Y-m-d H:i:s'), Carbon::parse((string) $row->provider_first_failure_at, 'UTC')->format('Y-m-d H:i:s'), 'the window starts at the first failure');
        $this->assertTrue($this->conversation->fresh()->is_processing, 'the task is still running for the whole wait');

        $jobs = $this->pushedResumeJobs();
        $this->assertCount(1, $jobs, 'one delayed job carries the retry');
        $job = $jobs->first();
        $this->assertSame($this->runId, $job->runId);
        $this->assertSame($row->dispatch_id, $job->dispatchId, 'the job carries the new dispatch id');
        $this->assertSame($due->format('Y-m-d H:i:s'), $this->delayOf($job)->format('Y-m-d H:i:s'), 'delayed to the attempt time');

        $events = $this->events('provider_retry_scheduled');
        $this->assertCount(1, $events);
        $this->assertSame(1, (int) $events[0]['attempt']);
        $this->assertTrue($events[0]['payload']['deferred'], 'the event says the retry was deferred');
        $this->assertSame('unavailable', $events[0]['payload']['reason']);
        $this->assertSame(503, $events[0]['payload']['status']);

        $action = DB::table('agent_run_actions')->where('id', $actionId)->first();
        $this->assertSame('failure', $action->outcome, 'the model-request action is closed as failed');
        $this->assertSame('provider unavailable (attempt 1)', $action->failure_reason);

        $this->assertCount(0, $this->notices('provider_waiting'), 'a single blip is invisible: no notice');
        $this->assertSame([], $this->telegram->textsFor($this->conversation->id), 'nothing is delivered');

        // The provider's own words appear nowhere that outlives the call.
        $stored = json_encode([
            DB::table('agent_run_actions')->get(),
            DB::table('agent_run_resume_events')->get(),
            DB::table('agent_run_steps')->get(),
            DB::table('agent_runs')->get(),
            DB::table('messages')->get(),
        ]);
        $this->assertStringNotContainsString('LEAK-CHECK', $stored, 'provider text and keys are never stored');
    }

    // ---------------------------------------------------------------
    // From the second failure on, the user is told it is waiting
    // ---------------------------------------------------------------

    #[Test]
    public function second_failure_posts_provider_waiting_notice(): void
    {
        $this->makeRun();
        $this->onFailure($this->failure(503));
        $this->assertCount(0, $this->notices('provider_waiting'), 'precondition: the first failure posted nothing');

        $this->failAgain($this->failure(503));

        $next = now()->addSeconds(30);
        $notices = $this->notices('provider_waiting');
        $this->assertCount(1, $notices, 'the second failure posts the waiting notice');
        $this->assertSame($this->runId, $notices->first()->tool_data['run_id']);
        $this->assertSame(app(TaskResumeText::class)->providerWaitingNotice($next), $notices->first()->content, 'it names the next attempt');
        $this->assertSame([app(TaskResumeText::class)->providerWaitingNotice($next)], $this->telegram->textsFor($this->conversation->id), 'and is delivered on the conversation\'s channel');

        $this->failAgain($this->failure(503));
        $this->assertCount(2, $this->notices('provider_waiting'), 'each later failure keeps the user informed');
    }

    // ---------------------------------------------------------------
    // The delays are the configured ones
    // ---------------------------------------------------------------

    #[Test]
    public function delays_follow_config(): void
    {
        $this->makeRun();
        $this->onFailure($this->failure(503));
        $seen = [(int) now()->diffInSeconds(Carbon::parse((string) $this->runRow()->next_attempt_at, 'UTC'))];
        foreach ([2, 3, 4] as $attempt) {
            $this->failAgain($this->failure(503));
            $seen[] = (int) now()->diffInSeconds(Carbon::parse((string) $this->runRow()->next_attempt_at, 'UTC'));
        }

        $this->assertSame([10, 30, 90, 180], $seen, 'attempts one to four wait 10, 30, 90 and 180 seconds');
    }

    #[Test]
    public function delays_are_read_from_config_not_fixed(): void
    {
        config(['llm-client.resume.provider_retry.delays_seconds' => [5, 7, 11]]);
        $this->makeRun();
        $this->onFailure($this->failure(503));
        $seen = [(int) now()->diffInSeconds(Carbon::parse((string) $this->runRow()->next_attempt_at, 'UTC'))];
        foreach ([2, 3] as $attempt) {
            $this->failAgain($this->failure(503));
            $seen[] = (int) now()->diffInSeconds(Carbon::parse((string) $this->runRow()->next_attempt_at, 'UTC'));
        }
        $this->assertSame([5, 7, 11], $seen);

        $this->failAgain($this->failure(503));
        $this->assertSame('failed', $this->runRow()->end_state, 'with three delays the fourth failure is the last attempt');
    }

    // ---------------------------------------------------------------
    // Retry-After
    // ---------------------------------------------------------------

    #[Test]
    public function retry_after_honoured_within_window(): void
    {
        $this->makeRun();

        $decision = $this->onFailure($this->failure(429, '45'));

        $this->assertSame(
            now()->addSeconds(45)->format('Y-m-d H:i:s'),
            $decision->deferredUntil()->format('Y-m-d H:i:s'),
            'the provider asked for 45 seconds, which fits the window, so that is the delay',
        );
        $this->assertSame('rate_limited', $this->events('provider_retry_scheduled')[0]['payload']['reason']);
    }

    #[Test]
    public function retry_after_beyond_the_window_is_not_honoured(): void
    {
        $this->makeRun();

        $decision = $this->onFailure($this->failure(429, '3600'));

        $this->assertSame(
            now()->addSeconds(10)->format('Y-m-d H:i:s'),
            $decision->deferredUntil()->format('Y-m-d H:i:s'),
            'an hour does not fit the ten-minute window: the scheduled delay is used',
        );
    }

    // ---------------------------------------------------------------
    // Exhaustion
    // ---------------------------------------------------------------

    #[Test]
    public function exhausted_after_five_attempts(): void
    {
        $this->makeRun();
        $this->onFailure($this->failure(503));
        foreach ([2, 3, 4] as $attempt) {
            $this->failAgain($this->failure(503));
            $this->assertSame('in_progress', $this->runRow()->end_state, "attempt {$attempt} still waits");
        }
        $this->assertCount(0, $this->events('provider_retry_exhausted'), 'precondition: four failures are not exhaustion');

        $decision = $this->failAgain($this->failure(503));

        $this->assertNull($decision->deferredUntil(), 'the fifth failure is the last');
        $this->assertNull($decision->inlineDelaySeconds());
        $exhausted = $this->events('provider_retry_exhausted');
        $this->assertCount(1, $exhausted);
        $this->assertSame(5, $exhausted[0]['payload']['attempts']);
        $this->assertSame('unavailable', $exhausted[0]['payload']['reason']);
        $this->assertArrayHasKey('window_seconds', $exhausted[0]['payload']);
        $this->assertCount(4, $this->events('provider_retry_scheduled'), 'four retries were scheduled, the fifth failure schedules none');

        $this->assertExhaustedLikeThis();
    }

    #[Test]
    public function exhausted_when_window_exceeded(): void
    {
        $this->makeRun();
        $this->onFailure($this->failure(503));
        // Ten minutes from the first failure, less five seconds: the next
        // 30-second delay would end after the window closes.
        Carbon::setTestNow(now()->addSeconds(595));
        DB::table('agent_runs')->where('id', $this->runId)->update(['dispatch_id' => $this->dispatchId, 'lease_holder' => null, 'resume_state' => 'waiting_provider_retry']);
        $this->assertTrue(app(RunLease::class)->claim($this->runId, $this->dispatchId)->won);

        $decision = $this->onFailure($this->failure(503));

        $this->assertNull($decision->deferredUntil(), 'a retry that would land past the window is not scheduled');
        $this->assertCount(1, $this->events('provider_retry_exhausted'));
        $this->assertSame(2, (int) $this->events('provider_retry_exhausted')[0]['payload']['attempts'], 'two attempts, not five: the window ended it');
        $this->assertExhaustedLikeThis();
    }

    /** What exhaustion leaves behind (contract §2 step 4). */
    private function assertExhaustedLikeThis(): void
    {
        $text = app(TaskResumeText::class);
        $row = $this->runRow();
        $this->assertSame('failed', $row->end_state, 'the run failed');
        $this->assertSame($text->providerUnavailableReason(), $row->end_reason);
        $step = DB::table('agent_run_steps')->where('id', $this->stepId)->first();
        $this->assertSame('failed', $step->end_state, 'the open step is closed failed');
        $this->assertFalse((bool) $this->conversation->fresh()->is_processing, 'the conversation is released');
        $this->assertCount(0, $this->pushedResumeJobs()->filter(fn ($job) => $this->delayOf($job)->greaterThan(now())), 'no further attempt is scheduled');

        $report = $text->providerUnavailableReport(2);
        $this->assertContains($report, $this->telegram->textsFor($this->conversation->id), 'the report, with the two completed steps, is delivered on the channel');
        $posted = $this->notices('provider_unavailable');
        $this->assertCount(1, $posted, 'and kept in the conversation');
        $this->assertSame($report, $posted->first()->content);
        $this->assertSame(2, DB::table('agent_run_steps')->where('run_id', $this->runId)->where('end_state', 'completed')->count(), 'the completed work is intact');
    }

    // ---------------------------------------------------------------
    // A nested run retries in place
    // ---------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function nestedDrivers(): array
    {
        $drivers = [];
        foreach (ResumeDriver::cases() as $driver) {
            if ($driver !== ResumeDriver::Root) {
                $drivers[$driver->value] = [$driver->value];
            }
        }

        return $drivers;
    }

    #[Test]
    #[DataProvider('nestedDrivers')]
    public function nested_run_returns_inline(string $driver): void
    {
        $this->makeRun($driver);
        $actionId = $this->openLlmAction();
        $before = $this->runRow();

        $decision = $this->scheduler()->onFailure(AgentRun::findOrFail($this->runId), $this->stepId, $actionId, $this->failure(503));

        $this->assertNull($decision->deferredUntil(), "a {$driver} run is never deferred");
        $this->assertSame(10, $decision->inlineDelaySeconds(), 'it retries in place after the scheduled delay');
        $row = $this->runRow();
        $this->assertSame('working', $row->resume_state, 'the run does not wait');
        $this->assertSame($before->dispatch_id, $row->dispatch_id, 'no new dispatch id');
        $this->assertNotNull($row->lease_holder, 'the caller keeps holding the run');
        $this->assertNull($row->next_attempt_at);
        $this->assertCount(0, $this->pushedResumeJobs(), 'no delayed job');
        $events = $this->events('provider_retry_scheduled');
        $this->assertCount(1, $events);
        $this->assertFalse($events[0]['payload']['deferred'], 'the event says the retry is in place');
        $this->assertSame('failure', DB::table('agent_run_actions')->where('id', $actionId)->value('outcome'));
        $this->assertCount(0, $this->notices('provider_waiting'), 'a helper says nothing to the user');
    }

    // ---------------------------------------------------------------
    // The write is a compare-and-set on the caller's nonce
    // ---------------------------------------------------------------

    #[Test]
    public function cas_on_caller_nonce(): void
    {
        $this->makeRun();
        // Another process now holds the run (the lease lapsed and a sweep or
        // a takeover claimed it): this caller no longer owns the decision.
        DB::table('agent_runs')->where('id', $this->runId)->update([
            'lease_holder' => '99999999-9999-9999-9999-999999999999',
            'lease_expires_at' => now()->addMinutes(5)->format('Y-m-d H:i:s.u'),
        ]);
        $before = $this->runRow();

        $decision = $this->scheduler()->onFailure(AgentRun::findOrFail($this->runId), $this->stepId, $this->openLlmAction(), $this->failure(503));

        $this->assertNull($decision->deferredUntil(), 'a caller that lost the run defers nothing');
        $row = $this->runRow();
        $this->assertSame($before->resume_state, $row->resume_state);
        $this->assertSame($before->dispatch_id, $row->dispatch_id, 'the other holder\'s dispatch is untouched');
        $this->assertSame($before->lease_holder, $row->lease_holder);
        $this->assertNull($row->next_attempt_at);
        $this->assertCount(0, $this->pushedResumeJobs(), 'no delayed job is dispatched');
        $this->assertCount(0, $this->events('provider_retry_scheduled'), 'no event is recorded');
    }
}
