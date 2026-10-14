<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\ApiCallValidator;
use ClarionApp\LlmClient\Services\ConversationAgentDefinitionResolver;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\ValueObjects\AgentChangeSource;
use ClarionApp\LlmClient\ValueObjects\PauseKind;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\UnansweredPolicy;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\GeneratorConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\OperationCatalogue;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\Integration\Harness\RecordingRichChannelDelivery;

/**
 * An assumed answer never stands in for the user's confirmation, end to end.
 *
 * The agent asks which drafts to clear out, assuming "the stale ones" if
 * nobody answers within the hour. Nobody does: the question expires on its
 * assumption and the run continues — and the continuation tries to delete
 * the stale drafts, an operation that needs the user's confirmation. The
 * run pauses for that confirmation exactly as it would have had the user
 * answered the question themselves: the deletion is not carried out, not
 * by the continuation, not by either sweep, not by any queued job, however
 * long it waits. Only the owner's explicit confirmation carries it out,
 * once.
 *
 * The continued run is the same interactive run the user started, never an
 * unattended one, so an agent that pre-authorizes the deletion for its
 * unattended work changes nothing here: each journey runs once on a plain
 * conversation and once bound to such an agent.
 *
 * Both execution paths: a messaging conversation on the synchronous path
 * and a web conversation on the streaming path. Workspace confirmation
 * relaxation is off (the default, asserted): nothing here may waive the
 * confirmation.
 *
 * Real wiring throughout — controllers, the agent loop, the question
 * service, both sweep commands, the resumer and its job (worked the way a
 * queue worker does), the confirmation route — with the model scripted at
 * the streaming and HTTP boundaries, the host API scripted at its HTTP
 * boundary (every deletion it receives is counted), and recording adapters
 * standing in for both channels.
 */
class ExpiryPolicyConfirmationJourneyTest extends AssembledSystemTestCase
{
    private const API = '/api/clarion-app/llm-client';

    private const MESSAGE_ENDPOINT = self::API.'/message';

    private const AGENT_ENDPOINT = self::API.'/agent';

    private const QUESTION_SWEEP = 'llm-client:resolve-expired-questions';

    private const ABANDONED_SWEEP = 'llm-client:resolve-abandoned-runs';

    private const QUESTION = 'Which drafts should I clear out?';

    private const CHOICES = ['The stale ones', 'All of them'];

    private const WAIT_MINUTES = 60;

    private const OPERATION = 'deleteStaleDrafts';

    /** The clock is frozen at each of these instants in turn. */
    private const ASKED_AT = '2026-10-12 09:00:00';

    private const SWEPT_AT = '2026-10-12 10:01:00';

    /** Inside the confirmation's own waiting window (five minutes by default). */
    private const SWEPT_AGAIN_AT = '2026-10-12 10:03:00';

    private const CONFIRMED_AT = '2026-10-12 10:04:00';

    /** Long after the confirmation's window and the abandonment threshold. */
    private const MUCH_LATER = '2026-10-12 13:00:00';

    private const FINAL_ANSWER = 'The stale drafts are gone.';

    /** The host application's API: one deletion, a DELETE, which the installation requires the user to confirm. */
    private const HOST_API_DOCUMENT = [
        'openapi' => '3.0.0',
        'info' => ['title' => 'Test API', 'version' => '1.0.0'],
        'paths' => [
            '/drafts/stale' => [
                'delete' => [
                    'operationId' => self::OPERATION,
                    'summary' => 'Delete every stale draft',
                    'parameters' => [],
                    'responses' => ['200' => ['description' => 'Deleted']],
                ],
            ],
        ],
    ];

    private RecordingChannelDelivery $web;

    private RecordingRichChannelDelivery $telegram;

    /** @var list<array{method: string, path: string}> every request the host API received to delete drafts */
    private array $deletions = [];

    /** @var array<int, true> resume jobs already worked, by object id */
    private array $worked = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::ASKED_AT, 'UTC'));

        config(['llm-client.run_trace.enabled' => true]);
        config(['llm-client.conversation.inactivity_threshold_hours' => 4]);

        // Resume jobs are held on the queue until a worker picks them up, so
        // the test sees exactly what each step queued.
        Queue::fake([AgentStreamStepJob::class, ResumeAfterQuestionJob::class]);

        Event::fake([
            NewConversationMessageEvent::class,
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            ToolExecutionEvent::class,
        ]);

        // The host application's catalogue holds one deletion — a DELETE,
        // which the installation requires the user to confirm — and its API
        // is scripted so every call to it is counted. McpToolExecutor
        // resolves API tokens via Passport in production; a fixed token
        // stands in here.
        $this->app->singleton(
            McpToolExecutor::class,
            fn () => new McpToolExecutor($this->app->make(McpToolRegistry::class), null, fn ($user) => 'test-token-'.$user->id),
        );
        $catalogue = new OperationCatalogue();
        $catalogue->seed(self::HOST_API_DOCUMENT);
        $this->serveAsTheHostDocumentation(self::HOST_API_DOCUMENT);
        $catalogue->fakeHostApi([
            '/drafts' => function ($request) {
                $this->deletions[] = ['method' => $request->method(), 'path' => '/'.ltrim((string) parse_url($request->url(), PHP_URL_PATH), '/')];

                return Http::response(['deleted' => 214], 200, ['Content-Type' => 'application/json']);
            },
        ]);

        $this->web = new RecordingChannelDelivery('web');
        $this->telegram = new RecordingRichChannelDelivery('telegram');
        $registry = $this->app->make(ConversationChannelRegistry::class);
        $registry->register($this->web);
        $registry->register($this->telegram);
    }

    /**
     * The host's API documentation, as its documentation generator would
     * produce it: what an agent definition's operation patterns are
     * resolved against when the definition is read. The generator
     * documents the host application's own routes, which this package's
     * test application does not have, so the one document above stands in
     * for its output.
     */
    private function serveAsTheHostDocumentation(array $document): void
    {
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
    }

    protected function tearDown(): void
    {
        (new OperationCatalogue())->reset();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Time, sweeps and the queue
    // ---------------------------------------------------------------

    private function at(string $instant): void
    {
        $this->travelTo(Carbon::parse($instant, 'UTC'));
    }

    private function runBothSweeps(): void
    {
        $this->assertSame(0, Artisan::call(self::QUESTION_SWEEP), 'the question sweep succeeds');
        $this->assertSame(0, Artisan::call(self::ABANDONED_SWEEP), 'the abandoned-run sweep succeeds');
    }

    /** Work every queued resume the way a queue worker does. */
    private function workQueuedResumes(): int
    {
        $worked = 0;
        foreach (Queue::pushed(ResumeAfterQuestionJob::class)->all() as $job) {
            if (isset($this->worked[spl_object_id($job)])) {
                continue;
            }
            $this->worked[spl_object_id($job)] = true;
            $this->app->call([$job, 'handle']);
            $worked++;
        }

        return $worked;
    }

    // ---------------------------------------------------------------
    // The streaming path
    // ---------------------------------------------------------------

    /** @return list<string> SSE chunks for one streamed model turn */
    private function turnChunks(string $text, array $calls): array
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
                    'function' => ['name' => $call['name'], 'arguments' => $call['arguments']],
                ]]],
                'finish_reason' => null,
            ]]])."\n\n";
        }
        $chunks[] = 'data: '.json_encode(['choices' => [['delta' => [], 'finish_reason' => $calls === [] ? 'stop' : 'tool_calls']]])."\n\n";

        return $chunks;
    }

    private function playTurn(int $slot, string $text, array $calls = []): void
    {
        $stream = $this->stream();
        $stream->extractDispatchedJobs();
        $this->assertGreaterThan($slot, $stream->turnCount(), "a streaming request #{$slot} must have been dispatched");
        $stream->setCurrentSlot($slot);
        $stream->emit($this->turnChunks($text, $calls));
        $stream->finish();
    }

    private function turnCount(): int
    {
        return $this->stream()->extractDispatchedJobs()->turnCount();
    }

    /** @return list<array<string, mixed>> */
    private function streamPayloadMessages(int $slot): array
    {
        $payloads = $this->stream()->extractDispatchedJobs()->capturedPayloads();
        $this->assertArrayHasKey($slot, $payloads, "no streaming request #{$slot} was dispatched");

        /** @var CapturedPayload $payload */
        $payload = $payloads[$slot];

        return json_decode(json_encode($payload->messages), true);
    }

    /** @return list<array<string, mixed>> */
    private function chatPayloadMessages(int $index): array
    {
        $payloads = $this->transport()->capturedChatPayloads();
        $this->assertArrayHasKey($index, $payloads, "no model request #{$index} was made");

        /** @var CapturedPayload $payload */
        $payload = $payloads[$index];

        return json_decode(json_encode($payload->messages), true);
    }

    private function toolResultFor(array $messages, string $toolCallId): ?array
    {
        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'tool' && ($message['tool_call_id'] ?? null) === $toolCallId) {
                $content = $message['content'] ?? null;

                return json_decode(is_string($content) ? $content : json_encode($content), true);
            }
        }

        return null;
    }

    // ---------------------------------------------------------------
    // The conversation and the agent
    // ---------------------------------------------------------------

    private function askArguments(): array
    {
        // A safe default: assume the stale ones if nobody answers within the hour.
        return ['question' => self::QUESTION, 'choices' => self::CHOICES, 'assumed_choices' => [1], 'wait_minutes' => self::WAIT_MINUTES];
    }

    private function deletionArguments(): array
    {
        return ['operationId' => self::OPERATION, 'parameters' => []];
    }

    private function conversationOn(string $channel, bool $boundToAPreAuthorizingAgent): Conversation
    {
        $conversation = $this->fixture()->build()->conversation;
        if ($channel !== 'web') {
            $conversation->update(['channel' => $channel]);
        }
        if ($boundToAPreAuthorizingAgent) {
            $this->bindToAnAgentThatPreAuthorizesTheDeletionWhenUnattended($conversation);
        }

        $conversation = $conversation->fresh();
        $this->assertConfirmationIsRequiredAndNotWaived($conversation);

        return $conversation;
    }

    /**
     * Binds the conversation to an agent whose own definition allows the
     * deletion and pre-authorizes it for unattended work: the one list an
     * unattended run would consult instead of asking a person.
     */
    private function bindToAnAgentThatPreAuthorizesTheDeletionWhenUnattended(Conversation $conversation): void
    {
        $yaml = "name: drafts-keeper\n"
            ."instructions: Keep the user's drafts tidy.\n"
            ."tools:\n  allow:\n    - ".self::OPERATION."\n"
            ."safety:\n  confirmation_required: []\n  unattended_authorized:\n    - ".self::OPERATION."\n";

        $agent = Agent::create(['user_id' => $conversation->user_id, 'name' => 'drafts-keeper']);
        $version = AgentVersion::create([
            'agent_id' => $agent->id,
            'version_number' => 1,
            'raw_definition' => $yaml,
            'content_hash' => hash('sha256', $yaml),
            'source' => AgentChangeSource::Created->value,
            'changed_by_user_id' => $conversation->user_id,
        ]);
        $agent->update(['current_version_id' => $version->id]);
        $conversation->update(['agent_id' => $agent->id, 'agent_version_id' => $version->id]);

        $definition = app(ConversationAgentDefinitionResolver::class)->effectiveDefinitionFor($conversation->fresh());
        $this->assertNotNull($definition, 'precondition: the bound agent\'s definition resolves');
        $this->assertTrue($definition->isOperationPermitted(self::OPERATION), 'precondition: the agent may delete drafts');
        $this->assertTrue($definition->isUnattendedAuthorized(self::OPERATION), 'precondition: it pre-authorizes the deletion for unattended work');
    }

    /** The installation requires confirmation for the deletion, and nothing waives it here. */
    private function assertConfirmationIsRequiredAndNotWaived(Conversation $conversation): void
    {
        $this->assertContains('DELETE', config('llm-client.confirm_methods'), 'precondition: deletions need the user\'s confirmation');
        $this->assertSame(
            ApiCallValidator::STATUS_CONFIRM,
            ApiCallValidator::validate(self::OPERATION, 'DELETE', '/drafts/stale')['status'],
            'precondition: the deletion is confirmation-gated',
        );
        $this->assertNull($conversation->coding_project_id, 'precondition: no workspace whose relaxation could apply');
        $this->assertSame(0, CodingProject::where('confirmation_relaxed', true)->count(), 'precondition: workspace confirmation relaxation is off');
    }

    private function questionIn(Conversation $conversation): AgentQuestion
    {
        $question = AgentQuestion::where('conversation_id', $conversation->id)->latest('asked_at')->first();
        $this->assertNotNull($question, 'the agent\'s ask must have opened a question');

        return $question;
    }

    private function assertAskedToProceed(AgentQuestion $question, string $resumeMode): void
    {
        $question = $question->fresh();
        $this->assertSame(QuestionState::Pending, $question->state);
        $this->assertSame($resumeMode, $question->resume_mode);
        $this->assertSame(UnansweredPolicy::ProceedOnAssumption, $question->unanswered_policy);
        $this->assertFalse($question->consequential);
        $this->assertSame([['number' => 1, 'text' => 'The stale ones']], $question->assumed_answer_selected);
    }

    /** The question expires on its assumption and one resume is queued. */
    private function letTheQuestionExpireOnItsAssumption(AgentQuestion $question): void
    {
        $this->at(self::SWEPT_AT);
        $this->assertSame(0, Artisan::call(self::QUESTION_SWEEP), 'the question sweep succeeds');

        $expired = $question->fresh();
        $this->assertSame(QuestionState::ExpiredAssumed, $expired->state, 'the question proceeds on its assumption');
        $this->assertNull($expired->answer_raw, 'the user never answered');
        $this->assertCount(1, Queue::pushed(ResumeAfterQuestionJob::class), 'exactly one resume is queued');
        $this->assertSame([], $this->deletions, 'precondition: nothing deleted yet');
    }

    /** The agent was told the answer is its own assumption and never the user's authorization. */
    private function assertToldItWasAnAssumption(?array $result): void
    {
        $this->assertSame('expired_assumed', $result['outcome'] ?? null);
        $this->assertSame('assumed', $result['answer_source'] ?? null);
        $this->assertArrayNotHasKey('answer', (array) $result, 'never handed over as the user\'s answer');
        $this->assertStringContainsString(
            'never treat it as the user\'s confirmation, approval or authorization of any action',
            (string) ($result['message'] ?? ''),
        );
    }

    /** The paused turn waiting on the user's confirmation, in $conversation. */
    private function pausedForConfirmation(Conversation $conversation): Message
    {
        $paused = Message::where('conversation_id', $conversation->id)->get()
            ->filter(fn (Message $m) => is_array($m->tool_data['pending_confirmation'] ?? null))
            ->values();
        $this->assertCount(1, $paused, 'the run paused for the user\'s confirmation, once');

        return $paused[0];
    }

    /**
     * The continuation reached the deletion and paused for the user's
     * confirmation: nothing was deleted, the run waits, and neither sweep
     * nor any queued job carries the deletion out.
     */
    private function assertWaitsForTheUsersConfirmation(AgentQuestion $question, string $toolCallId): Message
    {
        $this->assertSame([], $this->deletions, 'the assumed answer did not carry out the deletion');

        $conversation = Conversation::findOrFail($question->conversation_id);
        $paused = $this->pausedForConfirmation($conversation);

        $this->assertSame(PauseKind::Confirmation->value, $paused->tool_data['pause_kind'] ?? null, 'a confirmation pause, not a question');
        $pending = $paused->tool_data['pending_confirmation'];
        $this->assertSame(self::OPERATION, $pending['operationId'] ?? null, 'the deletion is what waits');
        $this->assertSame($toolCallId, $paused->tool_data['paused_tool_call_id'] ?? null, 'the deletion call is the one paused');

        $run = DB::table('agent_runs')->where('id', $question->run_id)->first();
        $this->assertSame('in_progress', $run->end_state, 'the run waits on its user');
        $this->assertSame('interactive', $run->kind, 'the continued run is still the user\'s interactive run');

        // The sweeps and the queue, inside the confirmation's window.
        $this->at(self::SWEPT_AGAIN_AT);
        $this->runBothSweeps();
        $this->assertSame(0, $this->workQueuedResumes(), 'nothing further was queued');
        $this->assertSame([], $this->deletions, 'neither sweep, nor any job, carries out the deletion');
        $this->assertSame('in_progress', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'));
        $this->assertSame(QuestionState::ExpiredAssumed, $question->fresh()->state);
        $this->assertCount(1, Queue::pushed(ResumeAfterQuestionJob::class), 'still the one resume the expiry queued');

        return $paused->fresh();
    }

    private function confirm(Conversation $conversation, Message $paused, bool $approved = true): TestResponse
    {
        return $this->actingAs($this->fixture()->user, 'api')
            ->postJson(self::API."/conversation/{$conversation->id}/confirm-api-call", [
                'message_id' => $paused->id,
                'approved' => $approved,
            ]);
    }

    /** @return array<string, array{bool}> */
    public static function agents(): array
    {
        return [
            'a plain conversation' => [false],
            'bound to an agent that pre-authorizes the deletion for unattended work' => [true],
        ];
    }

    // ---------------------------------------------------------------
    // The synchronous path, on a messaging conversation
    // ---------------------------------------------------------------

    /**
     * In the chat: the agent asks with an assumption, nobody answers, the
     * run continues on the assumption and asks to delete.
     */
    private function proceedToTheDeletionInTheChat(bool $boundToAPreAuthorizingAgent): AgentQuestion
    {
        $conversation = $this->conversationOn('telegram', $boundToAPreAuthorizingAgent);

        $this->script()
            ->toolRequest('ask_user', $this->askArguments())
            ->toolRequests([['id' => 'call_delete', 'name' => 'execute_operation', 'arguments' => $this->deletionArguments()]]);

        $this->actingAs($this->fixture()->user, 'api')->postJson(self::AGENT_ENDPOINT, [
            'message' => 'Tidy up my drafts.',
            'channel' => 'telegram',
            'conversation_id' => $conversation->id,
        ])->assertStatus(202)->assertJson(['status' => 'awaiting_user']);

        $question = $this->questionIn($conversation);
        $this->assertAskedToProceed($question, 'sync');

        $this->letTheQuestionExpireOnItsAssumption($question);
        $this->assertSame(1, $this->workQueuedResumes());

        $this->assertCount(2, $this->transport()->capturedChatPayloads(), 'the run continued with exactly one model request');
        $this->assertToldItWasAnAssumption($this->toolResultFor($this->chatPayloadMessages(1), (string) $question->tool_call_id));

        return $question;
    }

    #[Test]
    #[DataProvider('agents')]
    public function in_the_chat_an_assumed_answer_never_stands_in_for_the_users_confirmation(bool $boundToAPreAuthorizingAgent): void
    {
        $this->scenario = 'expiry_policy_confirmation_chat_sync';
        $this->entryPath = 'sync';

        $question = $this->proceedToTheDeletionInTheChat($boundToAPreAuthorizingAgent);
        $paused = $this->assertWaitsForTheUsersConfirmation($question, 'call_delete');

        // Only the owner's explicit confirmation carries it out, once.
        $conversation = Conversation::findOrFail($question->conversation_id);
        $this->at(self::CONFIRMED_AT);
        $this->confirm($conversation, $paused)->assertStatus(200);

        $this->assertSame([['method' => 'DELETE', 'path' => '/api/drafts/stale']], $this->deletions, 'confirmed, the deletion runs exactly once');
        $this->assertSame(1, $this->turnCount(), 'the confirmed call continues the run with one model request');
        $this->playTurn(0, self::FINAL_ANSWER);

        $this->assertSame('completed', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'));
        $this->assertCount(1, $this->deletions, 'and never again');
    }

    // ---------------------------------------------------------------
    // The streaming path, on the web
    // ---------------------------------------------------------------

    #[Test]
    #[DataProvider('agents')]
    public function on_the_web_an_assumed_answer_never_stands_in_for_the_users_confirmation(bool $boundToAPreAuthorizingAgent): void
    {
        $this->scenario = 'expiry_policy_confirmation_web_stream';
        $this->entryPath = 'stream';

        $conversation = $this->conversationOn('web', $boundToAPreAuthorizingAgent);

        $this->actingAs($this->fixture()->user, 'api')->postJson(self::MESSAGE_ENDPOINT, [
            'conversation_id' => $conversation->id,
            'content' => 'Tidy up my drafts.',
        ])->assertStatus(201);
        $this->playTurn(0, '', [['id' => 'call_ask', 'name' => 'ask_user', 'arguments' => json_encode($this->askArguments())]]);

        $question = $this->questionIn($conversation);
        $this->assertAskedToProceed($question, 'stream');

        $this->letTheQuestionExpireOnItsAssumption($question);
        $this->assertSame(1, $this->workQueuedResumes());
        $this->assertSame(2, $this->turnCount(), 'the run continued with exactly one model request');
        $this->assertToldItWasAnAssumption($this->toolResultFor($this->streamPayloadMessages(1), 'call_ask'));

        // The continuation asks to delete.
        $this->playTurn(1, '', [['id' => 'call_delete', 'name' => 'execute_operation', 'arguments' => json_encode($this->deletionArguments())]]);
        $this->assertSame(2, $this->turnCount(), 'the run pauses: no further model request');

        $paused = $this->assertWaitsForTheUsersConfirmation($question, 'call_delete');

        // Only the owner's explicit confirmation carries it out, once.
        $this->at(self::CONFIRMED_AT);
        $this->confirm($conversation, $paused)->assertStatus(200);

        $this->assertSame([['method' => 'DELETE', 'path' => '/api/drafts/stale']], $this->deletions, 'confirmed, the deletion runs exactly once');
        $this->assertSame(3, $this->turnCount(), 'the confirmed call continues the run with one model request');
        $this->playTurn(2, self::FINAL_ANSWER);

        $this->assertSame('completed', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'));
        $this->assertCount(1, $this->deletions, 'and never again');
    }

    // ---------------------------------------------------------------
    // Nobody ever confirms
    // ---------------------------------------------------------------

    #[Test]
    #[DataProvider('agents')]
    public function without_the_users_confirmation_the_deletion_never_happens(bool $boundToAPreAuthorizingAgent): void
    {
        $this->scenario = 'expiry_policy_confirmation_never_given';
        $this->entryPath = 'sync';

        $question = $this->proceedToTheDeletionInTheChat($boundToAPreAuthorizingAgent);
        $paused = $this->assertWaitsForTheUsersConfirmation($question, 'call_delete');

        // Hours pass: the confirmation's window and the abandonment
        // threshold both lapse, and every sweep and job runs.
        $this->at(self::MUCH_LATER);
        $this->runBothSweeps();
        $this->workQueuedResumes();
        $this->runBothSweeps();

        $this->assertSame([], $this->deletions, 'nothing ever carried out the deletion on the assumed answer');
        $this->assertSame('abandoned', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'), 'the run never completed without the user: it was abandoned once nobody confirmed');

        // A confirmation arriving after its window does not carry it out either.
        $conversation = Conversation::findOrFail($question->conversation_id);
        $this->confirm($conversation, $paused)->assertStatus(422);
        $this->assertSame([], $this->deletions);
        $this->assertSame(0, $this->turnCount(), 'no further model request');
    }
}
