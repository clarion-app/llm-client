<?php

namespace Tests\Feature\Resume;

use ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\RunResumer;
use ClarionApp\LlmClient\Services\TaskResumeText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\InterruptedRunJourneys;
use Tests\Integration\Harness\ProviderRetryJourneys;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\Support\ProviderOutage;

/**
 * FR-032 — every user-visible notice the resume feature produces is plain
 * language: no identifier, no tool-call id, no URL, no HTTP status, no
 * text a provider returned, no percent sign — and each names "this task".
 *
 * Each test drives one family of real journeys and scans everything the
 * user could read afterwards: the notice messages, the run's end reason,
 * what the channel delivered and, for a sync request, the response body.
 */
class ResumeWordingTest extends AssembledSystemTestCase
{
    use InterruptedRunJourneys;
    use ProviderRetryJourneys;

    private RecordingChannelDelivery $tgDelivery;

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // The scan
    // ---------------------------------------------------------------

    /** The content rules of notices-and-report §3, applied to one text. */
    private function assertPlain(string $text, string $context): void
    {
        $this->assertNotSame('', trim($text), "{$context}: not empty");
        $this->assertDoesNotMatchRegularExpression(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            $text,
            "{$context}: no UUID",
        );
        $this->assertDoesNotMatchRegularExpression('/\b(call_|toolu_)/i', $text, "{$context}: no tool-call id");
        $this->assertStringNotContainsStringIgnoringCase('http', $text, "{$context}: no URL or scheme");
        $this->assertDoesNotMatchRegularExpression('/(?<!\d)[1-5]\d{2}(?!\d)/', $text, "{$context}: no HTTP status");
        $this->assertStringNotContainsString(ProviderOutage::PROVIDER_TEXT, $text, "{$context}: no provider text");
        $this->assertStringNotContainsString('capacity exhausted', $text, "{$context}: no part of the provider text");
        $this->assertStringNotContainsString('sk-test', $text, "{$context}: no credential");
        $this->assertStringNotContainsString('%', $text, "{$context}: no percent sign");
    }

    /**
     * Every notice message of the conversation, plainly worded and naming
     * "this task". Returns the notices' kinds, in order.
     *
     * @return list<string>
     */
    private function scanNotices(Conversation $conversation): array
    {
        $kinds = [];
        $notices = Message::where('conversation_id', $conversation->id)->get()
            ->filter(fn (Message $m) => ($m->tool_data['kind'] ?? null) === 'task_resume_notice');
        foreach ($notices as $notice) {
            $kind = (string) ($notice->tool_data['notice'] ?? '');
            $kinds[] = $kind;
            $this->assertPlain((string) $notice->content, "notice {$kind}");
            $this->assertStringContainsStringIgnoringCase('this task', (string) $notice->content, "notice {$kind} names this task");
        }

        return $kinds;
    }

    /** The end reasons of the conversation's runs: plainly worded, naming "this task". */
    private function scanEndReasons(Conversation $conversation): array
    {
        $reasons = [];
        foreach (DB::table('agent_runs')->where('conversation_id', $conversation->id)->whereNotNull('end_reason')->pluck('end_reason') as $reason) {
            $this->assertPlain((string) $reason, 'end_reason');
            $this->assertStringContainsStringIgnoringCase('this task', (string) $reason, 'the end reason names this task');
            $reasons[] = (string) $reason;
        }

        return $reasons;
    }

    // ---------------------------------------------------------------
    // Outcome unknown, resumed
    // ---------------------------------------------------------------

    #[Test]
    public function outcome_unknown_and_resumed_notices_are_plain(): void
    {
        $this->scenario = 'wording_outcome_unknown';
        $this->setupLedgerJourney();
        $this->tgDelivery = new RecordingChannelDelivery('telegram');
        $this->app->make(ConversationChannelRegistry::class)->register($this->tgDelivery);
        $conversation = $this->syncConversation();
        $conversation->update(['channel' => 'telegram']);
        $conversation = $conversation->fresh();
        $this->scriptLedgerSteps(2);

        $this->killAt(
            fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Write two entries.'),
            'after_tool_effect',
            1,
        );

        foreach ($this->sweep($conversation) as $job) {
            $job->handle(app(RunResumer::class));
        }

        $kinds = $this->scanNotices($conversation);
        $this->assertContains('outcome_unknown', $kinds, 'the journey produced an outcome-unknown notice');
        $this->assertContains('resumed', $kinds, 'the journey produced a resumed notice');
        foreach ($this->tgDelivery->deliveries as $delivery) {
            $this->assertPlain((string) $delivery['text'], 'a delivery');
        }
    }

    // ---------------------------------------------------------------
    // Provider waiting, provider unavailable
    // ---------------------------------------------------------------

    #[Test]
    public function provider_waiting_and_unavailable_notices_are_plain(): void
    {
        $this->scenario = 'wording_provider_unavailable';
        $this->expectInterruptedRun = true;
        $this->setUpRetryJourney();
        config(['llm-client.agent_loop.max_iterations' => 6]);
        $conversation = $this->conversationFor('sync');
        $this->scriptSteps(1, 1, self::USAGE);
        $this->script()->finalAnswer('All done.')->usage(...self::USAGE);
        $this->outage->failRequests(range(2, 30));

        $response = $this->send('sync', $conversation, 'Store a note, then tell me when done.');
        $response->assertStatus(202);
        $content = (string) $response->json('content');
        $this->assertPlain($content, 'the continuing response');
        $this->assertStringContainsStringIgnoringCase('this task', $content);

        $this->workUntilRunEnds($conversation);

        $kinds = $this->scanNotices($conversation);
        $this->assertContains('provider_waiting', $kinds);
        $this->assertContains('provider_unavailable', $kinds);
        $reasons = $this->scanEndReasons($conversation);
        $this->assertSame([app(TaskResumeText::class)->providerUnavailableReason()], $reasons);
        foreach ($this->telegram->textsFor($conversation->id) as $text) {
            $this->assertPlain($text, 'a delivery');
        }
    }

    #[Test]
    public function provider_rejection_report_is_plain(): void
    {
        $this->scenario = 'wording_provider_rejected';
        $this->expectInterruptedRun = true;
        $this->setUpRetryJourney();
        $conversation = $this->conversationFor('stream');
        $this->streamTurns([$this->turnChunks('', $this->stepCalls(1), self::USAGE)]);
        $this->outage->withStatus(400)->failRequests([2]);

        $this->send('stream', $conversation, 'Store a note, then tell me when done.')->assertStatus(201);
        $this->workUntilRunEnds($conversation);

        $this->assertSame('failed', $this->runRowFor($conversation)->end_state, 'the rejected task stopped');
        $this->assertContains('provider_rejected', $this->scanNotices($conversation), 'the journey produced a rejection report');
        $reasons = $this->scanEndReasons($conversation);
        $this->assertSame([app(TaskResumeText::class)->providerRejectedReason()], $reasons);
        foreach ($this->telegram->textsFor($conversation->id) as $text) {
            $this->assertPlain($text, 'a delivery');
        }
    }

    // ---------------------------------------------------------------
    // Could not resume, one journey per code
    // ---------------------------------------------------------------

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

        return $conversation;
    }

    /** Sweep, work the job, and scan the report, the end reason and the channel. */
    private function stopAndScan(Conversation $conversation, string $code): void
    {
        foreach ($this->sweep($conversation) as $job) {
            $job->handle(app(RunResumer::class));
        }

        $kinds = $this->scanNotices($conversation);
        $this->assertSame(1, count(array_keys($kinds, 'could_not_resume', true)), "{$code}: exactly one report");
        $this->assertSame('could_not_resume', end($kinds), "{$code}: the report comes last");
        $this->assertSame([app(TaskResumeText::class)->couldNotResumeReason($code)], $this->scanEndReasons($conversation), "{$code}: the end reason");
        foreach ($this->tgDelivery->deliveries as $delivery) {
            $this->assertPlain((string) $delivery['text'], "{$code}: a delivery");
        }
    }

    #[Test]
    public function could_not_resume_checkpoint_incoherent_is_plain(): void
    {
        $this->scenario = 'wording_incoherent';
        $conversation = $this->killedAtStep3();
        DB::table('agent_run_steps')->where('run_id', $this->latestRun($conversation)->id)->where('end_state', 'in_progress')->update(['checkpoint' => '{not json']);

        $this->stopAndScan($conversation, 'checkpoint_incoherent');
    }

    #[Test]
    public function could_not_resume_model_unavailable_is_plain(): void
    {
        $this->scenario = 'wording_model_unavailable';
        $conversation = $this->killedAtStep3();
        Server::query()->delete();

        $this->stopAndScan($conversation, 'model_unavailable');
    }

    #[Test]
    public function could_not_resume_tool_unavailable_is_plain(): void
    {
        $this->scenario = 'wording_tool_unavailable';
        $conversation = $this->killedAtStep3();
        (new \Tests\Integration\Harness\OperationCatalogue())->seed([
            'openapi' => '3.0.0',
            'info' => ['title' => 'Ledger API', 'version' => '1.0.0'],
            'paths' => [
                '/ledger/read' => ['get' => ['operationId' => 'ledger.read', 'summary' => 'Read from the ledger (effect-free)', 'parameters' => [], 'responses' => ['200' => ['description' => 'Success']]]],
            ],
        ]);

        $this->stopAndScan($conversation, 'tool_unavailable');
    }

    #[Test]
    public function could_not_resume_superseded_is_plain(): void
    {
        $this->scenario = 'wording_superseded';
        $conversation = $this->killedAtStep3();
        $older = (array) $this->latestRun($conversation);
        DB::table('agent_runs')->insert(array_merge($older, [
            'id' => (string) Str::uuid(),
            'started_at' => Carbon::parse((string) $older['started_at'], 'UTC')->addSecond()->format('Y-m-d H:i:s.u'),
            'end_state' => 'completed',
            'end_reason' => null,
            'ended_at' => Carbon::parse((string) $older['started_at'], 'UTC')->addSeconds(2)->format('Y-m-d H:i:s.u'),
            'resume_state' => null,
            'lease_holder' => null,
            'lease_expires_at' => null,
            'dispatch_id' => null,
        ]));

        $this->stopAndScan($conversation, 'superseded');
    }

    #[Test]
    public function could_not_resume_ended_without_outcome_is_plain(): void
    {
        $this->scenario = 'wording_ended';
        $conversation = $this->killedAtStep3();
        Conversation::whereKey($conversation->id)->update(['is_processing' => false]);

        $this->stopAndScan($conversation, 'ended_without_outcome');
    }

    #[Test]
    public function could_not_resume_repeated_interruption_is_plain(): void
    {
        $this->scenario = 'wording_repeated';
        $this->setupLedgerJourney();
        $this->tgDelivery = new RecordingChannelDelivery('telegram');
        $this->app->make(ConversationChannelRegistry::class)->register($this->tgDelivery);
        $conversation = $this->syncConversation();
        $conversation->update(['channel' => 'telegram']);
        $conversation = $conversation->fresh();
        $this->scriptLedgerSteps(6);
        $this->killAt(
            fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Write six entries.'),
            'before_tool_started',
            9,
        );
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $jobs = $this->sweep($conversation);
            $this->killAt(
                fn () => app(RunResumer::class)->resume($jobs[0]->runId, $jobs[0]->dispatchId, \ClarionApp\LlmClient\ValueObjects\ResumeCaller::Background),
                'before_tool_started',
                1,
            );
        }

        $this->stopAndScan($conversation, 'repeated_interruption');
    }

    #[Test]
    public function could_not_resume_legacy_insufficient_is_plain(): void
    {
        $this->scenario = 'wording_legacy';
        config(['llm-client.resume.enabled' => false]);
        $this->setupLedgerJourney();
        $this->tgDelivery = new RecordingChannelDelivery('telegram');
        $this->app->make(ConversationChannelRegistry::class)->register($this->tgDelivery);
        $conversation = $this->syncConversation();
        $conversation->update(['channel' => 'telegram']);
        $conversation = $conversation->fresh();
        $this->scriptLedgerSteps(2);
        $this->killAt(
            fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Write twice.'),
            'after_tool_effect',
            1,
        );
        config(['llm-client.resume.enabled' => true]);

        $this->stopAndScan($conversation, 'legacy_insufficient');
    }

    // ---------------------------------------------------------------
    // Cancelled
    // ---------------------------------------------------------------

    #[Test]
    public function cancelled_end_reason_is_plain(): void
    {
        $this->scenario = 'wording_cancelled';
        $this->setupLedgerJourney();
        $conversation = $this->syncConversation();
        $this->scriptLedgerSteps(2);
        $this->killAt(
            fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Write twice.'),
            'after_tool_recorded',
            1,
        );
        $jobs = $this->sweep($conversation);
        $this->assertCount(1, $jobs);
        $this->actingAs($this->fixture()->user, 'api')->postJson('/api/clarion-app/llm-client/conversation/'.$conversation->id.'/end')->assertStatus(200);
        $jobs[0]->handle(app(RunResumer::class));

        $this->assertSame([app(TaskResumeText::class)->cancelledReason()], $this->scanEndReasons($conversation));
        $this->scanNotices($conversation);
    }
}
