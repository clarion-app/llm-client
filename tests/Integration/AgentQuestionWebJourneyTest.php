<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\OperationCatalogue;

/**
 * A web conversation, end to end: the agent asks a question mid-task on the
 * streaming path, the user sees it in the conversation and answers through
 * the ordinary message endpoint, and the agent continues with the parsed
 * answer as the `ask_user` call's result.
 *
 * Real wiring throughout — MessageController, the stream handler, the
 * question service, the resumer, the payload builder — with the model
 * scripted at the streaming boundary.
 */
class AgentQuestionWebJourneyTest extends AssembledSystemTestCase
{
    private const MESSAGE_ENDPOINT = '/api/clarion-app/llm-client/message';

    private const QUESTION = 'Which part should I change first?';

    private const CHOICES = ['Backend', 'Frontend', 'Docs'];

    /** @var list<array<string, mixed>> every request the host API received to store a note */
    private array $noteWrites = [];

    private const GOLDEN_THREE_CHOICES = "Which part should I change first?\n"
        ."\n"
        ."1. Backend\n"
        ."2. Frontend\n"
        ."3. Docs\n"
        ."\n"
        .'Reply with the number of your choice, or answer in your own words.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->entryPath = 'stream';
        config(['llm-client.run_trace.enabled' => true]);

        // The host application's catalogue holds one state-changing
        // operation, and its API is scripted so each call to it is counted.
        // McpToolExecutor resolves API tokens via Passport in production; a
        // fixed token stands in here.
        $this->app->singleton(
            McpToolExecutor::class,
            fn () => new McpToolExecutor($this->app->make(McpToolRegistry::class), null, fn ($user) => 'test-token-'.$user->id),
        );
        $catalogue = new OperationCatalogue();
        $catalogue->seed([
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
        ]);
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
        ]);
    }

    protected function tearDown(): void
    {
        (new OperationCatalogue())->reset();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Driving the conversation
    // ---------------------------------------------------------------

    private function reply(string $content): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->fixture()->user, 'api')->postJson(self::MESSAGE_ENDPOINT, [
            'conversation_id' => $this->fixture()->conversation->id,
            'content' => $content,
        ]);
    }

    private function toolCall(string $id, string $name, array $arguments): array
    {
        return ['id' => $id, 'name' => $name, 'arguments' => json_encode($arguments)];
    }

    private function askCall(string $id = 'call_ask', array $arguments = []): array
    {
        return $this->toolCall($id, 'ask_user', $arguments + ['question' => self::QUESTION, 'choices' => self::CHOICES]);
    }

    /**
     * SSE chunks for one streamed model turn: optional assistant text, then
     * each tool call at its own index.
     *
     * @param  list<array{id: string, name: string, arguments: string}>  $calls
     * @return list<string>
     */
    private function turnChunks(string $text, array $calls): array
    {
        $chunks = [];

        foreach (str_split($text, 10) as $piece) {
            if ($piece === '') {
                continue;
            }
            $chunks[] = 'data: '.json_encode(['choices' => [['delta' => ['content' => $piece], 'finish_reason' => null]]])."\n\n";
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

    /** Play the next dispatched streaming request with the given model turn. */
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

    /** @return list<array<string, mixed>> the request's messages as plain arrays */
    private function payloadMessages(int $slot): array
    {
        $payloads = $this->stream()->extractDispatchedJobs()->capturedPayloads();
        $this->assertArrayHasKey($slot, $payloads, "no streaming request #{$slot} was dispatched");

        /** @var CapturedPayload $payload */
        $payload = $payloads[$slot];

        return json_decode(json_encode($payload->messages), true);
    }

    /** The tool message answering $toolCallId in a request's messages. */
    private function toolResultFor(array $messages, string $toolCallId): ?string
    {
        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'tool' && ($message['tool_call_id'] ?? null) === $toolCallId) {
                return is_string($message['content'] ?? null) ? $message['content'] : json_encode($message['content']);
            }
        }

        return null;
    }

    private function pendingQuestion(): AgentQuestion
    {
        $question = AgentQuestion::where('conversation_id', $this->fixture()->conversation->id)->latest('asked_at')->first();
        $this->assertNotNull($question, 'the agent\'s ask must have opened a question');

        return $question;
    }

    /**
     * Ask on the first streamed turn, answer with $reply, and return the
     * resolved question plus the decoded `ask_user` result the continuation
     * sent to the model. The run then completes with a final answer.
     *
     * @return array{question: AgentQuestion, result: array<string, mixed>}
     */
    private function askAndAnswer(array $askArguments, string $reply, string $finalAnswer = 'Done.'): array
    {
        $this->fixture()->build();

        $this->reply('Help me tidy up the project.')->assertStatus(201);
        $this->playTurn(0, '', [$this->askCall('call_ask', $askArguments)]);

        $this->assertSame(1, $this->turnCount(), 'the run waits for the user: no further model request');
        $question = $this->pendingQuestion();
        $this->assertSame(QuestionState::Pending, $question->state);

        $this->reply($reply)->assertStatus(201);

        $this->assertSame(2, $this->turnCount(), 'the answer resumes the run with exactly one continuation request');
        $raw = $this->toolResultFor($this->payloadMessages(1), 'call_ask');
        $this->assertNotNull($raw, 'the continuation answers the ask_user call');

        $this->playTurn(1, $finalAnswer);

        return ['question' => $question->fresh(), 'result' => json_decode($raw, true)];
    }

    private function assertRunCompleted(AgentQuestion $question): void
    {
        $this->assertNotNull($question->run_id);
        $run = DB::table('agent_runs')->where('id', $question->run_id)->first();
        $this->assertSame('completed', $run->end_state, 'the resumed run completes');
        $this->assertFalse((bool) $this->fixture()->conversation->fresh()->is_processing);
        $this->assertNotNull($question->resumed_at);
    }

    // ---------------------------------------------------------------
    // Scenarios
    // ---------------------------------------------------------------

    public function test_three_choice_question_is_shown_and_answered_by_number(): void
    {
        $this->scenario = 'web_question_answered_by_number';
        $fixture = $this->fixture()->build();

        $this->reply('Help me tidy up the project.')->assertStatus(201);
        $this->playTurn(0, '', [$this->askCall()]);

        // The user sees the numbered question in the conversation.
        $question = $this->pendingQuestion();
        $display = Message::findOrFail($question->display_message_id);
        $this->assertSame(self::GOLDEN_THREE_CHOICES, $display->content);

        $listed = $this->actingAs($fixture->user, 'api')
            ->getJson("/api/clarion-app/llm-client/conversation/{$fixture->conversation->id}/message")
            ->assertStatus(200)
            ->json();
        $this->assertContains(self::GOLDEN_THREE_CHOICES, array_column($listed, 'content'), 'the conversation screen lists the question');

        // The run is waiting on the user, and nothing else is asked of the model.
        $this->assertSame(1, $this->turnCount());
        $run = DB::table('agent_runs')->where('id', $question->run_id)->first();
        $this->assertSame('in_progress', $run->end_state);
        $action = DB::table('agent_run_actions')->where('id', $question->action_id)->first();
        $this->assertSame('awaiting_confirmation', $action->outcome);
        $this->assertFalse((bool) $fixture->conversation->fresh()->is_processing);

        // The user replies "2".
        $this->reply('2')->assertStatus(201);

        $this->assertSame(2, $this->turnCount());
        $raw = $this->toolResultFor($this->payloadMessages(1), 'call_ask');
        $this->assertNotNull($raw);
        $this->assertSame([
            'outcome' => 'answered',
            'question_id' => $question->id,
            'answer' => [
                'raw_reply' => '2',
                'is_selection' => true,
                'selected' => [['number' => 2, 'text' => 'Frontend']],
            ],
        ], json_decode($raw, true));

        $this->playTurn(1, 'Starting with the frontend.');

        $question->refresh();
        $this->assertSame(QuestionState::Answered, $question->state);
        $this->assertRunCompleted($question);
        $this->assertSame('success', DB::table('agent_run_actions')->where('id', $question->action_id)->value('outcome'));
    }

    public function test_assistant_text_streamed_with_the_ask_is_shown_once(): void
    {
        $this->scenario = 'web_question_preamble_not_repeated';
        $fixture = $this->fixture()->build();
        $preamble = 'I found three areas that need work.';

        $this->reply('Help me tidy up the project.')->assertStatus(201);
        $this->playTurn(0, $preamble, [$this->askCall()]);

        $question = $this->pendingQuestion();
        $this->assertSame($preamble, Message::findOrFail($question->paused_message_id)->content, 'the streamed text is the paused message');
        $this->assertSame(self::GOLDEN_THREE_CHOICES, Message::findOrFail($question->display_message_id)->content, 'the display message does not repeat it');

        $occurrences = Message::where('conversation_id', $fixture->conversation->id)
            ->get()
            ->filter(fn (Message $m) => str_contains((string) $m->content, $preamble))
            ->count();
        $this->assertSame(1, $occurrences, 'the assistant text appears exactly once in the conversation');

        $this->reply('1')->assertStatus(201);
        $this->playTurn(1, 'Backend first, then.');
        $this->assertRunCompleted($question->fresh());
    }

    public function test_multi_select_reply_selects_every_named_choice(): void
    {
        $this->scenario = 'web_question_multi_select';

        ['question' => $question, 'result' => $result] = $this->askAndAnswer(['allow_multiple' => true], '1, 3');

        $this->assertSame('answered', $result['outcome']);
        $this->assertSame('1, 3', $result['answer']['raw_reply']);
        $this->assertTrue($result['answer']['is_selection']);
        $this->assertSame([['number' => 1, 'text' => 'Backend'], ['number' => 3, 'text' => 'Docs']], $result['answer']['selected']);
        $this->assertRunCompleted($question);
    }

    public function test_free_text_reply_to_a_choice_question_reaches_the_agent_verbatim(): void
    {
        $this->scenario = 'web_question_free_text_reply';
        $reply = 'none of these — use a fourth approach';

        ['question' => $question, 'result' => $result] = $this->askAndAnswer([], $reply);

        $this->assertSame([
            'outcome' => 'answered',
            'question_id' => $question->id,
            'answer' => ['raw_reply' => $reply, 'is_selection' => false, 'selected' => []],
        ], $result);
        $this->assertRunCompleted($question);
    }

    public function test_question_without_choices_takes_a_free_text_answer(): void
    {
        $this->scenario = 'web_question_without_choices';
        $this->fixture()->build();

        $this->reply('Book the meeting.')->assertStatus(201);
        $this->playTurn(0, '', [$this->toolCall('call_ask', 'ask_user', ['question' => 'What time works for you?'])]);

        $question = $this->pendingQuestion();
        $this->assertSame(
            "What time works for you?\n\nReply with your answer.",
            Message::findOrFail($question->display_message_id)->content,
        );

        $this->reply('2')->assertStatus(201);
        $result = json_decode((string) $this->toolResultFor($this->payloadMessages(1), 'call_ask'), true);

        $this->assertSame(
            ['raw_reply' => '2', 'is_selection' => false, 'selected' => []],
            $result['answer'] ?? null,
            'with no choices, a number is just text',
        );

        $this->playTurn(1, 'Booked.');
        $this->assertRunCompleted($question->fresh());
    }

    #[Test]
    public function earlier_action_not_repeated(): void
    {
        $this->scenario = 'web_question_earlier_action_not_repeated';
        $this->fixture()->build();

        $this->reply('Save a note, then ask me which part to change.')->assertStatus(201);
        $this->playTurn(0, '', [
            $this->toolCall('call_note', 'execute_operation', [
                'operationId' => 'storeNote',
                'parameters' => ['body' => ['text' => 'PRE-ASK-NOTE-5521']],
            ]),
            $this->askCall(),
        ]);

        $this->assertCount(1, $this->noteWrites, 'the state-changing call ran before the pause');
        $question = $this->pendingQuestion();

        $this->reply('3')->assertStatus(201);

        $noteResult = $this->toolResultFor($this->payloadMessages(1), 'call_note');
        $this->assertNotNull($noteResult, 'the earlier call\'s real result reaches the model');
        $this->assertStringNotContainsString('Not executed', $noteResult);
        $this->assertStringContainsString('stored', $noteResult);

        $this->playTurn(1, 'Docs it is.');

        $this->assertCount(1, $this->noteWrites, 'the call executed before the pause runs exactly once across pause and resume');
        $this->assertRunCompleted($question->fresh());
    }

    #[Test]
    public function continuation_payload_is_protocol_valid(): void
    {
        $this->scenario = 'web_question_continuation_protocol_valid';
        $fixture = $this->fixture()->build();

        $this->reply('Help me tidy up the project.')->assertStatus(201);
        $this->playTurn(0, '', [
            $this->toolCall('call_search', 'search_operations', ['query' => 'list projects']),
            $this->askCall(),
            $this->toolCall('call_later', 'search_operations', ['query' => 'list tasks']),
        ]);
        $question = $this->pendingQuestion();

        $this->reply('2')->assertStatus(201);
        $messages = $this->payloadMessages(1);

        // Every tool call is answered exactly once, by tool messages that
        // directly follow the assistant turn that made the calls.
        $answeredAnywhere = [];
        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'tool') {
                $answeredAnywhere[] = $message['tool_call_id'] ?? null;
            }
        }
        foreach ($messages as $index => $message) {
            if (($message['role'] ?? null) !== 'assistant' || empty($message['tool_calls'])) {
                continue;
            }
            $callIds = array_map(fn (array $call) => $call['id'], $message['tool_calls']);
            $following = [];
            for ($i = $index + 1; $i < count($messages) && ($messages[$i]['role'] ?? null) === 'tool'; $i++) {
                $following[] = $messages[$i]['tool_call_id'] ?? null;
            }
            $this->assertSame($callIds, $following, 'each tool call is answered, in order, right after its turn');
            foreach ($callIds as $id) {
                $this->assertSame(1, count(array_keys($answeredAnywhere, $id, true)), "tool call {$id} is answered exactly once");
            }
        }
        $this->assertSame(['call_search', 'call_ask', 'call_later'], array_values(array_filter(
            $answeredAnywhere,
            fn ($id) => in_array($id, ['call_search', 'call_ask', 'call_later'], true),
        )));

        // None of the conversation-only question messages reach the model:
        // the question is not repeated, and the answer arrives only as the
        // ask_user result, never as a user turn.
        foreach ($messages as $message) {
            $content = is_string($message['content'] ?? null) ? $message['content'] : '';
            $this->assertNotSame(self::GOLDEN_THREE_CHOICES, $content, 'the display message is not part of the payload');
            if (($message['role'] ?? null) === 'user') {
                $this->assertNotSame('2', trim($content), 'the answer is not sent as a user message');
            }
        }

        $this->playTurn(1, 'Frontend first.');
        $this->assertRunCompleted($question->fresh());
    }
}
