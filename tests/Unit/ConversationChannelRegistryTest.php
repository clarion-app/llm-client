<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Contracts\ConversationChannelDelivery;
use ClarionApp\LlmClient\LlmClientServiceProvider;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\WebChannelDelivery;
use ClarionApp\LlmClient\ValueObjects\DeliveryResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ConversationChannelRegistry maps a conversation's channel name to the
 * adapter that can deliver text on it. A channel with no adapter is simply
 * unknown (`has()` false, `for()` null). llm-client registers the built-in
 * `web` adapter itself, and binds the registry while registering (not while
 * booting) so another package's boot() can always add its own adapter,
 * whatever order the providers boot in.
 */
class ConversationChannelRegistryTest extends TestCase
{
    private function adapter(string $channel): ConversationChannelDelivery
    {
        return new class($channel) implements ConversationChannelDelivery {
            public function __construct(private string $name) {}

            public function channel(): string
            {
                return $this->name;
            }

            public function deliver(Conversation $conversation, string $text): DeliveryResult
            {
                return DeliveryResult::ok();
            }
        };
    }

    #[Test]
    public function an_unknown_channel_has_no_adapter(): void
    {
        $registry = new ConversationChannelRegistry();

        $this->assertFalse($registry->has('telegram'));
        $this->assertNull($registry->for('telegram'));
    }

    #[Test]
    public function a_registered_adapter_is_found_by_its_channel_name(): void
    {
        $registry = new ConversationChannelRegistry();
        $telegram = $this->adapter('telegram');

        $registry->register($telegram);

        $this->assertTrue($registry->has('telegram'));
        $this->assertSame($telegram, $registry->for('telegram'));
        $this->assertFalse($registry->has('sms'));
        $this->assertNull($registry->for('sms'));
    }

    #[Test]
    public function the_web_adapter_is_registered_out_of_the_box(): void
    {
        $registry = app(ConversationChannelRegistry::class);

        $this->assertTrue($registry->has('web'));
        $this->assertInstanceOf(WebChannelDelivery::class, $registry->for('web'));
        $this->assertSame('web', $registry->for('web')->channel());
    }

    #[Test]
    public function the_registry_is_a_container_singleton(): void
    {
        $first = app(ConversationChannelRegistry::class);
        $first->register($this->adapter('telegram'));

        $this->assertSame($first, app(ConversationChannelRegistry::class));
        $this->assertTrue(app(ConversationChannelRegistry::class)->has('telegram'), 'an adapter registered once is visible to every later resolution');
    }

    #[Test]
    public function the_registry_is_bound_by_register_alone_before_any_boot(): void
    {
        $this->app->offsetUnset(ConversationChannelRegistry::class);
        $this->assertFalse($this->app->bound(ConversationChannelRegistry::class));

        (new LlmClientServiceProvider($this->app))->register();

        $this->assertTrue($this->app->bound(ConversationChannelRegistry::class), 'the registry must be bound in register(), not boot()');
        $this->assertTrue(app(ConversationChannelRegistry::class)->has('web'));
    }
}
