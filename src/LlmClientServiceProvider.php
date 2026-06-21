<?php

namespace ClarionApp\LlmClient;

use ClarionApp\Backend\ClarionPackageServiceProvider;
use ClarionApp\Backend\Events\InstallComposerPackageEvent;
use ClarionApp\Backend\Events\UninstallComposerPackageEvent;
use ClarionApp\LlmClient\Commands\ReindexOperationsCommand;
use ClarionApp\LlmClient\Listeners\ReindexOnPackageChange;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpPromptRegistry;
use ClarionApp\LlmClient\Services\McpResourceHandler;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\OperationsSearchService;
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

        $this->app->singleton(AgentLoopService::class, function ($app) {
            return new AgentLoopService(
                $app->make(McpToolRegistry::class),
                $app->make(McpToolExecutor::class),
                $app->make(OperationCache::class)
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
}
