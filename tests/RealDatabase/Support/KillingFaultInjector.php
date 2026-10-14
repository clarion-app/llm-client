<?php

namespace Tests\RealDatabase\Support;

use ClarionApp\LlmClient\Contracts\FaultInjector;

/**
 * A fault injector that kills its own process, for real.
 *
 * Armed through LLM_CLIENT_FAULT_POINT=<point>:<occurrence>. The occurrence-th
 * time execution reaches the named point it sends the process SIGKILL, so no
 * catch, finally or destructor runs: exactly what a worker killed by the
 * operating system leaves behind.
 */
class KillingFaultInjector implements FaultInjector
{
    private ?string $point = null;

    private int $occurrence = 0;

    private int $reached = 0;

    public function __construct(?string $spec = null)
    {
        $spec ??= (string) getenv('LLM_CLIENT_FAULT_POINT');

        if ($spec !== '' && str_contains($spec, ':')) {
            [$point, $occurrence] = explode(':', $spec, 2);
            $this->point = $point;
            $this->occurrence = (int) $occurrence;
        }
    }

    public function reach(string $point): void
    {
        if ($this->point === null || $point !== $this->point) {
            return;
        }

        $this->reached++;

        if ($this->reached === $this->occurrence) {
            posix_kill(getmypid(), SIGKILL);
            // The signal is delivered before another statement runs; never fall through.
            sleep(60);
        }
    }
}
