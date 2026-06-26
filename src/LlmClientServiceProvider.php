<?php

namespace ClarionApp\LlmClient;

use ClarionApp\Backend\ClarionPackageServiceProvider;
use ClarionApp\Backend\Events\InstallComposerPackageEvent;
use ClarionApp\Backend\Events\UninstallComposerPackageEvent;
use ClarionApp\LlmClient\Commands\ReindexOperationsCommand;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Listeners\ReindexOnPackageChange;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\AnthropicProvider;
use ClarionApp\LlmClient\Providers\OpenAiProvider;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpPromptRegistry;
use ClarionApp\LlmClient\Services\McpResourceHandler;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\OperationsSearchService;
use GuzzleHttp\Client;
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

        // Register Artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                ReindexOperationsCommand::class,
            ]);
        }

        // Populate provider registry with factory callables
        $this->registerProviders();
    }

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(
            __DIR__.'/../config/llm-client.php', 'llm-client'
        );

        $this->app->singleton(OperationCache::class, function ($app) {
            return new OperationCache();
        });

        $this->app->singleton(ProviderRegistry::class, function () {
            return new ProviderRegistry();
        });

        $this->app->singleton(AgentLoopService::class, function ($app) {
            return new AgentLoopService(
                $app->make(McpToolRegistry::class),
                $app->make(McpToolExecutor::class),
                $app->make(OperationCache::class),
                $app->make(ProviderRegistry::class)
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
            fn (Server $server) => new OpenAiProvider($server, new Client(['timeout' => 240]))
        );

        // Register Anthropic provider factory
        $registry->register(
            ProviderType::Anthropic,
            fn (Server $server) => new AnthropicProvider($server, new Client(['timeout' => 240]))
        );

        // Set default factory to OpenAI for legacy records
        $registry->default(
            fn (Server $server) => new OpenAiProvider($server, new Client(['timeout' => 240]))
        );
    }
}
