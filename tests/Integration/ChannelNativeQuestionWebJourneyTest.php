<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\CapturedPayload;

/**
 * A web conversation answered through the form, end to end: the agent asks
 * mid-task on the streaming path, the conversation screen's message list
 * carries the question as server-held state, the user submits a choice (or
 * several, or their own words) to `POST question/{id}/answer`, and the
 * agent continues with exactly the `ask_user` result a typed reply of the
 * same answer produces.
 *
 * Real wiring throughout — MessageController, QuestionController, the
 * stream handler, the question services, the resumer, the payload builder —
 * with the model scripted at the streaming boundary.
 */
class ChannelNativeQuestionWebJourneyTest extends AssembledSystemTestCase
{
    private const MESSAGE_ENDPOINT = '/api/clarion-app/llm-client/message';

    private const QUESTION = 'Which part should I change first?';

    private const CHOICES = ['Backend', 'Frontend', 'Docs'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->entryPath = 'stream';
        config(['llm-client.run_trace.enabled' => true]);

        Event::fake([
            NewConversationMessageEvent::class,
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            ToolExecutionEvent::class,
        ]);
    }

    // ---------------------------------------------------------------
    // Driving the conversation
    // ---------------------------------------------------------------

    private function reply(Conversation $conversation, string $content): TestResponse
    {
        return $this->actingAs($this->fixture()->user, 'api')->postJson(self::MESSAGE_ENDPOINT, [
            'conversation_id' => $conversation->id,
            'content' => $content,
        ]);
    }

    private function submitForm(AgentQuestion $question, array $body): TestResponse
    {
        return $this->actingAs($this->fixture()->user, 'api')
            ->postJson("/api/clarion-app/llm-client/question/{$question->id}/answer", $body);
    }

    /** @return list<array<string, mixed>> the conversation screen's message list */
    private function messageList(Conversation $conversation): array
    {
        return $this->actingAs($this->fixture()->user, 'api')
            ->getJson("/api/clarion-app/llm-client/conversation/{$conversation->id}/message")
            ->assertStatus(200)
            ->json();
    }

    /** @return array<string, mixed> the question view on $question's display message */
    private function viewOf(AgentQuestion $question): array
    {
        foreach ($this->messageList(Conversation::findOrFail($question->conversation_id)) as $message) {
            if (($message['id'] ?? null) === $question->display_message_id) {
                $this->assertArrayHasKey('question', $message, 'the question\'s display message carries its view');

                return $message['question'];
            }
        }
        $this->fail('the question\'s display message is not listed');
    }

    /** A second conversation of the same user, to compare a typed reply against. */
    private function twinConversation(): Conversation
    {
        $fixture = $this->fixture();

        return Conversation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $fixture->user->id,
            'server_id' => $fixture->server->id,
            'model' => $fixture->conversation->model,
            'title' => 'Twin Conversation',
        ]);
    }

    private function askCall(array $arguments): array
    {
        return ['id' => 'call_ask', 'name' => 'ask_user', 'arguments' => json_encode($arguments)];
    }

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

    /** The decoded `ask_user` result in streaming request #$slot. */
    private function askResult(int $slot): array
    {
        $payloads = $this->stream()->extractDispatchedJobs()->capturedPayloads();
        $this->assertArrayHasKey($slot, $payloads, "no streaming request #{$slot} was dispatched");
        /** @var CapturedPayload $payload */
        $payload = $payloads[$slot];

        foreach (json_decode(json_encode($payload->messages), true) as $message) {
            if (($message['role'] ?? null) === 'tool' && ($message['tool_call_id'] ?? null) === 'call_ask') {
                $content = is_string($message['content'] ?? null) ? $message['content'] : json_encode($message['content']);

                return json_decode($content, true);
            }
        }
        $this->fail("streaming request #{$slot} does not answer the ask_user call");
    }

    /** Start a turn in $conversation whose model asks $askArguments; return the pending question. */
    private function ask(Conversation $conversation, array $askArguments): AgentQuestion
    {
        $turnsBefore = $this->turnCount();
        $this->reply($conversation, 'Help me tidy up the project.')->assertStatus(201);
        $this->playTurn($turnsBefore, '', [$this->askCall($askArguments)]);
        $this->assertSame($turnsBefore + 1, $this->turnCount(), 'the run waits for the user: no further model request');

        $question = AgentQuestion::where('conversation_id', $conversation->id)->where('state', QuestionState::Pending->value)->first();
        $this->assertNotNull($question, 'the agent\'s ask opened a question');

        return $question;
    }

    /**
     * Answer $question with $answer (a closure sending the answer), check it
     * resumed the run with exactly one request, and finish the run. Returns
     * the `ask_user` result the model received.
     */
    private function answerAndContinue(AgentQuestion $question, \Closure $answer): array
    {
        $turnsBefore = $this->turnCount();
        $answer($question)->assertStatus(201);
        $this->assertSame($turnsBefore + 1, $this->turnCount(), 'the answer resumes the run with exactly one continuation request');

        $result = $this->askResult($turnsBefore);
        $this->playTurn($turnsBefore, 'Done.');
        $this->assertRunCompleted($question->fresh());

        return $result;
    }

    /**
     * The result the agent receives for a form submission of $form in one
     * conversation and for a typed $typed in a twin conversation, asked the
     * same question. The question ids necessarily differ; everything else
     * must not.
     *
     * @return array{form: array<string, mixed>, typed: array<string, mixed>, question: AgentQuestion}
     */
    private function formAgainstTyped(array $askArguments, array $form, string $typed): array
    {
        $this->fixture()->build();

        $formQuestion = $this->ask($this->fixture()->conversation, $askArguments);
        $formResult = $this->answerAndContinue($formQuestion, fn (AgentQuestion $q) => $this->submitForm($q, $form));

        $twin = $this->twinConversation();
        $typedQuestion = $this->ask($twin, $askArguments);
        $typedResult = $this->answerAndContinue($typedQuestion, fn (AgentQuestion $q) => $this->reply($twin, $typed));

        $this->assertSame($formQuestion->id, $formResult['question_id'] ?? null);
        $this->assertSame($typedQuestion->id, $typedResult['question_id'] ?? null);
        unset($formResult['question_id'], $typedResult['question_id']);

        $this->assertSame(AnswerMethod::WebForm, $formQuestion->fresh()->answer_method);
        $this->assertSame(AnswerMethod::TypedReply, $typedQuestion->fresh()->answer_method);

        return ['form' => $formResult, 'typed' => $typedResult, 'question' => $formQuestion->fresh()];
    }

    private function assertRunCompleted(AgentQuestion $question): void
    {
        $this->assertSame(QuestionState::Answered, $question->state);
        $this->assertNotNull($question->resumed_at);
        $this->assertSame('completed', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'), 'the resumed run completes');
        $this->assertFalse((bool) Conversation::findOrFail($question->conversation_id)->is_processing);
    }

    // ---------------------------------------------------------------
    // Scenarios
    // ---------------------------------------------------------------

    #[Test]
    public function the_conversation_screen_receives_the_pending_question_as_a_form(): void
    {
        $this->scenario = 'web_form_question_listed';
        $this->fixture()->build();

        $question = $this->ask($this->fixture()->conversation, ['question' => self::QUESTION, 'choices' => self::CHOICES]);
        $view = $this->viewOf($question);

        $this->assertSame($question->id, $view['id']);
        $this->assertSame(self::QUESTION, $view['question']);
        $this->assertSame([
            ['number' => 1, 'text' => 'Backend'],
            ['number' => 2, 'text' => 'Frontend'],
            ['number' => 3, 'text' => 'Docs'],
        ], $view['choices']);
        $this->assertFalse($view['allow_multiple']);
        $this->assertSame('pending', $view['state']);
        $this->assertNull($view['answer']);

        $this->answerAndContinue($question, fn (AgentQuestion $q) => $this->submitForm($q, ['selected' => [1]]));
    }

    #[Test]
    public function a_chosen_option_reaches_the_agent_exactly_as_a_typed_number(): void
    {
        $this->scenario = 'web_form_single_selection';

        ['form' => $form, 'typed' => $typed] = $this->formAgainstTyped(
            ['question' => self::QUESTION, 'choices' => self::CHOICES],
            ['selected' => [2]],
            '2',
        );

        $this->assertSame([
            'outcome' => 'answered',
            'answer' => [
                'raw_reply' => '2',
                'is_selection' => true,
                'selected' => [['number' => 2, 'text' => 'Frontend']],
            ],
        ], $form);
        $this->assertSame($typed, $form, 'the form answer and the typed reply mean the same to the agent');
    }

    #[Test]
    public function several_chosen_options_reach_the_agent_exactly_as_a_typed_list(): void
    {
        $this->scenario = 'web_form_multi_selection';

        ['form' => $form, 'typed' => $typed] = $this->formAgainstTyped(
            ['question' => self::QUESTION, 'choices' => self::CHOICES, 'allow_multiple' => true],
            ['selected' => [1, 3]],
            '1, 3',
        );

        $this->assertTrue($form['answer']['is_selection']);
        $this->assertSame([['number' => 1, 'text' => 'Backend'], ['number' => 3, 'text' => 'Docs']], $form['answer']['selected']);
        $this->assertSame($typed, $form);
    }

    #[Test]
    public function an_answer_in_the_users_own_words_reaches_the_agent_exactly_as_typed_text(): void
    {
        $this->scenario = 'web_form_free_text';
        $text = 'none of these — use a fourth approach';

        ['form' => $form, 'typed' => $typed] = $this->formAgainstTyped(
            ['question' => self::QUESTION, 'choices' => self::CHOICES],
            ['text' => $text],
            $text,
        );

        $this->assertSame(['raw_reply' => $text, 'is_selection' => false, 'selected' => []], $form['answer']);
        $this->assertSame($typed, $form);
    }

    #[Test]
    public function a_question_without_choices_is_a_free_text_form(): void
    {
        $this->scenario = 'web_form_without_choices';
        $this->fixture()->build();

        $question = $this->ask($this->fixture()->conversation, ['question' => 'What time works for you?']);
        $view = $this->viewOf($question);

        $this->assertSame('What time works for you?', $view['question']);
        $this->assertSame([], $view['choices']);

        $result = $this->answerAndContinue($question, fn (AgentQuestion $q) => $this->submitForm($q, ['text' => 'Tuesday at ten']));
        $this->assertSame(['raw_reply' => 'Tuesday at ten', 'is_selection' => false, 'selected' => []], $result['answer']);
    }

    #[Test]
    public function an_empty_submission_is_refused_and_the_question_keeps_waiting(): void
    {
        $this->scenario = 'web_form_empty_submission';
        $this->fixture()->build();

        $question = $this->ask($this->fixture()->conversation, ['question' => self::QUESTION, 'choices' => self::CHOICES]);
        $turnsBefore = $this->turnCount();

        foreach ([['selected' => []], ['text' => '   ']] as $body) {
            $response = $this->submitForm($question, $body);
            $response->assertStatus(422);
            $this->assertSame('answer_required', $response->json('code'));
        }

        $this->assertSame($turnsBefore, $this->turnCount(), 'no model request');
        $this->assertSame(QuestionState::Pending, $question->fresh()->state);
        $this->assertSame('pending', $this->viewOf($question)['state']);

        // It is still answerable.
        $this->answerAndContinue($question, fn (AgentQuestion $q) => $this->submitForm($q, ['selected' => [3]]));
    }

    #[Test]
    public function a_typed_reply_still_answers_while_the_form_is_shown(): void
    {
        $this->scenario = 'web_form_typed_reply';
        $this->fixture()->build();
        $conversation = $this->fixture()->conversation;

        $question = $this->ask($conversation, ['question' => self::QUESTION, 'choices' => self::CHOICES]);
        $this->assertSame('pending', $this->viewOf($question)['state'], 'precondition: the form is shown');

        $result = $this->answerAndContinue($question, fn (AgentQuestion $q) => $this->reply($conversation, '3'));

        $this->assertSame([['number' => 3, 'text' => 'Docs']], $result['answer']['selected']);
        $this->assertSame(AnswerMethod::TypedReply, $question->fresh()->answer_method);
        $this->assertSame('typed_reply', $this->viewOf($question)['answer']['method']);
    }

    #[Test]
    public function an_answered_question_is_shown_as_answered_and_no_longer_answerable(): void
    {
        $this->scenario = 'web_form_answered_view';
        $this->fixture()->build();

        $question = $this->ask($this->fixture()->conversation, ['question' => self::QUESTION, 'choices' => self::CHOICES]);
        $this->answerAndContinue($question, fn (AgentQuestion $q) => $this->submitForm($q, ['selected' => [2]]));

        $view = $this->viewOf($question);
        $this->assertSame('answered', $view['state']);
        $this->assertSame([
            'is_selection' => true,
            'selected' => [['number' => 2, 'text' => 'Frontend']],
            'raw_reply' => '2',
            'method' => 'web_form',
        ], $view['answer']);

        $turnsBefore = $this->turnCount();
        $again = $this->submitForm($question, ['selected' => [1]]);
        $again->assertStatus(409);
        $this->assertSame('answered', $again->json('state'));
        $this->assertSame($turnsBefore, $this->turnCount(), 'a second submission resumes nothing');
        $this->assertSame([['number' => 2, 'text' => 'Frontend']], $question->fresh()->answer_selected, 'the first answer stands');
    }
}
