<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A refused request writes nothing into the conversation.
 *
 * "Nothing" is stronger than it first sounds, and the interesting half of
 * it is the *user's* turn, not the assistant's. Both entry paths persist
 * the user's message before any model is called — MessageController::store()
 * with Message::create(), and AgentLoopService::run() with its own — so a
 * gate placed anywhere but first leaves a message in the transcript for
 * work that never happened. Nothing downstream can tell that message apart
 * from one that was answered: condensation will summarise it, memory
 * capture will remember it, and the next turn's context will include it.
 *
 * Two more things must be true for a refusal to be a clean no-op:
 *
 *  - is_processing stays false. run()/start() set it before anything else,
 *    and there is no path that clears it for a request that never ran, so a
 *    late gate wedges the conversation permanently.
 *  - Exactly one agent_runs row exists for the refused work, closed
 *    stopped_early. agent_runs is not the transcript — it is the operator's
 *    record of the stop — so this file asserts both facts explicitly rather
 *    than letting "nothing was written" quietly mean two different things
 *    in two places.
 */
class RefusalLeavesTranscriptUntouchedTest extends TestCase
{
    private User $user;
    private Server $server;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('condensation_states')) {
            Schema::create('condensation_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            });
        }

        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00', 'UTC'));

        config(['llm-client.run_trace.enabled' => true]);

        $this->user = User::factory()->create();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'llama_cpp',
        ]);

        $this->conversation = Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
            'title' => 'Already titled',
        ]);

        \Illuminate\Support\Facades\Http::fake();
        Queue::fake([SendHttpStreamRequest::class]);

        $provider = Mockery::mock(\ClarionApp\LlmClient\Contracts\LlmProvider::class);
        $provider->shouldReceive('chat')->andReturn([
            'choices' => [['message' => ['content' => 'Here is your answer.']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]);
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(\ClarionApp\LlmClient\Providers\ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);
        $this->app->instance(\ClarionApp\LlmClient\Providers\ProviderRegistry::class, $registry);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        DB::table('cost_summaries')->delete();
        DB::table('spending_ceilings')->delete();
        DB::table('agent_runs')->delete();

        Mockery::close();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function blockTheUser(): void
    {
        app(SpendingCeilingService::class)->upsert(
            BudgetScope::UserDefault,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            ['amount' => '25.00', 'period_type' => 'month', 'enforcement_mode' => 'stop'],
        );

        DB::table('cost_summaries')->insert([
            'id' => (string) Str::uuid(),
            'entity_type' => CostSummary::ENTITY_USER,
            'entity_id' => $this->user->id,
            'user_id' => $this->user->id,
            'period_date' => '2026-08-14',
            'request_count' => 1,
            'priced_cost_total' => '30.0000000000',
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => Carbon::now(),
        ]);
    }

    /** An existing exchange, so "unchanged" has something to be unchanged from. */
    private function existingExchange(): void
    {
        Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'user',
            'content' => 'An earlier question.',
            'user' => 'User',
        ]);
        Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'assistant',
            'content' => 'An earlier answer.',
            'user' => 'Clarion',
        ]);
    }

    /** Every stored message row, ordered, as a comparable array. */
    private function transcript(): array
    {
        return DB::table('messages')
            ->where('conversation_id', $this->conversation->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function newRequestBoundary(): void
    {
        $this->app->forgetScopedInstances();
    }

    // ---------------------------------------------------------------
    // POST /message — the streamed entry path
    // ---------------------------------------------------------------

    #[Test]
    public function a_refused_message_post_stores_no_user_turn_and_no_assistant_turn(): void
    {
        $this->existingExchange();
        $this->blockTheUser();

        $before = $this->transcript();

        $response = $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/message', [
            'content' => 'A question that will never be answered.',
            'conversation_id' => $this->conversation->id,
        ]);

        $response->assertStatus(402);

        $this->assertSame(
            $before,
            $this->transcript(),
            'The stored history must be identical: the gate has to run before Message::create(), '
            .'not after it'
        );

        $this->assertSame(
            0,
            Message::where('conversation_id', $this->conversation->id)
                ->where('content', 'A question that will never be answered.')
                ->count()
        );
    }

    #[Test]
    public function a_refused_message_post_leaves_the_conversation_not_processing(): void
    {
        $this->blockTheUser();

        $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/message', [
            'content' => 'A question that will never be answered.',
            'conversation_id' => $this->conversation->id,
        ])->assertStatus(402);

        $this->assertFalse((bool) $this->conversation->fresh()->is_processing);
    }

    // ---------------------------------------------------------------
    // POST /agent — the synchronous entry path
    // ---------------------------------------------------------------

    #[Test]
    public function a_refused_agent_post_stores_no_user_turn_and_no_assistant_turn(): void
    {
        $this->existingExchange();
        $this->blockTheUser();

        $before = $this->transcript();

        $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'A question that will never be answered.',
            'conversation_id' => $this->conversation->id,
        ])->assertStatus(402);

        $this->assertSame($before, $this->transcript());
    }

    #[Test]
    public function a_refused_agent_post_leaves_the_conversation_not_processing(): void
    {
        $this->blockTheUser();

        $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'A question that will never be answered.',
            'conversation_id' => $this->conversation->id,
        ])->assertStatus(402);

        $this->assertFalse(
            (bool) $this->conversation->fresh()->is_processing,
            'A refusal that runs after is_processing is set leaves the conversation wedged, '
            .'with nothing to clear it'
        );
    }

    // ---------------------------------------------------------------
    // agent_runs is not the transcript
    // ---------------------------------------------------------------

    #[Test]
    public function a_refused_request_opens_exactly_one_stopped_early_run_and_no_other(): void
    {
        $this->blockTheUser();

        $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'A question that will never be answered.',
            'conversation_id' => $this->conversation->id,
        ])->assertStatus(402);

        $runs = DB::table('agent_runs')->get();

        $this->assertCount(
            1,
            $runs,
            'One refused unit of work is exactly one run row — not zero (invisible to operators) '
            .'and not two (the work never started, so there is nothing else to record)'
        );
        $this->assertSame('stopped_early', $runs[0]->end_state);
        $this->assertNotNull($runs[0]->end_reason);
        $this->assertSame($this->user->id, $runs[0]->user_id);

        // And no step or message link was fabricated for work that never ran.
        $this->assertSame(0, DB::table('agent_run_messages')->count());
    }

    /**
     * The distinction this file exists to make explicit: the transcript is
     * untouched *and* the refusal is recorded. Neither fact is allowed to be
     * inferred from the other.
     */
    #[Test]
    public function the_recorded_refusal_does_not_come_at_the_cost_of_an_untouched_transcript(): void
    {
        $this->existingExchange();
        $this->blockTheUser();

        $before = $this->transcript();

        $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'A question that will never be answered.',
            'conversation_id' => $this->conversation->id,
        ])->assertStatus(402);

        $this->assertSame($before, $this->transcript());
        $this->assertSame(1, DB::table('agent_runs')->where('end_state', 'stopped_early')->count());
    }

    // ---------------------------------------------------------------
    // The unrefused case, so "identical" is not identical to "nothing happens"
    // ---------------------------------------------------------------

    #[Test]
    public function an_admitted_request_does_change_the_transcript(): void
    {
        $this->existingExchange();

        $before = $this->transcript();

        $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'A question that will be answered.',
            'conversation_id' => $this->conversation->id,
        ])->assertStatus(200);

        $this->assertNotSame(
            $before,
            $this->transcript(),
            'Precondition for every assertion above: this comparison does detect a change'
        );
        $this->assertSame(4, count($this->transcript()));
    }
}
