<?php

namespace Tests\Integration\Harness;

use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\GeneratorConfig;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Helpers on top of the task-budget journeys: agents that delegate to
 * helpers, through the real delegation service, with every helper's model
 * calls served by the same scripted boundary as its delegator's.
 *
 * Queued work (batch members, managed-task steps, sequence stages) runs on
 * the synchronous queue, and every job starts in a fresh container scope —
 * exactly what a queue worker does between jobs — so nothing scoped to one
 * job (the task-budget gate among them) is ever seen by the next.
 *
 * Model calls are served in the order they are made, so a script lists the
 * root's calls and each helper's calls in execution order: a solo helper
 * runs inside its delegator's tool call; batch members run one after the
 * other, in the order delegated, before the delegator's next call. A note
 * hook runs when a given note is stored (that is, in the middle of the step
 * that stores it), which is how a test puts two pieces of work in progress
 * at the same moment.
 *
 * For use in an AssembledSystemTestCase.
 */
trait TaskBudgetHelperJourneys
{
    use TaskBudgetJourneys;

    /** @var array<string, \Closure> note text => what happens while that note is being stored */
    protected array $noteHooks = [];

    protected function setUpHelperJourney(string $at = '2026-10-12 09:00:00'): void
    {
        $this->setUpTaskBudgetJourney($at);
        $this->enableHelpers();
    }

    /** On top of setUpTaskBudgetJourney(): agents, helpers and queued work as described above. */
    protected function enableHelpers(): void
    {
        config([
            'queue.default' => 'sync',
            // The helpers here take more steps than a delegation's own
            // default bound; that bound is not what these journeys are about.
            'llm-client.delegation.max_iterations' => 50,
        ]);

        $document = self::taskBudgetHostApiDocument();
        $this->app->instance(Generator::class, new class($document) extends Generator
        {
            public function __construct(private array $document)
            {
            }

            public function __invoke(?GeneratorConfig $config = null)
            {
                return $this->document;
            }
        });

        (new OperationCatalogue())->fakeHostApi([
            '/notes' => function ($request) {
                $this->noteWrites[] = $request->data();
                $text = (string) ($request->data()['text'] ?? '');
                if (isset($this->noteHooks[$text])) {
                    $hook = $this->noteHooks[$text];
                    unset($this->noteHooks[$text]);
                    $hook();
                }

                return Http::response(['stored' => true], 201, ['Content-Type' => 'application/json']);
            },
            // A helper's conversation starts untitled, so its first exchange
            // asks for a title on the side.
            '/chat/completions' => fn () => Http::response(
                ['choices' => [['message' => ['role' => 'assistant', 'content' => json_encode(['title' => 'Helper work'])]]]],
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        Event::listen(JobProcessing::class, function () {
            $this->app->forgetScopedInstances();
        });
    }

    /**
     * The fixture's conversation as the root of $path (Telegram for sync, the
     * web for stream), bound to $agent. Call fixtureWithModel() first.
     */
    protected function rootConversation(string $path, Agent $agent): Conversation
    {
        $conversation = $this->fixture()->conversation;
        $conversation->update([
            'channel' => $path === 'sync' ? 'telegram' : $conversation->channel,
            'agent_id' => $agent->id,
            'agent_version_id' => $agent->current_version_id,
        ]);

        return $conversation->fresh();
    }

    /** Builds the fixture (user, server) and makes its model the owner's inference model. */
    protected function fixtureWithModel(): ConversationFixture
    {
        $fixture = $this->fixture()->build();
        app(RoleAssignmentService::class)->set(ModelRole::Inference, $fixture->user->id, $fixture->server->id, 'gpt-4');

        return $fixture;
    }

    protected function agent(string $name): Agent
    {
        return app(AgentService::class)->create($this->fixture()->user->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    protected function assignHelper(Agent $parent, Agent $helper): void
    {
        app(AgentHelperService::class)->assign($this->fixture()->user->id, $parent->id, $helper->id);
    }

    protected function delegateCall(string $id, Agent $helper, string $task): array
    {
        return ['id' => $id, 'name' => 'delegate_to_helper', 'arguments' => ['helper_agent_id' => $helper->id, 'task' => $task]];
    }

    /** One step that stores note $text (and lists applications). */
    protected function noteStep(string $text): array
    {
        return [
            ['id' => 'call_note_'.md5($text), 'name' => 'execute_operation', 'arguments' => ['operationId' => 'storeNote', 'parameters' => ['body' => ['text' => $text]]]],
            ['id' => 'call_apps_'.md5($text), 'name' => 'list_applications', 'arguments' => []],
        ];
    }

    /** Script one note step per text, each with the given usage. */
    protected function scriptNoteSteps(array $texts, ?array $usage = null): void
    {
        foreach ($texts as $text) {
            $this->script()->toolRequests($this->noteStep($text));
            if ($usage !== null) {
                $this->script()->usage($usage[0], $usage[1]);
            }
        }
    }

    /** A helper's finished answer, in the shape every delegated answer must take. */
    protected function scriptHelperAnswer(string $summary, ?array $usage = null): void
    {
        $this->script()->finalAnswer(json_encode(['status' => 'success', 'summary' => $summary, 'output' => (object) [], 'undone' => '']));
        if ($usage !== null) {
            $this->script()->usage($usage[0], $usage[1]);
        }
    }

    /** Prices the fixture's model so that every response with usage [10, 10] costs 0.04. */
    protected function priceModel(): void
    {
        ModelPrice::create([
            'provider_type' => 'openai',
            'model' => 'gpt-4',
            'reused_input_rate' => '0.00000000',
            'fresh_input_rate' => '0.00000000',
            'output_rate' => '4000.00000000',
            'effective_from' => Carbon::now()->subDay(),
            'effective_until' => null,
        ]);
    }

    /** Model requests that were agent steps (not condensation, summaries or embeddings). */
    protected function agentRequests(): int
    {
        return count($this->transport()->capturedPayloadsForLane(RequestLane::AgentTurn));
    }

    protected function delegationTo(Agent $helper, ?string $parentConversationId = null): Delegation
    {
        $query = Delegation::where('helper_agent_id', $helper->id);
        if ($parentConversationId !== null) {
            $query->where('parent_conversation_id', $parentConversationId);
        }

        return $query->latest('started_at')->firstOrFail();
    }

    protected function helperConversationOf(Agent $helper, ?string $parentConversationId = null): Conversation
    {
        return Conversation::findOrFail($this->delegationTo($helper, $parentConversationId)->helper_conversation_id);
    }

    /** The decoded tool result the delegator stored for tool call $toolCallId. */
    protected function toolResultFor(Conversation $conversation, string $toolCallId): ?array
    {
        foreach (\ClarionApp\LlmClient\Models\Message::where('conversation_id', $conversation->id)->get() as $message) {
            foreach (($message->tool_data['tool_results'] ?? []) as $result) {
                if (($result['tool_call_id'] ?? null) === $toolCallId) {
                    $decoded = json_decode((string) ($result['content'] ?? ''), true);

                    return is_array($decoded) ? $decoded : null;
                }
            }
        }

        return null;
    }

    protected function taskRow(string $taskBudgetId): object
    {
        $row = DB::table('task_budgets')->where('id', $taskBudgetId)->first();
        $this->assertNotNull($row, "task {$taskBudgetId} exists");

        return $row;
    }

    /** Usage rows written for conversation $conversationId, oldest first. */
    protected function usageOf(string $conversationId): array
    {
        return DB::table('usage_records')->where('conversation_id', $conversationId)->orderBy('created_at')->get()->all();
    }

    protected function onlyTask(): TaskBudget
    {
        $this->assertSame(1, TaskBudget::count(), 'one task for the whole tree of work');

        return TaskBudget::firstOrFail();
    }
}
