<?php

namespace ClarionApp\LlmClient\Tests\Unit\Commands;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PurgeMcpClientConnectionTestsCommand -- mirrors
 * PurgeExpiredRunTracesCommandTest's own shape (retention cutoff,
 * --hours/--dry-run options, an invalid/absent option falling back to
 * config) but against the single mcp_client_connection_tests table,
 * D3's short (1-hour-default) retention window for credential-bearing
 * scratch state.
 *
 * Written before PurgeMcpClientConnectionTestsCommand exists -- expected
 * to FAIL red (command not defined) until it is created.
 */
class PurgeMcpClientConnectionTestsCommandTest extends TestCase
{
    /** @var array<int, object{level:string,message:string,context:array}> */
    private array $warnings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.mcp_client.connection_test_retention_hours', 1);

        $this->warnings = [];
        Log::listen(function ($entry) {
            if ($entry->level === 'warning') {
                $this->warnings[] = $entry;
            }
        });
    }

    protected function tearDown(): void
    {
        DB::table('mcp_client_connection_tests')->delete();

        parent::tearDown();
    }

    private function insertRow(string $id, string $userId, string $createdAt, string $status = 'passed'): void
    {
        DB::table('mcp_client_connection_tests')->insert([
            'id' => $id,
            'user_id' => $userId,
            'transport' => 'streamable_http',
            'url' => 'https://mcp.example.com/mcp',
            'status' => $status,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    #[Test]
    public function rows_older_than_the_retention_cutoff_are_purged(): void
    {
        $userId = (string) Str::uuid();
        $expiredId = (string) Str::uuid();
        $expiredTime = CarbonImmutable::now()->subHours(2);

        $this->insertRow($expiredId, $userId, $expiredTime->format('Y-m-d H:i:s.u'));

        $exitCode = Artisan::call('llm-client:purge-mcp-connection-tests');

        $this->assertSame(0, $exitCode);
        $this->assertEquals(0, DB::table('mcp_client_connection_tests')->where('id', $expiredId)->count());
    }

    #[Test]
    public function rows_inside_the_retention_window_are_kept(): void
    {
        $userId = (string) Str::uuid();
        $recentId = (string) Str::uuid();
        $recentTime = CarbonImmutable::now()->subMinutes(10);

        $this->insertRow($recentId, $userId, $recentTime->format('Y-m-d H:i:s.u'));

        $exitCode = Artisan::call('llm-client:purge-mcp-connection-tests');

        $this->assertSame(0, $exitCode);
        $this->assertEquals(1, DB::table('mcp_client_connection_tests')->where('id', $recentId)->count());
    }

    #[Test]
    public function mixed_expired_and_recent_rows_purge_only_expired(): void
    {
        $userId = (string) Str::uuid();

        $expiredId = (string) Str::uuid();
        $this->insertRow($expiredId, $userId, CarbonImmutable::now()->subHours(3)->format('Y-m-d H:i:s.u'));

        $recentId = (string) Str::uuid();
        $this->insertRow($recentId, $userId, CarbonImmutable::now()->subMinutes(5)->format('Y-m-d H:i:s.u'));

        $exitCode = Artisan::call('llm-client:purge-mcp-connection-tests');

        $this->assertSame(0, $exitCode);
        $this->assertEquals(0, DB::table('mcp_client_connection_tests')->where('id', $expiredId)->count());
        $this->assertEquals(1, DB::table('mcp_client_connection_tests')->where('id', $recentId)->count());
    }

    #[Test]
    public function dry_run_deletes_nothing_but_reports_what_would_be_deleted(): void
    {
        $userId = (string) Str::uuid();
        $expiredId = (string) Str::uuid();
        $this->insertRow($expiredId, $userId, CarbonImmutable::now()->subHours(5)->format('Y-m-d H:i:s.u'));

        $exitCode = Artisan::call('llm-client:purge-mcp-connection-tests', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertEquals(1, DB::table('mcp_client_connection_tests')->where('id', $expiredId)->count());

        $output = Artisan::output();
        $this->assertStringContainsString('Dry-run', $output);
        $this->assertStringContainsString('1', $output);
    }

    #[Test]
    public function hours_option_overrides_config_default(): void
    {
        // Config default set generously (24h) so this row would survive
        // under it -- only a tighter --hours override can be responsible
        // for purging it, proving the option (not the config) governed
        // the outcome.
        $this->app['config']->set('llm-client.mcp_client.connection_test_retention_hours', 24);

        $userId = (string) Str::uuid();
        $rowId = (string) Str::uuid();
        $this->insertRow($rowId, $userId, CarbonImmutable::now()->subHours(2)->format('Y-m-d H:i:s.u'));

        $exitCode = Artisan::call('llm-client:purge-mcp-connection-tests', ['--hours' => 1]);

        $this->assertSame(0, $exitCode);
        $this->assertEquals(
            0,
            DB::table('mcp_client_connection_tests')->where('id', $rowId)->count(),
            'a 1-hour --hours override must purge a 2-hour-old row even though the 24-hour config default would have kept it',
        );
    }

    #[Test]
    public function absent_hours_option_falls_back_to_config(): void
    {
        $this->app['config']->set('llm-client.mcp_client.connection_test_retention_hours', 2);

        $userId = (string) Str::uuid();
        $rowId = (string) Str::uuid();
        // 90 minutes old -- inside the 2-hour config default, so it must
        // survive when no --hours option is given at all.
        $this->insertRow($rowId, $userId, CarbonImmutable::now()->subMinutes(90)->format('Y-m-d H:i:s.u'));

        $exitCode = Artisan::call('llm-client:purge-mcp-connection-tests');

        $this->assertSame(0, $exitCode);
        $this->assertEquals(1, DB::table('mcp_client_connection_tests')->where('id', $rowId)->count());
    }

    #[Test]
    public function non_numeric_hours_option_falls_back_to_config_default_and_logs_once(): void
    {
        $this->app['config']->set('llm-client.mcp_client.connection_test_retention_hours', 1);

        $userId = (string) Str::uuid();
        $expiredId = (string) Str::uuid();
        $this->insertRow($expiredId, $userId, CarbonImmutable::now()->subHours(2)->format('Y-m-d H:i:s.u'));

        $survivingId = (string) Str::uuid();
        $this->insertRow($survivingId, $userId, CarbonImmutable::now()->subMinutes(10)->format('Y-m-d H:i:s.u'));

        $exitCode = Artisan::call('llm-client:purge-mcp-connection-tests', ['--hours' => 'abc']);

        $this->assertSame(0, $exitCode);
        $this->assertEquals(0, DB::table('mcp_client_connection_tests')->where('id', $expiredId)->count());
        $this->assertEquals(1, DB::table('mcp_client_connection_tests')->where('id', $survivingId)->count());

        $this->assertNotEmpty($this->warnings, 'an invalid --hours value must be logged');
    }

    #[Test]
    public function purge_with_no_expired_rows_is_a_no_op(): void
    {
        $userId = (string) Str::uuid();
        $recentId = (string) Str::uuid();
        $this->insertRow($recentId, $userId, CarbonImmutable::now()->subMinutes(5)->format('Y-m-d H:i:s.u'));

        $exitCode = Artisan::call('llm-client:purge-mcp-connection-tests');

        $this->assertSame(0, $exitCode);
        $this->assertEquals(1, DB::table('mcp_client_connection_tests')->where('id', $recentId)->count());

        $output = Artisan::output();
        $this->assertStringContainsString('No expired', $output);
    }

    /**
     * Contract precedent (PurgeExpiredRunTracesCommand): the purge
     * deletes in bounded chunks rather than one unbounded whereIn/delete,
     * so a large backlog can never bind more ids than the chunk size in
     * a single statement.
     */
    #[Test]
    public function purge_deletes_in_bounded_chunks(): void
    {
        $userId = (string) Str::uuid();
        $expiredTime = CarbonImmutable::now()->subHours(5);

        $expiredCount = 1100;
        for ($i = 0; $i < $expiredCount; $i++) {
            $this->insertRow((string) Str::uuid(), $userId, $expiredTime->format('Y-m-d H:i:s.u'));
        }

        $survivingId = (string) Str::uuid();
        $this->insertRow($survivingId, $userId, CarbonImmutable::now()->subMinutes(5)->format('Y-m-d H:i:s.u'));

        $widestBinding = 0;
        $deleteStatements = 0;
        DB::listen(function ($query) use (&$widestBinding, &$deleteStatements) {
            if (stripos($query->sql, 'delete') === 0 && str_contains($query->sql, 'mcp_client_connection_tests')) {
                $deleteStatements++;
                $widestBinding = max($widestBinding, count($query->bindings));
            }
        });

        $exitCode = Artisan::call('llm-client:purge-mcp-connection-tests');

        $this->assertSame(0, $exitCode);
        $this->assertEquals(1, DB::table('mcp_client_connection_tests')->count());
        $this->assertEquals($survivingId, DB::table('mcp_client_connection_tests')->value('id'));

        $this->assertGreaterThanOrEqual(3, $deleteStatements);
        $this->assertLessThanOrEqual(500, $widestBinding, 'No single delete may bind more ids than the chunk size');
    }
}
