<?php

namespace Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use ClarionApp\LlmClient\LlmClientServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LlmClientServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    /**
     * Define environment setup. Creates stub classes needed by the package.
     */
    protected function defineEnvironment($app): void
    {
        // Create a stub App\Http\Controllers\Controller class if it doesn't exist.
        // The package routes/controllers extend this base Laravel app class.
        if (!class_exists('App\Http\Controllers\Controller')) {
            eval('namespace App\Http\Controllers { class Controller { } }');
        }
    }
}
