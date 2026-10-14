<?php

namespace Tests\Integration\Harness;

use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\GeneratorConfig;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\EffectLedger;
use Tests\Support\InjectedFault;
use Tests\Support\ScriptedProvider;
use Tests\Support\SnapshotFaultInjector;
use Tests\Support\TravelsPastLease;

/**
 * Shared fixtures for the journeys that interrupt a real run (a fault at a
 * wired point, the database put back exactly as a kill leaves it), run the
 * resume sweep and work the jobs it queues.
 *
 * For use in an AssembledSystemTestCase.
 */
trait InterruptedRunJourneys
{
    use TaskBudgetHelperJourneys;
    use TravelsPastLease;

    protected const USAGE = [10, 10];

    protected EffectLedger $effectLedger;

    protected ScriptedProvider $provider;

    protected SnapshotFaultInjector $injector;

    /** Bind the snapshot injector (every journey, sync or stream). */
    protected function bindInjector(): void
    {
        $this->injector = new SnapshotFaultInjector();
        $this->app->instance(\ClarionApp\LlmClient\Contracts\FaultInjector::class, $this->injector);
    }

    /** The synchronous journey: ledger operations, the provider's handler stack, the injector. */
    protected function setupLedgerJourney(): void
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
        $this->bindInjector();
    }

    /** Make the nth chat request reach the fault point `during_model_call` (before the provider answers). */
    protected function faultOnModelCalls(): void
    {
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
    }

    protected function syncConversation(): Conversation
    {
        return $this->fixture()->build()->conversation;
    }

    /** Script $steps steps, each writing one ledger entry and listing applications, then a final answer. */
    protected function scriptLedgerSteps(int $steps, string $final = 'All done.'): void
    {
        for ($n = 1; $n <= $steps; $n++) {
            $this->script()->toolRequests([
                ['id' => "call_write_{$n}", 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.write']],
                ['id' => "call_list_{$n}", 'name' => 'list_applications', 'arguments' => []],
            ])->usage(...self::USAGE);
        }
        $this->script()->finalAnswer($final)->usage(...self::USAGE);
    }

    /**
     * Arm a fault, drive, and put the tables back as the kill left them.
     * Fails when the fault was never reached.
     */
    protected function killAt(callable $drive, string $point, int $occurrence, array $tables = ['agent_runs', 'agent_run_steps', 'agent_run_actions']): void
    {
        $this->expectInterruptedRun = true;
        $this->injector->arm($point, $occurrence, $tables);
        try {
            $drive();
            if ($this->injector->fired()) {
                // A caller (a controller) that turns every exception into an error response.
                $this->injector->restore();

                return;
            }
        } catch (InjectedFault) {
            $this->injector->restore();

            return;
        } catch (\Throwable $e) {
            $this->injector->restore();
            $this->fail('the run failed before the fault point: '.get_class($e).': '.$e->getMessage());
        }
        $this->fail("the fault at {$point} (occurrence {$occurrence}) was never reached");
    }

    /** The conversation's newest run. */
    protected function latestRun(Conversation $conversation): object
    {
        $row = DB::table('agent_runs')->where('conversation_id', $conversation->id)->orderByDesc('started_at')->first();
        $this->assertNotNull($row, 'the run exists');

        return $row;
    }

    /**
     * Expire the leases, run the sweep and return the resume jobs it queued.
     *
     * @return list<ResumeInterruptedRunJob>
     */
    protected function sweep(?Conversation $conversation = null): array
    {
        $conversation !== null ? $this->travelPastLease($conversation) : $this->travelPastAllLeases();
        Queue::fake([AgentStreamStepJob::class, ResumeInterruptedRunJob::class]);
        $this->assertSame(0, Artisan::call('llm-client:resume-interrupted-runs'), 'the sweep ran');

        return Queue::pushed(ResumeInterruptedRunJob::class)->values()->all();
    }

    /** @return list<array{kind: string, attempt: ?int, payload: array}> */
    protected function eventsOf(string $runId, ?string $kind = null): array
    {
        $query = DB::table('agent_run_resume_events')->where('run_id', $runId)->orderBy('created_at');
        if ($kind !== null) {
            $query->where('kind', $kind);
        }

        return $query->get()->map(fn ($e) => ['kind' => $e->kind, 'attempt' => $e->attempt, 'payload' => json_decode((string) $e->payload, true)])->all();
    }

    /** @return list<Message> the conversation's report messages ("could not resume") */
    protected function reportsOf(Conversation $conversation): array
    {
        return Message::where('conversation_id', $conversation->id)->get()
            ->filter(fn (Message $m) => ($m->tool_data['kind'] ?? null) === 'task_resume_notice' && ($m->tool_data['notice'] ?? null) === 'could_not_resume')
            ->values()
            ->all();
    }

    /** The report's content rules: what was kept, that nothing more runs, a new request welcome, no identifiers. */
    protected function assertReportShape(string $text, int $completedSteps, string $context): void
    {
        $this->assertStringContainsString((string) $completedSteps, $text, "{$context}: names the completed steps");
        $this->assertStringContainsStringIgnoringCase('kept', $text, "{$context}: the completed steps are kept");
        $this->assertStringContainsStringIgnoringCase('nothing more', $text, "{$context}: nothing more runs automatically");
        $this->assertStringContainsStringIgnoringCase('new request', $text, "{$context}: a new request is welcome");
        $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $text, "{$context}: no identifiers");
        $this->assertStringNotContainsString('call_', $text, "{$context}: no tool-call ids");
    }
}
