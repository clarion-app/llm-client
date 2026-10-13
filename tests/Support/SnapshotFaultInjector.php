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
     * Arm one (point, occurrence) pair over the listed tables.
     */
    public function arm(string $point, int $occurrence, array $tables): void
    {
        $this->armedPoint = $point;
        $this->armedOccurrence = $occurrence;
        $this->armedTables = $tables;
        $this->reachCounts = [];
        $this->snapshot = [];
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

        throw new InjectedFault("Injected fault at {$point} (occurrence {$this->armedOccurrence}).");
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
    }
}
