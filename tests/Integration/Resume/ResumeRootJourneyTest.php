<?php

namespace Tests\Integration\Resume;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\RunResumer;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\GeneratorConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use Illuminate\Support\Facades\Event;
use Tests\Integration\Harness\RecordingChannelDelivery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\TaskBudgetHelperJourneys;
use Tests\Support\EffectLedger;
use Tests\Support\InjectedFault;
use Tests\Support\ScriptedProvider;
use Tests\Support\SnapshotFaultInjector;
use Tests\Support\TravelsPastLease;

/**
 * US1 — the root journey (AC1–AC5, SC-004). A long task that is interrupted
 * mid-flight is resumed by the sweep, in its own run, without re-running
 * completed steps: AC1 a kill between steps after seven completed steps
 * resumes at step 8 (the seven ledger entries once each, the final answer);
 * AC2 three users' runs interrupted together dispatch three jobs, each in its
 * own run; AC3 a kill mid model call repeats the call with one assistant
 * reply; AC4 a Telegram (POST agent) run whose process ended is resumed in
 * the background, delivering the final reply on the channel; AC5 the run
 * record and the resume events show the interruption and the resume.
 */
class ResumeRootJourneyTest extends AssembledSystemTestCase
{
    use TaskBudgetHelperJourneys;
    use TravelsPastLease;

    private const USAGE = [10, 10];

    private EffectLedger $effectLedger;

    private ScriptedProvider $provider;

    private SnapshotFaultInjector $injector;

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    /** Bind the executor with a fixed token, seed the ledger operations, bind the provider's handler stack, and bind the snapshot injector. */
    private function setupJourney(): void
    {
        $this->effectLedger = new EffectLedger();
        $this->app->singleton(
            McpToolExecutor::class,
            fn () => new McpToolExecutor($this->app->make(McpToolRegistry::class), null, fn ($user) => 'test-token-'.$user->id),
        );
        $this->app->instance(Generator::class, new class extends Generator
        {
            public function __construct()
            {
            }

            public function __invoke(?GeneratorConfig $config = null): array
            {
                return ['paths' => []];
            }
        });
        $this->provider = new ScriptedProvider($this->transport, $this->stream);
        $this->provider->seedLedgerOperations($this->effectLedger);
        $this->app->bind('llm-client.http_handler', fn () => $this->provider->handlerStack());

        $this->injector = new SnapshotFaultInjector();
        $this->app->instance(\ClarionApp\LlmClient\Contracts\FaultInjector::class, $this->injector);
    }

    private function syncConversation(): Conversation
    {
        return $this->fixture()->build()->conversation;
    }

    /** Create a user and a conversation for them (for the multi-user journey). */
    private function makeUserAndConversation(int $n): array
    {
        $user = User::create([
            'id' => (string) Str::uuid(),
            'name' => "User {$n}",
            'email' => "user{$n}@example.com",
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);
        $server = Server::create([
            'id' => (string) Str::uuid(),
            'name' => "Server {$n}",
            'provider_type' => 'openai',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test-token',
        ]);
        $conversation = Conversation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'server_id' => $server->id,
            'model' => 'gpt-4',
            'title' => "Conversation {$n}",
        ]);

        // Point embedding generation at the seeded server (the fixture's
        // pattern), so the AutoMemoryRetriever completes on its primary
        // route and the degradation ledger stays fully armed.
        config(['llm-client.memory.embedding.server_id' => $server->id]);

        return [$user, $conversation];
    }

    /** @return object the run row for the conversation's single run */
    private function runRowOf(Conversation $conversation): object
    {
        $row = DB::table('agent_runs')->where('conversation_id', $conversation->id)->orderByDesc('started_at')->first();
        $this->assertNotNull($row, 'the run exists');

        return $row;
    }

    /**
     * Reset the shared response script (its cursor advances per served step
     * and is not reset between runs, so a killed run's leftover steps would
     * otherwise be served to the next run's first model call).
     */
    private function resetScript(): void
    {
        $this->script->steps = [];
        $this->script->cursor = 0;
    }

    private function scriptWriteThenReply(): void
    {
        $this->script()
            ->toolRequests([['id' => 'call_write', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.write']]])
            ->usage(...self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);
    }

    /**
     * Start a run and interrupt it (kill at a wired point): the run is left
     * in progress, so the conversation is processing.
     */
    private function startAndInterrupt(Conversation $conversation, string $point = 'before_tool_started'): void
    {
        $this->resetScript();
        $this->scriptWriteThenReply();
        $this->expectInterruptedRun = true;
        $this->injector->arm($point, 1, ['agent_runs', 'agent_run_steps']);
        $thrown = null;
        try {
            $this->app->make(AgentLoopService::class)->run($conversation, 'Write to the ledger.');
        } catch (InjectedFault $e) {
            $thrown = $e;
            $this->injector->restore();
        }
        $this->assertNotNull($thrown, "the fault at {$point} was reached (the run is interrupted)");
    }

    /**
     * The resume half: expire the run's lease (travel window + 1 s), run the
     * interruption sweep, and run the job the sweep pushed.
     */
    private function resumeRun(Conversation $conversation): void
    {
        $this->travelPastLease($conversation);

        Queue::fake([AgentStreamStepJob::class, ResumeInterruptedRunJob::class]);
        $this->assertSame(0, Artisan::call('llm-client:resume-interrupted-runs'), 'the sweep ran');

        $captured = null;
        Queue::assertPushed(ResumeInterruptedRunJob::class, function ($job) use (&$captured) {
            $captured = $job;
            return true;
        });
        $this->assertNotNull($captured, 'the sweep pushed a resume job');

        $captured->handle(app(RunResumer::class));
    }

    // ---------------------------------------------------------------
    // AC1 — a kill between steps after seven completed steps resumes at
    // step 8 (the seven ledger entries once each, the final answer)
    // ---------------------------------------------------------------

    #[Test]
    public function ac1_kill_between_steps_resumes_at_the_next_step(): void
    {
        $this->scenario = 'us1_ac1_kill_between_steps';
        $this->setupJourney();
        $conversation = $this->syncConversation();

        // A twelve-step task: each step writes one ledger entry (and lists
        // the applications, so a step is never the last word), then a reply.
        for ($n = 1; $n <= 12; $n++) {
            $this->script()->toolRequests([
                ['id' => "call_write_{$n}", 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.write']],
                ['id' => "call_list_{$n}", 'name' => 'list_applications', 'arguments' => []],
            ])->usage(...self::USAGE);
        }
        $this->script()->finalAnswer('All twelve written.')->usage(...self::USAGE);

        // Killed between steps 7 and 8: step 7's message is written, the
        // step not yet closed.
        $this->expectInterruptedRun = true;
        $this->injector->arm('after_step_message', 7, ['agent_runs', 'agent_run_steps']);
        $thrown = null;
        try {
            $this->app->make(AgentLoopService::class)->run($conversation, 'Write twelve entries to the ledger.');
        } catch (InjectedFault $e) {
            $thrown = $e;
            $this->injector->restore();
        }
        $this->assertNotNull($thrown, 'the fault after step 7 was reached');
        $this->assertSame(7, $this->effectLedger->count('ledger.write'), 'precondition: seven completed steps');

        $runIdBefore = $this->runRowOf($conversation)->id;
        $this->resumeRun($conversation);
        $run = $this->runRowOf($conversation);

        $this->assertSame($runIdBefore, $run->id, 'the resume continues in the same run');
        $this->assertSame(1, DB::table('agent_runs')->where('conversation_id', $conversation->id)->count(), 'no second run is opened');
        $this->assertSame('completed', $run->end_state, 'the task finishes');
        $this->assertSame(12, $this->effectLedger->count('ledger.write'), 'steps 1–7 are not re-run: twelve entries, each step once');

        $started = DB::table('agent_run_resume_events')->where('run_id', $run->id)->where('kind', 'resume_started')->first();
        $this->assertSame(8, json_decode((string) $started->payload, true)['from_step'] ?? null, 'the resume continues at step 8');
        $this->assertSame(
            12,
            Message::where('conversation_id', $conversation->id)->where('role', 'assistant')->get()
                ->filter(fn (Message $m) => !empty($m->tool_data['tool_calls']))->count(),
            'each step\'s message is written once',
        );

        $final = Message::where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->get()
            ->filter(fn (Message $m) => empty($m->tool_data['tool_calls'])
                && ($m->tool_data['kind'] ?? null) !== 'task_resume_notice');
        $this->assertCount(1, $final, 'one assistant reply for the run');
        $this->assertStringContainsString('All twelve written.', (string) $final->first()->content);
    }

    // ---------------------------------------------------------------
    // AC2 — three users' runs interrupted together dispatch three jobs,
    // each in its own run
    // ---------------------------------------------------------------

    #[Test]
    public function ac2_three_users_runs_interrupted_together_dispatch_three_jobs(): void
    {
        $this->scenario = 'us1_ac2_three_users';
        $this->setupJourney();

        // Three users, three conversations, three runs — all interrupted.
        $conversations = [];
        for ($n = 1; $n <= 3; $n++) {
            [, $conversation] = $this->makeUserAndConversation($n);
            $this->startAndInterrupt($conversation);
            $conversations[] = $conversation;
        }

        // The sweep dispatches one job per interrupted run.
        $this->travelPastAllLeases();

        Queue::fake([AgentStreamStepJob::class, ResumeInterruptedRunJob::class]);
        $this->assertSame(0, Artisan::call('llm-client:resume-interrupted-runs'), 'the sweep ran');

        $pushed = 0;
        Queue::assertPushed(ResumeInterruptedRunJob::class, function ($job) use (&$pushed) {
            $pushed++;
            return true;
        });
        $this->assertSame(3, $pushed, 'the sweep dispatches one job per interrupted run (three)');

        // All three finish, each in its own run.
        foreach (Queue::pushed(ResumeInterruptedRunJob::class) as $job) {
            $job->handle(app(RunResumer::class));
        }
        $runIds = [];
        foreach ($conversations as $conversation) {
            $run = $this->runRowOf($conversation);
            $this->assertSame('completed', $run->end_state, 'each interrupted run finishes');
            $this->assertSame(1, DB::table('agent_runs')->where('conversation_id', $conversation->id)->count(), 'in its own run');
            $runIds[] = $run->id;
        }
        $this->assertCount(3, array_unique($runIds));
        $this->assertSame(3, $this->effectLedger->count('ledger.write'), 'each run\'s write ran once');
    }

    // ---------------------------------------------------------------
    // AC3 — a kill mid model call (sync) repeats the call with one
    // assistant reply
    // ---------------------------------------------------------------

    #[Test]
    public function ac3_kill_mid_model_call_repeats_the_call_with_one_reply(): void
    {
        $this->scenario = 'us1_ac3_kill_mid_model_call';
        $this->setupJourney();
        $conversation = $this->syncConversation();

        // The process dies while the step's model call is in flight: the
        // provider never answers it.
        $injector = $this->injector;
        $this->app->bind('llm-client.http_handler', function () use ($injector) {
            $stack = $this->provider->handlerStack();
            $stack->push(fn (callable $next) => function ($request, $options) use ($next, $injector) {
                if (str_contains((string) $request->getUri(), 'chat/completions')) {
                    $injector->reach('during_model_call');
                }

                return $next($request, $options);
            });

            return $stack;
        });
        $this->script()->toolRequests([
            ['id' => 'call_write', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.write']],
            ['id' => 'call_list', 'name' => 'list_applications', 'arguments' => []],
        ])->usage(...self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);

        $this->expectInterruptedRun = true;
        $this->injector->arm('during_model_call', 1, ['agent_runs', 'agent_run_steps', 'agent_run_actions']);
        $thrown = null;
        try {
            $this->app->make(AgentLoopService::class)->run($conversation, 'Write to the ledger.');
        } catch (InjectedFault $e) {
            $thrown = $e;
            $this->injector->restore();
        }
        $this->assertNotNull($thrown, 'the process died during the model call');
        $step = DB::table('agent_run_steps')->where('run_id', $this->runRowOf($conversation)->id)->first();
        $this->assertSame('open', $step->checkpoint_state, 'precondition: the step is open, its model call lost');

        $this->resumeRun($conversation);
        $run = $this->runRowOf($conversation);

        $this->assertSame('completed', $run->end_state);
        $this->assertSame(1, $this->effectLedger->count('ledger.write'), 'the repeated call\'s tool ran once');
        $started = json_decode((string) DB::table('agent_run_resume_events')->where('run_id', $run->id)->where('kind', 'resume_started')->value('payload'), true);
        $this->assertTrue($started['model_call_repeated'] ?? false, 'the model call is repeated');
        $this->assertSame(1, $started['from_step'] ?? null, 'in the same step');
        $this->assertSame(2, DB::table('agent_run_steps')->where('run_id', $run->id)->count(), 'the repeated call opened no extra step');
        $this->assertSame(
            1,
            DB::table('agent_run_actions')->where('step_id', $step->id)->where('action_type', 'llm_request')->where('outcome', 'unfinished')->count(),
            'the lost call\'s model-request action is closed unfinished',
        );

        $assistant = Message::where('conversation_id', $conversation->id)->where('role', 'assistant')->get()
            ->filter(fn (Message $m) => ($m->tool_data['kind'] ?? null) !== 'task_resume_notice');
        $this->assertCount(1, $assistant->filter(fn (Message $m) => !empty($m->tool_data['tool_calls'])), 'one step message for the repeated call');
        $this->assertCount(1, $assistant->filter(fn (Message $m) => empty($m->tool_data['tool_calls'])), 'one assistant reply for the run');
    }

    // ---------------------------------------------------------------
    // AC4 — a Telegram (POST agent) run whose process ended is resumed in
    // the background, delivering the final reply on the channel
    // ---------------------------------------------------------------

    /**
     * A sync run started through `POST agent` (the Telegram path) whose
     * process ended after its first tool result was recorded.
     */
    private function interruptPostAgentRun(Conversation $conversation, string $channel): void
    {
        $conversation->update(['channel' => $channel]);
        $this->resetScript();
        $this->script()->toolRequests([
            ['id' => 'call_write', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.write']],
            ['id' => 'call_list', 'name' => 'list_applications', 'arguments' => []],
        ])->usage(...self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);

        $this->expectInterruptedRun = true;
        $this->ledger->expect('AgentController:*');
        $this->injector->arm('after_tool_recorded', 1, ['agent_runs', 'agent_run_steps']);
        // The controller turns the fault into its own error response; the
        // snapshot then puts the run back exactly as the kill left it.
        $response = $this->actingAs($this->fixture()->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'Write to the ledger.',
            'conversation_id' => $conversation->id,
            'channel' => $channel,
        ]);
        $this->assertStringContainsString('Injected fault at after_tool_recorded', (string) $response->getContent(), 'the request\'s process ended after the first tool result');
        $this->injector->restore();
        $this->assertSame('sync', $this->runRowOf($conversation)->resume_mode, 'precondition: a sync run');
    }

    /** @return \Illuminate\Support\Collection<int, Message> the run's replies (not notices, not step messages) */
    private function repliesOf(Conversation $conversation)
    {
        return Message::where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->get()
            ->filter(fn (Message $m) => empty($m->tool_data['tool_calls'])
                && ($m->tool_data['kind'] ?? null) !== 'task_resume_notice')
            ->values();
    }

    #[Test]
    public function ac4_telegram_run_resumed_in_background_delivers_the_final_reply(): void
    {
        $this->scenario = 'us1_ac4_telegram_background_resume';
        $this->setupJourney();
        $telegram = new RecordingChannelDelivery('telegram');
        $this->app->make(ConversationChannelRegistry::class)->register($telegram);
        $conversation = $this->syncConversation();

        $this->interruptPostAgentRun($conversation, 'telegram');
        $this->resumeRun($conversation);

        $replies = $this->repliesOf($conversation);
        $this->assertCount(1, $replies, 'one assistant reply for the run');
        $this->assertStringContainsString('Done.', (string) $replies->first()->content);
        $this->assertSame(1, $this->effectLedger->count('ledger.write'), 'the recorded write is reused, not repeated');
        $this->assertContains(
            'Done.',
            array_map(fn (array $d) => $d['text'], $telegram->deliveries),
            'the background resume delivers the final reply on the Telegram channel',
        );
    }

    #[Test]
    public function ac4_web_run_resumed_in_background_broadcasts_the_final_reply(): void
    {
        $this->scenario = 'us1_ac4_web_background_resume';
        $this->setupJourney();
        $conversation = $this->syncConversation();

        $this->interruptPostAgentRun($conversation, 'web');
        Event::fake([NewConversationMessageEvent::class]);
        $this->resumeRun($conversation);

        $replies = $this->repliesOf($conversation);
        $this->assertCount(1, $replies, 'one assistant reply for the run');
        $replyId = $replies->first()->id;
        Event::assertDispatched(
            NewConversationMessageEvent::class,
            fn (NewConversationMessageEvent $event) => $event->message_id === $replyId,
        );
    }

    // ---------------------------------------------------------------
    // AC5 — the run record and the resume events show the interruption
    // and the resume
    // ---------------------------------------------------------------

    #[Test]
    public function ac5_run_record_and_events_show_the_interruption_and_the_resume(): void
    {
        $this->scenario = 'us1_ac5_run_record_and_events';
        $this->setupJourney();
        $conversation = $this->syncConversation();
        $this->startAndInterrupt($conversation);

        $runId = $this->runRowOf($conversation)->id;

        // The resume (the sweep re-drives the run).
        $this->resumeRun($conversation);

        // The resume events show the interruption (with last_progress_at) and
        // the resume (with from_step, reused[], rerun[], outcome_unknown[]).
        $detected = DB::table('agent_run_resume_events')->where('run_id', $runId)->where('kind', 'interruption_detected')->first();
        $this->assertNotNull($detected, 'an interruption_detected event is recorded');
        $detectedPayload = json_decode((string) $detected->payload, true);
        $this->assertNotNull($detectedPayload['last_progress_at'] ?? null, 'the interruption_detected event carries last_progress_at');

        $started = DB::table('agent_run_resume_events')->where('run_id', $runId)->where('kind', 'resume_started')->first();
        $this->assertNotNull($started, 'a resume_started event is recorded');
        $startedPayload = json_decode((string) $started->payload, true);
        $this->assertNotNull($startedPayload['from_step'] ?? null, 'the resume_started event carries from_step');
        foreach (['reused', 'rerun', 'outcome_unknown'] as $list) {
            $this->assertIsArray($startedPayload[$list] ?? null, "the resume_started event carries {$list}[]");
        }
    }
}
