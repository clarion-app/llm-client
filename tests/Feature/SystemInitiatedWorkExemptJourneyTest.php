<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Exceptions\RateLimitExceededException;
use ClarionApp\LlmClient\Jobs\GenerateEpisodicMemoryJob;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\EpisodicMemory;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\RateLimit;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\EmbeddingService;
use ClarionApp\LlmClient\Services\RateLimitGate;
use ClarionApp\LlmClient\Services\RateLimitService;
use ClarionApp\LlmClient\Services\RoleResolver;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\RateLimitScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
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
    private Server $server;
    private Conversation $conversation;
    private string $counterKey;
    private int $windowSeconds = 60;

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

        Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00', 'UTC'));

        \Illuminate\Support\Facades\Http::fake();
        Queue::fake([SendHttpStreamRequest::class]);

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

    // ---------------------------------------------------------------
    // A genuine background job, executed as a job
    // ---------------------------------------------------------------

    /**
     * SC-007 names two scenarios by kind, not one: a retry and a background
     * job. This is the background-job half, and it runs the job's real
     * handle() rather than the funnel the job happens to call — the whole
     * point of the criterion is that queued follow-up work a user never
     * asked for cannot lock that user out.
     */
    #[Test]
    public function a_queued_background_job_running_on_the_users_behalf_is_never_refused_and_never_moves_the_counter(): void
    {
        $this->exhaustTheUsersWindow();

        $countBefore = Cache::get($this->counterKey);
        $this->assertIsInt($countBefore);

        $this->seedTranscript();

        $summary = json_encode([
            'summary' => 'The user chose canary deployments for the web-facing services.',
            'topics' => ['deployment', 'kubernetes'],
        ]);

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->once()->andReturn([
            'choices' => [['message' => ['content' => $summary]]],
        ]);

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);

        $embedding = Mockery::mock(EmbeddingService::class);
        $embedding->shouldReceive('isEnabled')->andReturn(false);
        $embedding->shouldReceive('generate')->andReturn(null);

        (new GenerateEpisodicMemoryJob($this->conversation->id, 'Clarion'))
            ->handle($registry, $embedding);

        $this->assertSame(
            1,
            EpisodicMemory::withoutGlobalScope('user')->count(),
            'The background job must actually complete its work, not be refused before it starts.'
        );

        $this->assertSame(
            $countBefore,
            Cache::get($this->counterKey),
            'A queued job the system dispatched on the user\'s behalf must not spend a unit of '
            .'the allowance the user needs for work they actually chose to start.'
        );
    }

    // ---------------------------------------------------------------
    // A retry inside an already-admitted turn
    // ---------------------------------------------------------------

    /**
     * The retry half of SC-007, driven through the real retry path rather
     * than asserted about it: a first model response that violates the
     * requested schema makes AgentLoopService::run() re-enter its own loop
     * with a correction prompt. That second model call is the system
     * retrying after a transient failure — the user started one request and
     * must be charged for exactly one, however many attempts it takes
     * internally.
     */
    #[Test]
    public function a_retry_inside_an_admitted_turn_never_costs_the_user_a_second_unit(): void
    {
        $responses = [
            'I have replied in prose rather than the JSON that was asked for.',
            json_encode(['answer' => 'Corrected.']),
        ];

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function () use (&$responses) {
            return [
                'choices' => [['message' => ['content' => array_shift($responses)]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ];
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);
        $this->app->instance(ProviderRegistry::class, $registry);

        $result = app(AgentLoopService::class)->run(
            $this->conversation,
            'Answer in the required shape.',
            [
                'schema' => [
                    'type' => 'object',
                    'properties' => ['answer' => ['type' => 'string']],
                    'required' => ['answer'],
                ],
                'retry_on_validation_failure' => true,
                'max_schema_retries' => 2,
            ],
        );

        $this->assertSame('completed', $result['status']);
        $this->assertEmpty($responses, 'Both model calls must have happened — otherwise no retry occurred.');

        $this->assertSame(
            1,
            Cache::get($this->counterKey),
            'A turn that retried internally consumed one unit of the window, not one per attempt.'
        );
    }

    private function seedTranscript(): void
    {
        $lines = [
            ['user', 'I need to plan the deployment strategy for our microservices architecture, with five services going to Kubernetes.'],
            ['assistant', 'Canary deployments allow a gradual rollout and early detection of issues; blue-green gives instant rollback.'],
            ['user', 'Let us go with canary deployments for the web-facing services and document that decision.'],
        ];

        foreach ($lines as [$role, $content]) {
            Message::create([
                'conversation_id' => $this->conversation->id,
                'role' => $role,
                'content' => $content,
                'user' => 'Clarion',
            ]);
        }
    }
}
