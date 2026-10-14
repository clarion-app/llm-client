<?php

namespace Tests\Integration\Resume;

use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
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
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\TaskBudgetHelperJourneys;
use Tests\Support\EffectLedger;
use Tests\Support\InjectedFault;
use Tests\Support\ScriptedProvider;
use Tests\Support\SnapshotFaultInjector;
use Tests\Support\TravelsPastLease;

/**
 * Context reconstruction after a resume (contracts §5, FR-015): the model's
 * context is rebuilt from the conversation's rows, not from any in-memory
 * state. After a resume, the payload from `buildMessagesPayload()` then
 * `applyContextWindowTrim()` (the real path) holds the interrupted step's
 * assistant message exactly once with `tool_calls` equal to the checkpointed
 * response, has exactly one tool result per call id, contains no empty
 * assistant row, and never carries a `task_resume_notice` row (quickstart
 * M14) — including when the history is long enough to be trimmed.
 */
class ResumeContextReconstructionTest extends AssembledSystemTestCase
{
    use TaskBudgetHelperJourneys;
    use TravelsPastLease;

    private const USAGE = [10, 10];

    private EffectLedger $effectLedger;

    private ScriptedProvider $provider;

    private SnapshotFaultInjector $injector;

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

    /** @return object the run row for the conversation's single run */
    private function runRowOf(Conversation $conversation): object
    {
        $row = DB::table('agent_runs')->where('conversation_id', $conversation->id)->orderByDesc('started_at')->first();
        $this->assertNotNull($row, 'the run exists');

        return $row;
    }

    /**
     * Drive the run, catching the injected fault. Fails the test when the
     * run errored before the fault point, or when the fault never fired
     * (the funnel is not in place — the red reason).
     */
    private function killAt(string $point, int $occurrence, callable $drive): void
    {
        $this->expectInterruptedRun = true;
        $this->injector->arm($point, $occurrence, ['agent_runs', 'agent_run_steps']);
        $thrown = null;
        try {
            $drive();
        } catch (InjectedFault $e) {
            $thrown = $e;
            $this->injector->restore();
        } catch (\Throwable $e) {
            $this->fail("the run failed before the fault point ({$point}): {$e->getMessage()}");
        }
        $this->assertNotNull($thrown, "the fault at {$point} (occurrence {$occurrence}) was never reached — the funnel is not in place");
    }

    /**
     * The resume half: expire the run's lease (travel window + 1 s), run the
     * interruption sweep, and run the job the sweep pushed.
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

    /**
     * The real path: `buildMessagesPayload()` then `applyContextWindowTrim()`
     * (the latter is private, so it is invoked through reflection on the
     * container-resolved service).
     *
     * @return array<int, array<string, mixed>>
     */
    private function trimmedPayload(Conversation $conversation): array
    {
        $service = app(AgentLoopService::class);
        $payload = $service->buildMessagesPayload($conversation);
        $method = new \ReflectionMethod(AgentLoopService::class, 'applyContextWindowTrim');
        $method->setAccessible(true);

        return $method->invoke($service, $conversation, $payload);
    }

    /** The assistant message(s) in the payload that carry tool calls. */
    private function assistantWithToolCalls(array $payload): array
    {
        return array_values(array_filter(
            $payload,
            fn (array $m) => ($m['role'] ?? null) === 'assistant' && !empty($m['tool_calls'])
        ));
    }

    /** The call ids named by a payload assistant message's tool_calls. */
    private function callIdsOf(array $message): array
    {
        return array_map(fn (array $call) => (string) ($call['id'] ?? ''), array_values($message['tool_calls'] ?? []));
    }

    /** The tool-result call ids present in the payload (tool-role rows). */
    private function toolResultIds(array $payload): array
    {
        return array_values(array_filter(array_map(
            fn (array $m) => ($m['role'] ?? null) === 'tool' ? (string) ($m['tool_call_id'] ?? '') : null,
            $payload
        )));
    }

    // ---------------------------------------------------------------
    // The contract, at the record point
    // ---------------------------------------------------------------

    private function scriptWriteThenReply(): void
    {
        $this->script()
            ->toolRequests([['id' => 'call_write', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.write']]])
            ->usage(...self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);
    }

    #[Test]
    public function the_interrupted_steps_assistant_message_holds_once_with_the_checkpointed_calls(): void
    {
        $this->scenario = 'context_reconstruction_recorded';
        $this->setupJourney();
        $conversation = $this->syncConversation();
        $this->scriptWriteThenReply();

        $this->killAt('after_tool_recorded', 1, fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Write to the ledger.'));
        $this->resumeRun($conversation);

        $payload = $this->trimmedPayload($conversation);

        // The interrupted step's assistant message holds exactly once, with
        // tool_calls equal to the checkpointed response.
        $withCalls = $this->assistantWithToolCalls($payload);
        $this->assertCount(1, $withCalls, 'the interrupted step\'s assistant message holds exactly once');

        $run = $this->runRowOf($conversation);
        // The interrupted step is the one that holds the tool calls (the
        // resume opens a fresh step for the final answer, so the tool step
        // is not necessarily the last).
        $step = DB::table('agent_run_steps')->where('run_id', $run->id)
            ->get()
            ->first(fn ($s) => count(array_keys(json_decode((string) $s->checkpoint, true)['calls'] ?? [])) > 0);
        $this->assertNotNull($step, 'the interrupted step (the one with the tool calls) exists');
        $checkpoint = json_decode((string) $step->checkpoint, true);
        $checkpointCallIds = array_keys($checkpoint['calls'] ?? []);
        $this->assertEqualsCanonicalizing($checkpointCallIds, $this->callIdsOf($withCalls[0]), 'tool_calls equal the checkpointed response');

        // Exactly one tool result per call id.
        $resultIds = $this->toolResultIds($payload);
        $this->assertCount(count(array_unique($resultIds)), $resultIds, 'no call id has more than one tool result');
        $this->assertContains('call_write', $resultIds, 'the completed call has its tool result');

        // No empty assistant row.
        foreach ($payload as $message) {
            if (($message['role'] ?? null) === 'assistant') {
                $hasContent = trim((string) ($message['content'] ?? '')) !== '';
                $this->assertTrue($hasContent || !empty($message['tool_calls']), 'no empty assistant row');
            }
        }
    }

    #[Test]
    public function notices_not_in_model_history(): void
    {
        $this->scenario = 'context_reconstruction_notices';
        $this->setupJourney();
        $conversation = $this->syncConversation();
        $this->scriptWriteThenReply();

        $this->killAt('after_tool_recorded', 1, fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Write to the ledger.'));
        $this->resumeRun($conversation);

        // The resume posts a task_resume_notice row (user-facing). It must
        // never appear in the model's history.
        $notice = Message::where('conversation_id', $conversation->id)->get()->first(
            fn (Message $m) => ($m->tool_data['kind'] ?? null) === 'task_resume_notice'
        );
        $this->assertNotNull($notice, 'precondition: the resume posted a task_resume_notice');

        $payload = $this->trimmedPayload($conversation);
        $noticeContent = (string) $notice->content;
        $inPayload = false;
        foreach ($payload as $message) {
            if (str_contains((string) ($message['content'] ?? ''), $noticeContent) && $noticeContent !== '') {
                $inPayload = true;
                break;
            }
        }
        $this->assertFalse($inPayload, 'a task_resume_notice row never appears in the model history (M14)');
    }

    // ---------------------------------------------------------------
    // The contract holds with a history long enough to be trimmed
    // ---------------------------------------------------------------

    /** Seed $pairs long user/assistant turns before the killed run. */
    private function seedLongHistory(Conversation $conversation, int $pairs): void
    {
        for ($i = 1; $i <= $pairs; $i++) {
            Message::create([
                'conversation_id' => $conversation->id,
                'role' => 'user',
                'content' => "Question {$i}: ".str_repeat('context ', 200),
            ]);
            Message::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => "Answer {$i}: ".str_repeat('history ', 200),
            ]);
        }
    }

    #[Test]
    public function the_contract_holds_with_a_history_long_enough_to_be_trimmed(): void
    {
        $this->scenario = 'context_reconstruction_trimmed';
        $this->setupJourney();
        $conversation = $this->syncConversation();
        $this->seedLongHistory($conversation, 40);
        $this->scriptWriteThenReply();

        $this->killAt('after_tool_recorded', 1, fn () => $this->app->make(AgentLoopService::class)->run($conversation, 'Write to the ledger.'));
        $this->resumeRun($conversation);

        $payload = $this->trimmedPayload($conversation);

        // The interrupted step's assistant message still holds exactly once
        // (trimming drops the oldest rows, never the most recent step).
        $withCalls = $this->assistantWithToolCalls($payload);
        $this->assertCount(1, $withCalls, 'the interrupted step\'s assistant message still holds exactly once after a trim');
        $this->assertContains('call_write', $this->callIdsOf($withCalls[0]), 'tool_calls still name the checkpointed call');

        // Exactly one tool result per call id, and no empty assistant row.
        $resultIds = $this->toolResultIds($payload);
        $this->assertCount(count(array_unique($resultIds)), $resultIds, 'no call id has more than one tool result after a trim');
        foreach ($payload as $message) {
            if (($message['role'] ?? null) === 'assistant') {
                $hasContent = trim((string) ($message['content'] ?? '')) !== '';
                $this->assertTrue($hasContent || !empty($message['tool_calls']), 'no empty assistant row after a trim');
            }
        }
    }
}
