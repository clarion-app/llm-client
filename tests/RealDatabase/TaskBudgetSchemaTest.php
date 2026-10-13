<?php

namespace Tests\RealDatabase;

use ClarionApp\LlmClient\ValueObjects\CeilingOrigin;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The task-budget schema as MySQL/MariaDB actually builds it.
 *
 * The fast suite declares these tables by hand on SQLite, which enforces
 * neither string lengths nor most types. Only the real engine, running the
 * package's real migrations on the harness's own ephemeral database, can
 * show that the migration creates what the code assumes: every column with
 * its type, length, nullability and default, the indexes the gate and the
 * listings rely on, and room for every state, scope, origin, pause reason
 * and event kind the code writes — and that nothing already in production
 * was altered on the way (no ENUM touched, no existing column changed).
 */
#[Group('real-db')]
class TaskBudgetSchemaTest extends RealDatabaseTestCase
{
    protected array $seedTables = ['task_budgets', 'task_budget_events', 'agent_questions'];

    private const EVENT_KINDS = [
        'opened', 'ceiling_changed', 'approach_notice', 'ceiling_reached', 'prompt_raised', 'decision',
        'prompt_stopped', 'prompt_withdrawn', 'allowance_granted', 'report', 'halted', 'ended',
    ];

    private const SCOPES = ['request', 'managed_task', 'sequence_run', 'consensus'];

    /** MariaDB reports a display width; MySQL 8 does not. */
    private const UNSIGNED_INT = 'unsigned int';

    private const PAUSE_REASONS = ['steps', 'spend', 'both', 'unavailable'];

    /** @return array<string, object> */
    private function columns(string $table): array
    {
        $rows = DB::select(
            'SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM INFORMATION_SCHEMA.COLUMNS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );

        $columns = [];
        foreach ($rows as $row) {
            $columns[$row->COLUMN_NAME] = $row;
        }

        return $columns;
    }

    /** @return list<string> each index of $table as its comma-joined column list */
    private function indexes(string $table): array
    {
        $rows = DB::select(
            'SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols FROM INFORMATION_SCHEMA.STATISTICS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? GROUP BY INDEX_NAME',
            [$table]
        );

        return array_map(fn ($row) => (string) $row->cols, $rows);
    }

    private function assertUuid(object $column, string $label): void
    {
        $this->assertContains($column->COLUMN_TYPE, ['char(36)', 'uuid'], "{$label} is a uuid column");
    }

    /** @param array<string, string|null> $expected column => expected COLUMN_TYPE (null: uuid) */
    private function assertColumnTypes(array $columns, array $expected, string $table): void
    {
        foreach ($expected as $name => $type) {
            $this->assertArrayHasKey($name, $columns, "{$table}.{$name} exists");
            if ($type === null) {
                $this->assertUuid($columns[$name], "{$table}.{$name}");
            } elseif ($type === self::UNSIGNED_INT) {
                $this->assertContains($columns[$name]->COLUMN_TYPE, ['int(10) unsigned', 'int unsigned'], "{$table}.{$name} type");
            } else {
                $this->assertSame($type, $columns[$name]->COLUMN_TYPE, "{$table}.{$name} type");
            }
        }
    }

    #[Test]
    public function task_budgets_has_every_column_with_its_type_nullability_and_default(): void
    {
        $this->assertReady();
        $columns = $this->columns('task_budgets');

        $this->assertColumnTypes($columns, [
            'id' => null,
            'user_id' => null,
            'root_conversation_id' => null,
            'root_run_id' => null,
            'managed_task_id' => null,
            'scope' => 'varchar(16)',
            'unattended' => 'tinyint(1)',
            'state' => 'varchar(16)',
            'end_reason' => 'varchar(256)',
            'max_steps' => self::UNSIGNED_INT,
            'max_steps_origin' => 'varchar(32)',
            'original_max_steps' => self::UNSIGNED_INT,
            'max_spend' => 'decimal(20,10)',
            'max_spend_origin' => 'varchar(32)',
            'original_max_spend' => 'decimal(20,10)',
            'steps_used' => self::UNSIGNED_INT,
            'approach_threshold' => 'decimal(5,4)',
            'steps_approach_noticed_at' => 'timestamp(6)',
            'spend_approach_noticed_at' => 'timestamp(6)',
            'paused_at' => 'timestamp(6)',
            'pause_reason' => 'varchar(16)',
            'paused_run_id' => null,
            'paused_iteration' => self::UNSIGNED_INT,
            'resume_mode' => 'varchar(8)',
            'paused_steps_used' => self::UNSIGNED_INT,
            'paused_spend' => 'decimal(20,10)',
            'prompt_question_id' => null,
            'ended_at' => 'timestamp(6)',
            'created_at' => 'timestamp(6)',
            'updated_at' => 'timestamp(6)',
        ], 'task_budgets');
        $this->assertCount(30, $columns, 'no column beyond the data model');

        $notNull = ['id', 'root_conversation_id', 'scope', 'unattended', 'state', 'max_steps', 'max_steps_origin', 'original_max_steps', 'max_spend', 'max_spend_origin', 'original_max_spend', 'steps_used', 'approach_threshold'];
        foreach ($columns as $name => $column) {
            $this->assertSame(in_array($name, $notNull, true) ? 'NO' : 'YES', $column->IS_NULLABLE, "task_budgets.{$name} nullability");
        }

        $this->assertContains((string) $columns['unattended']->COLUMN_DEFAULT, ['0', "'0'"], 'unattended defaults to false');
        $this->assertContains((string) $columns['state']->COLUMN_DEFAULT, ['running', "'running'"], 'state defaults to running');
        $this->assertContains((string) $columns['steps_used']->COLUMN_DEFAULT, ['0', "'0'"], 'steps_used defaults to 0');
    }

    #[Test]
    public function task_budgets_has_its_seven_indexes(): void
    {
        $this->assertReady();
        $indexes = $this->indexes('task_budgets');

        foreach (['id', 'root_conversation_id', 'user_id,updated_at', 'state,updated_at', 'managed_task_id', 'prompt_question_id', 'root_run_id', 'paused_run_id'] as $expected) {
            $this->assertContains($expected, $indexes, "an index on ({$expected})");
        }
        $this->assertCount(8, $indexes, 'the primary key and the seven indexes, nothing else');
    }

    #[Test]
    public function task_budget_events_is_created_at_only_with_its_index(): void
    {
        $this->assertReady();
        $columns = $this->columns('task_budget_events');

        $this->assertEqualsCanonicalizing(['id', 'task_budget_id', 'kind', 'payload', 'actor_user_id', 'created_at'], array_keys($columns));
        $this->assertUuid($columns['id'], 'task_budget_events.id');
        $this->assertUuid($columns['task_budget_id'], 'task_budget_events.task_budget_id');
        $this->assertUuid($columns['actor_user_id'], 'task_budget_events.actor_user_id');
        $this->assertSame('varchar(32)', $columns['kind']->COLUMN_TYPE);
        $this->assertContains($columns['payload']->DATA_TYPE, ['json', 'longtext'], 'JSON (MariaDB reports LONGTEXT)');
        $this->assertSame('timestamp(6)', $columns['created_at']->COLUMN_TYPE);

        $this->assertSame('NO', $columns['task_budget_id']->IS_NULLABLE);
        $this->assertSame('NO', $columns['kind']->IS_NULLABLE);
        $this->assertSame('YES', $columns['actor_user_id']->IS_NULLABLE);
        $this->assertMatchesRegularExpression('/^current_timestamp(\(6\))?$/i', (string) $columns['created_at']->COLUMN_DEFAULT, 'created_at defaults to now');
        $this->assertStringNotContainsStringIgnoringCase('on update', (string) $columns['created_at']->EXTRA, 'no implicit ON UPDATE');

        $indexes = $this->indexes('task_budget_events');
        $this->assertContains('task_budget_id,created_at', $indexes);
        $this->assertContains('id', $indexes);
        $this->assertCount(2, $indexes);
    }

    #[Test]
    public function the_four_new_columns_on_existing_tables(): void
    {
        $this->assertReady();

        $usage = $this->columns('usage_records');
        $this->assertUuid($usage['task_budget_id'], 'usage_records.task_budget_id');
        $this->assertSame('YES', $usage['task_budget_id']->IS_NULLABLE);
        $this->assertContains('task_budget_id', $this->indexes('usage_records'));

        $conversations = $this->columns('conversations');
        $this->assertUuid($conversations['active_task_budget_id'], 'conversations.active_task_budget_id');
        $this->assertSame('YES', $conversations['active_task_budget_id']->IS_NULLABLE);
        $this->assertContains('active_task_budget_id', $this->indexes('conversations'));

        $questions = $this->columns('agent_questions');
        $this->assertSame('varchar(16)', $questions['origin']->COLUMN_TYPE);
        $this->assertSame('YES', $questions['origin']->IS_NULLABLE);
        $this->assertContains($questions['origin']->COLUMN_DEFAULT, [null, 'NULL'], 'origin has no default: NULL means an agent question');
        $this->assertUuid($questions['task_budget_id'], 'agent_questions.task_budget_id');
        $this->assertSame('YES', $questions['task_budget_id']->IS_NULLABLE);
        $this->assertContains('task_budget_id', $this->indexes('agent_questions'));

        $this->assertSame('NO', $questions['paused_message_id']->IS_NULLABLE, 'paused_message_id is still NOT NULL');
        $this->assertSame('NO', $questions['tool_call_id']->IS_NULLABLE, 'tool_call_id is still NOT NULL');
        $this->assertSame('varchar(128)', $questions['tool_call_id']->COLUMN_TYPE);
    }

    #[Test]
    public function no_enum_definition_changed(): void
    {
        $this->assertReady();

        $expected = [
            ['agent_runs', 'end_state', "enum('in_progress','completed','failed','stopped_early','abandoned')"],
            ['agent_run_steps', 'end_state', "enum('in_progress','completed','failed','stopped_early','abandoned')"],
            ['agent_delegations', 'status', "enum('queued','in_progress','completed','exhausted','failed')"],
            ['agent_delegations', 'result_status', "enum('success','partial','failure')"],
            ['cost_summaries', 'entity_type', "enum('conversation','user','agent')"],
        ];

        foreach ($expected as [$table, $column, $type]) {
            $this->assertSame($type, $this->columns($table)[$column]->COLUMN_TYPE, "{$table}.{$column} is unchanged");
        }
    }

    #[Test]
    public function every_value_the_code_writes_fits_in_strict_mode(): void
    {
        $this->assertReady();

        $states = array_map(fn (TaskBudgetState $s) => $s->value, TaskBudgetState::cases());
        $origins = array_map(fn (CeilingOrigin $o) => $o->value, CeilingOrigin::cases());
        $rows = max(count($states), count($origins), count(self::SCOPES), count(self::PAUSE_REASONS));

        for ($i = 0; $i < $rows; $i++) {
            $id = (string) Str::uuid();
            $row = [
                'id' => $id,
                'user_id' => (string) Str::uuid(),
                'root_conversation_id' => (string) Str::uuid(),
                'scope' => self::SCOPES[$i % count(self::SCOPES)],
                'unattended' => $i % 2 === 0,
                'state' => $states[$i % count($states)],
                'end_reason' => str_repeat('é', 256),
                'max_steps' => 1000000,
                'max_steps_origin' => $origins[$i % count($origins)],
                'original_max_steps' => 1000000,
                'max_spend' => '1000000.0000000000',
                'max_spend_origin' => $origins[($i + 1) % count($origins)],
                'original_max_spend' => '0.0000000001',
                'steps_used' => 999999,
                'approach_threshold' => '0.9999',
                'pause_reason' => self::PAUSE_REASONS[$i % count(self::PAUSE_REASONS)],
                'resume_mode' => $i % 2 === 0 ? 'stream' : 'sync',
                'paused_spend' => '9999999999.9999999999',
                'paused_at' => '2026-10-14 09:00:00.123456',
                'created_at' => '2026-10-14 09:00:00.000001',
                'updated_at' => '2026-10-14 09:00:00.999999',
            ];

            DB::table('task_budgets')->insert($row);

            $stored = DB::table('task_budgets')->where('id', $id)->first();
            foreach (['scope', 'state', 'max_steps_origin', 'max_spend_origin', 'pause_reason', 'resume_mode', 'end_reason', 'max_spend', 'original_max_spend', 'paused_spend', 'approach_threshold'] as $column) {
                $this->assertSame($row[$column], $stored->{$column}, "{$column} stored exactly");
            }
            $this->assertSame('2026-10-14 09:00:00.123456', (string) $stored->paused_at, 'microseconds kept');
        }

        $taskId = DB::table('task_budgets')->value('id');
        foreach (self::EVENT_KINDS as $kind) {
            DB::table('task_budget_events')->insert([
                'id' => (string) Str::uuid(),
                'task_budget_id' => $taskId,
                'kind' => $kind,
                'payload' => json_encode(['kind' => $kind]),
            ]);
        }

        $this->assertEqualsCanonicalizing(
            self::EVENT_KINDS,
            DB::table('task_budget_events')->where('task_budget_id', $taskId)->pluck('kind')->all(),
            'every event kind is stored whole'
        );
        $this->assertSame(0, DB::table('task_budget_events')->whereNull('created_at')->count(), 'created_at filled by the database');
    }

    #[Test]
    public function a_new_ceiling_prompt_row_fits_the_question_table(): void
    {
        $this->assertReady();
        $id = (string) Str::uuid();
        $taskId = (string) Str::uuid();

        DB::table('agent_questions')->insert([
            'id' => $id,
            'conversation_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'paused_message_id' => $id,
            'tool_call_id' => 'task_budget',
            'channel' => 'telegram',
            'resume_mode' => 'sync',
            'question' => "This task's budget ceiling was reached. What should happen next?",
            'choices' => json_encode(['Continue with more allowance (+20 steps, +1.00 USD)', 'Accept what has been done', 'Stop']),
            'asked_at' => now(),
            'expires_at' => now()->addDay(),
            'origin' => 'task_budget',
            'task_budget_id' => $taskId,
        ]);

        $row = DB::table('agent_questions')->where('id', $id)->first();
        $this->assertSame('task_budget', $row->origin);
        $this->assertSame($taskId, $row->task_budget_id);

        $legacy = (string) Str::uuid();
        DB::table('agent_questions')->insert([
            'id' => $legacy,
            'conversation_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'paused_message_id' => (string) Str::uuid(),
            'tool_call_id' => 'call_ask',
            'channel' => 'web',
            'resume_mode' => 'stream',
            'question' => 'Which one?',
            'choices' => json_encode(['A']),
            'asked_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        $this->assertNull(DB::table('agent_questions')->where('id', $legacy)->value('origin'), 'an agent question has no origin');
    }
}
