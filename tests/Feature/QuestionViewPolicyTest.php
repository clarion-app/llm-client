<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\AnswerParser;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\QuestionChannels\QuestionEndpointHarness;
use Tests\TestCase;

/**
 * The question view on a display message (owner's `GET
 * conversation/{id}/message`) tells the screen what happens if the
 * question goes unanswered and, once it has, what did: whether it can
 * still be answered, the policy chosen when it was asked, whether it is
 * consequential, the advance statement exactly as the question's text
 * carries it, a one-line outcome for every non-pending state, when work
 * stopped, and whether an answer came after work stopped. A question asked
 * before unanswered policies existed shows no policy and no statement.
 */
class QuestionViewPolicyTest extends TestCase
{
    use QuestionEndpointHarness;

    private const ASKED_AT = '2026-10-12 09:00:00';

    private const VIEW_KEYS = ['allow_multiple', 'answer', 'answerable', 'answered_late', 'assumed_answer', 'choices',
        'consequential', 'expires_at', 'expiry_statement', 'id', 'if_unanswered', 'outcome_text', 'preamble', 'question',
        'state', 'stopped_at'];

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->setUpQuestionEndpoints([$this->owner, $this->admin]);

        $adminId = (string) $this->admin->id;
        Gate::define('list users', fn ($user) => (string) $user->id === $adminId);

        Queue::fake();
        Event::fake([NewConversationMessageEvent::class]);

        Carbon::setTestNow(Carbon::parse(self::ASKED_AT, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
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

    /** @return array<string, mixed> the question view on the listed message with $id */
    private function viewOn(TestResponse $response, string $id): array
    {
        foreach ($response->json() as $message) {
            if (($message['id'] ?? null) === $id) {
                $this->assertArrayHasKey('question', $message, 'the display message carries a question view');
                $this->assertIsArray($message['question']);

                return $message['question'];
            }
        }
        $this->fail("message {$id} is not listed");
    }

    /** @return array{0: Conversation, 1: AgentQuestion} a pending question asked now, with the default stop policy */
    private function asked(): array
    {
        $conversation = $this->questionConversation($this->owner, 'web');
        $question = AgentQuestion::findOrFail($this->openQuestion($conversation)->question->id);
        $this->assertSame('stop', DB::table('agent_questions')->where('id', $question->id)->value('unanswered_policy'), 'precondition: stop is the default');
        $this->assertSame(1440, (int) DB::table('agent_questions')->where('id', $question->id)->value('effective_wait_minutes'), 'precondition: the default period');

        return [$conversation, $question];
    }

    /** Put $question into `stopped` exactly as the stop transition leaves it. */
    private function stop(AgentQuestion $question, string $stoppedAt = '2026-10-13 09:00:05'): AgentQuestion
    {
        DB::table('agent_questions')->where('id', $question->id)->update([
            'state' => 'stopped',
            'stopped_at' => $stoppedAt,
        ]);
        $question = AgentQuestion::findOrFail($question->id);
        $this->assertSame(QuestionState::Stopped, $question->state, 'precondition: stopped');
        $this->assertSame($question->conversation_id, $question->pending_conversation_id, 'precondition: the slot is kept');

        return $question;
    }

    private function answerTyped(AgentQuestion $question, string $reply): void
    {
        $answered = app(AgentQuestionService::class)->answer($question, app(AnswerParser::class)->parse($reply, $question), (string) $this->owner->id, $reply);
        $this->assertNotNull($answered, 'precondition: the typed reply answered the question');
    }

    /** @return list<string> */
    private function sortedKeys(array $array): array
    {
        $keys = array_keys($array);
        sort($keys);

        return $keys;
    }

    // ---------------------------------------------------------------
    // Pending
    // ---------------------------------------------------------------

    #[Test]
    public function a_pending_stop_question_shows_its_policy_and_the_exact_advance_statement(): void
    {
        [$conversation, $question] = $this->asked();

        $view = $this->viewOn($this->index($conversation), $question->display_message_id);

        $this->assertSame('pending', $view['state'], 'precondition');
        $this->assertSame(self::VIEW_KEYS, $this->sortedKeys($view), 'exactly the view fields, nothing else');
        $this->assertTrue($view['answerable']);
        $this->assertSame('stop', $view['if_unanswered']);
        $this->assertFalse($view['consequential']);
        $this->assertNull($view['assumed_answer']);
        $this->assertSame(
            'If there is no answer by 09:00 UTC on 13 Oct 2026 (1 day after this was asked), work on this task will pause until you reply.',
            $view['expiry_statement'],
        );
        $this->assertNull($view['outcome_text'], 'nothing has happened yet');
        $this->assertNull($view['stopped_at']);
        $this->assertFalse($view['answered_late']);
    }

    #[Test]
    public function a_consequential_stop_question_says_work_will_not_continue_without_an_answer(): void
    {
        [$conversation, $question] = $this->asked();
        DB::table('agent_questions')->where('id', $question->id)->update([
            'consequential' => true,
            'requested_wait_minutes' => 90,
            'effective_wait_minutes' => 90,
            'expires_at' => '2026-10-12 10:30:00',
        ]);

        $view = $this->viewOn($this->index($conversation), $question->display_message_id);

        $this->assertSame('pending', $view['state'], 'precondition');
        $this->assertSame(self::VIEW_KEYS, $this->sortedKeys($view));
        $this->assertTrue($view['answerable']);
        $this->assertSame('stop', $view['if_unanswered']);
        $this->assertTrue($view['consequential']);
        $this->assertNull($view['assumed_answer']);
        $this->assertSame(
            'Work on this task will not continue without your answer. If there is no answer by 10:30 UTC on 12 Oct 2026 (1 hour 30 minutes after this was asked), it will pause until you reply.',
            $view['expiry_statement'],
        );
        $this->assertNull($view['outcome_text']);
    }

    #[Test]
    public function the_statement_in_the_view_is_the_one_in_the_question_text(): void
    {
        [$conversation, $question] = $this->asked();

        $view = $this->viewOn($this->index($conversation), $question->display_message_id);

        $this->assertIsString($view['expiry_statement'] ?? null, 'the view carries the statement');
        $displayed = (string) DB::table('messages')->where('id', $question->display_message_id)->value('content');
        $this->assertStringEndsWith("\n\n".$view['expiry_statement'], $displayed, 'one producer: the question text ends with the same statement');
    }

    // ---------------------------------------------------------------
    // Stopped, answered late, discarded
    // ---------------------------------------------------------------

    #[Test]
    public function a_stopped_question_is_still_answerable_and_says_work_stopped(): void
    {
        [$conversation, $question] = $this->asked();
        $this->stop($question);

        $view = $this->viewOn($this->index($conversation), $question->display_message_id);

        $this->assertSame('stopped', $view['state']);
        $this->assertSame(self::VIEW_KEYS, $this->sortedKeys($view));
        $this->assertTrue($view['answerable'], 'a stopped question still accepts its answer');
        $this->assertSame('stop', $view['if_unanswered']);
        $this->assertSame('Expired — work stopped. Answer to resume.', $view['outcome_text']);
        $this->assertSame('2026-10-13T09:00:05Z', $view['stopped_at']);
        $this->assertFalse($view['answered_late']);
        $this->assertNull($view['answer']);
    }

    #[Test]
    public function a_selection_answered_after_work_stopped_is_shown_as_a_late_answer(): void
    {
        [$conversation, $question] = $this->asked();
        $this->answerTyped($this->stop($question), '2');

        $view = $this->viewOn($this->index($conversation), $question->display_message_id);

        $this->assertSame('answered', $view['state']);
        $this->assertSame(self::VIEW_KEYS, $this->sortedKeys($view));
        $this->assertFalse($view['answerable']);
        $this->assertTrue($view['answered_late']);
        $this->assertSame('Answered after work stopped: 2. Frontend', $view['outcome_text']);
        $this->assertSame('2026-10-13T09:00:05Z', $view['stopped_at'], 'when work stopped is kept');
        $this->assertSame('2', $view['answer']['raw_reply'] ?? null);
    }

    #[Test]
    public function free_text_answered_after_work_stopped_is_shown_as_a_late_answer(): void
    {
        [$conversation, $question] = $this->asked();
        $this->answerTyped($this->stop($question), 'none of these — use a fourth approach');

        $view = $this->viewOn($this->index($conversation), $question->display_message_id);

        $this->assertSame('answered', $view['state']);
        $this->assertSame(self::VIEW_KEYS, $this->sortedKeys($view));
        $this->assertFalse($view['answerable']);
        $this->assertTrue($view['answered_late']);
        $this->assertSame('Answered in your own words after work stopped.', $view['outcome_text']);
    }

    #[Test]
    public function an_answer_in_time_is_not_late(): void
    {
        [$conversation, $question] = $this->asked();
        $this->answerTyped($question, '2');

        $view = $this->viewOn($this->index($conversation), $question->display_message_id);

        $this->assertSame('answered', $view['state']);
        $this->assertSame(self::VIEW_KEYS, $this->sortedKeys($view));
        $this->assertFalse($view['answerable']);
        $this->assertFalse($view['answered_late']);
        $this->assertNull($view['stopped_at']);
        $this->assertSame('Answered: 2. Frontend', $view['outcome_text']);
    }

    #[Test]
    public function a_stopped_question_discarded_by_ending_the_conversation_is_withdrawn_and_not_answerable(): void
    {
        [$conversation, $question] = $this->asked();
        $this->stop($question);
        $this->assertTrue(app(AgentQuestionService::class)->withdrawForConversation(Conversation::findOrFail($conversation->id)), 'precondition: discarded');

        $view = $this->viewOn($this->index($conversation), $question->display_message_id);

        $this->assertSame('withdrawn', $view['state']);
        $this->assertSame(self::VIEW_KEYS, $this->sortedKeys($view));
        $this->assertFalse($view['answerable']);
        $this->assertFalse($view['answered_late']);
        $this->assertSame('Discarded — the conversation was ended or deleted while work was stopped.', $view['outcome_text']);
        $this->assertSame('2026-10-13T09:00:05Z', $view['stopped_at']);
    }

    // ---------------------------------------------------------------
    // A question asked before unanswered policies existed
    // ---------------------------------------------------------------

    #[Test]
    public function a_legacy_question_shows_no_policy_and_no_statement(): void
    {
        [$conversation, $question] = $this->asked();
        DB::table('agent_questions')->where('id', $question->id)->update(['unanswered_policy' => null, 'effective_wait_minutes' => null]);

        $view = $this->viewOn($this->index($conversation), $question->display_message_id);

        $this->assertSame('pending', $view['state'], 'precondition');
        $this->assertSame(self::VIEW_KEYS, $this->sortedKeys($view), 'the same fields for every question');
        $this->assertTrue($view['answerable']);
        $this->assertNull($view['if_unanswered']);
        $this->assertNull($view['expiry_statement']);
        $this->assertFalse($view['consequential']);
        $this->assertNull($view['assumed_answer']);
        $this->assertNull($view['outcome_text']);
        $this->assertNull($view['stopped_at']);
        $this->assertFalse($view['answered_late']);
    }

    // ---------------------------------------------------------------
    // Other readers
    // ---------------------------------------------------------------

    #[Test]
    public function an_admin_reader_gets_no_question_view_of_a_stopped_question(): void
    {
        [$conversation, $question] = $this->asked();
        $this->stop($question);

        $this->viewOn($this->index($conversation), $question->display_message_id); // precondition: the owner sees the view

        foreach ($this->index($conversation, $this->admin)->json() as $message) {
            $this->assertArrayNotHasKey('question', $message, 'no message carries a question view for a non-owner');
        }
    }
}
