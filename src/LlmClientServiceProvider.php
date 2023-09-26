<?php

namespace ClarionApp\LlmClient;

use Illuminate\Support\ServiceProvider;

class LlmClientServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if(!$this->app->routesAreCached())
        {
            require __DIR__.'/Routes.php';
        }
    }
}
