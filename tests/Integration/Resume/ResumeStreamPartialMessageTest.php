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
 * A stream killed mid-reply (quickstart M12, FR-013): the resumed stream
 * job reuses the step's partial message row rather than creating a
 * duplicate. The row's content is reset and then filled by the repeated
 * call, so the conversation ends with exactly one assistant row for the
 * step and no empty-content row left behind.
 *
 * The kill lands at the `mid_stream` fault point, after two content
 * deltas: the first delta has already created the partial row and named
 * it on the step (`agent_run_steps.message_id`), so the row exists on the
 * other side of the kill boundary.
 */
class ResumeStreamPartialMessageTest extends AssembledSystemTestCase
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

    /** @return object the run row for the conversation's single run */
    private function runRowOf(Conversation $conversation): object
    {
        $row = DB::table('agent_runs')->where('conversation_id', $conversation->id)->orderByDesc('started_at')->first();
        $this->assertNotNull($row, 'the run exists');

        return $row;
    }

    /**
     * The resume half: expire the run's lease (travel window + 1 s), run
     * the interruption sweep, and run the job the sweep pushed. The resumer
     * re-drives the step from the checkpoint, so the message row is reused.
     */
    private function resumeRun(Conversation $conversation): object
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

        return $this->runRowOf($conversation);
    }

    // ---------------------------------------------------------------
    // M12 — a stream killed mid-reply reuses the step's message row
    // ---------------------------------------------------------------

    #[Test]
    public function a_killed_mid_stream_resume_reuses_the_step_message_row(): void
    {
        $this->scenario = 'resume_stream_partial_message';
        $this->setupJourney();
        $conversation = $this->conversationFor('stream');

        $this->send('stream', $conversation, 'Say hello.')->assertStatus(201);

        // Kill at mid_stream after two content deltas. The first delta has
        // already created the partial row and named it on the step, so the
        // row exists on the other side of the kill boundary. The kill
        // interrupts the run before it can finish, so a leftover is
        // expected — suppress the unconsumed-steps check.
        $this->expectInterruptedRun = true;
        $this->injector->arm('mid_stream', 1, ['agent_runs', 'agent_run_steps', 'messages']);
        $stream = $this->stream();
        $stream->extractDispatchedJobs();
        $this->assertGreaterThan(0, $stream->turnCount(), 'a streaming request must have been dispatched');
        $stream->setCurrentSlot(0);
        $thrown = null;
        try {
            $stream->emit([
                'data: '.json_encode(['choices' => [['delta' => ['content' => 'Hel'], 'finish_reason' => null]]])."\n\n",
                'data: '.json_encode(['choices' => [['delta' => ['content' => 'lo'], 'finish_reason' => null]]])."\n\n",
            ]);
        } catch (InjectedFault $e) {
            $thrown = $e;
            $this->injector->restore();
        }

        // Precondition: the kill left a partial message — the step names the
        // row, so the step's final write updates that row rather than
        // creating a duplicate.
        $run = $this->runRowOf($conversation);
        $step = DB::table('agent_run_steps')->where('run_id', $run->id)->orderByDesc('position')->first();
        $this->assertNotNull($step, 'a step exists');
        $this->assertNotNull($step->message_id, 'the step names the partial message row');

        // The resumed stream job reuses the step's message row: its content
        // is reset and then filled by the repeated call.
        $this->resumeRun($conversation);

        // The repeated call is a stream step job carrying the partial row.
        $this->stream()->extractDispatchedJobs();
        $this->assertSame(1, $this->stream()->turnCount(), 'the resume dispatches the repeated model call');
        $this->playTurn(0, 'Hello there.', [], self::USAGE);

        // Exactly one assistant row for the step, with no empty-content row
        // left behind.
        $assistant = Message::where('conversation_id', $conversation->id)->where('role', 'assistant')->get();
        $this->assertCount(1, $assistant, 'exactly one assistant row for the step');
        $this->assertSame($step->message_id, $assistant[0]->id, 'the step\'s partial row is the one reused');
        $this->assertSame('Hello there.', (string) $assistant[0]->content, 'its content is reset, then filled by the repeated call');
        $this->assertSame('completed', $this->runRowOf($conversation)->end_state);
    }
}
