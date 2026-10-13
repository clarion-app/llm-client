<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A SQLite database in a file this test creates and deletes itself.
 *
 * SQLite `:memory:` is private to one connection, so two "workers" on it
 * are really one, and a race between them cannot be staged. A file can be
 * opened by two connections at once. This class makes a uniquely named
 * file under the system temporary directory, registers named connections
 * to it, hand-declares the tables the task-budget gate reads and writes
 * (no migration ever runs), and deletes the file again — from tearDown, or
 * from the destructor when a test dies before reaching it.
 *
 * It is a test's own scratch database: it is never any shared or
 * pre-existing database.
 */
final class TemporarySqliteFile
{
    public readonly string $path;

    /** @var list<string> */
    private array $connections = [];

    private bool $deleted = false;

    public function __construct(string $prefix = 'task_budget_race_')
    {
        $this->path = sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.bin2hex(random_bytes(8)).'.sqlite';

        if (file_exists($this->path)) {
            throw new \RuntimeException("Refusing to reuse an existing file: {$this->path}");
        }

        touch($this->path);
    }

    /** Register each named connection, all pointing at this file. */
    public function connect(string ...$names): void
    {
        foreach ($names as $name) {
            config(["database.connections.{$name}" => [
                'driver' => 'sqlite',
                'database' => $this->path,
                'prefix' => '',
                'foreign_key_constraints' => false,
                // Wait for the other connection's write rather than failing
                // at once with "database is locked".
                'busy_timeout' => 5000,
            ]]);
            DB::purge($name);
            $this->connections[] = $name;
        }
    }

    /**
     * Hand-declare, on $connection, the tables the gate touches: task_budgets
     * (column for column with the migration) and the usage_records columns
     * the spend read sums.
     */
    public function declareSchema(string $connection): void
    {
        $schema = Schema::connection($connection);

        $schema->create('task_budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->uuid('root_conversation_id');
            $table->uuid('root_run_id')->nullable();
            $table->uuid('managed_task_id')->nullable();
            $table->string('scope', 16);
            $table->boolean('unattended')->default(false);
            $table->string('state', 16)->default('running');
            $table->string('end_reason', 256)->nullable();
            $table->unsignedInteger('max_steps');
            $table->string('max_steps_origin', 32);
            $table->unsignedInteger('original_max_steps');
            $table->decimal('max_spend', 20, 10);
            $table->string('max_spend_origin', 32);
            $table->decimal('original_max_spend', 20, 10);
            $table->unsignedInteger('steps_used')->default(0);
            $table->decimal('approach_threshold', 5, 4);
            $table->timestamp('steps_approach_noticed_at', 6)->nullable();
            $table->timestamp('spend_approach_noticed_at', 6)->nullable();
            $table->timestamp('paused_at', 6)->nullable();
            $table->string('pause_reason', 16)->nullable();
            $table->uuid('paused_run_id')->nullable();
            $table->unsignedInteger('paused_iteration')->nullable();
            $table->string('resume_mode', 8)->nullable();
            $table->unsignedInteger('paused_steps_used')->nullable();
            $table->decimal('paused_spend', 20, 10)->nullable();
            $table->uuid('prompt_question_id')->nullable();
            $table->timestamp('ended_at', 6)->nullable();
            $table->timestamp('created_at', 6)->nullable();
            $table->timestamp('updated_at', 6)->nullable();

            $table->index('root_conversation_id');
            $table->index(['user_id', 'updated_at']);
            $table->index(['state', 'updated_at']);
            $table->index('managed_task_id');
            $table->index('prompt_question_id');
        });

        $schema->create('usage_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('task_budget_id')->nullable();
            $table->integer('total_tokens')->nullable()->default(0);
            $table->decimal('total_cost', 20, 10)->nullable();
            $table->boolean('cost_unpriced')->default(false);
            $table->boolean('cost_estimated')->default(false);
            $table->index('task_budget_id');
        });
    }

    /** Close every connection to the file and remove it. Safe to call twice. */
    public function delete(): void
    {
        if ($this->deleted) {
            return;
        }

        foreach ($this->connections as $name) {
            try {
                DB::purge($name);
            } catch (\Throwable) {
                // The application may already be gone (destructor path).
            }
        }

        foreach ([$this->path, $this->path.'-journal', $this->path.'-wal', $this->path.'-shm'] as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        $this->deleted = true;
    }

    public function __destruct()
    {
        $this->delete();
    }
}
