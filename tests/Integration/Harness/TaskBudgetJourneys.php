<?php

namespace Tests\Integration\Harness;

use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Events\ConversationEnded;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/**
 * Shared driving for the task-budget journeys, on both execution paths:
 * `sync` — a messaging (Telegram) conversation through POST agent, the model
 * scripted at the HTTP boundary — and `stream` — a web conversation through
 * POST message, the model scripted at the streaming boundary.
 *
 * Every scripted step makes the same two tool calls: storing a note through
 * the host application's API (a state-changing call, counted, so a repeated
 * or skipped execution is visible) and listing applications (read-only; it
 * also keeps the loop from ending early on an all-operations-succeeded
 * turn). Real wiring otherwise: the controllers, the agent loop, the stream
 * handler, the question service and its presenter, with recording adapters
 * standing in for both channels.
 *
 * For use in an AssembledSystemTestCase.
 */
trait TaskBudgetJourneys
{
    protected RecordingChannelDelivery $web;

    protected RecordingRichChannelDelivery $telegram;

    /** @var list<array<string, mixed>> every note the host API was asked to store */
    protected array $noteWrites = [];

    /** @var array<int, true> resume jobs already worked, by object id */
    protected array $workedResumes = [];

    protected function setUpTaskBudgetJourney(string $at = '2026-10-12 09:00:00'): void
    {
        $this->travelTo(Carbon::parse($at, 'UTC'));

        config(['llm-client.run_trace.enabled' => true, 'llm-client.cost.currency' => 'USD']);

        Queue::fake([SendHttpStreamRequest::class, ResumeAfterQuestionJob::class]);

        $this->app->singleton(
            McpToolExecutor::class,
            fn () => new McpToolExecutor($this->app->make(McpToolRegistry::class), null, fn ($user) => 'test-token-'.$user->id),
        );
        $catalogue = new OperationCatalogue();
        $catalogue->seed(self::taskBudgetHostApiDocument());
        $catalogue->fakeHostApi([
            '/notes' => function ($request) {
                $this->noteWrites[] = $request->data();

                return Http::response(['stored' => true], 201, ['Content-Type' => 'application/json']);
            },
        ]);

        Event::fake([
            NewConversationMessageEvent::class,
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            ToolExecutionEvent::class,
            ConversationEnded::class,
        ]);

        $this->web = new RecordingChannelDelivery('web');
        $this->telegram = new RecordingRichChannelDelivery('telegram');
        $registry = $this->app->make(ConversationChannelRegistry::class);
        $registry->register($this->web);
        $registry->register($this->telegram);
        $this->app->afterResolving(ConversationChannelRegistry::class, function (ConversationChannelRegistry $registry) {
            $registry->register($this->web);
            $registry->register($this->telegram);
        });
    }

    protected function tearDownTaskBudgetJourney(): void
    {
        (new OperationCatalogue())->reset();
    }

    protected static function taskBudgetHostApiDocument(): array
    {
        return [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Test API', 'version' => '1.0.0'],
            'paths' => [
                '/notes' => [
                    'post' => [
                        'operationId' => 'storeNote',
                        'summary' => 'Store a note',
                        'parameters' => [],
                        'responses' => ['201' => ['description' => 'Created']],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function paths(): array
    {
        return ['sync' => ['sync'], 'stream' => ['stream']];
    }

    /** The conversation a path's journey runs in: Telegram for sync, the web for stream. */
    protected function conversationFor(string $path): Conversation
    {
        $conversation = $this->fixture()->build()->conversation;
        if ($path === 'sync') {
            $conversation->update(['channel' => 'telegram']);
        }

        return $conversation->fresh();
    }

    /** The two tool calls of step $n, as the model requests them. */
    protected function stepCalls(int $n): array
    {
        return [
            ['id' => "call_note_{$n}", 'name' => 'execute_operation', 'arguments' => ['operationId' => 'storeNote', 'parameters' => ['body' => ['text' => "note {$n}"]]]],
            ['id' => "call_apps_{$n}", 'name' => 'list_applications', 'arguments' => []],
        ];
    }

    /** Script steps $from..$to on the synchronous path, each with the given usage. */
    protected function scriptSteps(int $from, int $to, ?array $usage = null): void
    {
        for ($n = $from; $n <= $to; $n++) {
            $this->script()->toolRequests($this->stepCalls($n));
            if ($usage !== null) {
                $this->script()->usage($usage[0], $usage[1]);
            }
        }
    }

    /** Send a request on $path: POST agent (Telegram) for sync, POST message (web) for stream. */
    protected function send(string $path, Conversation $conversation, string $content): TestResponse
    {
        if ($path === 'sync') {
            return $this->actingAs($this->fixture()->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
                'message' => $content,
                'channel' => 'telegram',
                'conversation_id' => $conversation->id,
            ]);
        }

        return $this->actingAs($this->fixture()->user, 'api')->postJson('/api/clarion-app/llm-client/message', [
            'conversation_id' => $conversation->id,
            'content' => $content,
        ]);
    }

    /** @return list<string> SSE chunks for one streamed model turn */
    protected function turnChunks(string $text, array $calls, ?array $usage = null): array
    {
        $chunks = [];
        if ($text !== '') {
            $chunks[] = 'data: '.json_encode(['choices' => [['delta' => ['content' => $text], 'finish_reason' => null]]])."\n\n";
        }
        foreach (array_values($calls) as $index => $call) {
            $chunks[] = 'data: '.json_encode(['choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => $index,
                    'id' => $call['id'],
                    'type' => 'function',
                    'function' => ['name' => $call['name'], 'arguments' => json_encode($call['arguments'])],
                ]]],
                'finish_reason' => null,
            ]]])."\n\n";
        }
        $chunks[] = 'data: '.json_encode(['choices' => [['delta' => [], 'finish_reason' => $calls === [] ? 'stop' : 'tool_calls']]])."\n\n";
        if ($usage !== null) {
            $chunks[] = 'data: '.json_encode(['choices' => [], 'usage' => ['prompt_tokens' => $usage[0], 'completion_tokens' => $usage[1], 'total_tokens' => $usage[0] + $usage[1]]])."\n\n";
        }

        return $chunks;
    }

    /** Play streamed turn $slot, which must already have been dispatched. */
    protected function playTurn(int $slot, string $text, array $calls = [], ?array $usage = null): void
    {
        $stream = $this->stream();
        $stream->extractDispatchedJobs();
        $this->assertGreaterThan($slot, $stream->turnCount(), "a streaming request #{$slot} must have been dispatched");
        $stream->setCurrentSlot($slot);
        $stream->emit($this->turnChunks($text, $calls, $usage));
        $stream->finish();
    }

    /** Play streamed steps $from..$to, step $n in slot $n - 1. */
    protected function playSteps(int $from, int $to, ?array $usage = null): void
    {
        for ($n = $from; $n <= $to; $n++) {
            $this->playTurn($n - 1, '', $this->stepCalls($n), $usage);
        }
    }

    /** Model requests made so far on $path. */
    protected function modelRequests(string $path): int
    {
        return $path === 'sync'
            ? count($this->transport()->capturedChatPayloads())
            : $this->stream()->extractDispatchedJobs()->turnCount();
    }

    protected function taskOf(Conversation $conversation): ?TaskBudget
    {
        $id = Conversation::findOrFail($conversation->id)->active_task_budget_id;

        return $id === null ? null : TaskBudget::find($id);
    }

    protected function taskRowOf(Conversation $conversation): ?object
    {
        $id = Conversation::findOrFail($conversation->id)->active_task_budget_id;

        return $id === null ? null : DB::table('task_budgets')->where('id', $id)->first();
    }

    protected function promptOf(Conversation $conversation): ?AgentQuestion
    {
        return AgentQuestion::where('conversation_id', $conversation->id)->where('origin', AgentQuestion::ORIGIN_TASK_BUDGET)->latest('asked_at')->first();
    }

    protected function latestRunIdOf(Conversation $conversation): ?string
    {
        return DB::table('agent_runs')->where('conversation_id', $conversation->id)->orderByDesc('started_at')->value('id');
    }

    /** The assistant turns that called tools, oldest first. */
    protected function toolTurnsOf(Conversation $conversation)
    {
        return Message::where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->orderBy('created_at')
            ->get()
            ->filter(fn (Message $m) => !empty($m->tool_data['tool_calls'] ?? null) && ($m->tool_data['kind'] ?? null) === null)
            ->values();
    }

    /** The ceiling prompt was delivered once on the conversation's channel, with the report above it. */
    protected function assertPromptDeliveredOnce(string $path, Conversation $conversation, AgentQuestion $prompt): void
    {
        $display = Message::findOrFail($prompt->display_message_id);
        $this->assertStringStartsWith((string) $prompt->preamble, $display->content, 'the report is the prompt\'s preamble');
        $this->assertStringStartsWith("This task's budget ceiling was reached", (string) $prompt->preamble);

        if ($path === 'sync') {
            $presentations = $this->telegram->presentationsFor($conversation->id);
            $this->assertCount(1, $presentations, 'presented once in the chat');
            $this->assertSame($display->content, $presentations[0]->plainText);
            $this->assertCount(3, $presentations[0]->options, 'three choices, as buttons');
        } else {
            $this->assertSame([$display->content], $this->web->textsFor($conversation->id), 'delivered once on the web');
        }
    }

    /** Work every queued resume the way a queue worker does. */
    protected function workQueuedResumes(): int
    {
        $worked = 0;
        foreach (Queue::pushed(ResumeAfterQuestionJob::class)->all() as $job) {
            if (isset($this->workedResumes[spl_object_id($job)])) {
                continue;
            }
            $this->workedResumes[spl_object_id($job)] = true;
            $this->app->call([$job, 'handle']);
            $worked++;
        }

        return $worked;
    }
}
