<?php

namespace Tests\Feature\Resume;

use ClarionApp\LlmClient\Events\RunUpdated;
use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Services\ProviderRetryScheduler;
use ClarionApp\LlmClient\Services\ResumeEventLog;
use ClarionApp\LlmClient\Services\RunCheckpoint;
use ClarionApp\LlmClient\Services\RunLease;
use ClarionApp\LlmClient\ValueObjects\ResumeDriver;
use ClarionApp\LlmClient\ValueObjects\ResumeMode;
use ClarionApp\LlmClient\ValueObjects\ToolEffect;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProviderOutage;
use Tests\Support\SeedsRunProgress;
use Tests\TestCase;

/**
 * Live updates: the run's owner is told the run
 * changed, on the one channel the run record already uses, whenever the
 * task's state changes: every resume_state change, every resume event, and
 * each of the checkpoint writes that move progress (C2 model recorded, C3
 * call started, C4 call completed, C5 step committed). No new channel and no
 * new authorisation predicate are introduced.
 */
class RunResumeBroadcastTest extends TestCase
{
    use SeedsRunProgress;

    private string $userId = '11111111-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:41.120000', 'UTC'));
        config(['llm-client.run_trace.enabled' => true, 'llm-client.resume.enabled' => true]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->clearProgressTables();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** How many RunUpdated events were broadcast for the run. */
    private function announced(string $runId): int
    {
        return Event::dispatched(RunUpdated::class, fn (RunUpdated $event) => $event->runId === $runId)->count();
    }

    /** Run $change with events faked and return how many times the run was announced. */
    private function announcements(string $runId, callable $change): int
    {
        Event::fake([RunUpdated::class]);
        $change();

        return $this->announced($runId);
    }

    /** A claimed run in its first step, ready for checkpoint writes. */
    private function checkpointedRun(): array
    {
        $runId = $this->seedRun($this->userId, ['checkpoint_version' => null, 'resume_state' => null, 'last_progress_at' => null]);
        $checkpoint = app(RunCheckpoint::class);
        $checkpoint->openRun($runId, ResumeMode::Sync, ResumeDriver::Root, []);
        $stepId = $this->seedStep($runId, 1, 'in_progress', null);
        $checkpoint->openStep($runId, $stepId, 1);

        return [$runId, $stepId, $checkpoint];
    }

    private function modelCall(): array
    {
        return ['id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'ledger.write', 'arguments' => '{}']];
    }

    // ---------------------------------------------------------------
    // The checkpoint writes that move progress
    // ---------------------------------------------------------------

    #[Test]
    public function recording_the_model_response_announces_the_run(): void
    {
        [$runId, $stepId, $checkpoint] = $this->checkpointedRun();

        $this->assertGreaterThanOrEqual(1, $this->announcements($runId, fn () => $checkpoint->recordModelResponse($stepId, 'Writing.', [$this->modelCall()])), 'C2');
    }

    #[Test]
    public function starting_a_call_announces_the_run(): void
    {
        [$runId, $stepId, $checkpoint] = $this->checkpointedRun();
        $checkpoint->recordModelResponse($stepId, 'Writing.', [$this->modelCall()]);

        $this->assertGreaterThanOrEqual(1, $this->announcements($runId, fn () => $checkpoint->markStarted($stepId, 'call_a', ToolEffect::Effectful, (string) Str::uuid(), 60)), 'C3');
    }

    #[Test]
    public function completing_a_call_announces_the_run(): void
    {
        [$runId, $stepId, $checkpoint] = $this->checkpointedRun();
        $checkpoint->recordModelResponse($stepId, 'Writing.', [$this->modelCall()]);
        $checkpoint->markStarted($stepId, 'call_a', ToolEffect::Effectful, (string) Str::uuid(), 60);

        $this->assertGreaterThanOrEqual(1, $this->announcements($runId, fn () => $checkpoint->markCompleted($stepId, 'call_a', ['tool_call_id' => 'call_a', 'content' => 'ok'])), 'C4');
    }

    #[Test]
    public function committing_a_step_announces_the_run(): void
    {
        [$runId, $stepId, $checkpoint] = $this->checkpointedRun();
        $checkpoint->recordModelResponse($stepId, 'Done.', []);

        $this->assertGreaterThanOrEqual(1, $this->announcements($runId, fn () => $checkpoint->commitStep($runId, $stepId, (string) Str::uuid())), 'C5');
    }

    // ---------------------------------------------------------------
    // Every resume_state change
    // ---------------------------------------------------------------

    #[Test]
    public function claiming_a_run_announces_it(): void
    {
        $runId = $this->seedRun($this->userId, ['resume_state' => 'interrupted', 'dispatch_id' => '10000000-0000-0000-0000-000000000001', 'lease_holder' => null, 'interrupted_at' => $this->stamp('2026-10-12 10:20:00')]);

        $won = null;
        $count = $this->announcements($runId, function () use ($runId, &$won) {
            $won = app(RunLease::class)->claim($runId, '10000000-0000-0000-0000-000000000001')->won;
        });

        $this->assertTrue($won, 'precondition: the claim won');
        $this->assertSame('resuming', DB::table('agent_runs')->where('id', $runId)->value('resume_state'), 'precondition: the state changed');
        $this->assertGreaterThanOrEqual(1, $count, 'working → resuming is announced');
    }

    #[Test]
    public function marking_an_expired_run_interrupted_announces_it(): void
    {
        $runId = $this->seedRun($this->userId, [
            'dispatch_id' => '10000000-0000-0000-0000-000000000001',
            'lease_holder' => '99999999-9999-9999-9999-999999999999',
            'lease_expires_at' => $this->stamp('2026-10-12 10:00:00'),
        ]);

        $newDispatch = null;
        $count = $this->announcements($runId, function () use ($runId, &$newDispatch) {
            $newDispatch = app(RunLease::class)->interruptExpired($runId, '10000000-0000-0000-0000-000000000001', 'working');
        });

        $this->assertNotNull($newDispatch, 'precondition: the compare-and-set won');
        $this->assertSame('interrupted', DB::table('agent_runs')->where('id', $runId)->value('resume_state'));
        $this->assertGreaterThanOrEqual(1, $count, 'working → interrupted is announced by the compare-and-set itself');
    }

    #[Test]
    public function the_detection_sweep_announces_the_runs_it_marks(): void
    {
        $runId = $this->seedRun($this->userId, [
            'dispatch_id' => '10000000-0000-0000-0000-000000000001',
            'lease_holder' => '99999999-9999-9999-9999-999999999999',
            'lease_expires_at' => $this->stamp('2026-10-12 10:00:00'),
        ]);

        $count = $this->announcements($runId, fn () => $this->assertSame(0, Artisan::call('llm-client:resume-interrupted-runs')));

        $this->assertSame('interrupted', DB::table('agent_runs')->where('id', $runId)->value('resume_state'), 'precondition: the sweep marked the run');
        $this->assertGreaterThanOrEqual(1, $count);
    }

    #[Test]
    public function a_pause_announces_the_run(): void
    {
        [$runId, $stepId, $checkpoint] = $this->checkpointedRun();
        $checkpoint->recordModelResponse($stepId, 'Asking.', [$this->modelCall()]);

        $count = $this->announcements($runId, fn () => $checkpoint->markPaused($runId, $stepId, 'call_a'));

        $this->assertSame('paused', DB::table('agent_runs')->where('id', $runId)->value('resume_state'), 'precondition');
        $this->assertGreaterThanOrEqual(1, $count, 'working → paused is announced');
    }

    #[Test]
    public function a_deferred_provider_retry_announces_the_run(): void
    {
        [$runId, $stepId] = $this->checkpointedRun();
        $failure = new \RuntimeException(ProviderOutage::PROVIDER_TEXT, 503, new \GuzzleHttp\Exception\RequestException(
            ProviderOutage::PROVIDER_TEXT,
            new \GuzzleHttp\Psr7\Request('POST', 'https://provider.example/v1/chat/completions'),
            new \GuzzleHttp\Psr7\Response(503),
        ));

        $count = $this->announcements($runId, fn () => app(ProviderRetryScheduler::class)->onFailure(AgentRun::findOrFail($runId), $stepId, null, $failure));

        $this->assertSame('waiting_provider_retry', DB::table('agent_runs')->where('id', $runId)->value('resume_state'), 'precondition: the run waits');
        $this->assertGreaterThanOrEqual(1, $count, 'working → waiting_provider_retry is announced');
    }

    // ---------------------------------------------------------------
    // Every resume event
    // ---------------------------------------------------------------

    #[Test]
    public function every_kind_of_resume_event_announces_the_run(): void
    {
        $kinds = ['interruption_detected', 'takeover', 'resume_started', 'resume_refused_cancelled', 'provider_retry_scheduled', 'provider_retry_exhausted', 'stopped_unresumable', 'legacy_adopted'];
        foreach ($kinds as $kind) {
            $runId = $this->seedRun($this->userId);

            $count = $this->announcements($runId, fn () => app(ResumeEventLog::class)->record($runId, $kind, 2, 1, []));

            $this->assertGreaterThanOrEqual(1, $count, "{$kind} is announced");
        }
    }

    // ---------------------------------------------------------------
    // Who hears it, and on what channel
    // ---------------------------------------------------------------

    #[Test]
    public function the_update_goes_to_the_owner_on_the_users_private_channel_only(): void
    {
        $runId = $this->seedRun($this->userId);

        $channels = (new RunUpdated($runId))->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame((new PrivateChannel('User.' . $this->userId))->name, $channels[0]->name);
    }

    #[Test]
    public function no_new_channel_is_registered(): void
    {
        $registered = [];
        foreach ($this->sourceFiles() as $path) {
            preg_match_all("/Broadcast::channel\(\s*'([^']+)'/", (string) file_get_contents($path), $found);
            array_push($registered, ...$found[1]);
        }
        sort($registered);

        $this->assertSame(
            ['Conversation.{id}', 'user.{userId}.episodic-memory-failed', 'user.{userId}.preference-proposal'],
            $registered,
            'the three channels the package registered before this feature, and no other',
        );
    }

    /** @return list<string> every PHP file in the package's src directory */
    private function sourceFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
