<?php

namespace ClarionApp\LlmClient;

use ClarionApp\Backend\ClarionPackageServiceProvider;

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
    }
}
