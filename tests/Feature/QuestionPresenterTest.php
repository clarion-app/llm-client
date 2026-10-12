<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\InteractiveQuestionAnswers;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\RunActionUpdated;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\QuestionPresenter;
use ClarionApp\LlmClient\Services\QuestionRenderer;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\ChannelQuestionCapabilities;
use ClarionApp\LlmClient\ValueObjects\DeliveryResult;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswer;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerStatus;
use ClarionApp\LlmClient\ValueObjects\OpenQuestionRequest;
use ClarionApp\LlmClient\ValueObjects\QuestionPresentation;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
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
 * QuestionPresenter delivers a newly opened question: richly when its
 * channel can show the options, otherwise — or when the rich send fails —
 * as the exact plain text a channel received before options existed. How
 * the question was presented, and why plain text was used, is recorded on
 * the row. A question becomes undeliverable only when the plain-text
 * attempt itself failed. Once delivery is known, the question's run record
 * (its still-waiting `ask_user` action) is rewritten once to show how the
 * question was presented.
 */
class QuestionPresenterTest extends TestCase
{
    use QuestionEndpointHarness;

    private const GOLDEN = "Which part should I change first?\n"
        ."\n"
        ."1. Backend\n"
        ."2. Frontend\n"
        ."3. Docs\n"
        ."\n"
        .'Reply with the number of your choice, or answer in your own words.'
        ."\n"
        ."\n"
        // Asked at the frozen 09:00 UTC on 12 Oct 2026 (see setUp()) with the
        // default one-day period and no policy, so the work stops if unanswered.
        .'If there is no answer by 09:00 UTC on 13 Oct 2026 (1 day after this was asked), work on this task will pause until you reply.';

    private const PREAMBLE = 'I found three areas that need work.';

    private User $user;

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Frozen, so the deadline every question states is known exactly.
        $this->travelTo(Carbon::parse('2026-10-12 09:00:00', 'UTC'));

        $this->user = User::factory()->create();
        $this->setUpQuestionEndpoints([$this->user]);

        Queue::fake();
        Event::fake([NewConversationMessageEvent::class]);
        Event::listen(MessageLogged::class, function (MessageLogged $entry) {
            $this->logged[] = $entry;
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownQuestionEndpoints();

        parent::tearDown();
    }

    private function register(object $adapter): object
    {
        app(ConversationChannelRegistry::class)->register($adapter);

        return $adapter;
    }

    /** @return array{presentation: ?string, presentation_fallback_reason: ?string} */
    private function recorded(string $questionId): array
    {
        $row = DB::table('agent_questions')
            ->where('id', $questionId)
            ->select(['presentation', 'presentation_fallback_reason'])
            ->first();

        return ['presentation' => $row->presentation, 'presentation_fallback_reason' => $row->presentation_fallback_reason];
    }

    private function assertWarnedAbout(string $questionId): void
    {
        foreach ($this->logged as $entry) {
            if (in_array($entry->level, ['warning', 'error'], true) && ($entry->context['question_id'] ?? null) === $questionId) {
                return;
            }
        }

        $this->fail("expected a warning or error logged with question_id {$questionId}");
    }

    // ---------------------------------------------------------------
    // Rich presentation
    // ---------------------------------------------------------------

    #[Test]
    public function a_channel_that_presents_options_receives_the_question_richly_and_nothing_plain(): void
    {
        $telegram = $this->register(new RecordingPresentingDelivery('telegram'));
        $conversation = $this->questionConversation($this->user, 'telegram');

        $opened = $this->openQuestion($conversation, OpenQuestionRequest::RESUME_SYNC, preamble: self::PREAMBLE);
        $question = AgentQuestion::findOrFail($opened->question->id);

        $this->assertCount(1, $telegram->presentations);
        $this->assertSame([], $telegram->deliveries, 'a successful rich presentation is not followed by plain text');
        $this->assertSame(0, $telegram->presentations[0]['transaction_level'], 'presented only after the question was committed');

        /** @var QuestionPresentation $presentation */
        $presentation = $telegram->presentations[0]['presentation'];
        $renderer = app(QuestionRenderer::class);
        $this->assertSame($question->id, $presentation->questionId);
        $this->assertSame(self::PREAMBLE, $presentation->preamble);
        $this->assertCount(3, $presentation->options);
        $this->assertSame($renderer->renderWithOptions($question, self::PREAMBLE), $presentation->bodyText);
        $this->assertSame((string) $opened->displayMessage->content, $presentation->plainText, 'the plain text is exactly the display message');

        $this->assertSame(['presentation' => 'rich', 'presentation_fallback_reason' => null], $this->recorded($question->id));
        $this->assertSame(QuestionState::Pending, $question->state);
        $this->assertNotNull($question->delivered_at);
        $this->assertTrue($opened->delivery->delivered);
    }

    #[Test]
    public function the_synchronous_preamble_is_kept_on_the_row_and_the_streamed_one_is_not(): void
    {
        $this->register(new RecordingPresentingDelivery('telegram'));
        $this->register(new RecordingPresentingDelivery('web'));

        $sync = $this->openQuestion($this->questionConversation($this->user, 'telegram'), OpenQuestionRequest::RESUME_SYNC, preamble: self::PREAMBLE);
        $stream = $this->openQuestion($this->questionConversation($this->user, 'web'), OpenQuestionRequest::RESUME_STREAM, preamble: self::PREAMBLE);

        $this->assertSame(self::PREAMBLE, DB::table('agent_questions')->where('id', $sync->question->id)->value('preamble'));
        $this->assertNull(
            DB::table('agent_questions')->where('id', $stream->question->id)->value('preamble'),
            'on the streamed path the accompanying text was already streamed as the paused message',
        );
    }

    // ---------------------------------------------------------------
    // Falling back to plain text
    // ---------------------------------------------------------------

    #[Test]
    public function a_rejected_rich_send_is_followed_immediately_by_the_plain_text(): void
    {
        $telegram = $this->register((new RecordingPresentingDelivery('telegram'))->rejectRich('telegram send failed: Bad Request'));
        $conversation = $this->questionConversation($this->user, 'telegram');

        $opened = $this->openQuestion($conversation);
        $question = AgentQuestion::findOrFail($opened->question->id);

        $this->assertCount(1, $telegram->presentations);
        $this->assertCount(1, $telegram->deliveries);
        $this->assertSame(self::GOLDEN, $telegram->deliveries[0]['text'], 'the fallback is exactly the plain-text question');
        $this->assertSame(
            ['presentation' => 'plain_text', 'presentation_fallback_reason' => 'rich_send_rejected'],
            $this->recorded($question->id),
        );
        $this->assertSame(QuestionState::Pending, $question->state, 'the plain text reached the user, so the question waits');
        $this->assertNotNull($question->delivered_at);
        $this->assertTrue($opened->delivery->delivered);
        $this->assertWarnedAbout($question->id);
    }

    #[Test]
    public function a_rich_send_that_throws_is_treated_as_rejected(): void
    {
        $telegram = $this->register((new RecordingPresentingDelivery('telegram'))->throwOnRich('connection reset'));
        $conversation = $this->questionConversation($this->user, 'telegram');

        $opened = $this->openQuestion($conversation);
        $question = AgentQuestion::findOrFail($opened->question->id);

        $this->assertCount(1, $telegram->deliveries);
        $this->assertSame(self::GOLDEN, $telegram->deliveries[0]['text']);
        $this->assertSame(
            ['presentation' => 'plain_text', 'presentation_fallback_reason' => 'rich_send_rejected'],
            $this->recorded($question->id),
        );
        $this->assertSame(QuestionState::Pending, $question->state);
        $this->assertTrue($opened->delivery->delivered);
    }

    #[Test]
    public function when_both_attempts_fail_the_question_is_undeliverable_for_the_plain_attempts_reason(): void
    {
        $telegram = $this->register(
            (new RecordingPresentingDelivery('telegram'))
                ->rejectRich('rich: keyboard rejected')
                ->failPlain('plain: chat not found'),
        );
        $conversation = $this->questionConversation($this->user, 'telegram');

        $opened = $this->openQuestion($conversation);
        $question = AgentQuestion::findOrFail($opened->question->id);

        $this->assertCount(1, $telegram->presentations);
        $this->assertSame(self::GOLDEN, $telegram->deliveries[0]['text'] ?? null, 'plain text was attempted after the rich failure');
        $this->assertSame(QuestionState::Undeliverable, $question->state);
        $this->assertSame('plain: chat not found', $question->delivery_failure_reason);
        $this->assertFalse($opened->delivery->delivered);
        $this->assertSame('plain: chat not found', $opened->delivery->failureReason);
        $this->assertSame(
            ['presentation' => 'plain_text', 'presentation_fallback_reason' => 'rich_send_rejected'],
            $this->recorded($question->id),
        );
    }

    #[Test]
    public function an_adapter_that_declares_nothing_gets_exactly_the_plain_text_it_always_got(): void
    {
        $plain = $this->register(new RecordingChannelDelivery('telegram'));
        $conversation = $this->questionConversation($this->user, 'telegram');

        $opened = $this->openQuestion($conversation);
        $question = AgentQuestion::findOrFail($opened->question->id);

        $this->assertSame([self::GOLDEN], $plain->textsFor($conversation->id));
        $this->assertSame(
            ['presentation' => 'plain_text', 'presentation_fallback_reason' => 'channel_lacks_options'],
            $this->recorded($question->id),
        );
        $this->assertSame(QuestionState::Pending, $question->state);
    }

    #[Test]
    public function a_channel_that_cannot_show_options_gets_plain_text(): void
    {
        $telegram = $this->register(new RecordingPresentingDelivery('telegram', ChannelQuestionCapabilities::none()));
        $conversation = $this->questionConversation($this->user, 'telegram');

        $opened = $this->openQuestion($conversation);

        $this->assertSame([], $telegram->presentations);
        $this->assertSame(self::GOLDEN, $telegram->deliveries[0]['text'] ?? null);
        $this->assertSame(
            ['presentation' => 'plain_text', 'presentation_fallback_reason' => 'channel_lacks_options'],
            $this->recorded($opened->question->id),
        );
    }

    #[Test]
    public function too_many_choices_for_the_channel_gets_the_whole_question_as_plain_text(): void
    {
        $telegram = $this->register(new RecordingPresentingDelivery('telegram', new ChannelQuestionCapabilities(
            supportsOptions: true,
            maxOptions: 2,
            maxOptionLabelChars: null,
        )));
        $conversation = $this->questionConversation($this->user, 'telegram');

        $opened = $this->openQuestion($conversation);

        $this->assertSame([], $telegram->presentations, 'never a subset of the options');
        $this->assertSame([self::GOLDEN], array_column($telegram->deliveries, 'text'));
        $this->assertSame(
            ['presentation' => 'plain_text', 'presentation_fallback_reason' => 'too_many_options'],
            $this->recorded($opened->question->id),
        );
        $this->assertSame(QuestionState::Pending, $opened->question->fresh()->state);
    }

    #[Test]
    public function a_channel_with_no_adapter_is_recorded_as_plain_text_and_the_question_is_undeliverable(): void
    {
        $conversation = $this->questionConversation($this->user, 'carrier-pigeon');

        $opened = $this->openQuestion($conversation);
        $question = AgentQuestion::findOrFail($opened->question->id);

        $this->assertSame(QuestionState::Undeliverable, $question->state);
        $this->assertSame("no delivery is registered for the 'carrier-pigeon' channel", $question->delivery_failure_reason);
        $this->assertSame(
            ['presentation' => 'plain_text', 'presentation_fallback_reason' => 'channel_lacks_options'],
            $this->recorded($question->id),
        );
    }

    // ---------------------------------------------------------------
    // deliverQuestion() itself
    // ---------------------------------------------------------------

    #[Test]
    public function deliver_question_returns_the_result_of_the_last_attempt(): void
    {
        // Opened on a plain adapter; each case below swaps the channel's
        // adapter and delivers the same question again.
        $this->register(new RecordingChannelDelivery('telegram'));
        $conversation = $this->questionConversation($this->user, 'telegram');
        $question = AgentQuestion::findOrFail($this->openQuestion($conversation)->question->id);
        $presenter = app(QuestionPresenter::class);

        $this->register(new RecordingPresentingDelivery('telegram'));
        $rich = $presenter->deliverQuestion($conversation, $question, self::GOLDEN, null);
        $this->assertInstanceOf(DeliveryResult::class, $rich);
        $this->assertTrue($rich->delivered);
        $this->assertSame('rich', $this->recorded($question->id)['presentation']);

        $this->register((new RecordingPresentingDelivery('telegram'))->rejectRich('rich no')->failPlain('plain no'));
        $both = $presenter->deliverQuestion($conversation, $question, self::GOLDEN, null);
        $this->assertFalse($both->delivered);
        $this->assertSame('plain no', $both->failureReason);
        $this->assertSame(
            ['presentation' => 'plain_text', 'presentation_fallback_reason' => 'rich_send_rejected'],
            $this->recorded($question->id),
        );

        $fallback = (new RecordingPresentingDelivery('telegram'))->rejectRich('rich no');
        $this->register($fallback);
        $plain = $presenter->deliverQuestion($conversation, $question, 'the plain text as given', null);
        $this->assertTrue($plain->delivered);
        $this->assertSame(['the plain text as given'], array_column($fallback->deliveries, 'text'), 'the plain attempt sends the text it was given');

        // The conversation and its question both name a channel nothing serves.
        $conversation->channel = 'nowhere';
        $question->channel = 'nowhere';
        $none = $presenter->deliverQuestion($conversation, $question, self::GOLDEN, null);
        $this->assertFalse($none->delivered);
        $this->assertSame("no delivery is registered for the 'nowhere' channel", $none->failureReason);
        $this->assertSame(
            ['presentation' => 'plain_text', 'presentation_fallback_reason' => 'channel_lacks_options'],
            $this->recorded($question->id),
        );
    }

    // ---------------------------------------------------------------
    // The run record shows how the question was presented
    // ---------------------------------------------------------------

    /**
     * Have the run trace record every request to rewrite a waiting
     * action's content, still carrying each one out.
     */
    private function recordContentRewrites(): RunTraceRecorder
    {
        $recorder = new class extends RunTraceRecorder
        {
            /** @var list<array{action_id: ?string, content: string}> */
            public array $contentRewrites = [];

            public function updateSuspendedActionContent(?string $actionId, string $content): void
            {
                $this->contentRewrites[] = ['action_id' => $actionId, 'content' => $content];

                parent::updateSuspendedActionContent($actionId, $content);
            }
        };
        $this->app->instance(RunTraceRecorder::class, $recorder);

        return $recorder;
    }

    private function storedActionContent(?string $actionId): ?string
    {
        return DB::table('agent_run_actions')->where('id', $actionId)->value('content');
    }

    /** @return array<string, array{string, string, ?string, string}> */
    public static function deliveryOutcomes(): array
    {
        return [
            'presented with options' => ['presents options', 'rich', null, 'pending'],
            'rich send rejected, then plain text' => ['rejects the rich send', 'plain_text', 'rich_send_rejected', 'pending'],
            'an adapter that declares nothing' => ['declares nothing', 'plain_text', 'channel_lacks_options', 'pending'],
            'more choices than the channel shows' => ['shows at most two options', 'plain_text', 'too_many_options', 'pending'],
            'both attempts failed' => ['rejects everything', 'plain_text', 'rich_send_rejected', 'undeliverable'],
            'no adapter for the channel' => ['no adapter', 'plain_text', 'channel_lacks_options', 'undeliverable'],
        ];
    }

    #[Test]
    #[DataProvider('deliveryOutcomes')]
    public function the_waiting_action_is_rewritten_once_with_how_the_question_was_presented(
        string $served,
        string $mode,
        ?string $reason,
        string $finalState,
    ): void {
        $recorder = $this->recordContentRewrites();
        $channel = $served === 'no adapter' ? 'carrier-pigeon' : 'telegram';
        match ($served) {
            'presents options' => $this->register(new RecordingPresentingDelivery('telegram')),
            'rejects the rich send' => $this->register((new RecordingPresentingDelivery('telegram'))->rejectRich('rich no')),
            'declares nothing' => $this->register(new RecordingChannelDelivery('telegram')),
            'shows at most two options' => $this->register(new RecordingPresentingDelivery('telegram', new ChannelQuestionCapabilities(
                supportsOptions: true,
                maxOptions: 2,
                maxOptionLabelChars: null,
            ))),
            'rejects everything' => $this->register((new RecordingPresentingDelivery('telegram'))->rejectRich('rich no')->failPlain('plain no')),
            'no adapter' => null,
        };

        // Streamed: an undeliverable question's continuation is queued, so
        // its action is still waiting when open() returns.
        $opened = $this->openQuestion($this->questionConversation($this->user, $channel), OpenQuestionRequest::RESUME_STREAM);
        $question = AgentQuestion::findOrFail($opened->question->id);
        $this->assertSame($finalState, $question->state->value, 'precondition: the delivery outcome');
        $this->assertSame(['presentation' => $mode, 'presentation_fallback_reason' => $reason], $this->recorded($question->id), 'precondition: the row records the presentation');
        $this->assertNotNull($question->action_id, 'precondition: the ask is a recorded action');
        $this->assertSame(
            'awaiting_confirmation',
            DB::table('agent_run_actions')->where('id', $question->action_id)->value('outcome'),
            'precondition: the ask still waits for its continuation',
        );

        $this->assertCount(1, $recorder->contentRewrites, 'the waiting action is rewritten exactly once per delivery');
        $this->assertSame($question->action_id, $recorder->contentRewrites[0]['action_id'], 'it is the question\'s own ask_user action that is rewritten');

        $rewritten = json_decode($recorder->contentRewrites[0]['content'], true);
        $this->assertIsArray($rewritten, 'the rewrite is the question\'s JSON snapshot');
        $this->assertSame($question->id, $rewritten['question_id'] ?? null);
        $this->assertSame('pending', $rewritten['resolution'] ?? null, 'written once delivery is known, before any resolution');
        $this->assertSame(['mode' => $mode, 'fallback_reason' => $reason], $rewritten['presentation'] ?? null, 'the snapshot carries the recorded presentation');

        if ($finalState === 'pending') {
            $this->assertSame(
                $question->runRecordSnapshot(),
                $recorder->contentRewrites[0]['content'],
                'the rewrite is the question\'s own snapshot, nothing else',
            );
        }

        $this->assertSame(
            $rewritten,
            json_decode((string) $this->storedActionContent($question->action_id), true),
            'the waiting action now shows how the question was presented',
        );
    }

    #[Test]
    public function an_action_that_is_no_longer_waiting_is_not_rewritten(): void
    {
        $this->register(new RecordingPresentingDelivery('telegram'));
        $conversation = $this->questionConversation($this->user, 'telegram');
        $question = AgentQuestion::findOrFail($this->openQuestion($conversation, OpenQuestionRequest::RESUME_SYNC)->question->id);

        $outcome = app(InteractiveQuestionAnswers::class)->submit($question->id, (string) $this->user->id, InteractiveAnswer::selection([2]), AnswerMethod::MessagingOption);
        $this->assertSame(InteractiveAnswerStatus::Answered, $outcome->status, 'precondition: answered');
        $this->app->call([new ResumeAfterQuestionJob($question->id), 'handle']);

        $action = DB::table('agent_run_actions')->where('id', $question->action_id)->first();
        $this->assertSame('success', $action->outcome, 'precondition: the ask is resolved in the run record');
        $final = $action->content;
        $this->assertNotNull($final);

        /** @var list<string> $announced */
        $announced = [];
        Event::listen(RunActionUpdated::class, function (RunActionUpdated $event) use (&$announced) {
            $announced[] = $event->actionId;
        });
        $this->recordContentRewrites();

        // Delivered again, this time falling back to plain text, so the
        // row's presentation changes and any rewrite would show.
        $this->register((new RecordingPresentingDelivery('telegram'))->rejectRich('rich no'));
        $delivery = app(QuestionPresenter::class)->deliverQuestion($conversation->fresh(), $question->fresh(), self::GOLDEN, null);
        $this->assertTrue($delivery->delivered, 'precondition: the plain text was delivered');
        $this->assertSame(
            ['presentation' => 'plain_text', 'presentation_fallback_reason' => 'rich_send_rejected'],
            $this->recorded($question->id),
            'precondition: the row now records a different presentation',
        );

        $after = DB::table('agent_run_actions')->where('id', $question->action_id)->first();
        $this->assertSame('success', $after->outcome);
        $this->assertSame($final, $after->content, 'a resolved action keeps its final record');
        $this->assertNotContains($question->action_id, $announced, 'nothing changed, so nothing is announced');
    }
}
