<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\AnswerParser;
use ClarionApp\LlmClient\Services\InteractiveAnswerService;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswer;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerStatus;
use ClarionApp\LlmClient\ValueObjects\OpenQuestionRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\QuestionChannels\QuestionEndpointHarness;
use Tests\TestCase;

/**
 * `GET conversation/{id}/message` carries, for the conversation's owner,
 * a `question` object on each question display message: everything the
 * screen needs to render the question as a form or as its outcome, built
 * fresh from the stored question on every load, and nothing internal. Every
 * other message, and every message read by someone other than the owner,
 * is listed exactly as before.
 */
class MessageIndexQuestionViewTest extends TestCase
{
    use QuestionEndpointHarness;

    private const VIEW_KEYS = ['allow_multiple', 'answer', 'choices', 'expires_at', 'id', 'preamble', 'question', 'state'];

    private const INTERNAL_KEYS = ['run_id', 'step_id', 'action_id', 'tool_call_id', 'paused_message_id', 'display_message_id',
        'delivered_at', 'delivery_failure_reason', 'presentation', 'presentation_fallback_reason', 'pending_conversation_id',
        'resume_mode', 'channel', 'user_id', 'answered_by', 'answer_message_id', 'resumed_at'];

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->setUpQuestionEndpoints([$this->owner, $this->admin]);

        // The administrator may read other users' conversations (the
        // existing `list users` path of the index).
        $adminId = (string) $this->admin->id;
        Gate::define('list users', fn ($user) => (string) $user->id === $adminId);

        Queue::fake();
        Event::fake([NewConversationMessageEvent::class]);
    }

    protected function tearDown(): void
    {
        $this->tearDownQuestionEndpoints();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    private function index(Conversation $conversation, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner, 'api')
            ->getJson("/api/clarion-app/llm-client/conversation/{$conversation->id}/message")
            ->assertStatus(200);
    }

    /** @return array<string, mixed> the listed message with $id */
    private function listed(TestResponse $response, string $id): array
    {
        foreach ($response->json() as $message) {
            if (($message['id'] ?? null) === $id) {
                return $message;
            }
        }
        $this->fail("message {$id} is not listed");
    }

    /** @return array<string, mixed> the question view on the listed message with $id */
    private function viewOn(TestResponse $response, string $id): array
    {
        $message = $this->listed($response, $id);
        $this->assertArrayHasKey('question', $message, 'the display message carries a question view');
        $this->assertIsArray($message['question']);

        return $message['question'];
    }

    private function kindOf(array $message): ?string
    {
        $toolData = $message['tool_data'] ?? null;
        if (is_string($toolData)) {
            $toolData = json_decode($toolData, true);
        }

        return is_array($toolData) ? ($toolData['kind'] ?? null) : null;
    }

    private function answerTyped(AgentQuestion $question, string $reply): void
    {
        $answered = app(AgentQuestionService::class)->answer($question, app(AnswerParser::class)->parse($reply, $question), (string) $this->owner->id, $reply);
        $this->assertNotNull($answered, 'precondition: the typed reply answered the question');
    }

    // ---------------------------------------------------------------
    // The owner's view
    // ---------------------------------------------------------------

    #[Test]
    public function a_display_message_naming_a_question_of_another_conversation_carries_no_view(): void
    {
        // Another user's question, answered; its display message then ends
        // up in the owner's conversation (however it got there). The view
        // is built only for a question asked in the conversation being
        // listed, so nothing of the other user's question — its state, its
        // answer — is shown.
        $theirs = $this->questionConversation($this->admin, 'web');
        $question = AgentQuestion::findOrFail($this->openQuestion($theirs)->question->id);
        $answered = app(AgentQuestionService::class)->answer($question, app(AnswerParser::class)->parse('my private reply', $question), (string) $this->admin->id, 'my private reply');
        $this->assertNotNull($answered, 'precondition: their question is answered');

        $mine = $this->questionConversation($this->owner, 'web');
        DB::table('messages')->where('id', $question->display_message_id)->update(['conversation_id' => $mine->id]);

        $response = $this->index($mine);
        $listed = $this->listed($response, $question->display_message_id);

        $this->assertArrayNotHasKey('question', $listed, 'no view for a question asked elsewhere');
        $this->assertStringNotContainsString('my private reply', $response->getContent());
    }

    #[Test]
    public function a_pending_question_carries_its_view_on_the_display_message(): void
    {
        $conversation = $this->questionConversation($this->owner, 'web');
        $question = AgentQuestion::findOrFail($this->openQuestion($conversation)->question->id);

        $view = $this->viewOn($this->index($conversation), $question->display_message_id);

        $this->assertSame(self::VIEW_KEYS, $this->sortedKeys($view), 'exactly the view fields, nothing else');
        $this->assertSame($question->id, $view['id']);
        $this->assertSame('Which part should I change first?', $view['question']);
        $this->assertNull($view['preamble'], 'a streamed question shows its text in the paused message, not in the view');
        $this->assertSame([
            ['number' => 1, 'text' => 'Backend'],
            ['number' => 2, 'text' => 'Frontend'],
            ['number' => 3, 'text' => 'Docs'],
        ], $view['choices']);
        $this->assertFalse($view['allow_multiple']);
        $this->assertSame('pending', $view['state']);
        $this->assertSame($question->expires_at->copy()->utc()->format('Y-m-d\\TH:i:s\\Z'), $view['expires_at']);
        $this->assertNull($view['answer']);
    }

    #[Test]
    public function a_synchronous_question_carries_its_preamble_and_a_multi_select_its_flag(): void
    {
        $conversation = $this->questionConversation($this->owner, 'web');
        $question = AgentQuestion::findOrFail($this->openQuestion(
            $conversation,
            OpenQuestionRequest::RESUME_SYNC,
            allowMultiple: true,
            preamble: 'I found three areas that need work.',
        )->question->id);

        $view = $this->viewOn($this->index($conversation), $question->display_message_id);

        $this->assertSame('I found three areas that need work.', $view['preamble']);
        $this->assertTrue($view['allow_multiple']);
    }

    #[Test]
    public function a_question_without_choices_has_an_empty_choice_list(): void
    {
        $conversation = $this->questionConversation($this->owner, 'web');
        $question = AgentQuestion::findOrFail($this->openQuestion($conversation, choices: [], question: 'What time works for you?')->question->id);

        $view = $this->viewOn($this->index($conversation), $question->display_message_id);

        $this->assertSame('What time works for you?', $view['question']);
        $this->assertSame([], $view['choices']);
    }

    #[Test]
    public function an_answered_question_carries_its_answer_and_how_it_was_given(): void
    {
        $conversation = $this->questionConversation($this->owner, 'web');
        $question = AgentQuestion::findOrFail($this->openQuestion($conversation, allowMultiple: true)->question->id);
        $before = $this->viewOn($this->index($conversation), $question->display_message_id);
        $this->assertSame('pending', $before['state'], 'precondition');

        $outcome = app(InteractiveAnswerService::class)->record($question->id, (string) $this->owner->id, InteractiveAnswer::selection([1, 3]), AnswerMethod::WebForm);
        $this->assertSame(InteractiveAnswerStatus::Answered, $outcome->status, 'precondition: the form answer applied');

        $view = $this->viewOn($this->index($conversation), $question->display_message_id);

        $this->assertSame('answered', $view['state'], 'the state is read fresh on every load');
        $this->assertSame([
            'is_selection' => true,
            'selected' => [['number' => 1, 'text' => 'Backend'], ['number' => 3, 'text' => 'Docs']],
            'raw_reply' => '1, 3',
            'method' => 'web_form',
        ], $view['answer']);
    }

    #[Test]
    public function a_typed_free_text_answer_is_shown_as_text(): void
    {
        $conversation = $this->questionConversation($this->owner, 'web');
        $question = AgentQuestion::findOrFail($this->openQuestion($conversation)->question->id);
        $this->answerTyped($question, 'none of these — use a fourth approach');

        $view = $this->viewOn($this->index($conversation), $question->display_message_id);

        $this->assertSame([
            'is_selection' => false,
            'selected' => [],
            'raw_reply' => 'none of these — use a fourth approach',
            'method' => 'typed_reply',
        ], $view['answer']);
    }

    #[Test]
    public function only_question_display_messages_carry_a_view_and_no_view_exposes_internals(): void
    {
        $conversation = $this->questionConversation($this->owner, 'web');
        $first = AgentQuestion::findOrFail($this->openQuestion($conversation)->question->id);
        $this->answerTyped($first, '2');
        $second = AgentQuestion::findOrFail($this->openQuestion($conversation)->question->id);
        // A question asked before unanswered policies existed: it expires as it always did.
        DB::table('agent_questions')->where('id', $second->id)->update(['unanswered_policy' => null, 'effective_wait_minutes' => null]);
        $second->refresh();
        $this->assertTrue(app(AgentQuestionService::class)->expire($second), 'precondition: the second question expired');
        $third = AgentQuestion::findOrFail($this->openQuestion($conversation)->question->id);

        $listed = $this->index($conversation)->json();

        $kinds = array_map(fn (array $m) => $this->kindOf($m), $listed);
        $this->assertContains('agent_question_answer', $kinds, 'precondition: an answer message is listed');
        $this->assertContains('agent_question_notice', $kinds, 'precondition: a notice message is listed');
        $this->assertContains(null, $kinds, 'precondition: ordinary messages are listed');

        $views = [];
        foreach ($listed as $message) {
            if ($this->kindOf($message) === 'agent_question') {
                $this->assertArrayHasKey('question', $message, 'every question display message carries a view');
                $views[$message['id']] = $message['question'];
            } else {
                $this->assertArrayNotHasKey('question', $message, 'no other message carries a view');
            }
        }

        $this->assertSame([
            $first->display_message_id => 'answered',
            $second->display_message_id => 'expired',
            $third->display_message_id => 'pending',
        ], array_map(fn (array $view) => $view['state'], $views), 'each display message carries its own question');

        foreach ($views as $view) {
            $this->assertSame(self::VIEW_KEYS, $this->sortedKeys($view));
            foreach (self::INTERNAL_KEYS as $internal) {
                $this->assertArrayNotHasKey($internal, $view);
            }
            if (is_array($view['answer'])) {
                $this->assertSame(['is_selection', 'method', 'raw_reply', 'selected'], $this->sortedKeys($view['answer']));
            }
            $encoded = json_encode($view);
            foreach ([$first, $second, $third] as $question) {
                foreach (['run_id', 'step_id', 'action_id', 'paused_message_id'] as $column) {
                    $this->assertStringNotContainsString((string) $question->{$column}, $encoded, "the view never contains {$column}");
                }
            }
        }
    }

    // ---------------------------------------------------------------
    // Other readers
    // ---------------------------------------------------------------

    #[Test]
    public function admin_reader_gets_no_question_view(): void
    {
        $conversation = $this->questionConversation($this->owner, 'web');
        $question = AgentQuestion::findOrFail($this->openQuestion($conversation)->question->id);

        $asOwner = $this->index($conversation);
        $this->assertArrayHasKey('question', $this->listed($asOwner, $question->display_message_id), 'precondition: the owner sees the view');

        $asAdmin = $this->index($conversation, $this->admin);

        $this->assertSame(
            array_column($asOwner->json(), 'id'),
            array_column($asAdmin->json(), 'id'),
            'the administrator still reads the same messages',
        );
        foreach ($asAdmin->json() as $message) {
            $this->assertArrayNotHasKey('question', $message, 'no message carries a question view for a non-owner');
        }
    }

    // ---------------------------------------------------------------
    // Cost
    // ---------------------------------------------------------------

    #[Test]
    public function the_views_cost_one_query_however_many_questions_there_are(): void
    {
        $plain = $this->questionConversation($this->owner, 'web');
        Message::create(['conversation_id' => $plain->id, 'role' => 'user', 'user' => 'User', 'content' => 'Hello', 'responseTime' => 0]);

        $one = $this->questionConversation($this->owner, 'web');
        $this->openQuestion($one);

        $three = $this->questionConversation($this->owner, 'web');
        $this->answerTyped(AgentQuestion::findOrFail($this->openQuestion($three)->question->id), '1');
        $this->answerTyped(AgentQuestion::findOrFail($this->openQuestion($three)->question->id), '2');
        $latest = $this->openQuestion($three)->question;

        $queries = function (Conversation $conversation): int {
            $this->index($conversation); // warm anything cached per process
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->index($conversation);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $withNone = $queries($plain);
        $withOne = $queries($one);
        $withThree = $queries($three);

        $this->assertSame($withOne, $withThree, 'three questions cost no more queries than one');
        $this->assertLessThanOrEqual($withNone + 1, $withOne, 'the views add at most one query');
        $this->assertArrayHasKey('question', $this->listed($this->index($three), AgentQuestion::findOrFail($latest->id)->display_message_id), 'precondition: views are attached');
    }

    /** @return list<string> */
    private function sortedKeys(array $array): array
    {
        $keys = array_keys($array);
        sort($keys);

        return $keys;
    }
}
