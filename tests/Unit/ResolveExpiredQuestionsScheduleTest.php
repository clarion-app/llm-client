<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Commands\ResolveExpiredQuestionsCommand;
use ClarionApp\LlmClient\LlmClientServiceProvider;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The question sweep is what re-drives a resolution recorded before a crash,
 * so forgetting to register or schedule it fails silently: the run simply
 * never resumes. Both are pinned here against the real provider.
 */
class ResolveExpiredQuestionsScheduleTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LlmClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
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

    #[Test]
    public function the_command_is_registered(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('llm-client:resolve-expired-questions', $commands);
        $this->assertInstanceOf(
            ResolveExpiredQuestionsCommand::class,
            $commands['llm-client:resolve-expired-questions'],
        );
    }

    #[Test]
    public function the_command_is_scheduled_every_minute_without_overlapping(): void
    {
        $matches = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn (Event $event) => str_contains($event->command ?? '', 'llm-client:resolve-expired-questions'),
        ));

        $this->assertCount(1, $matches, 'the sweep is scheduled exactly once');
        $this->assertSame('* * * * *', (string) $matches[0]->expression, 'it runs every minute');
        $this->assertTrue($matches[0]->withoutOverlapping, 'a slow tick never overlaps the next');
    }
}
