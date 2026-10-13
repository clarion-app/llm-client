<?php

namespace Tests\RealDatabase;

use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The resumable-task schema (data-model.md §1–§5) as MySQL/MariaDB actually
 * builds it, from the package's own additive migration.
 *
 * The fast suite declares these tables by hand on SQLite, which enforces
 * neither string lengths nor most types. Only the real engine, running the
 * package's real migrations on the harness's own ephemeral database, can
 * show that the migration creates what the code assumes: every new column
 * with its type, nullability and default, the indexes the detection sweep
 * and the planner rely on, microsecond precision on the lease and progress
 * timestamps, and room for a 200 KB checkpoint document.
 *
 * Red now: the migration does not exist yet, so none of the columns,
 * indexes or the agent_run_resume_events table are present.
 *
 * Later phases extend this file: Phase 3 adds the 300-character
 * tool_call_id cut (T051), Phase 7 the helper-chain columns' journey use,
 * Phase 9 the event-payload bound checks.
 */
#[Group('real-db')]
class ResumeSchemaTest extends RealDatabaseTestCase
{
    protected array $seedTables = ['agent_runs', 'agent_run_steps', 'agent_run_actions', 'agent_delegations', 'agent_run_resume_events'];

    /** MariaDB reports a display width; MySQL 8 does not. */
    private const UNSIGNED_INT = 'unsigned int';

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

    /**
     * @param array<string, object> $columns
     * @param array<string, string|list<string>|null> $expected column => expected COLUMN_TYPE
     *        (null: uuid; a list: any of the listed types; the sentinel: unsigned int)
     */
    private function assertColumnTypes(array $columns, array $expected, string $table): void
    {
        foreach ($expected as $name => $type) {
            $this->assertArrayHasKey($name, $columns, "{$table}.{$name} exists");
            if ($type === null) {
                $this->assertUuid($columns[$name], "{$table}.{$name}");
            } elseif ($type === self::UNSIGNED_INT) {
                $this->assertMatchesRegularExpression('/^int(\(\d+\))? unsigned$/', (string) $columns[$name]->COLUMN_TYPE, "{$table}.{$name} type");
            } elseif (is_array($type)) {
                $this->assertContains((string) $columns[$name]->COLUMN_TYPE, $type, "{$table}.{$name} type");
            } else {
                $this->assertSame($type, $columns[$name]->COLUMN_TYPE, "{$table}.{$name} type");
            }
        }
    }

    #[Test]
    public function agent_runs_has_all_sixteen_new_columns_with_their_types_and_nullability(): void
    {
        $this->assertReady();
        $columns = $this->columns('agent_runs');

        $this->assertColumnTypes($columns, [
            'checkpoint_version' => ['tinyint(1) unsigned', 'tinyint(3) unsigned', 'tinyint unsigned'],
            'resume_mode' => 'varchar(8)',
            'resume_driver' => 'varchar(16)',
            'resume_options' => 'longtext',
            'resume_state' => 'varchar(24)',
            'dispatch_id' => null,
            'lease_holder' => null,
            'lease_expires_at' => 'timestamp(6)',
            'last_progress_at' => 'timestamp(6)',
            'interrupted_at' => 'timestamp(6)',
            'consecutive_interruptions' => ['smallint(5) unsigned', 'smallint unsigned'],
            'resume_count' => self::UNSIGNED_INT,
            'provider_attempts' => ['tinyint(1) unsigned', 'tinyint(3) unsigned', 'tinyint unsigned'],
            'provider_first_failure_at' => 'timestamp(6)',
            'next_attempt_at' => 'timestamp(6)',
            'cancelled_at' => 'timestamp(6)',
        ], 'agent_runs');

        $nullable = ['checkpoint_version', 'resume_mode', 'resume_driver', 'resume_options', 'resume_state', 'dispatch_id', 'lease_holder', 'lease_expires_at', 'last_progress_at', 'interrupted_at', 'provider_first_failure_at', 'next_attempt_at', 'cancelled_at'];
        foreach ($nullable as $name) {
            $this->assertSame('YES', $columns[$name]->IS_NULLABLE, "agent_runs.{$name} is nullable");
        }
        foreach (['consecutive_interruptions', 'resume_count', 'provider_attempts'] as $name) {
            $this->assertSame('NO', $columns[$name]->IS_NULLABLE, "agent_runs.{$name} is not null");
            $this->assertContains((string) $columns[$name]->COLUMN_DEFAULT, ['0', "'0'"], "agent_runs.{$name} defaults to 0");
        }
    }

    #[Test]
    public function agent_runs_has_its_two_new_indexes(): void
    {
        $this->assertReady();
        $indexes = $this->indexes('agent_runs');

        $this->assertContains('end_state,resume_state,lease_expires_at', $indexes, 'the detection sweep driving index');
        $this->assertContains('end_state,next_attempt_at', $indexes, 'the lost-delayed-job index');
    }

    #[Test]
    public function agent_run_steps_has_its_four_new_columns(): void
    {
        $this->assertReady();
        $columns = $this->columns('agent_run_steps');

        $this->assertColumnTypes($columns, [
            'iteration' => ['int(10) unsigned', 'int unsigned'],
            'checkpoint_state' => 'varchar(16)',
            'checkpoint' => 'longtext',
            'message_id' => null,
        ], 'agent_run_steps');

        foreach (['iteration', 'checkpoint_state', 'checkpoint', 'message_id'] as $name) {
            $this->assertSame('YES', $columns[$name]->IS_NULLABLE, "agent_run_steps.{$name} is nullable");
        }
    }

    #[Test]
    public function agent_run_actions_has_tool_call_id_with_its_index(): void
    {
        $this->assertReady();
        $columns = $this->columns('agent_run_actions');

        $this->assertColumnTypes($columns, ['tool_call_id' => 'varchar(255)'], 'agent_run_actions');
        $this->assertSame('YES', $columns['tool_call_id']->IS_NULLABLE, 'agent_run_actions.tool_call_id is nullable');
        $this->assertContains('run_id,tool_call_id', $this->indexes('agent_run_actions'));
    }

    #[Test]
    public function agent_delegations_has_parent_tool_call_id_with_its_index(): void
    {
        $this->assertReady();
        $columns = $this->columns('agent_delegations');

        $this->assertColumnTypes($columns, ['parent_tool_call_id' => 'varchar(255)'], 'agent_delegations');
        $this->assertSame('YES', $columns['parent_tool_call_id']->IS_NULLABLE, 'agent_delegations.parent_tool_call_id is nullable');
        $this->assertContains('parent_run_id,parent_tool_call_id', $this->indexes('agent_delegations'));
    }

    #[Test]
    public function agent_run_resume_events_exists_with_its_columns_and_index(): void
    {
        $this->assertReady();
        $columns = $this->columns('agent_run_resume_events');

        $this->assertEqualsCanonicalizing(
            ['id', 'run_id', 'kind', 'step_position', 'attempt', 'payload', 'created_at'],
            array_keys($columns),
            'exactly the data model §5 columns'
        );
        $this->assertUuid($columns['id'], 'agent_run_resume_events.id');
        $this->assertUuid($columns['run_id'], 'agent_run_resume_events.run_id');
        $this->assertSame('varchar(32)', $columns['kind']->COLUMN_TYPE);
        $this->assertContains($columns['payload']->DATA_TYPE, ['json', 'longtext'], 'JSON (MariaDB reports LONGTEXT)');
        $this->assertSame('timestamp(6)', $columns['created_at']->COLUMN_TYPE);

        $this->assertSame('NO', $columns['id']->IS_NULLABLE);
        $this->assertSame('NO', $columns['run_id']->IS_NULLABLE);
        $this->assertSame('NO', $columns['kind']->IS_NULLABLE);
        $this->assertSame('YES', $columns['step_position']->IS_NULLABLE);
        $this->assertSame('YES', $columns['attempt']->IS_NULLABLE);
        $this->assertSame('NO', $columns['payload']->IS_NULLABLE);
        $this->assertSame('NO', $columns['created_at']->IS_NULLABLE);

        $this->assertContains('run_id,created_at', $this->indexes('agent_run_resume_events'));
    }

    #[Test]
    public function the_lease_and_progress_timestamps_keep_microseconds_on_a_round_trip(): void
    {
        $this->assertReady();
        $runId = (string) Str::uuid();

        DB::table('agent_runs')->insert([
            'id' => $runId,
            'kind' => 'interactive',
            'user_id' => (string) Str::uuid(),
            'end_state' => 'in_progress',
            'started_at' => '2026-10-15 09:00:00.000001',
            'created_at' => '2026-10-15 09:00:00.000001',
            'lease_expires_at' => '2026-10-15 09:05:00.123456',
            'last_progress_at' => '2026-10-15 09:04:30.654321',
        ]);

        $row = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertSame('2026-10-15 09:05:00.123456', (string) $row->lease_expires_at, 'lease_expires_at keeps microseconds');
        $this->assertSame('2026-10-15 09:04:30.654321', (string) $row->last_progress_at, 'last_progress_at keeps microseconds');

        $eventId = (string) Str::uuid();
        DB::table('agent_run_resume_events')->insert([
            'id' => $eventId,
            'run_id' => $runId,
            'kind' => 'interruption_detected',
            'payload' => json_encode(['detected_by' => 'sweep']),
            'created_at' => '2026-10-15 09:05:00.987654',
        ]);

        $event = DB::table('agent_run_resume_events')->where('id', $eventId)->first();
        $this->assertSame('2026-10-15 09:05:00.987654', (string) $event->created_at, 'created_at keeps microseconds');
    }

    #[Test]
    public function a_step_checkpoint_round_trips_a_200kb_json_document(): void
    {
        $this->assertReady();
        $stepId = (string) Str::uuid();

        $document = [
            'v' => 1,
            'iteration' => 8,
            'model' => [
                'recorded_at' => '2026-10-15T09:00:01.123456Z',
                'content' => str_repeat('I will check the file, then update it. ', 20),
                'tool_calls' => [
                    ['id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'execute_operation', 'arguments' => json_encode(['operationId' => 'codingWorkspace.writeFile'])]],
                ],
            ],
            'calls' => [
                'call_a' => [
                    'index' => 0,
                    'tool' => 'execute_operation',
                    'label' => 'codingWorkspace.writeFile',
                    'effect' => 'effectful',
                    'state' => 'completed',
                    'result' => ['tool_call_id' => 'call_a', 'content' => str_repeat('written ', 30000), 'method' => 'passthrough'],
                ],
            ],
        ];
        $encoded = json_encode($document);
        $this->assertGreaterThan(200 * 1024, strlen($encoded), 'fixture sanity: the document is at least 200 KB');

        DB::table('agent_run_steps')->insert([
            'id' => $stepId,
            'run_id' => (string) Str::uuid(),
            'position' => 8,
            'started_at' => '2026-10-15 09:00:00.000001',
            'checkpoint_state' => 'model_recorded',
            'checkpoint' => $encoded,
        ]);

        $stored = DB::table('agent_run_steps')->where('id', $stepId)->value('checkpoint');
        $this->assertSame($encoded, $stored, 'the 200 KB checkpoint document is stored whole');
        $this->assertSame($document, json_decode($stored, true), 'the checkpoint decodes to the same document');
    }

    #[Test]
    public function a_resume_event_payload_round_trips_json(): void
    {
        $this->assertReady();
        $eventId = (string) Str::uuid();

        $payload = [
            'from_step' => 8,
            'iteration' => 8,
            'mode' => 'sync',
            'reused' => [['tool_call_id' => 'call_a', 'tool' => 'execute_operation', 'label' => 'codingWorkspace.writeFile']],
            'rerun' => [],
            'outcome_unknown' => [],
            'model_call_repeated' => true,
        ];

        DB::table('agent_run_resume_events')->insert([
            'id' => $eventId,
            'run_id' => (string) Str::uuid(),
            'kind' => 'resume_started',
            'step_position' => 8,
            'attempt' => 1,
            'payload' => json_encode($payload),
            'created_at' => '2026-10-15 09:00:00.000001',
        ]);

        $stored = DB::table('agent_run_resume_events')->where('id', $eventId)->value('payload');
        $this->assertSame($payload, json_decode($stored, true), 'the payload decodes to the same document');
    }

    // ---------------------------------------------------------------
    // D22: a 300-character tool_call_id is cut to 255 on the real engine
    // ---------------------------------------------------------------

    #[Test]
    public function a_300_character_tool_call_id_is_cut_to_255_on_the_real_engine(): void
    {
        $this->assertReady();
        $recorder = app(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, (string) Str::uuid());
        $this->assertNotNull($runId, 'precondition: the run is opened');
        $stepId = $recorder->openStep($runId);
        $this->assertNotNull($stepId, 'precondition: the step is opened');

        // A 300-character provider call id, longer than the varchar(255)
        // column: openAction() must cut it to 255 before the insert, or
        // strict MySQL/MariaDB would refuse the row.
        $longId = str_repeat('x', 300);
        $actionId = $recorder->openAction($stepId, ActionType::ToolInvocation, null, null, null, $longId);
        $this->assertNotNull($actionId, 'the action is opened with the 300-character call id');

        $stored = DB::table('agent_run_actions')->where('id', $actionId)->value('tool_call_id');
        $this->assertSame(255, strlen((string) $stored), 'the 300-character call id is cut to 255');
        $this->assertSame(str_repeat('x', 255), (string) $stored, 'the stored value is the first 255 characters');
    }
}
