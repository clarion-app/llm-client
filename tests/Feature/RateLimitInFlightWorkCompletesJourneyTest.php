<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Exceptions\RateLimitExceededException;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\RateLimit;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\RateLimitCounter;
use ClarionApp\LlmClient\Services\RateLimitService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\RateLimitScope;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * A request already admitted and in progress is allowed to finish even if
 * the user's window fills up entirely before the last byte is sent.
 *
 * AgentLoopStreamHandler re-enters AgentLoopService::start($conversation,
 * $iteration + 1, $this->runId) on every streaming iteration with the run id
 * carried forward, and start()'s rate-limit gate only fires when $runId is
 * null. A non-null run id is therefore the "already executing" signal — the
 * exact same condition the spending-ceiling feature relies on for its own
 * identically-named property, one call site over. This file proves the
 * property holds for the rate limiter specifically: filling the user's
 * window mid-stream must never turn a later continuation into a refusal.
 *
 * The opposite mistake is equally worth ruling out: dropping the gate from
 * start() entirely would also make every continuation "succeed," for the
 * wrong reason. The last case in this file makes sure a null run id — new
 * work — is still gated, so the property under test is genuinely "in-flight
 * work is exempt," not "the gate stopped working."
 */
class RateLimitInFlightWorkCompletesJourneyTest extends TestCase
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

        Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00', 'UTC'));
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        DB::table('rate_limits')->delete();
        DB::table('agent_runs')->delete();

        Mockery::close();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function declareTightLimit(int $maxRequests, int $windowSeconds = 60): void
    {
        app(RateLimitService::class)->upsert(
            RateLimitScope::UserDefault,
            RateLimit::INSTALLATION_SCOPE_ID,
            ['max_requests' => $maxRequests, 'window_seconds' => $windowSeconds],
        );
    }

    /**
     * Simulate other concurrent activity filling the user's window entirely,
     * without going through AgentLoopService at all — the point of this
     * file is what happens to a turn already in progress, not how the
     * window came to be full.
     */
    private function fillTheUsersWindow(int $windowSeconds = 60): void
    {
        for ($i = 0; $i < 10; $i++) {
            app(RateLimitCounter::class)->increment((string) $this->user->id, $windowSeconds);
        }
    }

    private function fakeProvider(?\Closure $onChat = null): void
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function () use ($onChat) {
            if ($onChat !== null) {
                $onChat();
            }

            return [
                'choices' => [['message' => ['content' => 'A whole answer, start to finish.']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ];
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);
        $this->app->instance(ProviderRegistry::class, $registry);
    }

    // ---------------------------------------------------------------
    // The streamed continuation
    // ---------------------------------------------------------------

    #[Test]
    public function every_streaming_continuation_proceeds_after_the_window_fills_up_mid_turn(): void
    {
        $this->fakeProvider();
        $this->declareTightLimit(maxRequests: 5, windowSeconds: 60);

        $agentLoop = app(AgentLoopService::class);
        $recorder = app(RunTraceRecorder::class);

        // A turn begins: the run is opened, which is what "executing" means.
        $runId = $recorder->openRun(
            RunKind::Interactive,
            (string) $this->user->id,
            $this->conversation->id,
            streamed: true,
        );
        $this->assertNotNull($runId);

        $agentLoop->start($this->conversation, 1, $runId);

        // ...and mid-turn, the user's window fills up completely from other
        // activity that has nothing to do with this already-admitted turn.
        $this->fillTheUsersWindow();

        // Every subsequent iteration is a re-entry into start() with the run
        // id carried forward — verbatim the call AgentLoopStreamHandler makes
        // at each iteration.
        for ($iteration = 2; $iteration <= 5; $iteration++) {
            // A fresh container scope per iteration, so the continuation is
            // not being carried by a remembered admission from iteration 1 —
            // the run id alone has to be enough.
            $this->app->forgetScopedInstances();

            $agentLoop->start($this->conversation, $iteration, $runId);
        }

        Queue::assertPushed(SendHttpStreamRequest::class, 5);
    }

    /**
     * The same property stated as the exception it must not throw, so a
     * failure names the defect rather than an absent queue job.
     */
    #[Test]
    public function a_continuation_carrying_a_run_id_never_throws_a_rate_limit_refusal(): void
    {
        $this->fakeProvider();
        $this->declareTightLimit(maxRequests: 1, windowSeconds: 60);
        $this->fillTheUsersWindow();

        $runId = app(RunTraceRecorder::class)->openRun(
            RunKind::Interactive,
            (string) $this->user->id,
            $this->conversation->id,
            streamed: true,
        );

        try {
            app(AgentLoopService::class)->start($this->conversation, 3, $runId);
        } catch (RateLimitExceededException $e) {
            $this->fail(
                'The streaming continuation was refused. A non-null run id means the work is '
                .'already executing; gating it truncates a response the user is already reading.'
            );
        }

        Queue::assertPushed(SendHttpStreamRequest::class);
    }

    /**
     * The condition is only meaningful if the *other* branch is still
     * gated. Stated here so this file cannot be satisfied by deleting the
     * gate from start() altogether.
     */
    #[Test]
    public function a_null_run_id_is_new_work_and_is_still_gated(): void
    {
        $this->fakeProvider();
        $this->declareTightLimit(maxRequests: 1, windowSeconds: 60);
        $this->fillTheUsersWindow();

        $this->expectException(RateLimitExceededException::class);

        app(AgentLoopService::class)->start($this->conversation, 1, null);
    }

    // ---------------------------------------------------------------
    // The synchronous turn
    // ---------------------------------------------------------------

    #[Test]
    public function a_synchronous_turn_already_past_its_gate_is_unaffected_by_the_window_filling_during_it(): void
    {
        // The window fills *while the model call is in progress*, which is
        // the only moment that matters: the gate has already run, and there
        // is deliberately no second check inside the loop.
        $this->fakeProvider(onChat: fn () => $this->fillTheUsersWindow());

        $this->declareTightLimit(maxRequests: 5, windowSeconds: 60);

        $result = app(AgentLoopService::class)->run($this->conversation, 'A question mid-fill.');

        $this->assertSame('completed', $result['status']);
        $this->assertSame('A whole answer, start to finish.', $result['content']);

        // The whole exchange is on record — not a user turn with no answer.
        $this->assertSame(2, Message::where('conversation_id', $this->conversation->id)->count());
        $this->assertSame(
            1,
            Message::where('conversation_id', $this->conversation->id)->where('role', 'assistant')->count()
        );
    }
}
