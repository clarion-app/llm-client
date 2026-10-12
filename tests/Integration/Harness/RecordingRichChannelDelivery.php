<?php

namespace Tests\Integration\Harness;

use ClarionApp\LlmClient\Contracts\ConversationChannelDelivery;
use ClarionApp\LlmClient\Contracts\InteractiveQuestionAnswers;
use ClarionApp\LlmClient\Contracts\PresentsQuestions;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\ChannelQuestionCapabilities;
use ClarionApp\LlmClient\ValueObjects\DeliveryResult;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswer;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerOutcome;
use ClarionApp\LlmClient\ValueObjects\QuestionOutcomeView;
use ClarionApp\LlmClient\ValueObjects\QuestionPresentation;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;

/**
 * A real channel adapter for integration tests that can present questions
 * with options, standing in for a messaging app with buttons. It sends
 * nothing anywhere: it records every plain delivery, rich presentation and
 * resolution it is shown, can be switched to reject the rich send, fail
 * the plain one or throw while showing a resolution, and can tap an option
 * the way a user would — through InteractiveQuestionAnswers, exactly as the
 * Telegram package does — then work the queued resume like a queue worker.
 *
 * Register it with ConversationChannelRegistry under whichever channel name
 * the test drives (e.g. `telegram`).
 */
class RecordingRichChannelDelivery implements ConversationChannelDelivery, PresentsQuestions
{
    /** @var list<array{conversation_id: string, text: string}> */
    public array $deliveries = [];

    /** @var list<array{conversation_id: string, presentation: QuestionPresentation}> */
    public array $presentations = [];

    /** @var list<array{conversation_id: string, outcome: QuestionOutcomeView}> */
    public array $resolutions = [];

    private ChannelQuestionCapabilities $capabilities;

    private ?string $richRejection = null;

    private ?string $plainFailure = null;

    private ?string $resolutionThrow = null;

    /** @var array<int, true> resume jobs already worked, by object id */
    private array $worked = [];

    public function __construct(private string $channelName = 'telegram', ?ChannelQuestionCapabilities $capabilities = null)
    {
        $this->capabilities = $capabilities ?? ChannelQuestionCapabilities::unlimited();
    }

    public function channel(): string
    {
        return $this->channelName;
    }

    public function questionCapabilities(): ChannelQuestionCapabilities
    {
        return $this->capabilities;
    }

    public function withCapabilities(ChannelQuestionCapabilities $capabilities): self
    {
        $this->capabilities = $capabilities;

        return $this;
    }

    public function deliver(Conversation $conversation, string $text): DeliveryResult
    {
        $this->deliveries[] = ['conversation_id' => $conversation->id, 'text' => $text];

        return $this->plainFailure === null ? DeliveryResult::ok() : DeliveryResult::failed($this->plainFailure);
    }

    public function presentQuestion(Conversation $conversation, QuestionPresentation $presentation): DeliveryResult
    {
        $this->presentations[] = ['conversation_id' => $conversation->id, 'presentation' => $presentation];

        return $this->richRejection === null ? DeliveryResult::ok() : DeliveryResult::failed($this->richRejection);
    }

    public function presentResolution(Conversation $conversation, QuestionOutcomeView $outcome): void
    {
        $this->resolutions[] = ['conversation_id' => $conversation->id, 'outcome' => $outcome];

        if ($this->resolutionThrow !== null) {
            // Deliberately breaks the contract's "MUST NOT throw", to prove
            // a failed refresh never touches the resolution.
            throw new \RuntimeException($this->resolutionThrow);
        }
    }

    /** Make every later rich presentation report failure with $reason. */
    public function rejectRich(string $reason): self
    {
        $this->richRejection = $reason;

        return $this;
    }

    /** Make every later plain delivery report failure with $reason. */
    public function failPlain(string $reason): self
    {
        $this->plainFailure = $reason;

        return $this;
    }

    /** Make every later presentResolution() throw (after recording the call). */
    public function throwOnResolution(string $message = 'message to edit not found'): self
    {
        $this->resolutionThrow = $message;

        return $this;
    }

    /**
     * Tap an option (a list of choice numbers) or send free text as the
     * channel's own control would, then run any resume it queued the way a
     * queue worker does. With a real (sync) queue the resume already ran
     * inside submit().
     *
     * @param  list<int>|string  $answer
     */
    public function tap(string $questionId, string $userId, array|string $answer): InteractiveAnswerOutcome
    {
        $outcome = app(InteractiveQuestionAnswers::class)->submit(
            $questionId,
            $userId,
            is_array($answer) ? InteractiveAnswer::selection($answer) : InteractiveAnswer::freeText($answer),
            AnswerMethod::MessagingOption,
        );

        if (Queue::getFacadeRoot() instanceof QueueFake) {
            foreach (Queue::pushed(ResumeAfterQuestionJob::class)->all() as $job) {
                if ($job->questionId !== $questionId || isset($this->worked[spl_object_id($job)])) {
                    continue;
                }
                $this->worked[spl_object_id($job)] = true;
                app()->call([$job, 'handle']);
            }
        }

        return $outcome;
    }

    /** @return list<QuestionPresentation> */
    public function presentationsFor(string $conversationId): array
    {
        return array_values(array_map(
            fn (array $entry) => $entry['presentation'],
            array_filter($this->presentations, fn (array $entry) => $entry['conversation_id'] === $conversationId),
        ));
    }

    /** @return list<string> */
    public function textsFor(string $conversationId): array
    {
        return array_values(array_map(
            fn (array $delivery) => $delivery['text'],
            array_filter($this->deliveries, fn (array $delivery) => $delivery['conversation_id'] === $conversationId),
        ));
    }

    /** @return list<QuestionOutcomeView> */
    public function resolutionsFor(string $questionId): array
    {
        return array_values(array_map(
            fn (array $entry) => $entry['outcome'],
            array_filter($this->resolutions, fn (array $entry) => $entry['outcome']->questionId === $questionId),
        ));
    }
}
