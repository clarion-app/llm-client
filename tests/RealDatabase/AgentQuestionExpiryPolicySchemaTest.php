<?php

namespace Tests\RealDatabase;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The columns that record what happens to a question nobody answers, on
 * the engine production runs. SQLite enforces neither lengths nor integer
 * ranges nor the TIMESTAMP range, so only this test can show that the most
 * extreme values the validator and QuestionWaitingPeriod let through — a
 * 4000-character multibyte assumed answer, the 15-character
 * `expired_assumed` state, a requested period of ±1 000 000 000 minutes and
 * the one-year ceiling on the effective period with its deadline — are
 * stored exactly as given.
 */
#[Group('real-db')]
class AgentQuestionExpiryPolicySchemaTest extends RealDatabaseTestCase
{
    protected array $seedTables = ['agent_questions'];

    private const MIGRATION = __DIR__.'/../../src/Migrations/2026_10_13_000000_add_expiry_policy_columns_to_agent_questions_table.php';

    private const NEW_COLUMNS = [
        'unanswered_policy',
        'consequential',
        'assumed_answer_raw',
        'assumed_answer_selected',
        'requested_wait_minutes',
        'effective_wait_minutes',
        'stopped_at',
        'late_reply_noticed_at',
    ];

    /** @return array<string, object> the column metadata of agent_questions, by name */
    private function columns(): array
    {
        $rows = DB::select(
            'SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS '
            ."WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_questions'"
        );

        $columns = [];
        foreach ($rows as $row) {
            $columns[$row->COLUMN_NAME] = $row;
        }

        return $columns;
    }

    /** @return array<string, mixed> a question row with every required column */
    private function baseRow(array $overrides = []): array
    {
        return $overrides + [
            'id' => (string) Str::uuid(),
            'conversation_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'paused_message_id' => (string) Str::uuid(),
            'tool_call_id' => 'call_ask',
            'channel' => 'web',
            'resume_mode' => 'stream',
            'question' => 'Which test framework should I use?',
            'choices' => json_encode(['PHPUnit', 'Pest', 'Codeception']),
            'asked_at' => now(),
            'expires_at' => now()->addHour(),
        ];
    }

    #[Test]
    public function the_migration_adds_each_column_with_its_type_and_nullability(): void
    {
        $this->assertReady();
        $columns = $this->columns();

        foreach (self::NEW_COLUMNS as $name) {
            $this->assertArrayHasKey($name, $columns, "{$name} exists");
        }

        $this->assertSame('varchar(32)', $columns['unanswered_policy']->COLUMN_TYPE);
        $this->assertSame('tinyint(1)', $columns['consequential']->COLUMN_TYPE);
        $this->assertSame('text', $columns['assumed_answer_raw']->COLUMN_TYPE);
        $this->assertContains($columns['assumed_answer_selected']->DATA_TYPE, ['json', 'longtext'], 'JSON (MariaDB reports LONGTEXT)');
        $this->assertSame('int', $columns['requested_wait_minutes']->DATA_TYPE);
        $this->assertStringNotContainsString('unsigned', $columns['requested_wait_minutes']->COLUMN_TYPE, 'a requested period may be negative');
        $this->assertSame('int', $columns['effective_wait_minutes']->DATA_TYPE);
        $this->assertStringContainsString('unsigned', $columns['effective_wait_minutes']->COLUMN_TYPE);
        $this->assertSame('timestamp(6)', $columns['stopped_at']->COLUMN_TYPE);
        $this->assertSame('timestamp(6)', $columns['late_reply_noticed_at']->COLUMN_TYPE);

        $this->assertSame('NO', $columns['consequential']->IS_NULLABLE, 'consequential is never unknown');
        $this->assertContains((string) $columns['consequential']->COLUMN_DEFAULT, ['0', "'0'"], 'consequential defaults to false');
        foreach (array_diff(self::NEW_COLUMNS, ['consequential']) as $name) {
            $this->assertSame('YES', $columns[$name]->IS_NULLABLE, "{$name} is nullable (legacy rows have none)");
        }

        $this->assertSame('varchar(16)', $columns['state']->COLUMN_TYPE, 'state stays 16 characters wide');
    }

    #[Test]
    public function a_maximal_row_round_trips_exactly(): void
    {
        $this->assertReady();

        $assumption = str_repeat('é', 3999).'ü'; // 4000 characters, 8000 bytes
        $selected = [['number' => 1, 'text' => str_repeat('ß', 500)]];
        $askedAt = Carbon::parse('2026-10-13 09:00:00.123456');
        $expiresAt = $askedAt->copy()->addMinutes(525600);
        // Given as strings: the query builder formats a bound Carbon to whole
        // seconds, which would hide whether the column keeps microseconds.
        $stoppedAt = '2027-10-13 09:00:01.654321';
        $noticedAt = '2027-10-13 09:05:02.000007';

        $high = (string) Str::uuid();
        $low = (string) Str::uuid();

        DB::table('agent_questions')->insert($this->baseRow([
            'id' => $high,
            'state' => 'expired_assumed',
            'unanswered_policy' => 'proceed_on_assumption',
            'consequential' => false,
            'assumed_answer_raw' => $assumption,
            'assumed_answer_selected' => json_encode($selected, JSON_UNESCAPED_UNICODE),
            'requested_wait_minutes' => 1_000_000_000,
            'effective_wait_minutes' => 525600,
            'asked_at' => $askedAt,
            'expires_at' => $expiresAt,
            'stopped_at' => $stoppedAt,
            'late_reply_noticed_at' => $noticedAt,
        ]));
        DB::table('agent_questions')->insert($this->baseRow([
            'id' => $low,
            'state' => 'stopped',
            'unanswered_policy' => 'stop',
            'consequential' => true,
            'requested_wait_minutes' => -1_000_000_000,
            'effective_wait_minutes' => 60,
        ]));

        $row = DB::table('agent_questions')->where('id', $high)->first();
        $this->assertSame('expired_assumed', $row->state);
        $this->assertSame('proceed_on_assumption', $row->unanswered_policy);
        $this->assertSame(0, (int) $row->consequential);
        $this->assertSame($assumption, $row->assumed_answer_raw, 'every character of the assumed answer is kept');
        $this->assertSame(4000, mb_strlen($row->assumed_answer_raw));
        $this->assertSame($selected, json_decode($row->assumed_answer_selected, true));
        $this->assertSame(1_000_000_000, (int) $row->requested_wait_minutes);
        $this->assertSame(525600, (int) $row->effective_wait_minutes);
        $this->assertSame($expiresAt->format('Y-m-d H:i:s'), Carbon::parse($row->expires_at)->format('Y-m-d H:i:s'), 'a one-year deadline fits a TIMESTAMP');
        $this->assertSame('2027-10-13 09:00:01.654321', Carbon::parse($row->stopped_at)->format('Y-m-d H:i:s.u'), 'stopped_at keeps microseconds');
        $this->assertSame('2027-10-13 09:05:02.000007', Carbon::parse($row->late_reply_noticed_at)->format('Y-m-d H:i:s.u'), 'late_reply_noticed_at keeps microseconds');

        $row = DB::table('agent_questions')->where('id', $low)->first();
        $this->assertSame('stopped', $row->state);
        $this->assertSame('stop', $row->unanswered_policy);
        $this->assertSame(1, (int) $row->consequential);
        $this->assertSame(-1_000_000_000, (int) $row->requested_wait_minutes);
        $this->assertNull($row->assumed_answer_raw);
        $this->assertNull($row->assumed_answer_selected);
        $this->assertNull($row->stopped_at);
        $this->assertNull($row->late_reply_noticed_at);
    }

    #[Test]
    public function a_legacy_row_needs_none_of_the_new_columns(): void
    {
        $this->assertReady();
        $id = (string) Str::uuid();

        DB::table('agent_questions')->insert($this->baseRow(['id' => $id]));

        $row = DB::table('agent_questions')->where('id', $id)->first();
        $this->assertNull($row->unanswered_policy, 'a question asked before the feature has no policy');
        $this->assertSame(0, (int) $row->consequential);
        $this->assertNull($row->requested_wait_minutes);
        $this->assertNull($row->effective_wait_minutes);
    }

    #[Test]
    public function down_drops_the_columns_and_up_restores_them(): void
    {
        $this->assertReady();
        $this->assertFileExists(self::MIGRATION);
        $migration = require self::MIGRATION;

        try {
            $migration->down();

            foreach (self::NEW_COLUMNS as $name) {
                $this->assertFalse(Schema::hasColumn('agent_questions', $name), "down() drops {$name}");
            }
            $this->assertTrue(Schema::hasColumn('agent_questions', 'answer_method'), 'down() drops only its own columns');
        } finally {
            $migration->up();
        }

        foreach (self::NEW_COLUMNS as $name) {
            $this->assertTrue(Schema::hasColumn('agent_questions', $name), "up() adds {$name} again");
        }

        $migration->up();
        $this->assertCount(count(self::NEW_COLUMNS), array_intersect(self::NEW_COLUMNS, array_keys($this->columns())), 'a second up() is harmless');
    }
}
