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
 * 122-workspace-browser-ui, US3, T046 (research.md D2, FR-012). Mirrors
 * PurgeExpiredRunTracesCommandTest's shape exactly: retention cutoff,
 * dry-run, --days override, invalid-input fallback-with-warning, and
 * chunked deletion -- plus this command's own defining property, its
 * genuine independence from run_trace.retention_days (FR-012's "stated,
 * meaningfully longer duration" requirement, decoupled by construction,
 * not merely by having a different current value).
 */
class PurgeExpiredWorkspaceChangesCommandTest extends TestCase
{
    /** @var array<int, object{level:string,message:string,context:array}> */
    private array $warnings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.coding_agent.change_record_retention_days', 365);
        // Deliberately different from the workspace-change key, so a test
        // that accidentally reads the wrong config key is caught by a
        // wrong cutoff, not masked by both keys coincidentally agreeing.
        $this->app['config']->set('llm-client.run_trace.retention_days', 90);

        $this->warnings = [];
        Log::listen(function ($entry) {
            if ($entry->level === 'warning') {
                $this->warnings[] = $entry;
            }
        });
    }

    protected function tearDown(): void
    {
        if (DB::getSchemaBuilder()->hasTable('coding_workspace_changes')) {
            DB::table('coding_workspace_changes')->delete();
        }

        parent::tearDown();
    }

    private function insertChange(string $id, string $createdAt): void
    {
        DB::table('coding_workspace_changes')->insert([
            'id' => $id,
            'coding_project_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'root_path' => '/tmp/does-not-matter',
            'path' => 'file.txt',
            'operation' => 'created',
            'old_content' => null,
            'old_content_truncated' => false,
            'old_binary' => false,
            'old_size' => null,
            'new_content' => 'x',
            'new_content_truncated' => false,
            'new_binary' => false,
            'new_size' => 1,
            'agent_id' => null,
            'agent_name' => null,
            'conversation_id' => null,
            'created_at' => $createdAt,
        ]);
    }

    // ========== Core purge logic ==========

    #[Test]
    public function rows_older_than_the_cutoff_are_purged(): void
    {
        $expiredId = (string) Str::uuid();
        $expiredTime = CarbonImmutable::now()->subDays(400);
        $this->insertChange($expiredId, $expiredTime->format('Y-m-d H:i:s.u'));

        $exitCode = Artisan::call('llm-client:purge-workspace-changes');

        $this->assertSame(0, $exitCode);
        $this->assertEquals(0, DB::table('coding_workspace_changes')->where('id', $expiredId)->count());
    }

    #[Test]
    public function rows_within_the_cutoff_survive_untouched(): void
    {
        $recentId = (string) Str::uuid();
        $recentTime = CarbonImmutable::now()->subDays(10);
        $this->insertChange($recentId, $recentTime->format('Y-m-d H:i:s.u'));

        $exitCode = Artisan::call('llm-client:purge-workspace-changes');

        $this->assertSame(0, $exitCode);
        $this->assertEquals(1, DB::table('coding_workspace_changes')->where('id', $recentId)->count());
    }

    #[Test]
    public function mixed_expired_and_recent_rows_purge_only_expired(): void
    {
        $expiredId = (string) Str::uuid();
        $this->insertChange($expiredId, CarbonImmutable::now()->subDays(400)->format('Y-m-d H:i:s.u'));

        $recentId = (string) Str::uuid();
        $this->insertChange($recentId, CarbonImmutable::now()->subDays(10)->format('Y-m-d H:i:s.u'));

        $exitCode = Artisan::call('llm-client:purge-workspace-changes');

        $this->assertSame(0, $exitCode);
        $this->assertEquals(0, DB::table('coding_workspace_changes')->where('id', $expiredId)->count());
        $this->assertEquals(1, DB::table('coding_workspace_changes')->where('id', $recentId)->count());
    }

    #[Test]
    public function dry_run_deletes_nothing(): void
    {
        $expiredId = (string) Str::uuid();
        $this->insertChange($expiredId, CarbonImmutable::now()->subDays(400)->format('Y-m-d H:i:s.u'));

        $exitCode = Artisan::call('llm-client:purge-workspace-changes', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertEquals(1, DB::table('coding_workspace_changes')->where('id', $expiredId)->count());
    }

    #[Test]
    public function days_option_overrides_config_default(): void
    {
        $id = (string) Str::uuid();
        // 50 days old -- expired by a 30-day override, but inside the
        // 365-day default.
        $this->insertChange($id, CarbonImmutable::now()->subDays(50)->format('Y-m-d H:i:s.u'));

        $exitCode = Artisan::call('llm-client:purge-workspace-changes', ['--days' => 30]);

        $this->assertSame(0, $exitCode);
        $this->assertEquals(0, DB::table('coding_workspace_changes')->where('id', $id)->count());
    }

    #[Test]
    public function purge_with_no_expired_data_is_a_no_op(): void
    {
        $recentId = (string) Str::uuid();
        $this->insertChange($recentId, CarbonImmutable::now()->subDays(5)->format('Y-m-d H:i:s.u'));

        $exitCode = Artisan::call('llm-client:purge-workspace-changes');

        $this->assertSame(0, $exitCode);
        $this->assertEquals(1, DB::table('coding_workspace_changes')->where('id', $recentId)->count());

        $output = Artisan::output();
        $this->assertStringContainsString('No expired', $output);
    }

    /**
     * Contract-shaped parity with PurgeExpiredRunTracesCommandTest's own
     * bounded-chunk proof: no single delete statement may bind more ids
     * than the 500-row chunk size, verified on the statements themselves
     * rather than by overflowing a driver limit.
     */
    #[Test]
    public function purge_deletes_in_bounded_chunks(): void
    {
        $expiredTime = CarbonImmutable::now()->subDays(400);
        $recentId = (string) Str::uuid();

        $expiredCount = 1100;
        for ($i = 0; $i < $expiredCount; $i++) {
            $this->insertChange((string) Str::uuid(), $expiredTime->format('Y-m-d H:i:s.u'));
        }
        $this->insertChange($recentId, CarbonImmutable::now()->subDays(1)->format('Y-m-d H:i:s.u'));

        $widestBinding = 0;
        $deleteStatements = 0;
        DB::listen(function ($query) use (&$widestBinding, &$deleteStatements) {
            if (stripos($query->sql, 'delete') === 0) {
                $deleteStatements++;
                $widestBinding = max($widestBinding, count($query->bindings));
            }
        });

        $exitCode = Artisan::call('llm-client:purge-workspace-changes');

        $this->assertSame(0, $exitCode);
        $this->assertEquals(1, DB::table('coding_workspace_changes')->count());
        $this->assertEquals($recentId, DB::table('coding_workspace_changes')->value('id'));

        $this->assertGreaterThanOrEqual(3, $deleteStatements);
        $this->assertLessThanOrEqual(500, $widestBinding, 'No single delete may bind more ids than the chunk size');
    }

    // ========== FR-012: invalid retention_days falls back to the documented (365-day) default ==========

    private function assertDefaultThreeSixtyFiveDayCutoffWasApplied(array $artisanOptions): void
    {
        $expiredId = (string) Str::uuid();
        $this->insertChange($expiredId, CarbonImmutable::now()->subDays(400)->format('Y-m-d H:i:s.u'));

        $survivingId = (string) Str::uuid();
        $this->insertChange($survivingId, CarbonImmutable::now()->subDays(200)->format('Y-m-d H:i:s.u'));

        $exitCode = Artisan::call('llm-client:purge-workspace-changes', $artisanOptions);

        $this->assertSame(0, $exitCode);
        $this->assertEquals(
            0,
            DB::table('coding_workspace_changes')->where('id', $expiredId)->count(),
            'a row older than the documented 365-day default must be purged once the invalid retention input falls back to it',
        );
        $this->assertEquals(
            1,
            DB::table('coding_workspace_changes')->where('id', $survivingId)->count(),
            'a row inside the documented 365-day default must survive once the invalid retention input falls back to it',
        );
    }

    private function assertInvalidRetentionWarningWasLoggedOnce(mixed $rejectedValue): void
    {
        $this->assertCount(
            1,
            $this->warnings,
            'expected exactly one Log::warning for an invalid retention_days input, got: '
                . json_encode(array_map(fn ($w) => ['message' => $w->message, 'context' => $w->context], $this->warnings)),
        );

        $warning = $this->warnings[0];

        $this->assertSame(
            'PurgeExpiredWorkspaceChangesCommand: invalid retention_days, using default',
            $warning->message,
        );

        $namesRejectedValue = false;
        foreach ($warning->context as $contextValue) {
            if ($contextValue === $rejectedValue || (string) $contextValue === (string) $rejectedValue) {
                $namesRejectedValue = true;
                break;
            }
        }

        $this->assertTrue(
            $namesRejectedValue,
            'expected the warning context to name the rejected value ' . var_export($rejectedValue, true)
                . ', got context: ' . var_export($warning->context, true),
        );
    }

    #[Test]
    public function non_numeric_days_option_falls_back_to_default_and_logs_once(): void
    {
        $this->assertDefaultThreeSixtyFiveDayCutoffWasApplied(['--days' => 'abc']);
        $this->assertInvalidRetentionWarningWasLoggedOnce('abc');
    }

    #[Test]
    public function zero_days_option_falls_back_to_default_and_logs_once(): void
    {
        $this->assertDefaultThreeSixtyFiveDayCutoffWasApplied(['--days' => 0]);
        $this->assertInvalidRetentionWarningWasLoggedOnce(0);
    }

    #[Test]
    public function negative_days_option_falls_back_to_default_and_logs_once(): void
    {
        $this->assertDefaultThreeSixtyFiveDayCutoffWasApplied(['--days' => -5]);
        $this->assertInvalidRetentionWarningWasLoggedOnce(-5);
    }

    #[Test]
    public function absent_days_option_with_unset_config_falls_back_to_default_and_logs_once(): void
    {
        $this->app['config']->offsetUnset('llm-client.coding_agent.change_record_retention_days');

        $this->assertDefaultThreeSixtyFiveDayCutoffWasApplied([]);
        $this->assertInvalidRetentionWarningWasLoggedOnce(null);
    }

    #[Test]
    public function absent_days_option_with_non_numeric_config_falls_back_to_default_and_logs_once(): void
    {
        $this->app['config']->set('llm-client.coding_agent.change_record_retention_days', 'abc');

        $this->assertDefaultThreeSixtyFiveDayCutoffWasApplied([]);
        $this->assertInvalidRetentionWarningWasLoggedOnce('abc');
    }

    // ========== FR-012: genuine independence from run_trace.retention_days ==========

    /**
     * The command's own resolveRetentionDays() must read
     * coding_agent.change_record_retention_days SPECIFICALLY -- not
     * run_trace.retention_days. Proven by value: the two keys are set to
     * deliberately different numbers in setUp() (365 vs 90), and a row
     * aged 120 days is inside the workspace-changes retention window but
     * would already be expired under the run-trace one -- so this row
     * surviving proves the command read the correct key, not merely a
     * plausible one.
     */
    #[Test]
    public function retention_reads_the_coding_agent_change_record_key_not_run_trace_retention_days(): void
    {
        $id = (string) Str::uuid();
        // Inside the 365-day workspace-changes window, but already past
        // the 90-day run_trace window -- distinguishes the two keys.
        $this->insertChange($id, CarbonImmutable::now()->subDays(120)->format('Y-m-d H:i:s.u'));

        $exitCode = Artisan::call('llm-client:purge-workspace-changes');

        $this->assertSame(0, $exitCode);
        $this->assertEquals(
            1,
            DB::table('coding_workspace_changes')->where('id', $id)->count(),
            'a 120-day-old row must survive under the 365-day coding_agent.change_record_retention_days key -- if the command read run_trace.retention_days (90) instead, this row would have been purged',
        );
    }

    #[Test]
    public function changing_run_trace_retention_days_never_affects_this_commands_own_cutoff(): void
    {
        // Shorten the unrelated run_trace key drastically -- must have
        // zero effect on this command's own, independently configured key.
        $this->app['config']->set('llm-client.run_trace.retention_days', 1);

        $id = (string) Str::uuid();
        $this->insertChange($id, CarbonImmutable::now()->subDays(120)->format('Y-m-d H:i:s.u'));

        $exitCode = Artisan::call('llm-client:purge-workspace-changes');

        $this->assertSame(0, $exitCode);
        $this->assertEquals(1, DB::table('coding_workspace_changes')->where('id', $id)->count());
    }
}
