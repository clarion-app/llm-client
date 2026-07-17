<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\ConversationEnded;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\ConversationLifecycleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The conversation session boundary.
 *
 * Regression context: ConversationEnded was dispatched at the end of every agent
 * response. Its listeners treat it as a session boundary, so short-term memory was
 * wiped after every turn (making it indistinguishable from scratch) and episodic
 * capture ran against the opening exchange, then blocked itself forever.
 */
class ConversationLifecycleTest extends TestCase
{
    private User $user;
    private Server $server;

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('llm_servers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('server_url');
            $table->string('provider_type')->nullable();
            $table->text('token')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->uuid('server_id')->nullable();
            $table->string('title')->nullable();
            $table->string('model')->nullable();
            $table->string('character')->nullable();
            $table->string('provider_override')->nullable();
            $table->boolean('is_processing')->default(false);
            $table->timestamp('ended_at')->nullable();
            $table->string('channel')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->string('role');
            $table->text('content')->nullable();
            $table->string('user')->nullable();
            $table->integer('responseTime')->nullable();
            $table->json('tool_data')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('llm_memory_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('scope');
            $table->uuid('agent_id');
            $table->uuid('user_id');
            $table->uuid('conversation_id')->nullable();
            $table->string('turn_id')->nullable();
            $table->string('key', 64)->nullable();
            $table->text('content');
            $table->json('embedding')->nullable();
            $table->timestamp('last_accessed_at')->useCurrent();
            $table->timestamps();
            $table->unique(['scope', 'agent_id', 'key']);
        });

        Schema::create('declarative_memories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('type');
            $table->text('content');
            $table->string('source');
            $table->integer('confidence_level')->nullable();
            $table->json('embedding')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('chunk_summaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->integer('chunk_index');
            $table->string('source_hash');
            $table->integer('source_message_count')->default(0);
            $table->json('summary');
            $table->integer('summary_tokens')->nullable();
            $table->string('condensation_model')->nullable();
            $table->string('condensation_provider')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'chunk_index']);
        });

        Schema::create('condensation_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id')->unique();
            $table->integer('consecutive_failures')->default(0);
            $table->timestamp('cooldown_until')->nullable();
            $table->timestamps();
        });

        Schema::create('episodic_memories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('conversation_id');
            $table->text('summary');
            $table->json('topics')->nullable();
            $table->boolean('protected')->default(false);
            $table->integer('word_count')->default(0);
            $table->integer('summary_word_count')->default(0);
            $table->json('embedding')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Lifecycle User',
            'email' => 'lifecycle@example.com',
            'password' => bcrypt('secret'),
        ]);

        $this->server = Server::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Server',
            'server_url' => 'https://api.example.com',
            'provider_type' => 'openai',
        ]);
    }

    private function makeConversation(array $attributes = []): Conversation
    {
        return Conversation::create(array_merge([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'gpt-4o',
            'character' => 'Clarion',
            'is_processing' => false,
        ], $attributes));
    }

    #[Test]
    public function ending_a_session_dispatches_conversation_ended_once(): void
    {
        Event::fake([ConversationEnded::class]);
        $conversation = $this->makeConversation();
        $lifecycle = $this->app->make(ConversationLifecycleService::class);

        $this->assertTrue($lifecycle->end($conversation));
        $this->assertFalse($lifecycle->end($conversation), 'Ending twice must be a no-op');

        Event::assertDispatchedTimes(ConversationEnded::class, 1);
        $this->assertNotNull($conversation->fresh()->ended_at);
    }

    #[Test]
    public function idle_sweep_ends_only_conversations_past_the_threshold(): void
    {
        Event::fake([ConversationEnded::class]);

        $idle = $this->makeConversation();
        $idle->forceFill(['updated_at' => now()->subMinutes(60)])->save();

        $active = $this->makeConversation();
        $active->forceFill(['updated_at' => now()->subMinutes(2)])->save();

        $ended = $this->app->make(ConversationLifecycleService::class)->endIdleConversations(30);

        $this->assertSame(1, $ended);
        $this->assertNotNull($idle->fresh()->ended_at);
        $this->assertNull($active->fresh()->ended_at, 'A recently active session must stay open');
        Event::assertDispatchedTimes(ConversationEnded::class, 1);
    }

    #[Test]
    public function idle_sweep_is_idempotent_across_runs(): void
    {
        Event::fake([ConversationEnded::class]);

        $conversation = $this->makeConversation();
        $conversation->forceFill(['updated_at' => now()->subMinutes(60)])->save();

        $lifecycle = $this->app->make(ConversationLifecycleService::class);
        $lifecycle->endIdleConversations(30);
        $lifecycle->endIdleConversations(30);

        Event::assertDispatchedTimes(ConversationEnded::class, 1);
    }

    #[Test]
    public function a_conversation_in_flight_is_not_ended(): void
    {
        Event::fake([ConversationEnded::class]);

        $conversation = $this->makeConversation(['is_processing' => true]);
        $conversation->forceFill(['updated_at' => now()->subMinutes(60)])->save();

        $this->assertSame(0, $this->app->make(ConversationLifecycleService::class)->endIdleConversations(30));
        Event::assertNotDispatched(ConversationEnded::class);
    }

    #[Test]
    public function a_returning_user_reopens_the_session_so_it_can_end_again(): void
    {
        Event::fake([ConversationEnded::class]);

        $conversation = $this->makeConversation();
        $lifecycle = $this->app->make(ConversationLifecycleService::class);

        $lifecycle->end($conversation);
        $this->assertNotNull($conversation->fresh()->ended_at);

        // User comes back — the agent loop clears the marker when it starts work.
        $lifecycle->markActive($conversation);
        $this->assertNull($conversation->fresh()->ended_at);

        // The now-live session must be able to end (and be captured) again.
        $this->assertTrue($lifecycle->end($conversation->fresh()));
        Event::assertDispatchedTimes(ConversationEnded::class, 2);
    }

    /**
     * The core regression: finishing a turn is not ending a session.
     *
     * Drives the container-resolved agent loop with only the provider faked, so
     * the wiring between the loop and its lifecycle listeners is what is under
     * test — the thing mock-injected unit tests could never see.
     */
    #[Test]
    public function completing_an_agent_turn_does_not_end_the_session(): void
    {
        Event::fake([ConversationEnded::class]);
        \Illuminate\Support\Facades\Http::fake();

        $provider = \Mockery::mock(\ClarionApp\LlmClient\Contracts\LlmProvider::class);
        $provider->shouldReceive('chat')->andReturn([
            'choices' => [['message' => ['content' => 'Here is your answer.']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]);
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = \Mockery::mock(\ClarionApp\LlmClient\Providers\ProviderRegistry::class);
        $registry->shouldReceive('resolveByType')->andReturn($provider);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $this->app->instance(\ClarionApp\LlmClient\Providers\ProviderRegistry::class, $registry);

        // Titled already, so the loop's first-exchange title generation (which
        // makes its own outbound request) stays out of this test.
        $conversation = $this->makeConversation(['title' => 'Trip planning']);

        // Session-scoped memory the user expects to survive their next question.
        $memory = $this->app->make(\ClarionApp\LlmClient\Contracts\MemoryService::class);
        $memory->create(
            \ClarionApp\LlmClient\Contracts\MemoryScope::SHORT_TERM,
            $conversation->character,
            $this->user->id,
            $conversation->id,
            null,
            'session_fact',
            'The user is planning a trip to Lisbon.'
        );

        $agentLoop = $this->app->make(\ClarionApp\LlmClient\Services\AgentLoopService::class);
        $result = $agentLoop->run($conversation, 'What should I pack?');

        $this->assertSame('completed', $result['status']);

        // Note: assertNotDispatched()'s second argument is a filter callback, not
        // a message, so assert on hasDispatched() to explain a failure properly.
        $this->assertFalse(
            Event::hasDispatched(ConversationEnded::class),
            'Answering a message ends a turn, not the session — firing here wipes session memory every turn and captures a first-turn-only episodic record.'
        );

        $this->assertNotNull(
            $memory->read(
                \ClarionApp\LlmClient\Contracts\MemoryScope::SHORT_TERM,
                $conversation->character,
                'session_fact'
            ),
            'Short-term memory must survive a completed turn'
        );

        $this->assertNull($conversation->fresh()->ended_at);
    }

    /**
     * The idle sweep is the only thing that ends a session, so if it is not
     * scheduled, short-term memory is never released and no conversation is ever
     * captured episodically — silently, with every test still green.
     */
    #[Test]
    public function the_idle_sweep_is_scheduled(): void
    {
        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

        $commands = array_map(
            fn ($event) => $event->command,
            $schedule->events()
        );

        $matching = array_filter(
            $commands,
            fn ($c) => str_contains((string) $c, 'llm-client:end-idle-conversations')
        );

        $this->assertNotEmpty($matching, 'llm-client:end-idle-conversations must be scheduled by the package');
    }

    #[Test]
    public function idle_command_ends_sessions(): void
    {
        Event::fake([ConversationEnded::class]);

        $conversation = $this->makeConversation();
        $conversation->forceFill(['updated_at' => now()->subMinutes(90)])->save();

        $this->artisan('llm-client:end-idle-conversations', ['--minutes' => 30])
            ->assertExitCode(0);

        $this->assertNotNull($conversation->fresh()->ended_at);
        Event::assertDispatchedTimes(ConversationEnded::class, 1);
    }
}
