<?php

namespace Tests\Fixtures\QuestionChannels;

use ClarionApp\LlmClient\Contracts\ConversationChannelDelivery;
use ClarionApp\LlmClient\Contracts\PresentsQuestions;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\ValueObjects\ChannelQuestionCapabilities;
use ClarionApp\LlmClient\ValueObjects\DeliveryResult;
use ClarionApp\LlmClient\ValueObjects\QuestionOutcomeView;
use ClarionApp\LlmClient\ValueObjects\QuestionPresentation;
use Illuminate\Support\Facades\DB;

/**
 * A channel adapter that can present questions with options, for Feature
 * and Unit tests. It sends nothing anywhere: it records every plain
 * delivery, every rich presentation and every resolution it is shown,
 * together with the transaction depth at the moment of the call (so a test
 * can prove a call happened only after commit), and can be switched to
 * reject or throw on each kind of call.
 *
 * Register it with ConversationChannelRegistry under whichever channel name
 * the test drives.
 */
class RecordingPresentingDelivery implements ConversationChannelDelivery, PresentsQuestions
{
    /** @var list<array{conversation_id: string, text: string, transaction_level: int}> */
    public array $deliveries = [];

    /** @var list<array{conversation_id: string, presentation: QuestionPresentation, transaction_level: int}> */
    public array $presentations = [];

    /** @var list<array{conversation_id: string, outcome: QuestionOutcomeView, transaction_level: int, row_state: ?string}> */
    public array $resolutions = [];

    private ChannelQuestionCapabilities $capabilities;

    private ?string $richRejection = null;

    private ?string $richThrow = null;

    private ?string $plainFailure = null;

    private ?string $resolutionThrow = null;

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

    public function deliver(Conversation $conversation, string $text): DeliveryResult
    {
        $this->deliveries[] = [
            'conversation_id' => $conversation->id,
            'text' => $text,
            'transaction_level' => DB::transactionLevel(),
        ];

        return $this->plainFailure === null ? DeliveryResult::ok() : DeliveryResult::failed($this->plainFailure);
    }

    public function presentQuestion(Conversation $conversation, QuestionPresentation $presentation): DeliveryResult
    {
        $this->presentations[] = [
            'conversation_id' => $conversation->id,
            'presentation' => $presentation,
            'transaction_level' => DB::transactionLevel(),
        ];

        if ($this->richThrow !== null) {
            // Deliberately breaks the contract's "MUST NOT throw", to prove
            // the caller treats a throwing presentation as a rejected one.
            throw new \RuntimeException($this->richThrow);
        }

        return $this->richRejection === null ? DeliveryResult::ok() : DeliveryResult::failed($this->richRejection);
    }

    public function presentResolution(Conversation $conversation, QuestionOutcomeView $outcome): void
    {
        $this->resolutions[] = [
            'conversation_id' => $conversation->id,
            'outcome' => $outcome,
            'transaction_level' => DB::transactionLevel(),
            'row_state' => DB::table('agent_questions')->where('id', $outcome->questionId)->value('state'),
        ];

        if ($this->resolutionThrow !== null) {
            // Deliberately breaks the contract's "MUST NOT throw".
            throw new \RuntimeException($this->resolutionThrow);
        }
    }

    public function withCapabilities(ChannelQuestionCapabilities $capabilities): self
    {
        $this->capabilities = $capabilities;

        return $this;
    }

    /** Make every later rich presentation report failure with $reason. */
    public function rejectRich(string $reason): self
    {
        $this->richRejection = $reason;

        return $this;
    }

    /** Make every later rich presentation throw (after recording the attempt). */
    public function throwOnRich(string $message): self
    {
        $this->richThrow = $message;

        return $this;
    }

    /** Make every later plain delivery report failure with $reason. */
    public function failPlain(string $reason): self
    {
        $this->plainFailure = $reason;

        return $this;
    }

    /** Make every later presentResolution() throw (after recording the call). */
    public function throwOnResolution(string $message): self
    {
        $this->resolutionThrow = $message;

        return $this;
    }

    /** @return list<array{conversation_id: string, outcome: QuestionOutcomeView, transaction_level: int, row_state: ?string}> */
    public function resolutionsFor(string $questionId): array
    {
        return array_values(array_filter(
            $this->resolutions,
            fn (array $resolution) => $resolution['outcome']->questionId === $questionId,
        ));
    }
}
