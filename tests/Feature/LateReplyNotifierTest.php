<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\AnswerParser;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\LateReplyNotifier;
use ClarionApp\LlmClient\ValueObjects\AskUserRequest;
use ClarionApp\LlmClient\ValueObjects\OpenQuestionRequest;
use ClarionApp\LlmClient\ValueObjects\ParsedAnswer;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\UnansweredPolicy;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\QuestionChannels\QuestionEndpointHarness;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\TestCase;

/**
 * The one-time notice a user gets when they type a reply after a question
 * already expired on the assumption it stated.
 *
 * LateReplyNotifier::noticeFor() posts the notice — once per question — only
 * when the conversation's most recently asked question proceeded on its
 * assumption, belongs to the user replying, and has not had its notice yet.
 * The notice is a system message about the question, written into the
 * conversation and delivered on the conversation's own channel; it never
 * reaches the model. Whether the notice is still owed is decided by a
 * compare-and-set on the question, so two replies arriving together produce
 * one notice. Nothing that goes wrong while posting it ever reaches the
 * caller: the reply is handled either way.
 */
class LateReplyNotifierTest extends TestCase
{
    use QuestionEndpointHarness;

    private const ASKED_AT = '2026-10-12 09:00:00';

    private const SWEPT_AT = '2026-10-12 10:01:00';

    private const REPLIED_AT = '2026-10-12 14:30:00';

    private const QUESTION = 'Which part should I change first?';

    private const CHOICES = ['Backend', 'Frontend', 'Docs'];

    private const LATE_REPLY_NOTICE = 'Your reply arrived after the question "Which part should I change first?" had expired. '
        .'The agent had already continued assuming: 1. Backend. Your reply is being handled as a new message.';

    private User $owner;

    private User $stranger;

    private RecordingChannelDelivery $telegram;

    /** @var list<string> every error or warning logged */
    private array $problemsLogged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->setUpQuestionEndpoints([$this->owner, $this->stranger]);

        Queue::fake();
        Event::fake([NewConversationMessageEvent::class]);

        $this->telegram = new RecordingChannelDelivery('telegram');
        app(ConversationChannelRegistry::class)->register($this->telegram);

        Event::listen(MessageLogged::class, function (MessageLogged $logged) {
            if (in_array($logged->level, ['error', 'warning', 'critical', 'alert', 'emergency'], true)) {
                $this->problemsLogged[] = $logged->level.': '.$logged->message;
            }
        });

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

    private function notifier(): LateReplyNotifier
    {
        return app(LateReplyNotifier::class);
    }

    private function conversation(): Conversation
    {
        return $this->questionConversation($this->owner, 'telegram');
    }

    /** A question asked now that proceeds on $assumption within an hour. */
    private function askToProceed(Conversation $conversation, ?ParsedAnswer $assumption = null): AgentQuestion
    {
        $opened = $this->openQuestion($conversation, OpenQuestionRequest::RESUME_SYNC, request: new AskUserRequest(
            self::QUESTION,
            self::CHOICES,
            false,
            policy: UnansweredPolicy::ProceedOnAssumption,
            assumedAnswer: $assumption ?? new ParsedAnswer('1', true, [['number' => 1, 'text' => 'Backend']]),
            requestedWaitMinutes: 60,
        ));

        return AgentQuestion::findOrFail($opened->question->id);
    }

    /** A question asked now with the default stop policy, an hour long. */
    private function askToStop(Conversation $conversation): AgentQuestion
    {
        $opened = $this->openQuestion($conversation, OpenQuestionRequest::RESUME_SYNC, request: new AskUserRequest(
            self::QUESTION,
            self::CHOICES,
            false,
            requestedWaitMinutes: 60,
        ));

        return AgentQuestion::findOrFail($opened->question->id);
    }

    /** Let $question's deadline pass and expire it by its policy, as the sweep does. */
    private function expire(AgentQuestion $question, string $at = self::SWEPT_AT): AgentQuestion
    {
        Carbon::setTestNow(Carbon::parse($at, 'UTC'));
        $this->assertNotNull(app(AgentQuestionService::class)->expireWithPolicy($question->fresh()), 'precondition: the expiry took effect');

        return $question->fresh();
    }

    /** A question in $conversation that expired on its assumption, with the notices so far cleared from the channel. */
    private function proceeded(Conversation $conversation, ?ParsedAnswer $assumption = null): AgentQuestion
    {
        $question = $this->expire($this->askToProceed($conversation, $assumption));
        $this->assertSame(QuestionState::ExpiredAssumed, $question->state, 'precondition: proceeded on its assumption');
        $this->assertNull($question->late_reply_noticed_at, 'precondition: no late reply has been noticed');
        $this->telegram->deliveries = [];
        Carbon::setTestNow(Carbon::parse(self::REPLIED_AT, 'UTC'));

        return $question;
    }

    /** @return list<Message> the late-reply notices written in $conversation */
    private function lateReplyNotices(Conversation $conversation): array
    {
        return Message::where('conversation_id', $conversation->id)->orderBy('created_at')->get()
            ->filter(fn (Message $m) => ($m->tool_data['notice'] ?? null) === 'late_reply')
            ->values()
            ->all();
    }

    /** @return array<string, mixed> what the late reply must never change about the question */
    private function decision(AgentQuestion $question): array
    {
        return (array) DB::table('agent_questions')->where('id', $question->id)->first([
            'state', 'unanswered_policy', 'assumed_answer_raw', 'assumed_answer_selected', 'answer_raw', 'answer_selected',
            'answer_method', 'answer_message_id', 'answered_by', 'pending_conversation_id', 'resolved_at', 'resumed_at',
            'stopped_at', 'expires_at', 'effective_wait_minutes',
        ]);
    }

    private function assertNothingPosted(Conversation $conversation, AgentQuestion $question, ?Message $returned, string $why): void
    {
        $this->assertNull($returned, "{$why}: no notice is returned");
        $this->assertSame([], $this->lateReplyNotices($conversation), "{$why}: no notice is written");
        $this->assertSame([], $this->telegram->textsFor($conversation->id), "{$why}: nothing is delivered");
        $this->assertNull(
            DB::table('agent_questions')->where('id', $question->id)->value('late_reply_noticed_at'),
            "{$why}: the question's notice is not used up",
        );
    }

    // ---------------------------------------------------------------
    // The notice
    // ---------------------------------------------------------------

    #[Test]
    public function the_first_late_reply_gets_one_notice_naming_what_was_assumed(): void
    {
        $conversation = $this->conversation();
        $question = $this->proceeded($conversation);
        $decision = $this->decision($question);

        $notice = $this->notifier()->noticeFor($conversation->fresh(), (string) $this->owner->id);

        $this->assertInstanceOf(Message::class, $notice, 'the notice is posted');
        $stored = Message::findOrFail($notice->id);
        $this->assertSame($conversation->id, $stored->conversation_id, 'in the conversation replied in');
        $this->assertSame('assistant', $stored->role, 'written by the system, not by the user');
        $this->assertSame(self::LATE_REPLY_NOTICE, $stored->content);
        $this->assertEquals(
            ['kind' => 'agent_question_notice', 'question_id' => $question->id, 'state' => 'expired_assumed', 'notice' => 'late_reply', 'delivered' => true],
            $stored->tool_data,
        );

        $this->assertSame([self::LATE_REPLY_NOTICE], $this->telegram->textsFor($conversation->id), 'delivered once, on the conversation\'s own channel');
        $this->assertSame([$stored->id], array_map(fn (Message $m) => $m->id, $this->lateReplyNotices($conversation)));

        $this->assertNotNull(DB::table('agent_questions')->where('id', $question->id)->value('late_reply_noticed_at'), 'the notice is used up');
        $this->assertSame($decision, $this->decision($question), 'nothing about what was decided changes');
        $this->assertSame([], $this->problemsLogged);
    }

    #[Test]
    public function an_assumption_in_the_agents_own_words_is_quoted_in_full(): void
    {
        $conversation = $this->conversation();
        $this->proceeded($conversation, new ParsedAnswer('whichever part is smallest', false, []));

        $notice = $this->notifier()->noticeFor($conversation->fresh(), (string) $this->owner->id);

        $this->assertInstanceOf(Message::class, $notice);
        $this->assertSame(
            'Your reply arrived after the question "Which part should I change first?" had expired. '
                .'The agent had already continued assuming: "whichever part is smallest". Your reply is being handled as a new message.',
            Message::findOrFail($notice->id)->content,
        );
    }

    #[Test]
    public function a_second_late_reply_gets_no_second_notice(): void
    {
        $conversation = $this->conversation();
        $question = $this->proceeded($conversation);

        $first = $this->notifier()->noticeFor($conversation->fresh(), (string) $this->owner->id);
        $noticedAt = DB::table('agent_questions')->where('id', $question->id)->value('late_reply_noticed_at');
        Carbon::setTestNow(Carbon::parse(self::REPLIED_AT, 'UTC')->addMinutes(5));
        $second = $this->notifier()->noticeFor($conversation->fresh(), (string) $this->owner->id);

        $this->assertInstanceOf(Message::class, $first);
        $this->assertNull($second, 'the notice is posted once per question');
        $this->assertCount(1, $this->lateReplyNotices($conversation));
        $this->assertSame([self::LATE_REPLY_NOTICE], $this->telegram->textsFor($conversation->id), 'delivered once');
        $this->assertSame($noticedAt, DB::table('agent_questions')->where('id', $question->id)->value('late_reply_noticed_at'), 'the first notice\'s time is kept');
    }

    #[Test]
    public function two_replies_arriving_together_produce_exactly_one_notice(): void
    {
        $conversation = $this->conversation();
        $question = $this->proceeded($conversation);

        // The second reply's notice is attempted while the first one has
        // read the question and not yet claimed its notice.
        $nested = 'not fired';
        Event::listen('eloquent.retrieved: '.AgentQuestion::class, function (AgentQuestion $retrieved) use (&$nested, $question, $conversation) {
            if ($nested !== 'not fired' || $retrieved->id !== $question->id) {
                return;
            }
            $nested = 'firing';
            $nested = $this->notifier()->noticeFor(Conversation::findOrFail($conversation->id), (string) $this->owner->id);
        });

        $outer = $this->notifier()->noticeFor($conversation->fresh(), (string) $this->owner->id);

        $this->assertNotSame('not fired', $nested, 'precondition: the second reply arrived after the first read the question');
        $returned = array_values(array_filter([$outer, $nested], fn ($m) => $m instanceof Message));
        $this->assertCount(1, $returned, 'exactly one of the two posted the notice');
        $this->assertCount(1, $this->lateReplyNotices($conversation), 'one notice in the conversation');
        $this->assertSame([self::LATE_REPLY_NOTICE], $this->telegram->textsFor($conversation->id), 'delivered once');
    }

    // ---------------------------------------------------------------
    // Only for the replier's own latest question that proceeded
    // ---------------------------------------------------------------

    public static function latestQuestionsThatGetNoNotice(): array
    {
        return [
            'still pending' => ['pending'],
            'stopped at its deadline' => ['stopped'],
            'answered in time' => ['answered'],
            'expired before unanswered policies existed' => ['legacy expired'],
        ];
    }

    #[Test]
    #[DataProvider('latestQuestionsThatGetNoNotice')]
    public function no_notice_unless_the_latest_question_proceeded_on_its_assumption(string $how): void
    {
        $conversation = $this->conversation();

        $question = match ($how) {
            'pending' => $this->askToStop($conversation),
            'stopped' => $this->expire($this->askToStop($conversation)),
            'answered' => (function () use ($conversation) {
                $question = $this->askToProceed($conversation);
                $this->assertNotNull(app(AgentQuestionService::class)->answer(
                    $question,
                    app(AnswerParser::class)->parse('2', $question),
                    (string) $this->owner->id,
                    '2',
                ), 'precondition: answered');

                return $question->fresh();
            })(),
            'legacy expired' => (function () use ($conversation) {
                $question = $this->askToProceed($conversation);
                // A question asked before unanswered policies existed: it expires as it always did.
                DB::table('agent_questions')->where('id', $question->id)->update(['unanswered_policy' => null, 'effective_wait_minutes' => null]);

                return $this->expire($question->fresh());
            })(),
        };
        $this->assertSame(
            ['pending' => 'pending', 'stopped' => 'stopped', 'answered' => 'answered', 'legacy expired' => 'expired'][$how],
            $question->fresh()->state->value,
            'precondition',
        );
        $this->telegram->deliveries = [];
        Carbon::setTestNow(Carbon::parse(self::REPLIED_AT, 'UTC'));

        $returned = $this->notifier()->noticeFor($conversation->fresh(), (string) $this->owner->id);

        $this->assertNothingPosted($conversation, $question, $returned, $how);
    }

    #[Test]
    public function another_users_reply_gets_no_notice_and_leaves_the_owners_notice_owed(): void
    {
        $conversation = $this->conversation();
        $question = $this->proceeded($conversation);

        $returned = $this->notifier()->noticeFor($conversation->fresh(), (string) $this->stranger->id);

        $this->assertNothingPosted($conversation, $question, $returned, 'another user');

        $this->assertInstanceOf(
            Message::class,
            $this->notifier()->noticeFor($conversation->fresh(), (string) $this->owner->id),
            'the owner still gets the notice',
        );
    }

    #[Test]
    public function an_assumed_question_followed_by_a_newer_question_gets_no_notice(): void
    {
        $conversation = $this->conversation();
        $older = $this->proceeded($conversation);

        // The agent continued on its assumption and later asked again; that
        // newer question was answered.
        Carbon::setTestNow(Carbon::parse('2026-10-12 11:00:00', 'UTC'));
        $newer = $this->askToStop($conversation->fresh());
        $this->assertNotNull(app(AgentQuestionService::class)->answer(
            $newer,
            app(AnswerParser::class)->parse('3', $newer),
            (string) $this->owner->id,
            '3',
        ), 'precondition: the newer question was answered');
        $this->telegram->deliveries = [];
        Carbon::setTestNow(Carbon::parse(self::REPLIED_AT, 'UTC'));

        $returned = $this->notifier()->noticeFor($conversation->fresh(), (string) $this->owner->id);

        $this->assertNothingPosted($conversation, $older, $returned, 'a newer question was asked since');
    }

    // ---------------------------------------------------------------
    // Never throws
    // ---------------------------------------------------------------

    #[Test]
    public function a_channel_that_throws_is_logged_and_the_notice_still_stands(): void
    {
        $conversation = $this->conversation();
        $question = $this->proceeded($conversation);
        $this->telegram->throwWith('chat not found');

        $notice = $this->notifier()->noticeFor($conversation->fresh(), (string) $this->owner->id);

        $this->assertInstanceOf(Message::class, $notice, 'the notice is in the conversation even though delivery failed');
        $this->assertCount(1, $this->lateReplyNotices($conversation));
        $this->assertCount(1, $this->telegram->deliveries, 'delivery was attempted once');
        $this->assertNotNull(DB::table('agent_questions')->where('id', $question->id)->value('late_reply_noticed_at'));
        $this->assertNotSame([], $this->problemsLogged, 'the failed delivery is logged');
    }

    #[Test]
    public function a_failure_writing_the_notice_is_logged_and_never_thrown(): void
    {
        $conversation = $this->conversation();
        $this->proceeded($conversation);

        Event::listen('eloquent.creating: '.Message::class, function (Message $message) {
            if (($message->tool_data['notice'] ?? null) === 'late_reply') {
                throw new \RuntimeException('database unavailable');
            }
        });

        $returned = $this->notifier()->noticeFor($conversation->fresh(), (string) $this->owner->id);

        $this->assertNull($returned, 'no notice was posted');
        $this->assertSame([], $this->lateReplyNotices($conversation));
        $this->assertSame([], $this->telegram->textsFor($conversation->id), 'nothing is delivered for a notice that was not written');
        $this->assertNotSame([], $this->problemsLogged, 'the failure is logged');
    }

    // ---------------------------------------------------------------
    // The model never sees it
    // ---------------------------------------------------------------

    #[Test]
    public function the_notice_never_reaches_the_model(): void
    {
        $conversation = $this->conversation();
        $this->proceeded($conversation);

        $notice = $this->notifier()->noticeFor($conversation->fresh(), (string) $this->owner->id);
        $this->assertInstanceOf(Message::class, $notice, 'precondition: the notice was posted');

        $history = app(AgentLoopService::class)->buildHistoryMessages($conversation->fresh());

        $this->assertNotSame([], $history, 'precondition: the conversation has history the model sees');
        foreach ($history as $entry) {
            $this->assertStringNotContainsString(
                'Your reply arrived after the question',
                is_string($entry['content'] ?? null) ? $entry['content'] : (string) json_encode($entry['content'] ?? null),
                'the late-reply notice is for the user only',
            );
        }
    }
}
