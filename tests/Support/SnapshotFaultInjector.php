<?php

namespace Tests\Support;

use ClarionApp\LlmClient\Contracts\FaultInjector;
use Illuminate\Support\Facades\DB;

/**
 * The in-process fault-injection stand-in for a SIGKILLed worker
 * (research D21). Arm one (point, occurrence) pair; on the Nth reach()
 * of that point, copy every row of the listed tables and throw the
 * InjectedFault. The test then does its "aftermath" work and calls
 * restore() to return the tables to exactly the snapshot — the database
 * as a kill would have left it, since the run's own catch would
 * otherwise close it as failed.
 */
class SnapshotFaultInjector implements FaultInjector
{
    private ?string $armedPoint = null;
    private int $armedOccurrence = 0;

    /** @var list<string> */
    private array $armedTables = [];

    /** @var array<string, int> */
    private array $reachCounts = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $snapshot = [];

    /**
     * Arm one (point, occurrence) pair over the listed tables. `task_budgets`
     * is always snapshotted and restored too: the run's own catch closes the
     * run (ending its task budget via rootRunClosed()), but a real SIGKILL
     * would not run that catch, so the budget must be left as it was at the
     * kill — otherwise a resumed run would see a terminal budget and stop.
     */
    private bool $fired = false;

    public function arm(string $point, int $occurrence, array $tables): void
    {
        $this->armedPoint = $point;
        $this->armedOccurrence = $occurrence;
        $this->armedTables = array_values(array_unique(array_merge($tables, ['task_budgets'])));
        $this->reachCounts = [];
        $this->snapshot = [];
        $this->fired = false;
    }

    public function reach(string $point): void
    {
        if ($this->armedPoint === null) {
            return;
        }

        $this->reachCounts[$point] = ($this->reachCounts[$point] ?? 0) + 1;

        if ($point !== $this->armedPoint || $this->reachCounts[$point] !== $this->armedOccurrence) {
            return;
        }

        foreach ($this->armedTables as $table) {
            $this->snapshot[$table] = DB::table($table)
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();
        }

        $this->fired = true;

        throw new InjectedFault("Injected fault at {$point} (occurrence {$this->armedOccurrence}).");
    }

    /**
     * Whether the armed fault fired — for a kill inside a path whose caller
     * turns every exception into an error response, so it never reaches
     * the test as a thrown InjectedFault.
     */
    public function fired(): bool
    {
        return $this->fired;
    }

    /**
     * Return the listed tables to exactly the snapshot: truncate each and
     * re-insert the snapshot rows.
     */
    public function restore(): void
    {
        foreach ($this->snapshot as $table => $rows) {
            DB::table($table)->delete();
            if ($rows !== []) {
                DB::table($table)->insert($rows);
            }
        }

        $this->snapshot = [];
        $this->armedPoint = null;
        $this->fired = false;
    }
}
