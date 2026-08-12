<?php

namespace ClarionApp\LlmClient;

use ClarionApp\Backend\ClarionPackageServiceProvider;
use ClarionApp\Backend\Events\InstallComposerPackageEvent;
use ClarionApp\Backend\Events\UninstallComposerPackageEvent;
use ClarionApp\LlmClient\Commands\EmbedMemoryCommand;
use ClarionApp\LlmClient\Commands\ReindexOperationsCommand;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Listeners\ReindexOnPackageChange;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\AnthropicProvider;
use ClarionApp\LlmClient\Providers\LlamaCppProvider;
use ClarionApp\LlmClient\Providers\OpenAiProvider;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\ContextWindowBudgeter;
use ClarionApp\LlmClient\Services\ConversationCondenser;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpPromptRegistry;
use ClarionApp\LlmClient\Services\McpResourceHandler;
use ClarionApp\LlmClient\Services\MessageFormatter;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\OperationsSearchService;
use ClarionApp\LlmClient\Services\StructuredOutputPresetRegistry;
use ClarionApp\LlmClient\Services\SchemaMerger;
use ClarionApp\LlmClient\Services\ToolFormatter;
use ClarionApp\LlmClient\Services\MemoryService;
use ClarionApp\LlmClient\Services\MemoryEvictionService;
use ClarionApp\LlmClient\Services\EmbeddingService;
use ClarionApp\LlmClient\Services\EndpointResolver;
use ClarionApp\LlmClient\Services\RoleResolver;
use ClarionApp\LlmClient\Services\DeclarativeMemoryService as DeclarativeMemoryServiceImpl;
use ClarionApp\LlmClient\Services\EpisodicMemoryService;
use ClarionApp\LlmClient\Services\EpisodicMemorySearchService;
use ClarionApp\LlmClient\Contracts\DeclarativeMemoryService as DeclarativeMemoryServiceContract;
use ClarionApp\LlmClient\Contracts\FeedbackSignalAccumulator as FeedbackSignalAccumulatorContract;
use ClarionApp\LlmClient\Contracts\MemoryService as MemoryServiceContract;
use ClarionApp\LlmClient\Contracts\EpisodicMemoryService as EpisodicMemoryServiceContract;
use ClarionApp\LlmClient\Services\FeedbackSignalAccumulator as FeedbackSignalAccumulatorImpl;
use ClarionApp\LlmClient\Events\AgentTurnCompleted;
use ClarionApp\LlmClient\Events\ConversationEnded;
use ClarionApp\LlmClient\Events\EpisodicMemoryGenerationFailed;
use ClarionApp\LlmClient\Events\FeedbackReceived;
use ClarionApp\LlmClient\Listeners\CleanupScratchMemory;
use ClarionApp\LlmClient\Listeners\CleanupShortTermMemory;
use ClarionApp\LlmClient\Listeners\PersistFeedbackSignal;
use ClarionApp\LlmClient\Presets\DecisionPreset;
use ClarionApp\LlmClient\Presets\SummaryPreset;
use ClarionApp\LlmClient\Presets\ExtractionPreset;
use GuzzleHttp\Client;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

class LlmClientServiceProvider extends ClarionPackageServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        $this->loadMigrationsFrom(__DIR__.'/Migrations');

        $this->app->booted(function () {
            if(!$this->app->routesAreCached())
            {
                require __DIR__.'/Routes.php';
            }
        });

        // Register event listeners for package install/uninstall
        Event::listen(
            [InstallComposerPackageEvent::class, UninstallComposerPackageEvent::class],
            ReindexOnPackageChange::class
        );

        // Register memory lifecycle event listeners
        Event::listen(AgentTurnCompleted::class, CleanupScratchMemory::class);
        Event::listen(ConversationEnded::class, CleanupShortTermMemory::class);

        // Register episodic memory event listener (dispatch job on conversation end)
        Event::listen(ConversationEnded::class, function ($event) {
            $job = new \ClarionApp\LlmClient\Jobs\GenerateEpisodicMemoryJob(
                $event->conversation_id,
                $event->agent_id
            );
            dispatch($job);
        });

        // Register feedback signal persistence listener
        Event::listen(FeedbackReceived::class, PersistFeedbackSignal::class);

        // Register broadcast channel for episodic memory failure notifications
        \Illuminate\Support\Facades\Broadcast::channel('user.{userId}.episodic-memory-failed', function ($user, $userId) {
            return (string) $user->id === (string) $userId;
        });

        // Register broadcast channel for preference proposal notifications
        \Illuminate\Support\Facades\Broadcast::channel('user.{userId}.preference-proposal', function ($user, $userId) {
            return (string) $user->id === (string) $userId;
        });

        // Register Conversation observer for operation cache cleanup
        \ClarionApp\LlmClient\Models\Conversation::observe(
            \ClarionApp\LlmClient\Observers\ConversationObserver::class
        );

        // Register Artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                ReindexOperationsCommand::class,
                EmbedMemoryCommand::class,
                \ClarionApp\LlmClient\Commands\CleanupExpiredEpisodicMemoriesCommand::class,
                \ClarionApp\LlmClient\Commands\EndIdleConversationsCommand::class,
                \ClarionApp\LlmClient\Commands\PurgeExpiredContextManagementMetricsCommand::class,
                \ClarionApp\LlmClient\Commands\ResolveAbandonedRunsCommand::class,
                \ClarionApp\LlmClient\Commands\PurgeExpiredRunTracesCommand::class,
                \ClarionApp\LlmClient\Commands\ForwardRunTracesCommand::class,
                \ClarionApp\LlmClient\Commands\MigrateUserSettingsCommand::class,
                \ClarionApp\LlmClient\Commands\ResolveStalledEvalRunsCommand::class,
                \ClarionApp\LlmClient\Commands\RecomputeEvalPassRateSummariesCommand::class,
            ]);
        }

        // Named rate limiter bounding how many eval-run case executions
        // may be admitted to run per minute, installation-wide
        // (research.md D9) — attached to every RunEvalCaseJob via its own
        // middleware(). Independent of BudgetGate (money); this is a
        // throughput/saturation concern, not a spend one.
        RateLimiter::for('eval-run-cases', function () {
            // Null-coalesced rather than relying solely on config()'s own
            // default argument: Illuminate\Config\Repository::offsetUnset()
            // sets a key's value to null rather than removing it, so an
            // explicitly-null config value must fall back to the
            // documented default the same way a genuinely absent key
            // does — otherwise (int) null silently becomes 0, disabling
            // eval-run case throughput entirely.
            return Limit::perMinute((int) (config('llm-client.eval_runs.max_cases_per_minute') ?? 30));
        });

        // Nothing else ends a conversation session, so this sweep is what makes
        // short-term memory cleanup and episodic capture happen at all. Registered
        // here rather than left to the host app, because forgetting it does not
        // fail loudly — memories simply never get captured.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('llm-client:end-idle-conversations')
                ->everyFiveMinutes()
                ->withoutOverlapping();

            // Purge expired context management metrics daily.
            // User summaries are lifetime rollups and are always exempted.
            $schedule->command('llm-client:purge-context-metrics')
                ->daily()
                ->withoutOverlapping();

            // Resolve abandoned (stale in_progress) agent runs every five minutes.
            $schedule->command('llm-client:resolve-abandoned-runs')
                ->everyFiveMinutes()
                ->withoutOverlapping();

            // Resolve stalled (stale in_progress) eval runs every five
            // minutes — the automatic half of research.md D8's
            // resumption mechanism, so an operator does not have to
            // notice and manually resume every interrupted run.
            $schedule->command('llm-client:resolve-stalled-eval-runs')
                ->everyFiveMinutes()
                ->withoutOverlapping();

            // Purge expired agent run traces daily.
            $schedule->command('llm-client:purge-run-traces')
                ->daily()
                ->withoutOverlapping();

            // Drain the external-forwarding buffer every minute. A tick with
            // nothing due (forwarding disabled, or nothing queued) is a no-op.
            $schedule->command('llm-client:forward-run-traces')
                ->everyMinute()
                ->withoutOverlapping();
        });

        // Populate provider registry with factory callables
        $this->registerProviders();

        // Register built-in structured output presets
        $this->registerPresets();
    }

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(
            __DIR__.'/../config/llm-client.php', 'llm-client'
        );

        $this->app->singleton(OperationCache::class, function ($app) {
            // Resolve the store named in config; null falls back to the
            // application default. Passing $app['cache.store'] here instead
            // would silently ignore operation_cache.store and can leave the
            // cache on a per-worker store, reproducing the process-local bug.
            $storeName = $app['config']->get('llm-client.operation_cache.store');

            return new OperationCache(null, $app['cache']->store($storeName));
        });

        $this->app->singleton(ProviderRegistry::class, function () {
            return new ProviderRegistry();
        });

        $this->app->singleton(ToolFormatter::class, function ($app) {
            return new ToolFormatter();
        });

        $this->app->singleton(\ClarionApp\LlmClient\Services\ChunkPartitioner::class, function ($app) {
            return new \ClarionApp\LlmClient\Services\ChunkPartitioner();
        });

        $this->app->singleton(\ClarionApp\LlmClient\Services\CondensationSummaryStore::class, function ($app) {
            return new \ClarionApp\LlmClient\Services\CondensationSummaryStore($app['cache.store']);
        });

        $this->app->singleton(\ClarionApp\LlmClient\Services\MessageScorer::class, function ($app) {
            return new \ClarionApp\LlmClient\Services\MessageScorer();
        });

        $this->app->singleton(\ClarionApp\LlmClient\Services\CoherenceValidator::class, function ($app) {
            return new \ClarionApp\LlmClient\Services\CoherenceValidator();
        });

        $this->app->singleton(\ClarionApp\LlmClient\Services\SmartHistoryTrimmer::class, function ($app) {
            return new \ClarionApp\LlmClient\Services\SmartHistoryTrimmer(
                $app->make(\ClarionApp\LlmClient\Services\MessageScorer::class),
                $app->make(\ClarionApp\LlmClient\Services\CoherenceValidator::class)
            );
        });

        $this->app->singleton(ConversationCondenser::class, function ($app) {
            return new ConversationCondenser(
                $app->make(\ClarionApp\LlmClient\Services\ChunkPartitioner::class),
                $app->make(\ClarionApp\LlmClient\Services\CondensationSummaryStore::class),
                $app->make(ContextWindowBudgeter::class),
                $app->make(\ClarionApp\LlmClient\Presets\CondensationPreset::class),
                $app->make(\ClarionApp\LlmClient\Services\SmartHistoryTrimmer::class),
                null,
                $app->make(ProviderRegistry::class)
            );
        });

        $this->app->singleton(ContextWindowBudgeter::class, function ($app) {
            return new ContextWindowBudgeter();
        });

        $this->app->singleton(AgentLoopService::class, function ($app) {
            return new AgentLoopService(
                $app->make(McpToolRegistry::class),
                $app->make(McpToolExecutor::class),
                $app->make(OperationCache::class),
                $app->make(ProviderRegistry::class),
                $app->make(MessageFormatter::class),
                $app->make(ToolFormatter::class),
                null,
                $app->make(StructuredOutputPresetRegistry::class),
                $app->make(MemoryServiceContract::class),
                null,
                null,
                $app->make(ContextWindowBudgeter::class),
                $app->make(ConversationCondenser::class),
                null,
                null,
                $app->make(\ClarionApp\LlmClient\Services\AutoMemoryRetriever::class),
                $app->make(\ClarionApp\LlmClient\Services\MetricsRecorder::class),
                $app->make(\ClarionApp\LlmClient\Services\RunTraceRecorder::class)
            );
        });

        $this->app->singleton(McpPromptRegistry::class, function ($app) {
            return new McpPromptRegistry();
        });

        $this->app->singleton(McpResourceHandler::class, function ($app) {
            return new McpResourceHandler();
        });

        $this->app->singleton(OperationsSearchService::class, function ($app) {
            return new OperationsSearchService();
        });

        $this->app->singleton(SchemaMerger::class, function () {
            return new SchemaMerger();
        });

        $this->app->singleton(StructuredOutputPresetRegistry::class, function ($app) {
            return new StructuredOutputPresetRegistry($app->make(SchemaMerger::class));
        });

        // Register memory services as singletons
        $this->app->singleton(MemoryEvictionService::class, function () {
            return new MemoryEvictionService();
        });

        $this->app->singleton(EmbeddingService::class, function ($app) {
            return new EmbeddingService(
                $app->make(ProviderRegistry::class),
                $app->make(RoleResolver::class)
            );
        });

        $this->app->singleton(MemoryServiceContract::class, function ($app) {
            return new MemoryService(
                $app->make(MemoryEvictionService::class),
                $app->make(EmbeddingService::class)
            );
        });

        // Register episodic memory services as singletons
        $this->app->singleton(EpisodicMemoryServiceContract::class, function ($app) {
            return new EpisodicMemoryService();
        });

        $this->app->singleton(EpisodicMemorySearchService::class, function ($app) {
            return new EpisodicMemorySearchService(
                $app->make(EmbeddingService::class)
            );
        });

        // Register declarative memory service
        $this->app->singleton(DeclarativeMemoryServiceContract::class, function ($app) {
            return new DeclarativeMemoryServiceImpl(
                $app->make(EmbeddingService::class)
            );
        });

        // Register feedback signal accumulator
        $this->app->singleton(FeedbackSignalAccumulatorContract::class, function () {
            return new FeedbackSignalAccumulatorImpl();
        });

        // Register auto memory retriever
        $this->app->singleton(\ClarionApp\LlmClient\Services\AutoMemoryRetriever::class, function ($app) {
            return new \ClarionApp\LlmClient\Services\AutoMemoryRetriever(
                $app->make(DeclarativeMemoryServiceContract::class),
                $app->make(EpisodicMemorySearchService::class),
                $app->make(MemoryServiceContract::class),
                $app->make(EmbeddingService::class),
                $app->make(\ClarionApp\LlmClient\Services\PreferenceInjector::class),
                $app->make(\ClarionApp\LlmClient\Services\MetricsRecorder::class)
            );
        });

        // Register run trace services
        $this->app->singleton(\ClarionApp\LlmClient\Services\RunTraceRecorder::class, function () {
            return new \ClarionApp\LlmClient\Services\RunTraceRecorder();
        });

        $this->app->singleton(\ClarionApp\LlmClient\Services\RunTraceQuery::class, function () {
            return new \ClarionApp\LlmClient\Services\RunTraceQuery();
        });

        // Register role resolution services
        $this->app->singleton(\ClarionApp\LlmClient\Services\RoleResolver::class, function ($app) {
            return new \ClarionApp\LlmClient\Services\RoleResolver(
                $app->make(ProviderRegistry::class)
            );
        });

        $this->app->singleton(\ClarionApp\LlmClient\Services\RoleAssignmentService::class, function ($app) {
            return new \ClarionApp\LlmClient\Services\RoleAssignmentService(
                $app->make(\ClarionApp\LlmClient\Services\RoleResolver::class)
            );
        });

        $this->app->singleton(\ClarionApp\LlmClient\Services\RoleTestRunner::class, function ($app) {
            return new \ClarionApp\LlmClient\Services\RoleTestRunner(
                $app->make(\ClarionApp\LlmClient\Services\RoleResolver::class),
                $app->make(ProviderRegistry::class)
            );
        });

        // Register endpoint resolver
        $this->app->singleton(EndpointResolver::class, function () {
            return new EndpointResolver();
        });

        // Register content sanitizer for action content redaction and truncation
        $this->app->singleton(\ClarionApp\LlmClient\Services\ContentSanitizer::class, function () {
            return new \ClarionApp\LlmClient\Services\ContentSanitizer();
        });

        // scoped(), deliberately — and NOT singleton() like every other
        // binding in this method. BudgetLedger memoizes the consumption
        // figure it reads, so that the two scope reads and any repeated
        // checks within one request or job share one read and the memo is
        // then discarded. In a web request scoped() and singleton() are
        // indistinguishable, but a queue worker keeps one container alive
        // across many jobs and flushes only *scoped* instances between
        // them: a singleton ledger would carry the first job's consumption
        // figure into every later job for the life of the worker, letting
        // work through long after a ceiling had been crossed. That is a
        // binding mistake a passing single-process test suite would never
        // reveal, which is why it is written down here.
        $this->app->scoped(\ClarionApp\LlmClient\Services\BudgetLedger::class, function ($app) {
            return new \ClarionApp\LlmClient\Services\BudgetLedger(
                $app->make(\ClarionApp\LlmClient\Services\CostRollupQuery::class)
            );
        });

        // Stateless — it holds no memo and reads every ceiling row live, so
        // an operator's change takes effect on the next enforcement
        // decision with no restart. singleton() is safe here for exactly
        // the reason it is not safe for BudgetLedger above.
        $this->app->singleton(\ClarionApp\LlmClient\Services\SpendingCeilingService::class, function () {
            return new \ClarionApp\LlmClient\Services\SpendingCeilingService();
        });

        // scoped(), for the same reason BudgetLedger is — and for one more of
        // its own. The gate keeps two pieces of per-unit-of-work state: the
        // ledger memo it reads through, and its own record of which scopes it
        // has already admitted. The second is what stops nested work inside a
        // live turn being re-evaluated and throwing mid-flight; as a singleton
        // it would instead become a standing pass for the life of a queue
        // worker, admitting every later job on the strength of the first one's
        // decision.
        $this->app->scoped(\ClarionApp\LlmClient\Services\BudgetGate::class, function ($app) {
            return new \ClarionApp\LlmClient\Services\BudgetGate(
                $app->make(\ClarionApp\LlmClient\Services\SpendingCeilingService::class),
                $app->make(\ClarionApp\LlmClient\Services\BudgetLedger::class),
                $app->make(\ClarionApp\LlmClient\Services\CostEstimator::class),
                $app->make(\ClarionApp\LlmClient\Services\ReservationLedger::class),
            );
        });

        // Stateless — no memo, no per-instance admitted-once state. It
        // reads the conversation's already-persisted message history and
        // the live model_prices table fresh on every call, so an operator's
        // price change takes effect on the next estimate with no restart.
        // singleton() is safe here for the same reason it is for
        // SpendingCeilingService above.
        $this->app->singleton(\ClarionApp\LlmClient\Services\CostEstimator::class, function () {
            return new \ClarionApp\LlmClient\Services\CostEstimator();
        });

        // Stateless — like BudgetLedger it reads through to durable storage
        // on every call, but unlike BudgetLedger it keeps no per-instance
        // memo to become stale across a queue worker's jobs: every read and
        // write goes straight to budget_reservation_ledger/cost_reservations,
        // so singleton() carries none of BudgetLedger's cross-job staleness
        // risk.
        $this->app->singleton(\ClarionApp\LlmClient\Services\ReservationLedger::class, function ($app) {
            return new \ClarionApp\LlmClient\Services\ReservationLedger(
                $app->make(\ClarionApp\LlmClient\Services\SpendingCeilingService::class),
            );
        });

        // Stateless — it holds no memo and reads every rate_limits row
        // live, so an operator's change takes effect on the next admission
        // decision with no restart. singleton() is safe here for the same
        // reason it is safe for SpendingCeilingService above.
        $this->app->singleton(\ClarionApp\LlmClient\Services\RateLimitService::class, function () {
            return new \ClarionApp\LlmClient\Services\RateLimitService();
        });

        // Stateless — it holds no per-instance property at all and reads
        // and writes an external Cache-backed key on every call, so one
        // shared instance is safe for the life of the process. singleton()
        // here, unlike RateLimitGate just below.
        $this->app->singleton(\ClarionApp\LlmClient\Services\RateLimitCounter::class, function () {
            return new \ClarionApp\LlmClient\Services\RateLimitCounter();
        });

        // scoped(), deliberately — and NOT singleton(), mirroring
        // BudgetGate above. RateLimitGate keeps its own per-instance record
        // of which users it has already admitted this request or job, and a
        // singleton would carry job n's admitted-once memo into every later
        // job for the life of a queue worker, silently exempting every
        // later job's user from rate limiting.
        $this->app->scoped(\ClarionApp\LlmClient\Services\RateLimitGate::class, function ($app) {
            return new \ClarionApp\LlmClient\Services\RateLimitGate(
                $app->make(\ClarionApp\LlmClient\Services\RateLimitService::class),
                $app->make(\ClarionApp\LlmClient\Services\RateLimitCounter::class),
            );
        });

        // All three conversation-work-ceiling services are singleton(),
        // deliberately diverging from RateLimitGate/RateLimitCounter's own
        // scoped()/singleton() split above. Every one of them is stateless:
        // ConversationWorkCeilingService and ConversationWorkCounter hold no
        // per-instance property at all (the same reason RateLimitCounter is
        // already singleton()), and ConversationWorkGate carries no
        // per-instance "already evaluated" memo the way RateLimitGate does —
        // every one of its four in-loop call sites is a genuinely distinct
        // unit of work that must be counted, not the same unit of work
        // reachable two ways in one request, so there is no admitted-once
        // state a request or job boundary ever needs to reset.
        $this->app->singleton(\ClarionApp\LlmClient\Services\ConversationWorkCeilingService::class, function () {
            return new \ClarionApp\LlmClient\Services\ConversationWorkCeilingService();
        });

        $this->app->singleton(\ClarionApp\LlmClient\Services\ConversationWorkCounter::class, function () {
            return new \ClarionApp\LlmClient\Services\ConversationWorkCounter();
        });

        $this->app->singleton(\ClarionApp\LlmClient\Services\ConversationWorkGate::class, function ($app) {
            return new \ClarionApp\LlmClient\Services\ConversationWorkGate(
                $app->make(\ClarionApp\LlmClient\Services\ConversationWorkCeilingService::class),
                $app->make(\ClarionApp\LlmClient\Services\ConversationWorkCounter::class),
            );
        });

        // bind(), not singleton() and not scoped(). The notifier holds no
        // state of its own, but it reads through BudgetLedger, whose memo is
        // deliberately per-request/per-job: a longer-lived notifier would
        // pin one job's ledger instance and compare every later job's
        // consumption against a figure that stopped being current when that
        // first job ended. Resolving a fresh one each time costs nothing and
        // always picks up the current scoped ledger.
        $this->app->bind(\ClarionApp\LlmClient\Services\BudgetThresholdNotifier::class, function ($app) {
            return new \ClarionApp\LlmClient\Services\BudgetThresholdNotifier(
                $app->make(\ClarionApp\LlmClient\Services\SpendingCeilingService::class),
                $app->make(\ClarionApp\LlmClient\Services\BudgetLedger::class),
            );
        });
    }

    /**
     * Register provider factory callables with the ProviderRegistry.
     */
    protected function registerProviders(): void
    {
        $registry = $this->app->make(ProviderRegistry::class);

        // Register OpenAI provider factory
        $registry->register(
            ProviderType::OpenAI,
            fn (Server $server) => new OpenAiProvider($server, $this->httpClientFor(ProviderType::OpenAI))
        );

        // Register Anthropic provider factory
        $registry->register(
            ProviderType::Anthropic,
            fn (Server $server) => new AnthropicProvider($server, $this->httpClientFor(ProviderType::Anthropic))
        );

        // Register llama.cpp provider factory
        $registry->register(
            ProviderType::LlamaCpp,
            fn (Server $server) => new LlamaCppProvider($server, $this->httpClientFor(ProviderType::LlamaCpp))
        );

        // Set default factory to OpenAI for legacy records
        $registry->default(
            fn (Server $server) => new OpenAiProvider($server, $this->httpClientFor(ProviderType::OpenAI))
        );
    }

    /**
     * Build an HTTP client honouring the provider's configured timeout.
     */
    protected function httpClientFor(ProviderType $type): Client
    {
        $config = ['timeout' => (int) config('llm-client.providers.'.$type->value.'.timeout', 240)];

        // Test-only seam: nothing binds this in production.
        if ($this->app->bound('llm-client.http_handler')) {
            $config['handler'] = $this->app->make('llm-client.http_handler');
        }

        return new Client($config);
    }

    /**
     * Register built-in structured output presets with the registry.
     */
    protected function registerPresets(): void
    {
        $registry = $this->app->make(StructuredOutputPresetRegistry::class);
        $enabled = config('llm-client.presets.enabled', ['decision', 'summary', 'extraction']);

        $presetClasses = [
            'decision' => DecisionPreset::class,
            'summary' => SummaryPreset::class,
            'extraction' => ExtractionPreset::class,
        ];

        foreach ($presetClasses as $name => $class) {
            if (in_array($name, $enabled)) {
                $registry->register(new $class());
            }
        }
    }
}
