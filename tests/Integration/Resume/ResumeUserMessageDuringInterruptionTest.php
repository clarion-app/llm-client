<?php

namespace Tests\Integration\Resume;

use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\RunResumer;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\GeneratorConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\TaskBudgetJourneys;
use Tests\Support\EffectLedger;
use Tests\Support\InjectedFault;
use Tests\Support\ScriptedProvider;
use Tests\Support\SnapshotFaultInjector;
use Tests\Support\TravelsPastLease;

/**
 * A user message posted while a stream run is interrupted (quickstart M15):
 * `POST message` returns today's "processing" behaviour — the message is
 * created and queued, and no second run is started. After the resume
 * finishes (whose last rows include a `task_resume_notice` posted after the
 * user's message), `checkForUnprocessedMessages()` still picks the user's
 * message up and starts the next run.
 */
class ResumeUserMessageDuringInterruptionTest extends AssembledSystemTestCase
{
    use TaskBudgetJourneys;
    use TravelsPastLease;

    private const USAGE = [10, 10];

    private EffectLedger $effectLedger;

    private ScriptedProvider $provider;

    private SnapshotFaultInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTaskBudgetJourney();
        config(['llm-client.agent_loop.max_iterations' => 4]);
    }

    protected function tearDown(): void
    {
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    /** Bind the executor with a fixed token, seed the ledger operations, bind the provider's stack, and bind the snapshot injector. */
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

    /** @return int the number of runs for the conversation */
    private function runCount(Conversation $conversation): int
    {
        return DB::table('agent_runs')->where('conversation_id', $conversation->id)->count();
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
    // M15 — a user message posted while a stream run is interrupted
    // ---------------------------------------------------------------

    #[Test]
    public function a_user_message_during_an_interruption_is_queued_and_picked_up_after_the_resume(): void
    {
        $this->scenario = 'resume_user_message_during_interruption';
        $this->setupJourney();
        $conversation = $this->conversationFor('stream');

        // Start the stream run.
        $this->send('stream', $conversation, 'Write to the ledger.')->assertStatus(201);
        $this->assertSame(1, $this->runCount($conversation), 'the run is started');

        // Interrupt it (kill at a wired point): the run is left in progress,
        // so the conversation is processing.
        $this->expectInterruptedRun = true;
        $this->injector->arm('before_tool_started', 1, ['agent_runs', 'agent_run_steps']);
        $thrown = null;
        try {
            $this->playTurn(0, '', [
                ['id' => 'call_write', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.write']],
            ], self::USAGE);
        } catch (InjectedFault $e) {
            $thrown = $e;
            $this->injector->restore();
        }
        $this->assertNotNull($thrown, 'the fault at before_tool_started was reached (the run is interrupted)');

        // A minute into the interruption, the user posts a message: today's
        // "processing" behaviour — the message is created and queued, and no
        // second run is started.
        Carbon::setTestNow(now()->addMinute());
        $this->send('stream', $conversation, 'Also read the ledger.')->assertStatus(201);
        $this->assertSame(1, $this->runCount($conversation), 'a message posted while processing does not start a second run');

        // The resume finishes; its last rows include a task_resume_notice
        // posted after the user's message.
        $this->resumeRun($conversation);

        $userMessage = Message::where('conversation_id', $conversation->id)->where('role', 'user')->orderByDesc('created_at')->first();
        $this->assertNotNull($userMessage, 'the user\'s message is recorded');

        $notice = Message::where('conversation_id', $conversation->id)->get()->first(
            fn (Message $m) => ($m->tool_data['kind'] ?? null) === 'task_resume_notice'
        );
        $this->assertNotNull($notice, 'a task_resume_notice is posted');
        $this->assertTrue(
            $notice->created_at >= $userMessage->created_at,
            'the task_resume_notice is posted after the user\'s message',
        );

        // The resumed run's close runs checkForUnprocessedMessages(), which
        // still picks the user's message up and starts the next run.
        $this->assertSame('completed', DB::table('agent_runs')->where('conversation_id', $conversation->id)->orderBy('started_at')->first()->end_state);
        $this->assertSame(2, $this->runCount($conversation), 'checkForUnprocessedMessages() starts the next run');
        $this->stream()->extractDispatchedJobs();
        $this->assertSame(1, $this->stream()->turnCount(), 'the next run\'s first model call is dispatched');
    }
}
