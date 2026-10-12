<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Carbon\Carbon;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\InteractiveQuestionAnswers;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\ChannelQuestionCapabilities;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswer;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerStatus;
use ClarionApp\LlmClient\ValueObjects\OpenQuestionRequest;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\QuestionChannels\QuestionEndpointHarness;
use Tests\Fixtures\QuestionChannels\RecordingPresentingDelivery;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\TestCase;

/**
 * The run record of a question shows how it reached its user and how its
 * user answered it, alongside everything it already showed.
 *
 * The `ask_user` action's content (read by its owner through
 * `GET agent-runs/{runId}/actions/{actionId}`) gains two things:
 *
 *  - `presentation: {mode, fallback_reason}` — `mode` is `rich` or
 *    `plain_text` once the question has been delivered (null only before
 *    delivery), and `fallback_reason` says why plain text was used
 *    (`channel_lacks_options`, `too_many_options`, `rich_send_rejected`),
 *    null for a rich presentation. The content written at the pause is
 *    rewritten as soon as delivery is known, so a question still waiting
 *    already shows it.
 *  - `answer.method` — `web_form`, `messaging_option` or `typed_reply`;
 *    the answer reads `{raw_reply, selected, method}`.
 *
 * Every field the record showed before keeps its meaning and value, and a
 * resolved record keeps how the question was presented.
 */
class AgentQuestionRunRecordPresentationTest extends TestCase
{
    use QuestionEndpointHarness;

    private const QUESTION = 'Which part should I change first?';

    private const CHOICES = ['Backend', 'Frontend', 'Docs'];

    /** The fields the record showed before presentation was tracked. */
    private const EARLIER_SNAPSHOT_KEYS = [
        'question_id', 'outcome', 'stopped_at', 'stopped_ms', 'policy', 'question', 'choices', 'allow_multiple', 'asked_at',
        'expires_at', 'resolution', 'resolved_at', 'waited_ms', 'answer',
    ];

    private User $user;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->setUpQuestionEndpoints([$this->user, $this->stranger]);

        Queue::fake();
        Event::fake([NewConversationMessageEvent::class]);
    }

    protected function tearDown(): void
    {
        $this->tearDownQuestionEndpoints();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixtures and assertions
    // ---------------------------------------------------------------

    /**
     * Serve the `telegram` channel the way $how describes; `web` keeps the
     * package's own web adapter.
     */
    private function serveTelegram(string $how): void
    {
        $adapter = match ($how) {
            'presents options' => new RecordingPresentingDelivery('telegram'),
            'declares nothing' => new RecordingChannelDelivery('telegram'),
            'cannot show options' => new RecordingPresentingDelivery('telegram', ChannelQuestionCapabilities::none()),
            'shows at most two options' => new RecordingPresentingDelivery('telegram', new ChannelQuestionCapabilities(
                supportsOptions: true,
                maxOptions: 2,
                maxOptionLabelChars: null,
            )),
            'rejects the rich send' => (new RecordingPresentingDelivery('telegram'))->rejectRich('telegram send failed: Bad Request'),
            'rejects everything' => (new RecordingPresentingDelivery('telegram'))
                ->rejectRich('telegram send failed: Bad Request')
                ->failPlain('telegram send failed: chat not found'),
        };

        app(ConversationChannelRegistry::class)->register($adapter);
    }

    /** A pending question in a new conversation of the user's on $channel, opened as that channel's pause site opens it. */
    private function askOn(string $channel): AgentQuestion
    {
        $resumeMode = $channel === 'web' ? OpenQuestionRequest::RESUME_STREAM : OpenQuestionRequest::RESUME_SYNC;
        $question = $this->pendingQuestionIn($this->questionConversation($this->user, $channel), $resumeMode);

        $this->assertNotNull($question->run_id, 'precondition: the question belongs to a recorded run');
        $this->assertNotNull($question->action_id, 'precondition: the ask is a recorded action');

        return $question;
    }

    /** @return array<string, mixed> the action as $reader reads it */
    private function actionDetail(AgentQuestion $question, ?User $reader = null): array
    {
        return $this->actingAs($reader ?? $this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$question->run_id}/actions/{$question->action_id}")
            ->assertStatus(200)
            ->json();
    }

    /** @return array<string, mixed> the decoded content of the question's action */
    private function snapshotOf(array $detail): array
    {
        $this->assertSame('ask_user', $detail['target'] ?? null);
        $this->assertIsString($detail['content'] ?? null, 'the ask_user action carries the question snapshot as its content');
        $snapshot = json_decode($detail['content'], true);
        $this->assertIsArray($snapshot, 'the content is the JSON snapshot; got: '.$detail['content']);
        $this->assertEqualsCanonicalizing(
            [...self::EARLIER_SNAPSHOT_KEYS, 'presentation'],
            array_keys($snapshot),
            'the snapshot has every field it had before, plus how the question was presented',
        );

        return $snapshot;
    }

    /** @param array{mode: ?string, fallback_reason: ?string} $expected */
    private function assertPresentation(array $expected, array $snapshot): void
    {
        $this->assertIsArray($snapshot['presentation'], 'presentation is an object');
        $this->assertSame(['mode', 'fallback_reason'], array_keys($snapshot['presentation']), 'presentation is exactly {mode, fallback_reason}');
        $this->assertSame($expected, $snapshot['presentation']);
    }

    private function assertSameInstant(?Carbon $expected, mixed $actual, string $field): void
    {
        $this->assertNotNull($expected, "precondition: the question row has {$field}");
        $this->assertIsString($actual, "the snapshot's {$field} is a timestamp");
        $this->assertSame(
            $expected->copy()->utc()->format('Y-m-d\\TH:i:s\\Z'),
            $actual,
            "the snapshot's {$field} is the question's {$field}, as before",
        );
    }

    /** Every field the record showed before still describes the row exactly as it did. */
    private function assertEarlierFieldsUnchanged(AgentQuestion $question, array $snapshot, string $resolution): void
    {
        $question = $question->fresh();

        $this->assertSame($question->id, $snapshot['question_id']);
        $this->assertSame(self::QUESTION, $snapshot['question']);
        $this->assertSame(self::CHOICES, $snapshot['choices']);
        $this->assertFalse($snapshot['allow_multiple']);
        $this->assertSameInstant($question->asked_at, $snapshot['asked_at'], 'asked_at');
        $this->assertSameInstant($question->expires_at, $snapshot['expires_at'], 'expires_at');
        $this->assertSame($resolution, $snapshot['resolution']);

        if ($resolution === 'pending') {
            $this->assertNull($snapshot['resolved_at']);
            $this->assertNull($snapshot['waited_ms']);
            $this->assertNull($snapshot['answer']);

            return;
        }

        $this->assertSameInstant($question->resolved_at, $snapshot['resolved_at'], 'resolved_at');
        $this->assertIsInt($snapshot['waited_ms']);
    }

    /** The question's record as it was presented, and its row, agree. */
    private function assertRowRecorded(AgentQuestion $question, ?string $mode, ?string $reason): void
    {
        $row = DB::table('agent_questions')->where('id', $question->id)->first(['presentation', 'presentation_fallback_reason']);
        $this->assertSame($mode, $row->presentation, 'precondition: the row records the presentation');
        $this->assertSame($reason, $row->presentation_fallback_reason, 'precondition: the row records the fallback reason');
    }

    /** Answer $question with choice 2 by $method, the way that route does it, and let the task continue. */
    private function answerWithChoiceTwo(AgentQuestion $question, string $route): void
    {
        $request = $this->actingAs($this->user, 'api');

        match ($route) {
            'web form' => $request
                ->postJson("/api/clarion-app/llm-client/question/{$question->id}/answer", ['selected' => [2]])
                ->assertStatus(201),
            'typed reply on the web' => $request
                ->postJson('/api/clarion-app/llm-client/message', [
                    'conversation_id' => $question->conversation_id,
                    'content' => '2',
                ])
                ->assertStatus(201),
            'typed reply in the messaging app' => $request
                ->postJson('/api/clarion-app/llm-client/agent', [
                    'message' => '2',
                    'conversation_id' => $question->conversation_id,
                    'channel' => 'telegram',
                ])
                ->assertSuccessful(),
            'tapped option' => $this->tap($question),
        };

        $row = $question->fresh();
        $this->assertSame(QuestionState::Answered, $row->state, 'precondition: the question was answered');
        $this->assertNotNull($row->resumed_at, 'precondition: the task continued, so the record was rewritten');
    }

    private function tap(AgentQuestion $question): void
    {
        $outcome = app(InteractiveQuestionAnswers::class)->submit(
            $question->id,
            (string) $this->user->id,
            InteractiveAnswer::selection([2]),
            AnswerMethod::MessagingOption,
        );
        $this->assertSame(InteractiveAnswerStatus::Answered, $outcome->status, 'precondition: the tap answered the question');

        // Work the queued continuation, as a queue worker would.
        Queue::assertPushed(ResumeAfterQuestionJob::class, fn (ResumeAfterQuestionJob $job) => $job->questionId === $question->id);
        $this->app->call([new ResumeAfterQuestionJob($question->id), 'handle']);
    }

    // ---------------------------------------------------------------
    // While the question waits
    // ---------------------------------------------------------------

    /** @return array<string, array{string, string, ?string, ?string}> */
    public static function presentations(): array
    {
        return [
            'web: options as a form' => ['web', 'web', 'rich', null],
            'messaging app: options as buttons' => ['telegram', 'presents options', 'rich', null],
            'messaging app: an adapter that declares nothing' => ['telegram', 'declares nothing', 'plain_text', 'channel_lacks_options'],
            'messaging app: cannot show options' => ['telegram', 'cannot show options', 'plain_text', 'channel_lacks_options'],
            'messaging app: more choices than it can show' => ['telegram', 'shows at most two options', 'plain_text', 'too_many_options'],
            'messaging app: the rich send was rejected' => ['telegram', 'rejects the rich send', 'plain_text', 'rich_send_rejected'],
        ];
    }

    #[Test]
    #[DataProvider('presentations')]
    public function a_waiting_question_already_shows_how_it_was_presented(string $channel, string $served, string $mode, ?string $reason): void
    {
        if ($channel === 'telegram') {
            $this->serveTelegram($served);
        }
        $question = $this->askOn($channel);
        $this->assertSame(QuestionState::Pending, $question->fresh()->state, 'precondition: the question reached its user and waits');
        $this->assertRowRecorded($question, $mode, $reason);

        $detail = $this->actionDetail($question);
        $this->assertSame('awaiting_confirmation', $detail['outcome'], 'precondition: the ask still waits on its user');

        $snapshot = $this->snapshotOf($detail);
        $this->assertPresentation(['mode' => $mode, 'fallback_reason' => $reason], $snapshot);
        $this->assertEarlierFieldsUnchanged($question, $snapshot, 'pending');
    }

    // ---------------------------------------------------------------
    // Once the question is answered
    // ---------------------------------------------------------------

    /** @return array<string, array{string, string, string, string, ?string, string}> */
    public static function answerRoutes(): array
    {
        return [
            'web form' => ['web', 'web', 'web form', 'rich', null, 'web_form'],
            'tapped option' => ['telegram', 'presents options', 'tapped option', 'rich', null, 'messaging_option'],
            'typed reply on the web' => ['web', 'web', 'typed reply on the web', 'rich', null, 'typed_reply'],
            'typed reply in the messaging app, after a rejected rich send' => ['telegram', 'rejects the rich send', 'typed reply in the messaging app', 'plain_text', 'rich_send_rejected', 'typed_reply'],
            'typed reply in the messaging app, to an adapter that declares nothing' => ['telegram', 'declares nothing', 'typed reply in the messaging app', 'plain_text', 'channel_lacks_options', 'typed_reply'],
        ];
    }

    #[Test]
    #[DataProvider('answerRoutes')]
    public function an_answered_question_shows_how_it_was_answered_and_still_how_it_was_presented(
        string $channel,
        string $served,
        string $route,
        string $mode,
        ?string $reason,
        string $method,
    ): void {
        if ($channel === 'telegram') {
            $this->serveTelegram($served);
        }
        $question = $this->askOn($channel);

        $this->answerWithChoiceTwo($question, $route);
        $this->assertSame($method, DB::table('agent_questions')->where('id', $question->id)->value('answer_method'), 'the row records how it was answered');

        $detail = $this->actionDetail($question);
        $this->assertSame('success', $detail['outcome']);
        $this->assertNull($detail['failure_reason']);

        $snapshot = $this->snapshotOf($detail);
        $this->assertEarlierFieldsUnchanged($question, $snapshot, 'answered');
        $this->assertSame(
            ['raw_reply' => '2', 'selected' => [['number' => 2, 'text' => 'Frontend']], 'method' => $method],
            $snapshot['answer'],
            'the answer reads {raw_reply, selected, method}, its earlier fields unchanged',
        );
        $this->assertPresentation(['mode' => $mode, 'fallback_reason' => $reason], $snapshot);
    }

    #[Test]
    public function every_answer_method_is_distinguishable_in_the_record(): void
    {
        $seen = [];

        $this->serveTelegram('presents options');
        foreach (['web form' => 'web', 'tapped option' => 'telegram', 'typed reply in the messaging app' => 'telegram'] as $route => $channel) {
            $question = $this->askOn($channel);
            $this->answerWithChoiceTwo($question, $route);
            $seen[$route] = $this->snapshotOf($this->actionDetail($question))['answer']['method'] ?? null;
        }

        $this->assertSame(
            ['web form' => 'web_form', 'tapped option' => 'messaging_option', 'typed reply in the messaging app' => 'typed_reply'],
            $seen,
        );
    }

    // ---------------------------------------------------------------
    // Questions that were never answered
    // ---------------------------------------------------------------

    #[Test]
    public function an_undeliverable_question_shows_it_was_tried_as_plain_text_and_why(): void
    {
        $this->serveTelegram('rejects everything');
        $question = AgentQuestion::findOrFail(
            $this->openQuestion($this->questionConversation($this->user, 'telegram'), OpenQuestionRequest::RESUME_SYNC)->question->id,
        );
        $this->assertSame(QuestionState::Undeliverable, $question->state, 'precondition: neither attempt reached the user');

        $detail = $this->actionDetail($question);
        $this->assertSame('failure', $detail['outcome']);
        $this->assertSame('question undeliverable', $detail['failure_reason']);

        $snapshot = $this->snapshotOf($detail);
        $this->assertEarlierFieldsUnchanged($question, $snapshot, 'undeliverable');
        $this->assertNull($snapshot['answer']);
        $this->assertPresentation(['mode' => 'plain_text', 'fallback_reason' => 'rich_send_rejected'], $snapshot);
    }

    #[Test]
    public function a_withdrawn_question_keeps_how_it_was_presented_and_has_no_answer(): void
    {
        $this->serveTelegram('presents options');
        $question = $this->askOn('telegram');

        $this->actingAs($this->user, 'api')
            ->postJson("/api/clarion-app/llm-client/conversation/{$question->conversation_id}/end")
            ->assertStatus(200);
        $this->assertSame(QuestionState::Withdrawn, $question->fresh()->state, 'precondition: ending the conversation withdrew the question');

        $snapshot = $this->snapshotOf($this->actionDetail($question));
        $this->assertSame('withdrawn', $snapshot['resolution']);
        $this->assertNull($snapshot['answer']);
        $this->assertPresentation(['mode' => 'rich', 'fallback_reason' => null], $snapshot);
    }

    // ---------------------------------------------------------------
    // Rows without the new facts
    // ---------------------------------------------------------------

    /**
     * Before delivery (the content written at the pause) and for a question
     * recorded before presentation and answer method were tracked, the new
     * fields are present and null rather than missing or guessed.
     */
    #[Test]
    public function unknown_presentation_and_method_are_shown_as_null(): void
    {
        $this->serveTelegram('presents options');
        $question = $this->askOn('telegram');

        DB::table('agent_questions')->where('id', $question->id)->update(['presentation' => null, 'presentation_fallback_reason' => null]);
        $pending = json_decode(AgentQuestion::findOrFail($question->id)->runRecordSnapshot(), true);
        $this->assertEqualsCanonicalizing([...self::EARLIER_SNAPSHOT_KEYS, 'presentation'], array_keys($pending));
        $this->assertSame(['mode' => null, 'fallback_reason' => null], $pending['presentation']);

        DB::table('agent_questions')->where('id', $question->id)->update([
            'state' => QuestionState::Answered->value,
            'answer_raw' => '2',
            'answer_selected' => json_encode([['number' => 2, 'text' => 'Frontend']]),
            'answer_method' => null,
            'resolved_at' => now(),
        ]);
        $answered = json_decode(AgentQuestion::findOrFail($question->id)->runRecordSnapshot(), true);
        $this->assertSame(['mode' => null, 'fallback_reason' => null], $answered['presentation']);
        $this->assertSame(
            ['raw_reply' => '2', 'selected' => [['number' => 2, 'text' => 'Frontend']], 'method' => null],
            $answered['answer'],
        );
    }

    // ---------------------------------------------------------------
    // Ownership
    // ---------------------------------------------------------------

    #[Test]
    public function only_the_runs_owner_can_read_how_the_question_was_presented_and_answered(): void
    {
        $this->serveTelegram('presents options');
        $question = $this->askOn('telegram');
        $this->tap($question);

        $foreign = $this->actingAs($this->stranger, 'api')
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$question->run_id}/actions/{$question->action_id}");
        $absent = $this->actingAs($this->stranger, 'api')
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$question->run_id}/actions/00000000-0000-4000-8000-000000000000");

        $foreign->assertStatus(404);
        $this->assertSame($absent->status(), $foreign->status(), 'another user\'s action is indistinguishable from an absent one');
        $this->assertSame($absent->getContent(), $foreign->getContent());
        $this->assertStringNotContainsString('messaging_option', $foreign->getContent());
        $this->assertStringNotContainsString(self::QUESTION, $foreign->getContent());

        // The owner still reads it.
        $this->assertSame('messaging_option', $this->snapshotOf($this->actionDetail($question))['answer']['method'] ?? null);
    }
}
