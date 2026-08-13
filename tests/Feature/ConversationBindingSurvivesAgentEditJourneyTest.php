<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * spec.md US1 Acceptance Scenario 2, FR-003, quickstart.md step 3 —
 * Phase 3/T015 (090-agent-version-binding).
 *
 * This file's own US1 portion: editing the agent after a conversation is
 * bound to it must not change what the conversation recorded — a pure
 * write-path/persistence assertion.
 *
 * Phase 4/US2 (T022) extends this SAME file with the behavioral "does the
 * response actually differ" case (quickstart step 4) — see the second test
 * method below. Written first, confirmed RED: AgentLoopService does not yet
 * consult any bound definition, so the dispatched request's system content
 * never reflects the bound version's instructions.
 */
class ConversationBindingSurvivesAgentEditJourneyTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog();

        // buildMessagesPayload() (called from AgentLoopService::formatMessages()'s
        // own funnel, via start()) always attempts an auto-memory retrieval pass
        // once a conversation has both a user_id and a persisted user message —
        // episodic retrieval hits this table directly, and a raw QueryException
        // from a missing table is not one of the RuntimeException/
        // InvalidArgumentException types AutoMemoryRetriever degrades on
        // internally (mirrors EntryPathCoverageJourneyTest's own
        // createSupportingTables()).
        if (!Schema::hasTable('episodic_memories')) {
            Schema::create('episodic_memories', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->uuid('conversation_id');
                $table->text('summary');
                $table->json('topics');
                $table->boolean('protected')->default(false);
                $table->unsignedInteger('word_count');
                $table->unsignedInteger('summary_word_count');
                $table->json('embedding')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // applyContextWindowTrim() (called from start()'s own funnel) reads
        // this table via ConversationCondenser/CondensationSummaryStore
        // regardless of whether condensation ever actually triggers.
        if (!Schema::hasTable('condensation_states')) {
            Schema::create('condensation_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            });
        }
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function seedOperationCatalog(array $operations = []): void
    {
        $paths = [];
        foreach ($operations as $operationId => $entry) {
            $paths[$entry['path']][$entry['method']] = [
                'operationId' => $operationId,
                'summary' => $entry['summary'],
            ];
        }
        $doc = ['paths' => $paths];

        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);

        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->andReturn($doc);
        $this->app->instance(Generator::class, $generator);
    }

    private function clearOperationCatalog(): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function makeServer(): Server
    {
        return Server::create([
            'name' => 'TestServer',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'test-token',
        ]);
    }

    #[Test]
    public function editing_the_agent_afterward_does_not_change_what_the_conversation_recorded(): void
    {
        $agent = app(AgentService::class)->create($this->user->id, "name: weather-agent\ninstructions: Always respond in English.");
        $version1Id = $agent->current_version_id;

        $server = $this->makeServer();

        $response = $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/conversation', [
            'agent_id' => $agent->id,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);
        $response->assertStatus(201);
        $conversationId = $response->json('id');

        // Edit the agent while the conversation is still open — this produces version 2.
        app(AgentService::class)->update($agent, $this->user->id, "name: weather-agent\ninstructions: changed");

        $conversation = Conversation::find($conversationId);
        $this->assertNotNull($conversation);
        $this->assertSame(
            $version1Id,
            $conversation->agent_version_id,
            'the conversation must still name version 1 — its own agent_version_id is immutable once written (FR-003)',
        );

        $agentFresh = Agent::find($agent->id);
        $this->assertNotSame(
            $version1Id,
            $agentFresh->current_version_id,
            'the agent itself must now point at a newer version — the two have diverged, as intended',
        );
    }

    // ---------------------------------------------------------------
    // Phase 4/T022 (US2) — spec.md US2 AC1/AC2, FR-004, SC-002,
    // quickstart.md step 4.
    //
    // A conversation already under way keeps running on its bound
    // version's instructions, not the agent's current ones. Deliberately
    // an OpenAI-provider fixture (not Anthropic): MessageFormatter's
    // pass-through branch for OpenAI/LlamaCpp always returns an empty
    // 'system' string and keeps the system message inline in the
    // 'messages' array — so an implementation that appends bound
    // instructions only onto the post-formatting $formatted['system']
    // result would silently do nothing for this provider family, and
    // this test would not catch it. Appending onto the raw $messages
    // array's own system-role entry BEFORE formatForProvider() is called
    // is the only shape that works for both provider families.
    //
    // Written first, confirmed RED: AgentLoopService does not yet consult
    // any bound definition, so the dispatched request's system content is
    // the installation's own default system prompt — it contains neither
    // "Always respond in English." nor "Always respond in French.".
    // ---------------------------------------------------------------

    #[Test]
    public function a_conversation_already_under_way_keeps_running_on_its_bound_versions_instructions_not_the_agents_current_ones(): void
    {
        $agent = app(AgentService::class)->create(
            $this->user->id,
            "name: weather-agent\ninstructions: Always respond in English.",
        );

        // OpenAI-provider fixture — deliberately not Anthropic (see class
        // doc comment above for why this choice is load-bearing).
        $server = $this->makeServer();

        $response = $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/conversation', [
            'agent_id' => $agent->id,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);
        $response->assertStatus(201);
        $conversationId = $response->json('id');

        // Edit the agent while the conversation is still open — version 2
        // has DIFFERENT instructions.
        app(AgentService::class)->update(
            $agent,
            $this->user->id,
            "name: weather-agent\ninstructions: Always respond in French.",
        );

        // Send another message in the still-open conversation.
        Message::create([
            'conversation_id' => $conversationId,
            'role' => 'user',
            'user' => 'Tim',
            'content' => 'What is the weather today?',
            'responseTime' => 0,
        ]);

        Queue::fake();

        // Reload — mirrors production's own discipline of never carrying
        // an in-memory Conversation object forward from creation time.
        $conversation = Conversation::find($conversationId);
        app(AgentLoopService::class)->start($conversation);

        // Capture the dispatched SendHttpStreamRequest job's HttpRequest via
        // reflection on its protected 'request' property — mirrors
        // tests/Integration/Harness/ScriptedStream.php:56-73's own
        // established technique.
        $capturedRequests = [];
        Queue::pushed(SendHttpStreamRequest::class, function (SendHttpStreamRequest $job) use (&$capturedRequests) {
            $reflector = new \ReflectionClass($job);
            $requestProperty = $reflector->getProperty('request');
            $requestProperty->setAccessible(true);
            $capturedRequests[] = $requestProperty->getValue($job);

            return true;
        });

        $this->assertNotEmpty($capturedRequests, 'start() must dispatch a SendHttpStreamRequest job');

        $body = $capturedRequests[0]->body;
        $messages = is_array($body->messages ?? null) ? $body->messages : [];

        $systemContent = '';
        foreach ($messages as $message) {
            $role = is_array($message) ? ($message['role'] ?? null) : ($message->role ?? null);
            if ($role === 'system') {
                $content = is_array($message) ? ($message['content'] ?? '') : ($message->content ?? '');
                $systemContent .= (string) $content;
            }
        }

        $this->assertStringContainsString(
            'Always respond in English.',
            $systemContent,
            'a bound conversation must keep using its bound version 1 instructions, appended onto the raw messages array\'s own system-role entry',
        );
        $this->assertStringNotContainsString(
            'Always respond in French.',
            $systemContent,
            'a bound conversation must never pick up the agent\'s current version 2 instructions',
        );
    }
}
