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
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

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
            ]);
        }

        // Nothing else ends a conversation session, so this sweep is what makes
        // short-term memory cleanup and episodic capture happen at all. Registered
        // here rather than left to the host app, because forgetting it does not
        // fail loudly — memories simply never get captured.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('llm-client:end-idle-conversations')
                ->everyFiveMinutes()
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

        $this->app->singleton(ConversationCondenser::class, function ($app) {
            return new ConversationCondenser(
                $app->make(\ClarionApp\LlmClient\Services\ChunkPartitioner::class),
                $app->make(\ClarionApp\LlmClient\Services\CondensationSummaryStore::class),
                $app->make(ContextWindowBudgeter::class),
                $app->make(\ClarionApp\LlmClient\Presets\CondensationPreset::class),
                null,
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
                $app->make(ConversationCondenser::class)
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
                $app->make(ProviderRegistry::class)
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
        $timeout = config('llm-client.providers.'.$type->value.'.timeout', 240);

        return new Client(['timeout' => (int) $timeout]);
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
