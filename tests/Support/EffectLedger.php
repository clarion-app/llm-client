<?php

namespace Tests\Support;

/**
 * The durable witness that an effectful tool's side effect actually fired
 * (research D11). An in-memory counter keyed by tool name + argument hash,
 * so two executions with different arguments are distinct entries and the
 * same (tool, arguments) pair counts as repeated executions.
 *
 * The in-memory counter is lost on a process restart — which is exactly the
 * scenario under test (a killed worker) — so the optional file mode appends
 * one durable line per execution: after a restart the test re-reads the file
 * and counts the lines, which the in-memory counter cannot do.
 */
final class EffectLedger
{
    /** @var array<string, int> keyed by tool + argument hash. */
    private array $counts = [];

    /** @var resource|null */
    private $handle = null;

    /**
     * Enable the file mode: append one durable line per execution to $path.
     * The file is opened in append-binary mode and left open for the ledger's
     * lifetime; close it with close() (or let the process exit).
     */
    public function withFile(string $path): self
    {
        $this->handle = fopen($path, 'ab');
        if ($this->handle === false) {
            throw new \RuntimeException("EffectLedger: could not open {$path} for append.");
        }
        return $this;
    }

    /**
     * Record one execution of $tool with $arguments.
     */
    public function record(string $tool, array $arguments = []): void
    {
        $key = $this->key($tool, $arguments);
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;

        if ($this->handle !== null) {
            $line = json_encode([
                'tool' => $tool,
                'key' => $key,
                'n' => $this->counts[$key],
            ], JSON_THROW_ON_ERROR) . PHP_EOL;
            fwrite($this->handle, $line);
            fflush($this->handle);
            $this->fsync();
        }
    }

    /**
     * Total executions of $tool across all argument hashes.
     */
    public function count(string $tool): int
    {
        $total = 0;
        $prefix = $tool . '::';
        foreach ($this->counts as $key => $count) {
            if (str_starts_with($key, $prefix)) {
                $total += $count;
            }
        }
        return $total;
    }

    /**
     * The full key => count map.
     *
     * @return array<string, int>
     */
    public function entries(): array
    {
        return $this->counts;
    }

    /**
     * The number of distinct (tool, arguments) keys recorded.
     */
    public function distinct(): int
    {
        return count($this->counts);
    }

    /**
     * Reset the in-memory counter. The file, once written, is not truncated —
     * it is the durable witness and must survive.
     */
    public function reset(): void
    {
        $this->counts = [];
    }

    /**
     * Flush and close the file handle. Safe to call twice.
     */
    public function close(): void
    {
        if ($this->handle !== null) {
            fflush($this->handle);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * The (tool, arguments) key: tool name + a stable hash of the arguments,
     * so argument shape is part of the identity and argument order is not.
     */
    private function key(string $tool, array $arguments): string
    {
        ksort($arguments);
        return $tool . '::' . sha1(json_encode($arguments, JSON_THROW_ON_ERROR));
    }

    /**
     * Make the just-written line durable. PHP has no per-file fsync();
     * fflush() pushes the line into the OS buffer — durable across a process
     * crash, the "kill the worker" scenario this ledger exists for — and the
     * flock barrier is the portable best-effort sync on top of it.
     */
    private function fsync(): void
    {
        flock($this->handle, LOCK_EX);
        flock($this->handle, LOCK_UN);
    }
}
