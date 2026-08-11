<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Events\ConversationEnded;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\EvalRun;
use ClarionApp\LlmClient\Models\EvalRunCase;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\ToolInvocationRecord;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\ConversationLifecycleService;
use ClarionApp\LlmClient\Services\EvalCaseExecutor;
use ClarionApp\LlmClient\Services\EvalCaseService;
use ClarionApp\LlmClient\Services\EvalSuiteService;
use ClarionApp\LlmClient\Services\McpResourceHandler;
use ClarionApp\LlmClient\ValueObjects\EvalRunCaseStatus;
use ClarionApp\LlmClient\ValueObjects\EvalRunStatus;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * research.md D1/D2: a system-owned (user_id = null) Conversation must be
 * genuinely unreachable through every user-facing entry point, while the
 * one path that legitimately needs unscoped access — EvalCaseExecutor
 * driving AgentLoopService::run() — must keep working exactly as before.
 *
 * Anchors quickstart.md's mutation-testing checklist rows 1 and 2 — row 1
 * ("Conversation::scopeOwnedByRealUser() dropped from
 * ConversationController::show()") is, per this file's own task
 * description, the single most important row in the whole checklist.
 */
class SystemConversationIsolationTest extends TestCase
{
    private User $realUser;
    private Server $server;
    private Conversation $evalConversation;
    private EvalRun $run;
    private EvalRunCase $evalRunCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareSupportingSchema();

        Http::fake();

        $this->realUser = User::factory()->create();

        // Spatie's HasRoles provider is never registered in this minimal
        // test app (see Support/OperatorAccess.php's own note), so
        // ordinary Auth::user()->can(...) falls through to Laravel's
        // built-in deny-by-default — a plain Gate::define is the only way
        // to reach userConversations()'s "authorized viewer" branch at all
        // and actually exercise its query.
        Gate::define('list user conversations', fn () => true);

        $this->server = Server::create([
            'name' => 'Isolation fixture server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        $suite = app(EvalSuiteService::class)->create('Isolation fixture suite', 'home-automation-agent');
        $case = app(EvalCaseService::class)->addCase(
            $suite,
            'Say hello.',
            'Greet the user.',
            [['kind' => 'text_match', 'expected_text' => 'Hello!']],
        );

        $this->run = EvalRun::create([
            'suite_id' => $suite->id,
            'agent_label' => $suite->agent_identifier,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'status' => EvalRunStatus::InProgress,
            'case_count' => 1,
            'started_at' => now(),
        ]);

        $this->evalRunCase = EvalRunCase::create([
            'run_id' => $this->run->id,
            'eval_case_id' => $case->id,
            'eval_case_version_id' => $case->current_version_id,
            'position' => 0,
            'status' => EvalRunCaseStatus::Pending,
            'dispatch_attempts' => 0,
        ]);

        // A deterministic title matching EvalCaseExecutor's own
        // findOrCreateConversation() naming (research.md D6) — this is
        // what lets this file's own positive case, below, prove the
        // executor finds and reuses this *exact* seeded row rather than
        // creating a second one.
        $this->evalConversation = Conversation::create([
            'user_id' => null,
            'title' => 'eval-run-case:'.$this->evalRunCase->id,
            'character' => $suite->agent_identifier,
            'server_id' => $this->server->id,
            'model' => 'test-model',
        ]);

        Message::create([
            'conversation_id' => $this->evalConversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => 'A message that must never surface to a real user.',
        ]);

        ToolInvocationRecord::create([
            'conversation_id' => $this->evalConversation->id,
            // (string) null === '' — the exact attribution D11 documents
            // for a null-user conversation's own metrics rows.
            'user_id' => '',
            'attempt_group_id' => (string) Str::uuid(),
            'tool_name' => 'contacts.create',
            'outcome' => 'success',
        ]);

        $this->fakeProvider();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        DB::table('tool_invocation_records')->delete();
        DB::table('messages')->delete();
        DB::table('conversations')->delete();
        DB::table('eval_case_results')->delete();
        DB::table('eval_run_cases')->delete();
        DB::table('eval_runs')->delete();
        DB::table('eval_case_versions')->delete();
        DB::table('eval_cases')->delete();
        DB::table('eval_suites')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixture helpers
    // ---------------------------------------------------------------

    private function declareSupportingSchema(): void
    {
        // AgentLoopService::run() consults ConversationCondenser on every
        // call, unconditionally — the RunSuiteJourneyTest precedent for
        // the identical need, since this file also drives run() for real
        // in its own positive case below.
        if (!Schema::hasTable('condensation_states')) {
            Schema::create('condensation_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            });
        }

        // Declared defensively in case the fixture's own turn ever
        // resolves a tool call — mcp_sessions.user_id must be nullable
        // to match the real migrated column (see this feature's Phase 3
        // progress log: the production fix for a user_id = null
        // conversation's first tool call).
        if (!Schema::hasTable('mcp_sessions')) {
            Schema::create('mcp_sessions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id')->nullable();
                $table->string('protocol_version');
                $table->string('client_name')->nullable();
                $table->string('client_version')->nullable();
                $table->json('capabilities')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('user_id');
            });
        }
    }

    private function fakeProvider(): void
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturn([
            'choices' => [['message' => ['content' => 'Hello! How can I help you today?']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]);
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);
        $this->app->instance(ProviderRegistry::class, $registry);
    }

    // ---------------------------------------------------------------
    // Absent from list-shaped reads (already structurally safe today —
    // every one of these already filters by an explicit real user_id, so
    // NULL can never match — but proven anyway, not assumed)
    // ---------------------------------------------------------------

    #[Test]
    public function the_eval_run_conversation_is_absent_from_the_conversation_index(): void
    {
        $response = $this->actingAs($this->realUser)->getJson('/api/clarion-app/llm-client/conversation');

        $response->assertStatus(200);
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertNotContains($this->evalConversation->id, $ids);
    }

    #[Test]
    public function the_eval_run_conversation_is_absent_from_user_conversations(): void
    {
        $response = $this->actingAs($this->realUser)
            ->getJson('/api/clarion-app/llm-client/user/'.$this->realUser->id.'/conversation');

        $response->assertStatus(200);
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertNotContains($this->evalConversation->id, $ids);
    }

    #[Test]
    public function the_eval_run_conversation_is_absent_from_mcp_resource_listing(): void
    {
        $result = app(McpResourceHandler::class)->listResources($this->realUser->id);

        $uris = collect($result['resources'])->pluck('uri')->all();
        $this->assertNotContains('conversation://'.$this->evalConversation->id, $uris);
    }

    // ---------------------------------------------------------------
    // 404, not 403, on every by-ID route (the actual gap D1 identifies —
    // mutation-checklist row 1, "the single most important row")
    // ---------------------------------------------------------------

    #[Test]
    public function every_by_id_route_returns_404_rather_than_exposing_or_mutating_the_eval_run_conversation(): void
    {
        $base = '/api/clarion-app/llm-client/conversation/'.$this->evalConversation->id;

        $this->actingAs($this->realUser)->getJson($base)
            ->assertStatus(404);

        $this->actingAs($this->realUser)->getJson($base.'/message')
            ->assertStatus(404);

        $this->actingAs($this->realUser)->putJson($base, [
            'title' => 'Hijacked title',
            'model' => 'test-model',
            'server_id' => $this->server->id,
        ])->assertStatus(404);

        $this->actingAs($this->realUser)->deleteJson($base)
            ->assertStatus(404);

        // None of the above may have mutated or deleted the row.
        $this->assertDatabaseHas('conversations', [
            'id' => $this->evalConversation->id,
            'title' => 'eval-run-case:'.$this->evalRunCase->id,
            'deleted_at' => null,
        ]);
    }

    // ---------------------------------------------------------------
    // The idle sweep must never treat an eval-run conversation as a real
    // session ending (mutation-checklist row 2 — the second,
    // independently-discovered leak vector, research.md D1)
    // ---------------------------------------------------------------

    #[Test]
    public function the_idle_sweep_never_fires_conversation_ended_for_the_eval_run_conversation(): void
    {
        Event::fake([ConversationEnded::class]);

        // Age the row well past any configured idle cutoff.
        DB::table('conversations')
            ->where('id', $this->evalConversation->id)
            ->update(['updated_at' => now()->subYears(1)]);

        app(ConversationLifecycleService::class)->endIdleConversations();

        Event::assertNotDispatched(
            ConversationEnded::class,
            fn (ConversationEnded $event) => $event->conversation_id === $this->evalConversation->id,
        );
    }

    // ---------------------------------------------------------------
    // The isolation mechanism's own positive case: the one internal path
    // that legitimately needs unscoped access must still work, against
    // this exact row, in the same test run.
    // ---------------------------------------------------------------

    #[Test]
    public function eval_case_executor_can_still_find_and_drive_this_exact_conversation_through_the_agent_loop(): void
    {
        $messageCountBefore = Message::where('conversation_id', $this->evalConversation->id)->count();

        app(EvalCaseExecutor::class)->execute($this->run->id, $this->evalRunCase->id);

        $result = EvalCaseResult::where('eval_run_case_id', $this->evalRunCase->id)->first();

        $this->assertNotNull($result, 'EvalCaseExecutor must have recorded a result for this case');
        $this->assertSame(
            $this->evalConversation->id,
            $result->conversation_id,
            "EvalCaseExecutor's own findOrCreateConversation() must reuse this exact seeded row, not silently ".
            "create a second one — proving the isolation scope's opt-in nature did not also break the one path ".
            'that legitimately needs to see it',
        );
        $this->assertNotSame('errored', $result->outcome->value, 'the run through the real agent loop must succeed');

        $messageCountAfter = Message::where('conversation_id', $this->evalConversation->id)->count();
        $this->assertGreaterThan(
            $messageCountBefore,
            $messageCountAfter,
            'AgentLoopService::run() must have actually written a new turn to this exact conversation',
        );
    }
}
