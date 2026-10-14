<?php

namespace Tests\Integration\Resume;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\RunResumer;
use ClarionApp\LlmClient\Services\TaskResumeText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\InterruptedRunJourneys;
use Tests\Integration\Harness\OperationCatalogue;
use Tests\Integration\Harness\RecordingChannelDelivery;

/**
 * FR-020 — every cause that makes a task unresumable ends it with a report:
 * what was completed, where it stopped, why, that nothing more runs
 * automatically and that a new request is welcome. The conversation is
 * released, the open step is closed failed, the task budget ends, and a
 * message the user queued meanwhile is picked up.
 */
class ResumeUnresumableJourneyTest extends AssembledSystemTestCase
{
    use InterruptedRunJourneys;

    private RecordingChannelDelivery $tgDelivery;

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    /**
     * A real run killed at step 3's first write (steps 1 and 2 completed),
     * on the Telegram channel.
     */
    private function killedAtStep3(): Conversation
    {
        $this->setupLedgerJourney();
        $this->tgDelivery = new RecordingChannelDelivery('telegram');
        $this->app->make(ConversationChannelRegistry::class)->register($this->tgDelivery);
        $conversation = $this->syncConversation();
        $conversation->update(['channel' => 'telegram']);
        $conversation = $conversation->fresh();

        $this->scriptLedgerSteps(4);
        $this->killAt(
            fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Write four entries.'),
            'before_tool_started',
            5,
        );
        $this->assertSame(2, $this->effectLedger->count('ledger.write'), 'precondition: two completed steps');

        return $conversation;
    }

    /** Sweep and work the job, then assert everything every stop has in common. */
    private function sweepAndAssertStopped(Conversation $conversation, string $codePrefix, array $reportMustContain = []): object
    {
        $runId = $this->latestRun($conversation)->id;
        $jobs = $this->sweep($conversation);
        $this->assertCount(1, $jobs, 'the sweep hands the run to a job');
        $jobs[0]->handle(app(RunResumer::class));

        $run = $this->latestRun($conversation);
        $this->assertSame('failed', $run->end_state, 'the task stops as failed');
        $this->assertNotNull($run->end_reason);
        $this->assertFalse((bool) Conversation::find($conversation->id)->is_processing, 'the conversation is released');
        $this->assertSame(0, DB::table('agent_run_steps')->where('run_id', $runId)->where('end_state', 'in_progress')->count(), 'no step is left open');
        $this->assertSame('failed', DB::table('agent_run_steps')->where('run_id', $runId)->orderByDesc('position')->value('end_state'), 'the open step is closed failed');
        $this->assertSame(2, $this->effectLedger->count('ledger.write'), 'nothing is executed after the stop');

        $budget = $this->taskRowOf($conversation);
        if ($budget !== null) {
            $this->assertNotSame('running', $budget->state, 'the task budget ended with the run');
        }

        $stopped = $this->eventsOf($runId, 'stopped_unresumable');
        $this->assertCount(1, $stopped, 'one stopped_unresumable event');
        $this->assertStringStartsWith($codePrefix, (string) ($stopped[0]['payload']['reason_code'] ?? ''));
        $this->assertSame(2, $stopped[0]['payload']['completed_steps'] ?? null);

        $reports = $this->reportsOf($conversation);
        $this->assertCount(1, $reports, 'one report');
        $report = (string) $reports[0]->content;
        $this->assertReportShape($report, 2, $codePrefix);
        $this->assertStringContainsStringIgnoringCase('step 3', $report, 'where it stopped');
        $this->assertStringContainsString(
            app(TaskResumeText::class)->couldNotResumeReason((string) $stopped[0]['payload']['reason_code']),
            $report,
            'why it stopped',
        );
        foreach ($reportMustContain as $needle) {
            $this->assertStringContainsString($needle, $report);
        }
        $this->assertContains($report, array_map(fn (array $d) => $d['text'], $this->tgDelivery->deliveries), 'the report reaches the channel');

        return $run;
    }

    #[Test]
    public function checkpoint_incoherent_stops_with_a_report(): void
    {
        $this->scenario = 'us6_unresumable_incoherent';
        $conversation = $this->killedAtStep3();

        DB::table('agent_run_steps')->where('run_id', $this->latestRun($conversation)->id)->where('end_state', 'in_progress')->update(['checkpoint' => '{not json']);

        $this->sweepAndAssertStopped($conversation, 'checkpoint_incoherent');
    }

    #[Test]
    public function model_unavailable_stops_with_a_report(): void
    {
        $this->scenario = 'us6_unresumable_model';
        $conversation = $this->killedAtStep3();

        Server::query()->delete();

        $this->sweepAndAssertStopped($conversation, 'model_unavailable');
    }

    #[Test]
    public function tool_unavailable_stops_with_a_report_naming_the_tool(): void
    {
        $this->scenario = 'us6_unresumable_tool';
        $conversation = $this->killedAtStep3();

        // The operation the resume must execute is gone from the catalogue.
        (new OperationCatalogue())->seed([
            'openapi' => '3.0.0',
            'info' => ['title' => 'Ledger API', 'version' => '1.0.0'],
            'paths' => [
                '/ledger/read' => ['get' => ['operationId' => 'ledger.read', 'summary' => 'Read from the ledger (effect-free)', 'parameters' => [], 'responses' => ['200' => ['description' => 'Success']]]],
            ],
        ]);

        $this->sweepAndAssertStopped($conversation, 'tool_unavailable', ['Write to the ledger (effectful)']);
    }

    #[Test]
    public function superseded_stops_with_a_report(): void
    {
        $this->scenario = 'us6_unresumable_superseded';
        $conversation = $this->killedAtStep3();

        // A newer task of the same conversation exists.
        $older = (array) $this->latestRun($conversation);
        $newer = array_merge($older, [
            'id' => (string) Str::uuid(),
            'started_at' => Carbon::parse((string) $older['started_at'], 'UTC')->addSecond()->format('Y-m-d H:i:s.u'),
            'end_state' => 'completed',
            'ended_at' => Carbon::parse((string) $older['started_at'], 'UTC')->addSeconds(2)->format('Y-m-d H:i:s.u'),
            'resume_state' => null,
            'lease_holder' => null,
            'lease_expires_at' => null,
            'dispatch_id' => null,
        ]);
        DB::table('agent_runs')->insert($newer);

        $runId = $older['id'];
        $jobs = $this->sweep($conversation);
        $this->assertCount(1, $jobs);
        $jobs[0]->handle(app(RunResumer::class));

        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertSame('failed', $run->end_state);
        $stopped = $this->eventsOf($runId, 'stopped_unresumable');
        $this->assertCount(1, $stopped);
        $this->assertSame('superseded', $stopped[0]['payload']['reason_code'] ?? null);
        $this->assertSame('failed', DB::table('agent_run_steps')->where('run_id', $runId)->orderByDesc('position')->value('end_state'));
        $this->assertFalse((bool) Conversation::find($conversation->id)->is_processing);
        $this->assertSame(2, $this->effectLedger->count('ledger.write'));
        $reports = $this->reportsOf($conversation);
        $this->assertCount(1, $reports);
        $this->assertReportShape((string) $reports[0]->content, 2, 'superseded');
        $this->assertStringContainsString(app(TaskResumeText::class)->couldNotResumeReason('superseded'), (string) $reports[0]->content);
    }

    #[Test]
    public function ended_without_outcome_stops_with_a_report(): void
    {
        $this->scenario = 'us6_unresumable_ended';
        $conversation = $this->killedAtStep3();

        // Whatever gave up on the task already released the conversation.
        Conversation::whereKey($conversation->id)->update(['is_processing' => false]);

        $this->sweepAndAssertStopped($conversation, 'ended_without_outcome');
    }

    // ---------------------------------------------------------------
    // A message queued while the task was interrupted is picked up
    // ---------------------------------------------------------------

    public static function pickupCauses(): array
    {
        return ['checkpoint_incoherent' => ['checkpoint'], 'tool_unavailable' => ['tool']];
    }

    #[Test]
    #[DataProvider('pickupCauses')]
    public function a_queued_message_is_picked_up_after_the_stop(string $cause): void
    {
        $this->scenario = 'us6_unresumable_pickup_'.$cause;
        $this->setUpTaskBudgetJourney();
        $this->bindInjector();
        $conversation = $this->conversationFor('stream');

        $this->send('stream', $conversation, 'Write to the ledger.')->assertStatus(201);
        $this->killAt(
            fn () => $this->playTurn(0, '', $this->stepCalls(1), self::USAGE),
            'before_tool_started',
            1,
        );
        $runId = $this->latestRun($conversation)->id;

        Carbon::setTestNow(now()->addMinute());
        $this->send('stream', $conversation, 'Also read the notes.')->assertStatus(201);
        $this->assertSame(1, DB::table('agent_runs')->where('conversation_id', $conversation->id)->count(), 'no second run while processing');

        if ($cause === 'checkpoint') {
            DB::table('agent_run_steps')->where('run_id', $runId)->where('end_state', 'in_progress')->update(['checkpoint' => '{not json']);
        } else {
            (new OperationCatalogue())->seed(['openapi' => '3.0.0', 'info' => ['title' => 'Empty', 'version' => '1.0.0'], 'paths' => []]);
        }

        $jobs = $this->sweep($conversation);
        $this->assertCount(1, $jobs);
        $jobs[0]->handle(app(RunResumer::class));

        $this->assertSame('failed', DB::table('agent_runs')->where('id', $runId)->value('end_state'), 'the interrupted task is stopped');
        $this->assertCount(1, $this->reportsOf($conversation), 'with its report');
        $this->assertSame(2, DB::table('agent_runs')->where('conversation_id', $conversation->id)->count(), 'the queued message starts the next run');
        $this->stream()->extractDispatchedJobs();
        $this->assertSame(1, $this->stream()->turnCount(), 'its first model call is dispatched');
        $this->assertSame(0, count($this->noteWrites), 'the interrupted step\'s note was never written');
    }
}
