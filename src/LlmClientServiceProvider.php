<?php

namespace ClarionApp\LlmClient;

use ClarionApp\Backend\ClarionPackageServiceProvider;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\McpToolExecutor;

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
    }

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(
            __DIR__.'/../config/llm-client.php', 'llm-client'
        );

        $this->app->singleton(AgentLoopService::class, function ($app) {
            return new AgentLoopService(
                $app->make(McpToolRegistry::class),
                $app->make(McpToolExecutor::class)
            );
        });
    }
}
