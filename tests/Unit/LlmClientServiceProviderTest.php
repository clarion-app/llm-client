<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Commands\PurgeExpiredRunTracesCommand;
use ClarionApp\LlmClient\Commands\ResolveAbandonedRunsCommand;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\LlmClientServiceProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class LlmClientServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LlmClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Create a stub App\Http\Controllers\Controller class if it doesn't exist.
        // The package routes/controllers extend this base Laravel app class.
        if (!class_exists('App\Http\Controllers\Controller')) {
            eval('namespace App\Http\Controllers { class Controller { } }');
        }
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('eloquent-multichain-bridge.disabled', true);
    }

    /**
     * Create a testable subclass that exposes the protected method.
     */
    private function createTestableProvider(): TestableLlmClientServiceProvider
    {
        return new TestableLlmClientServiceProvider($this->app);
    }

    #[Test]
    public function httpClientFor_uses_bound_handler()
    {
        $mockHandler = new MockHandler([new Response(200, [], 'mocked')]);
        $this->app->bind('llm-client.http_handler', fn () => $mockHandler);

        $provider = $this->createTestableProvider();
        $client = $provider->testableHttpClientFor(ProviderType::OpenAI);

        // Verify the mock handler is actually used by making a request.
        $response = $client->request('GET', 'https://example.com/test');
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('mocked', $response->getBody()->getContents());
    }

    #[Test]
    public function httpClientFor_without_bound_handler_is_default()
    {
        // Ensure nothing is bound.
        if ($this->app->bound('llm-client.http_handler')) {
            $this->app->forgetInstance('llm-client.http_handler');
        }

        $provider = $this->createTestableProvider();
        $client = $provider->testableHttpClientFor(ProviderType::OpenAI);

        // Without a bound handler, the client should NOT have a MockHandler.
        // Guzzle's default handler is a HandlerStack, not a MockHandler.
        $handler = $client->getConfig('handler');
        $this->assertNotInstanceOf(MockHandler::class, $handler);
    }

    /**
     * T068 [US5]: ResolveAbandonedRunsCommand is registered in the commands array.
     */
    #[Test]
    public function resolveAbandonedRunsCommand_is_registered()
    {
        // Assert the command is registered via Artisan.
        $commands = Artisan::all();

        $this->assertArrayHasKey(
            'llm-client:resolve-abandoned-runs',
            $commands,
            'ResolveAbandonedRunsCommand should be registered'
        );

        // Assert the command instance is the correct class.
        $command = $commands['llm-client:resolve-abandoned-runs'];
        $this->assertInstanceOf(
            ResolveAbandonedRunsCommand::class,
            $command,
            'Command should be ResolveAbandonedRunsCommand instance'
        );
    }

    /**
     * T068 [US5]: The schedule lives in the existing callAfterResolving block
     * and runs every five minutes without overlapping.
     */
    #[Test]
    public function resolveAbandonedRunsCommand_is_scheduled()
    {
        $schedule = $this->app->make(Schedule::class);

        // Get all scheduled events.
        $events = $schedule->events();

        // Find the resolve-abandoned-runs event.
        $abandonedRunsEvent = null;
        foreach ($events as $event) {
            $command = $event->command ?? '';
            if (str_contains($command, 'resolve-abandoned-runs')) {
                $abandonedRunsEvent = $event;
                break;
            }
        }

        $this->assertNotNull(
            $abandonedRunsEvent,
            'Schedule should contain resolve-abandoned-runs command'
        );

        // Assert the event runs every five minutes (cron: */5 * * * *).
        $this->assertSame(
            '*/5 * * * *',
            (string) $abandonedRunsEvent->expression,
            'Schedule should run every five minutes'
        );

        // Assert the event uses withoutOverlapping.
        $this->assertTrue(
            $abandonedRunsEvent->withoutOverlapping,
            'Schedule should use withoutOverlapping'
        );
    }

    /**
     * T082: PurgeExpiredRunTracesCommand is registered in the commands array.
     */
    #[Test]
    public function purgeExpiredRunTracesCommand_is_registered()
    {
        // Assert the command is registered via Artisan.
        $commands = Artisan::all();

        $this->assertArrayHasKey(
            'llm-client:purge-run-traces',
            $commands,
            'PurgeExpiredRunTracesCommand should be registered'
        );

        // Assert the command instance is the correct class.
        $command = $commands['llm-client:purge-run-traces'];
        $this->assertInstanceOf(
            PurgeExpiredRunTracesCommand::class,
            $command,
            'Command should be PurgeExpiredRunTracesCommand instance'
        );
    }

    /**
     * T082: The purge schedule lives in the existing callAfterResolving block
     * and runs daily without overlapping.
     */
    #[Test]
    public function purgeExpiredRunTracesCommand_is_scheduled()
    {
        $schedule = $this->app->make(Schedule::class);

        // Get all scheduled events.
        $events = $schedule->events();

        // Find the purge-run-traces event.
        $purgeTracesEvent = null;
        foreach ($events as $event) {
            $command = $event->command ?? '';
            if (str_contains($command, 'purge-run-traces')) {
                $purgeTracesEvent = $event;
                break;
            }
        }

        $this->assertNotNull(
            $purgeTracesEvent,
            'Schedule should contain purge-run-traces command'
        );

        // Assert the event runs daily (cron: 0 0 * * *).
        $this->assertSame(
            '0 0 * * *',
            (string) $purgeTracesEvent->expression,
            'Schedule should run daily'
        );

        // Assert the event uses withoutOverlapping.
        $this->assertTrue(
            $purgeTracesEvent->withoutOverlapping,
            'Schedule should use withoutOverlapping'
        );
    }
}

/**
 * Test subclass exposing the protected httpClientFor method.
 */
class TestableLlmClientServiceProvider extends LlmClientServiceProvider
{
    public function testableHttpClientFor(
        \ClarionApp\LlmClient\Contracts\ProviderType $type
    ): Client {
        return $this->httpClientFor($type);
    }
}
