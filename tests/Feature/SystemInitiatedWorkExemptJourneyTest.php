<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Exceptions\RateLimitExceededException;
use ClarionApp\LlmClient\Models\RateLimit;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\EmbeddingService;
use ClarionApp\LlmClient\Services\RateLimitGate;
use ClarionApp\LlmClient\Services\RateLimitService;
use ClarionApp\LlmClient\Services\RoleResolver;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\RateLimitScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * Work the system initiates on a user's behalf never consumes that user's
 * own rate-limit allowance, because it is never presented to RateLimitGate
 * at all — a structural exemption, not a case-by-case one.
 *
 * RateLimitCallSiteGuardTest already proves the structural half of this: the
 * closed set of files calling RateLimitGate::admit() is exactly
 * {AgentLoopService.php, MessageController.php}, and RunTraceRecorder never
 * mentions RateLimitGate at all. What that test cannot show is what actually
 * happens to a request when the funnel it wraps runs against an exhausted
 * window — this file drives the funnel for real, with the user's window
 * genuinely exhausted first, and checks three things together: the work
 * runs and returns a real result, it is never refused, and — the detail
 * that distinguishes "exempt" from "counted but never denied" — the raw
 * counter the user's own next genuine request would read is bit-for-bit
 * unchanged by the system-initiated call.
 */
class SystemInitiatedWorkExemptJourneyTest extends TestCase
{
    private User $user;
    private string $counterKey;
    private int $windowSeconds = 60;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00', 'UTC'));

        $this->user = User::factory()->create();

        app(RateLimitService::class)->upsert(
            RateLimitScope::UserDefault,
            RateLimit::INSTALLATION_SCOPE_ID,
            ['max_requests' => 1, 'window_seconds' => $this->windowSeconds],
        );

        $windowStart = intdiv(Carbon::now()->timestamp, $this->windowSeconds) * $this->windowSeconds;
        $this->counterKey = 'llm-client:rate-limit:user:'
            .$this->user->id.':'.$this->windowSeconds.':'.$windowStart;
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

    /**
     * Consume the one request this window allows, then confirm a second
     * genuine interactive request is refused — grounding every assertion
     * below in a window that is actually exhausted, not merely configured
     * tight.
     */
    private function exhaustTheUsersWindow(): void
    {
        app(RateLimitGate::class)->admit((string) $this->user->id, BudgetWorkKind::Interactive);

        // A fresh scope: the gate's own admitted-once-per-instance memo must
        // not stand in for what the counter itself now holds.
        $this->app->forgetScopedInstances();

        try {
            app(RateLimitGate::class)->admit((string) $this->user->id, BudgetWorkKind::Interactive);
            $this->fail('The window must already be exhausted before the exemption is exercised.');
        } catch (RateLimitExceededException $e) {
            // Expected.
        }

        $this->app->forgetScopedInstances();
    }

    // ---------------------------------------------------------------
    // The traceSystemRun() funnel
    // ---------------------------------------------------------------

    #[Test]
    public function work_wrapped_by_trace_system_run_succeeds_and_never_touches_the_users_own_counter(): void
    {
        $this->exhaustTheUsersWindow();

        $countBefore = Cache::get($this->counterKey);
        $this->assertIsInt($countBefore, "The user's window must already hold a real count before the exempt call.");

        $reached = false;

        $result = app(RunTraceRecorder::class)->traceSystemRun(
            'embedding',
            (string) $this->user->id,
            null,
            function () use (&$reached) {
                $reached = true;

                return 'the embedding vector';
            },
        );

        $this->assertTrue($reached, 'The wrapped work must actually run, not be refused before it starts.');
        $this->assertSame('the embedding vector', $result);

        $this->assertSame(
            $countBefore,
            Cache::get($this->counterKey),
            'System-initiated work must never touch the user\'s own rate-limit counter at all — '
            .'exempt by construction, not merely counted-but-never-denied.'
        );
    }

    #[Test]
    public function a_genuine_interactive_request_is_still_refused_after_the_exempt_call(): void
    {
        $this->exhaustTheUsersWindow();

        app(RunTraceRecorder::class)->traceSystemRun(
            'title_generation',
            (string) $this->user->id,
            null,
            fn () => 'a title',
        );

        $this->app->forgetScopedInstances();

        $this->expectException(RateLimitExceededException::class);

        app(RateLimitGate::class)->admit((string) $this->user->id, BudgetWorkKind::Interactive);
    }

    // ---------------------------------------------------------------
    // A concrete system-initiated path: EmbeddingService::generate()
    // ---------------------------------------------------------------

    /**
     * Reached from inside an already-admitted turn (AutoMemoryRetriever,
     * MemoryService, DeclarativeMemoryService embed a query while building
     * context) as well as standalone from background jobs. It calls
     * BudgetGate directly and never RateLimitGate — RateLimitCallSiteGuardTest
     * proves that structurally; this proves it behaviorally, against a
     * window that is genuinely exhausted.
     */
    #[Test]
    public function embedding_service_generate_is_never_refused_and_never_moves_the_counter(): void
    {
        $this->exhaustTheUsersWindow();

        $countBefore = Cache::get($this->counterKey);

        $mockProvider = Mockery::mock(LlmProvider::class);
        $mockProvider->shouldReceive('embed')->once()->andReturn([
            'embeddings' => [[0.1, 0.2, 0.3]],
        ]);

        $registry = app(ProviderRegistry::class);
        $registry->register(ProviderType::OpenAI, fn () => $mockProvider);

        $server = Server::create([
            'name' => 'Embedding Server',
            'server_url' => 'https://api.example.com',
            'token' => 'test-token',
            'provider_type' => ProviderType::OpenAI,
        ]);

        config(['llm-client.memory.embedding.enabled' => true]);
        config(['llm-client.memory.embedding.server_id' => $server->id]);

        $service = new EmbeddingService($registry, new RoleResolver());

        $embedding = $service->generate('content embedded on the user\'s behalf', null, (string) $this->user->id);

        $this->assertSame([0.1, 0.2, 0.3], $embedding);

        $this->assertSame(
            $countBefore,
            Cache::get($this->counterKey),
            "EmbeddingService::generate() must never move the user's rate-limit counter — "
            .'it is reached inside already-admitted turns and standalone jobs alike, and neither '
            .'may spend the user\'s own allowance.'
        );
    }
}
