<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\QuestionRenderer;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerStatus;
use ClarionApp\LlmClient\ValueObjects\PresentationMode;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\RecordingRichChannelDelivery;

/**
 * One question, four ways to answer it, one meaning. The same scripted
 * agent asks the same question in four conversations of the same user and
 * each is answered differently:
 *
 *  - web form      — a web conversation (streaming path), `POST question/{id}/answer`;
 *  - web typed     — a web conversation (streaming path), a reply to `POST message`;
 *  - messaging tap — a Telegram conversation (synchronous path), a tapped option
 *                    through the interactive-answer contract;
 *  - messaging typed — a Telegram conversation (synchronous path), a reply to `POST agent`.
 *
 * What the agent asked is byte-identical in all four and carries nothing
 * about where or how it is shown; what the agent receives back means the
 * same in all four; and every channel shows the question's text and every
 * choice, in order, under its own number.
 *
 * Real wiring throughout — both controllers that take typed replies, the
 * answer endpoint, the stream handler and the synchronous loop, the
 * question services, the presenter, the resumer and its job — with the
 * model scripted at both boundaries and a recording adapter that presents
 * questions registered for the `telegram` channel.
 */
class QuestionAnswerEquivalenceJourneyTest extends AssembledSystemTestCase
{
    private const MESSAGE_ENDPOINT = '/api/clarion-app/llm-client/message';

    private const AGENT_ENDPOINT = '/api/clarion-app/llm-client/agent';

    private const QUESTION = 'Which part should I change first?';

    private const CHOICES = ['Backend', 'Frontend', 'Docs'];

    private const WAYS = ['web form', 'web typed', 'messaging tap', 'messaging typed'];

    private RecordingRichChannelDelivery $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        // Both entry paths are driven in every scenario; the web ways stream,
        // the messaging ways run synchronously.
        $this->entryPath = 'sync';
        config(['llm-client.run_trace.enabled' => true]);

        Event::fake([
            NewConversationMessageEvent::class,
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            ToolExecutionEvent::class,
        ]);
        // A tap's resume is queued; the harness adapter works it after
        // submit() returns, as a queue worker would.
        Queue::fake([AgentStreamStepJob::class, ResumeAfterQuestionJob::class]);

        $this->telegram = new RecordingRichChannelDelivery('telegram');
        $this->app->make(ConversationChannelRegistry::class)->register($this->telegram);
    }

    // ---------------------------------------------------------------
    // Conversations
    // ---------------------------------------------------------------

    /** @return array<string, Conversation> one fresh conversation per way, all the same user's */
    private function conversations(): array
    {
        $fixture = $this->fixture()->build();
        $conversations = ['web form' => $fixture->conversation->fresh()];

        foreach (['web typed' => 'web', 'messaging tap' => 'telegram', 'messaging typed' => 'telegram'] as $way => $channel) {
            $conversations[$way] = Conversation::create([
                'id' => (string) Str::uuid(),
                'user_id' => $fixture->user->id,
                'server_id' => $fixture->server->id,
                'model' => $fixture->conversation->model,
                'title' => "Conversation answered by {$way}",
            ]);
            if ($channel !== 'web') {
                $conversations[$way]->update(['channel' => $channel]);
            }
        }

        $this->assertSame('web', (string) ($conversations['web form']->channel ?? 'web'));
        $this->assertSame('web', (string) ($conversations['web typed']->fresh()->channel ?? 'web'));

        return $conversations;
    }

    private function userId(): string
    {
        return (string) $this->fixture()->user->id;
    }

    private function postMessage(Conversation $conversation, string $content): TestResponse
    {
        return $this->actingAs($this->fixture()->user, 'api')->postJson(self::MESSAGE_ENDPOINT, [
            'conversation_id' => $conversation->id,
            'content' => $content,
        ]);
    }

    private function postAgent(Conversation $conversation, string $message): TestResponse
    {
        return $this->actingAs($this->fixture()->user, 'api')->postJson(self::AGENT_ENDPOINT, [
            'message' => $message,
            'channel' => 'telegram',
            'conversation_id' => $conversation->id,
        ]);
    }

    private function submitForm(AgentQuestion $question, array $body): TestResponse
    {
        return $this->actingAs($this->fixture()->user, 'api')
            ->postJson("/api/clarion-app/llm-client/question/{$question->id}/answer", $body);
    }

    private function pendingIn(Conversation $conversation): AgentQuestion
    {
        $question = AgentQuestion::where('conversation_id', $conversation->id)
            ->where('state', QuestionState::Pending->value)
            ->first();
        $this->assertNotNull($question, 'the agent\'s ask opened a question');

        return $question;
    }

    // ---------------------------------------------------------------
    // The streaming boundary (web ways)
    // ---------------------------------------------------------------

    /** @return list<string> SSE chunks for one streamed model turn */
    private function turnChunks(string $text, array $calls): array
    {
        $chunks = [];
        foreach (str_split($text, 10) as $piece) {
            if ($piece !== '') {
                $chunks[] = 'data: '.json_encode(['choices' => [['delta' => ['content' => $piece], 'finish_reason' => null]]])."\n\n";
            }
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

    private function streamPayload(int $slot): CapturedPayload
    {
        $payloads = $this->stream()->extractDispatchedJobs()->capturedPayloads();
        $this->assertArrayHasKey($slot, $payloads, "no streaming request #{$slot} was dispatched");

        return $payloads[$slot];
    }

    // ---------------------------------------------------------------
    // Running one way end to end
    // ---------------------------------------------------------------

    /**
     * Ask $askArguments in the way's conversation, answer it that way with
     * $answer, let the run finish, and return everything to compare.
     *
     * @param  list<int>|string  $answer  a selection, or free text
     * @return array{ask_call: string, ask_tool: string, result: array<string, mixed>, question: AgentQuestion, shown: array<string, mixed>}
     */
    private function runWay(string $way, Conversation $conversation, array $askArguments, array|string $answer): array
    {
        return str_starts_with($way, 'web')
            ? $this->runWebWay($way, $conversation, $askArguments, $answer)
            : $this->runMessagingWay($way, $conversation, $askArguments, $answer);
    }

    private function runWebWay(string $way, Conversation $conversation, array $askArguments, array|string $answer): array
    {
        $askSlot = $this->turnCount();
        $this->postMessage($conversation, 'Help me tidy up the project.')->assertStatus(201);
        $asking = $this->streamPayload($askSlot);
        $this->playTurn($askSlot, '', [['id' => 'call_ask', 'name' => 'ask_user', 'arguments' => json_encode($askArguments)]]);
        $this->assertSame($askSlot + 1, $this->turnCount(), "{$way}: the run waits for the user");

        $question = $this->pendingIn($conversation);
        $shown = $this->shownOnTheWeb($question);

        $continuationSlot = $this->turnCount();
        $response = $way === 'web form'
            ? $this->submitForm($question, is_array($answer) ? ['selected' => $answer] : ['text' => $answer])
            : $this->postMessage($conversation, is_array($answer) ? implode(', ', $answer) : $answer);
        $response->assertStatus(201);
        $this->assertSame($continuationSlot + 1, $this->turnCount(), "{$way}: the answer resumes the run with exactly one request");

        $continuation = $this->streamPayload($continuationSlot);
        $this->playTurn($continuationSlot, 'Done.');

        return $this->collected($way, $question, $asking, $continuation, $shown);
    }

    private function runMessagingWay(string $way, Conversation $conversation, array $askArguments, array|string $answer): array
    {
        $this->script()->toolRequest('ask_user', $askArguments)->finalAnswer('Done.');
        $before = count($this->transport()->capturedChatPayloads());

        $this->postAgent($conversation, 'Help me tidy up the project.')->assertStatus(202);
        $this->assertCount($before + 1, $this->transport()->capturedChatPayloads(), "{$way}: the run waits for the user");
        $question = $this->pendingIn($conversation);
        $shown = $this->shownInTheChat($question);

        if ($way === 'messaging tap') {
            $outcome = $this->telegram->tap($question->id, $this->userId(), $answer);
            $this->assertSame(InteractiveAnswerStatus::Answered, $outcome->status, "{$way}: answered");
            $this->assertSame(['Done.'], $this->telegram->textsFor($conversation->id), "{$way}: the reply is pushed to the chat");
        } else {
            $typed = is_array($answer) ? implode(' ', array_reverse($answer)) : $answer;
            $this->postAgent($conversation, $typed)->assertStatus(200)->assertJson(['status' => 'completed', 'content' => 'Done.']);
        }

        $payloads = $this->transport()->capturedChatPayloads();
        $this->assertCount($before + 2, $payloads, "{$way}: the answer resumes the run with exactly one request");

        return $this->collected($way, $question, $payloads[$before], $payloads[$before + 1], $shown);
    }

    private function collected(string $way, AgentQuestion $question, CapturedPayload $asking, CapturedPayload $continuation, array $shown): array
    {
        $question = $question->fresh();
        $this->assertSame(QuestionState::Answered, $question->state, "{$way}: answered");
        $this->assertNotNull($question->resumed_at, "{$way}: resumed");
        $this->assertSame('completed', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'), "{$way}: the run completes");
        $this->assertSame(match ($way) {
            'web form' => AnswerMethod::WebForm,
            'messaging tap' => AnswerMethod::MessagingOption,
            default => AnswerMethod::TypedReply,
        }, $question->answer_method, "{$way}: recorded with its own method");

        $result = $this->askResult($continuation, $question->tool_call_id, $way);
        $this->assertSame($question->id, $result['question_id'] ?? null, "{$way}: the result names its own question");
        unset($result['question_id']);

        return [
            'ask_call' => $this->askCall($continuation, $question->tool_call_id, $way),
            'ask_tool' => $this->askTool($asking, $way),
            'result' => $result,
            'question' => $question,
            'shown' => $shown,
        ];
    }

    // ---------------------------------------------------------------
    // Reading the model boundary
    // ---------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function plainMessages(CapturedPayload $payload): array
    {
        return json_decode(json_encode($payload->messages), true);
    }

    /**
     * The agent's `ask_user` call as the model sees it replayed in the
     * continuation — its function name and arguments, byte for byte. The
     * call id is generated per conversation and is left out.
     */
    private function askCall(CapturedPayload $continuation, string $toolCallId, string $way): string
    {
        foreach ($this->plainMessages($continuation) as $message) {
            foreach ($message['tool_calls'] ?? [] as $call) {
                if (($call['id'] ?? null) === $toolCallId) {
                    $this->assertSame('assistant', $message['role'] ?? null);
                    unset($call['id']);

                    return json_encode($call, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
        }
        $this->fail("{$way}: the continuation does not replay the ask_user call");
    }

    /** The `ask_user` tool the agent was offered, byte for byte. */
    private function askTool(CapturedPayload $asking, string $way): string
    {
        foreach (json_decode(json_encode($asking->tools), true) as $tool) {
            if (($tool['function']['name'] ?? null) === 'ask_user') {
                return json_encode($tool, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        $this->fail("{$way}: the agent was not offered ask_user");
    }

    /** @return array<string, mixed> the decoded `ask_user` tool result the continuation sent the model */
    private function askResult(CapturedPayload $continuation, string $toolCallId, string $way): array
    {
        foreach ($this->plainMessages($continuation) as $message) {
            if (($message['role'] ?? null) === 'tool' && ($message['tool_call_id'] ?? null) === $toolCallId) {
                $content = is_string($message['content'] ?? null) ? $message['content'] : json_encode($message['content']);

                return json_decode($content, true);
            }
        }
        $this->fail("{$way}: the continuation does not answer the ask_user call");
    }

    // ---------------------------------------------------------------
    // What each channel showed
    // ---------------------------------------------------------------

    /** @return array{question: string, choices: list<array{number: int, text: string}>, allow_multiple: bool} */
    private function shownOnTheWeb(AgentQuestion $question): array
    {
        $list = $this->actingAs($this->fixture()->user, 'api')
            ->getJson("/api/clarion-app/llm-client/conversation/{$question->conversation_id}/message")
            ->assertStatus(200)
            ->json();

        foreach ($list as $message) {
            if (($message['id'] ?? null) === $question->display_message_id) {
                $this->assertArrayHasKey('question', $message, 'the screen receives the question as a form');
                $view = $message['question'];
                $this->assertSame('pending', $view['state']);
                $this->assertSame(PresentationMode::Rich, $question->fresh()->presentation);

                return ['question' => $view['question'], 'choices' => $view['choices'], 'allow_multiple' => $view['allow_multiple']];
            }
        }
        $this->fail('the question\'s display message is not listed');
    }

    /** @return array{question: string, choices: list<array{number: int, text: string}>, allow_multiple: bool} */
    private function shownInTheChat(AgentQuestion $question): array
    {
        $presentations = $this->telegram->presentationsFor($question->conversation_id);
        $this->assertCount(1, $presentations, 'presented once, with options');
        $presentation = $presentations[0];
        $this->assertSame($question->id, $presentation->questionId);
        $this->assertSame([], $this->telegram->textsFor($question->conversation_id), 'nothing was sent as plain text');

        $choices = [];
        foreach ($presentation->options as $option) {
            $choices[] = ['number' => $option->number, 'text' => $option->text];
            $this->assertSame("{$option->number}. {$option->text}", $option->label, 'no label is shortened at these lengths');
        }

        // The message body lists every choice, numbered, in order.
        $offset = 0;
        foreach ($choices as $choice) {
            $position = strpos($presentation->bodyText, "{$choice['number']}. {$choice['text']}", $offset);
            $this->assertNotFalse($position, "the body shows choice {$choice['number']} in order");
            $offset = $position;
        }
        $question->refresh();
        $this->assertSame($this->app->make(QuestionRenderer::class)->renderWithOptions($question, $question->preamble), $presentation->bodyText);
        $this->assertSame($this->app->make(QuestionRenderer::class)->render($question, $question->preamble), $presentation->plainText);

        return ['question' => $presentation->question, 'choices' => $choices, 'allow_multiple' => $presentation->allowMultiple];
    }

    // ---------------------------------------------------------------
    // Assertions across the four ways
    // ---------------------------------------------------------------

    /**
     * @param  array<string, array<string, mixed>>  $runs
     */
    private function assertAskedIdenticallyEverywhere(array $runs, array $askArguments): void
    {
        $reference = $runs['web form'];

        foreach ($runs as $way => $run) {
            $this->assertSame($reference['ask_call'], $run['ask_call'], "the ask_user call is byte-identical: web form vs {$way}");
            $this->assertSame($reference['ask_tool'], $run['ask_tool'], "the ask_user tool is byte-identical: web form vs {$way}");
        }

        // Nothing about the channel or how the question is shown is in what
        // the agent asked, or in what it is offered to ask with.
        $call = json_decode($reference['ask_call'], true);
        $this->assertSame('ask_user', $call['function']['name']);
        $this->assertSame($askArguments, json_decode($call['function']['arguments'], true), 'exactly what the agent asked, nothing added');
        $tool = json_decode($reference['ask_tool'], true);
        $this->assertSame(
            ['question', 'choices', 'allow_multiple', 'if_unanswered', 'assumed_choices', 'assumed_text', 'consequential', 'wait_minutes'],
            array_keys($tool['function']['parameters']['properties']),
        );
        foreach (['telegram', 'channel', 'button', 'presentation', 'keyboard', 'web_form'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $reference['ask_call']);
            $this->assertStringNotContainsStringIgnoringCase($word, $reference['ask_tool']);
        }

        // The stored question is the same in every conversation but for where it lives.
        $asked = fn (AgentQuestion $q) => [$q->question, $q->choiceList(), (bool) $q->allow_multiple];
        foreach ($runs as $way => $run) {
            $this->assertSame($asked($reference['question']), $asked($run['question']), "stored identically: web form vs {$way}");
        }
        $this->assertSame('web', $runs['web typed']['question']->channel);
        $this->assertSame('telegram', $runs['messaging tap']['question']->channel);
        $this->assertSame('sync', $runs['messaging typed']['question']->resume_mode);
        $this->assertSame('stream', $runs['web form']['question']->resume_mode);
    }

    /**
     * @param  array<string, array<string, mixed>>  $runs
     */
    private function assertShownFaithfullyEverywhere(array $runs, array $askArguments): void
    {
        $expected = [
            'question' => $askArguments['question'],
            'choices' => array_map(
                fn (int $i, string $text) => ['number' => $i + 1, 'text' => $text],
                array_keys($askArguments['choices']),
                $askArguments['choices'],
            ),
            'allow_multiple' => (bool) ($askArguments['allow_multiple'] ?? false),
        ];

        foreach ($runs as $way => $run) {
            $this->assertSame($expected, $run['shown'], "{$way}: the question, every choice, its order and its number are shown exactly");
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function answerEveryWay(array $askArguments, array|string $answer): array
    {
        $runs = [];
        foreach ($this->conversations() as $way => $conversation) {
            $runs[$way] = $this->runWay($way, $conversation, $askArguments, $answer);
        }
        $this->assertSame(self::WAYS, array_keys($runs));

        return $runs;
    }

    // ---------------------------------------------------------------
    // Scenarios
    // ---------------------------------------------------------------

    #[Test]
    public function choosing_the_second_choice_means_the_same_however_and_wherever_it_is_chosen(): void
    {
        $this->scenario = 'question_equivalence_single';
        $ask = ['question' => self::QUESTION, 'choices' => self::CHOICES];

        $runs = $this->answerEveryWay($ask, [2]);

        $this->assertAskedIdenticallyEverywhere($runs, $ask);
        $this->assertShownFaithfullyEverywhere($runs, $ask);

        $expected = [
            'outcome' => 'answered',
            'answer' => [
                'raw_reply' => '2',
                'is_selection' => true,
                'selected' => [['number' => 2, 'text' => 'Frontend']],
            ],
        ];
        foreach ($runs as $way => $run) {
            $this->assertSame($expected, $run['result'], "{$way}: the agent receives choice 2");
            $this->assertSame([['number' => 2, 'text' => 'Frontend']], $run['question']->answer_selected);
        }
    }

    #[Test]
    public function choosing_several_choices_means_the_same_however_and_wherever_they_are_chosen(): void
    {
        $this->scenario = 'question_equivalence_multi';
        $ask = ['question' => 'Which parts should I change?', 'choices' => self::CHOICES, 'allow_multiple' => true];

        // Controls report [3, 1]; the messaging typed reply is "1 3" reversed ("3 1").
        $runs = $this->answerEveryWay($ask, [1, 3]);

        $this->assertAskedIdenticallyEverywhere($runs, $ask);
        $this->assertShownFaithfullyEverywhere($runs, $ask);

        $selected = [['number' => 1, 'text' => 'Backend'], ['number' => 3, 'text' => 'Docs']];
        foreach ($runs as $way => $run) {
            $this->assertSame('answered', $run['result']['outcome']);
            $this->assertTrue($run['result']['answer']['is_selection'], "{$way}: a selection");
            $this->assertSame($selected, $run['result']['answer']['selected'], "{$way}: the same choices, numbers and texts");
        }
        // Only the raw reply may differ — and it is what the user (or the
        // control, canonically) sent.
        $this->assertSame('1, 3', $runs['web form']['result']['answer']['raw_reply']);
        $this->assertSame('1, 3', $runs['web typed']['result']['answer']['raw_reply']);
        $this->assertSame('1, 3', $runs['messaging tap']['result']['answer']['raw_reply']);
        $this->assertSame('3 1', $runs['messaging typed']['result']['answer']['raw_reply']);
    }

    #[Test]
    public function an_answer_in_the_users_own_words_means_the_same_however_and_wherever_it_is_given(): void
    {
        $this->scenario = 'question_equivalence_free_text';
        $ask = ['question' => self::QUESTION, 'choices' => self::CHOICES];
        $text = 'none of these — use a fourth approach';

        $runs = $this->answerEveryWay($ask, $text);

        $this->assertAskedIdenticallyEverywhere($runs, $ask);
        $this->assertShownFaithfullyEverywhere($runs, $ask);

        foreach ($runs as $way => $run) {
            $this->assertSame([
                'outcome' => 'answered',
                'answer' => ['raw_reply' => $text, 'is_selection' => false, 'selected' => []],
            ], $run['result'], "{$way}: a free-text answer carrying exactly that text");
        }

        $answers = Message::all()
            ->filter(fn (Message $m) => ($m->tool_data['kind'] ?? null) === 'agent_question_answer')
            ->map(fn (Message $m) => $m->content)
            ->values()
            ->all();
        $this->assertSame(array_fill(0, 4, $text), $answers, 'each conversation stores the same answer text');
    }

    #[Test]
    public function own_words_with_surrounding_whitespace_mean_the_same_from_the_form_as_typed(): void
    {
        $this->scenario = 'question_equivalence_padded_free_text';
        $ask = ['question' => self::QUESTION, 'choices' => self::CHOICES];
        $padded = "  none of these — use a fourth approach \n";

        // The ways a person can type words: a tap carries no text.
        $conversations = $this->conversations();
        $runs = [];
        foreach (['web form', 'web typed', 'messaging typed'] as $way) {
            $runs[$way] = $this->runWay($way, $conversations[$way], $ask, $padded);
        }

        // Typed replies reach the question through the host's request
        // normalisation; the form's words must arrive the same way.
        $reference = $runs['web typed']['result'];
        $this->assertFalse($reference['answer']['is_selection']);
        foreach ($runs as $way => $run) {
            $this->assertSame($reference, $run['result'], "{$way}: the same words reach the agent as typed in the message box");
            $this->assertSame($reference['answer']['raw_reply'], $run['question']->answer_raw, "{$way}: the same words are stored");
        }
    }

    #[Test]
    public function free_text_naming_a_choice_selects_it_however_and_wherever_it_is_given(): void
    {
        $this->scenario = 'question_equivalence_free_text_number';
        $ask = ['question' => self::QUESTION, 'choices' => self::CHOICES];

        // "2" typed into the form's own-words field, or sent as words by the
        // tap path, is the same "2" a typed reply is.
        $runs = $this->answerEveryWay($ask, '2');

        foreach ($runs as $way => $run) {
            $this->assertSame([
                'outcome' => 'answered',
                'answer' => ['raw_reply' => '2', 'is_selection' => true, 'selected' => [['number' => 2, 'text' => 'Frontend']]],
            ], $run['result'], "{$way}: \"2\" in words selects choice 2");
        }
    }
}
