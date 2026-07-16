<?php

namespace Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\DeclarativeMemory;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\OperationCache;
use Illuminate\Support\Facades\Config;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

use PHPUnit\Framework\Attributes\Test;
use Mockery;

/**
 * Feature tests for preference injection into AgentLoopService.
 *
 * Verifies that PreferenceInjector::assemble() output appears in
 * buildMessagesPayload() system prompt between base prompt and Known Operations.
 */
class AgentLoopPreferenceInjectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Ensure declarative_memories table exists
        if (!\Illuminate\Support\Facades\Schema::hasTable('declarative_memories')) {
            \Illuminate\Support\Facades\Schema::create('declarative_memories', function ($table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('type');
                $table->text('content');
                $table->string('source');
                $table->integer('confidence_level')->nullable();
                $table->json('embedding')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('user_id');
                $table->index(['user_id', 'type']);
                $table->index('deleted_at');
            });
        }

        // Ensure feature is enabled
        Config::set('llm-client.preferences_injection.enabled', true);
        Config::set('llm-client.preferences_injection.max_tokens', 500);
    }

    protected function tearDown(): void
    {
        Config::set('llm-client.preferences_injection.enabled', true);
        Config::set('llm-client.preferences_injection.max_tokens', 500);
        Mockery::close();
        parent::tearDown();
    }

    private function createAgentLoopService(): AgentLoopService
    {
        $registryMock = Mockery::mock(McpToolRegistry::class);
        $executorMock = Mockery::mock(McpToolExecutor::class);

        return new AgentLoopService(
            $registryMock,
            $executorMock,
            new OperationCache()
        );
    }

    private function createConversation(): Conversation
    {
        $server = Server::create([
            'name' => 'test',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);

        return Conversation::factory()->create([
            'user_id' => $this->user->id,
            'server_id' => $server->id,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  T006: Sync path includes preference injection                     */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function sync_path_includes_preference_injection(): void
    {
        $conversation = $this->createConversation();
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'Hello',
            'responseTime' => 0,
        ]);

        (new DeclarativeMemory())->forceFill([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'Always use 24-hour time format',
            'source' => 'user_stated',
        ])->save();

        $service = $this->createAgentLoopService();
        $messages = $service->buildMessagesPayload($conversation);

        // Find system message
        $systemMsg = null;
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemMsg = $msg['content'];
                break;
            }
        }

        $this->assertNotNull($systemMsg, 'System message should exist');
        $this->assertStringContainsString('## User Preferences', $systemMsg);
        $this->assertStringContainsString('Always use 24-hour time format', $systemMsg);
    }

    /* ------------------------------------------------------------------ */
    /*  T007: Streaming path includes preference injection                */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function streaming_path_includes_preference_injection(): void
    {
        Queue::fake();

        $conversation = $this->createConversation();
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'Hello',
            'responseTime' => 0,
        ]);

        (new DeclarativeMemory())->forceFill([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'Always use metric units',
            'source' => 'user_stated',
        ])->save();

        $service = $this->createAgentLoopService();
        $messages = $service->buildMessagesPayload($conversation);

        $systemMsg = null;
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemMsg = $msg['content'];
                break;
            }
        }

        $this->assertNotNull($systemMsg, 'System message should exist');
        $this->assertStringContainsString('Always use metric units', $systemMsg);
    }

    /* ------------------------------------------------------------------ */
    /*  T008: Empty store injects nothing                                 */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function empty_store_injects_nothing(): void
    {
        $conversation = $this->createConversation();
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'Hello',
            'responseTime' => 0,
        ]);

        $service = $this->createAgentLoopService();
        $messages = $service->buildMessagesPayload($conversation);

        $systemMsg = null;
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemMsg = $msg['content'];
                break;
            }
        }

        $this->assertStringNotContainsString('## User Preferences', $systemMsg ?? '');
    }

    /* ------------------------------------------------------------------ */
    /*  Regression: ownerless conversation does not crash the loop        */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function conversation_without_user_id_builds_payload(): void
    {
        // conversations.user_id is nullable. buildMessagesPayload() backs every
        // entry point (start/resume/run/resumeSync), so a null owner must skip
        // injection rather than throw.
        $server = Server::create([
            'name' => 'test',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);

        $conversation = Conversation::factory()->create([
            'user_id' => null,
            'server_id' => $server->id,
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'Hello',
            'responseTime' => 0,
        ]);

        $service = $this->createAgentLoopService();
        $messages = $service->buildMessagesPayload($conversation);

        $systemMsg = null;
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemMsg = $msg['content'];
                break;
            }
        }

        $this->assertStringNotContainsString('## User Preferences', $systemMsg ?? '');
    }

    /* ------------------------------------------------------------------ */
    /*  T009: Binding rules appear with MUST language                     */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function binding_rules_appear_with_must_language(): void
    {
        $conversation = $this->createConversation();
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'Hello',
            'responseTime' => 0,
        ]);

        (new DeclarativeMemory())->forceFill([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'rule',
            'content' => 'Never delete files without confirmation',
            'source' => 'user_stated',
        ])->save();

        $service = $this->createAgentLoopService();
        $messages = $service->buildMessagesPayload($conversation);

        $systemMsg = null;
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemMsg = $msg['content'];
                break;
            }
        }

        $this->assertStringContainsString('### Binding Rules', $systemMsg);
        $this->assertStringContainsString('MUST follow', $systemMsg);
        $this->assertStringContainsString('Never delete files without confirmation', $systemMsg);
    }

    /* ------------------------------------------------------------------ */
    /*  T010: Soft preferences appear with yield language                 */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function soft_preferences_appear_with_yield_language(): void
    {
        $conversation = $this->createConversation();
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'Hello',
            'responseTime' => 0,
        ]);

        (new DeclarativeMemory())->forceFill([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'preference',
            'content' => 'Prefer concise responses',
            'source' => 'user_stated',
        ])->save();

        $service = $this->createAgentLoopService();
        $messages = $service->buildMessagesPayload($conversation);

        $systemMsg = null;
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemMsg = $msg['content'];
                break;
            }
        }

        $this->assertStringContainsString('### Preferences', $systemMsg);
        $this->assertStringContainsString('yield', strtolower($systemMsg));
        $this->assertStringContainsString('Prefer concise responses', $systemMsg);
    }
}
