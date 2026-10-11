<?php

namespace Tests\Integration\Harness;

use ClarionApp\LlmClient\Contracts\ConversationChannelDelivery;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\ValueObjects\DeliveryResult;

/**
 * A real channel adapter for integration tests: it records every delivery
 * instead of sending it anywhere, and can be switched to report failure.
 * Register it with ConversationChannelRegistry under whichever channel name
 * the test drives (e.g. `telegram`).
 */
class RecordingChannelDelivery implements ConversationChannelDelivery
{
    /** @var list<array{conversation_id: string, text: string}> */
    public array $deliveries = [];

    private ?string $failureReason = null;

    public function __construct(private string $channelName = 'telegram')
    {
    }

    public function channel(): string
    {
        return $this->channelName;
    }

    public function deliver(Conversation $conversation, string $text): DeliveryResult
    {
        $this->deliveries[] = ['conversation_id' => $conversation->id, 'text' => $text];

        return $this->failureReason === null
            ? DeliveryResult::ok()
            : DeliveryResult::failed($this->failureReason);
    }

    /** Make every later delivery report failure with $reason. */
    public function failWith(string $reason): self
    {
        $this->failureReason = $reason;

        return $this;
    }

    /** Make every later delivery succeed again. */
    public function succeed(): self
    {
        $this->failureReason = null;

        return $this;
    }

    /** @return list<string> */
    public function textsFor(string $conversationId): array
    {
        return array_values(array_map(
            fn (array $delivery) => $delivery['text'],
            array_filter($this->deliveries, fn (array $delivery) => $delivery['conversation_id'] === $conversationId),
        ));
    }
}
