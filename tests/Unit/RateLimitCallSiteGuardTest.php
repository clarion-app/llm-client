<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * A closed-set guard over every call site that admits work through
 * RateLimitGate.
 *
 * Unlike the enforcement guard the spending ceiling feature needed, there
 * is no open set of "must eventually be gated" call sites to keep covered
 * here — the rate limiter has exactly two entry funnels by design, and
 * nothing else in this package ever reaches a rate-limited user's request
 * count. This guard exists in the other direction: to catch a *future*
 * addition that wires RateLimitGate into a third call site by analogy with
 * how spending is enforced (a background job, a deferred retry, or a
 * system-initiated path) — precisely the kind of well-intentioned "fix"
 * that would let system-initiated work quietly consume a human user's own
 * allowance.
 *
 * Equality, not containment: a subset check would notice a new,
 * unauthorized call site but say nothing when one of the two intended call
 * sites disappears — silently un-gating a whole entry path while this test
 * keeps passing.
 *
 * This test needs no database and no application container.
 */
class RateLimitCallSiteGuardTest extends TestCase
{
    /**
     * The exact set of files permitted to call RateLimitGate::admit(),
     * relative to src/. No more, no fewer.
     */
    private const ALLOWLIST = [
        'Services/AgentLoopService.php',
        'Controllers/MessageController.php',
    ];

    private string $srcDir;

    protected function setUp(): void
    {
        $this->srcDir = dirname(__DIR__, 2).'/src';
    }

    #[Test]
    public function the_set_of_files_calling_rate_limit_gate_admit_equals_the_allowlist(): void
    {
        $found = $this->filesCallingAdmit();

        $unexpected = array_values(array_diff($found, self::ALLOWLIST));
        $missing = array_values(array_diff(self::ALLOWLIST, $found));

        $this->assertSame(
            [],
            $unexpected,
            "A new call site admits work through RateLimitGate outside the closed set:\n"
            .implode("\n", $unexpected)
            .'
Either remove this call site or, if it is genuinely a third funnel, name '
            .'the reason it belongs here and add it to the allowlist deliberately.'
        );

        $this->assertSame(
            [],
            $missing,
            "An allowlisted file no longer calls RateLimitGate::admit():\n"
            .implode("\n", $missing)
            ."\nA member that has stopped admitting work through the gate is a stale "
            .'allowlist entry, or a silently un-gated entry path — find out which.'
        );

        $expected = self::ALLOWLIST;
        sort($expected);
        sort($found);
        $this->assertSame($expected, $found);
    }

    /**
     * RateLimitGate has no call site to re-enter from — unlike BudgetGate,
     * it is never reached from RunTraceRecorder::traceSystemRun() or from
     * any deferred/system-initiated path, because a rate limit protects how
     * often a user starts something, and system-initiated work is
     * definitionally not that.
     */
    #[Test]
    public function run_trace_recorder_never_calls_rate_limit_gate(): void
    {
        $path = $this->srcDir.'/Services/RunTraceRecorder.php';
        $this->assertFileExists($path);

        $content = file_get_contents($path);

        $this->assertStringNotContainsString(
            'RateLimitGate',
            $content,
            'RunTraceRecorder must never reach RateLimitGate — system-initiated work is not '
            .'presented to the gate at all, by design, not exempted case by case'
        );
    }

    /**
     * @return string[] Relative paths, sorted, of every src/ file containing
     *                  a call of the form RateLimitGate::class)->admit(
     */
    private function filesCallingAdmit(): array
    {
        $found = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->srcDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach (new RegexIterator($iterator, '/\.php$/') as $file) {
            $content = file_get_contents($file->getPathname());

            if ($content === false) {
                continue;
            }

            // Matches app(RateLimitGate::class)->admit( as well as any other
            // expression ending in RateLimitGate::class)->admit(.
            if (preg_match('/RateLimitGate::class\)->admit\(/', $content)) {
                $found[] = str_replace($this->srcDir.'/', '', $file->getPathname());
            }
        }

        sort($found);

        return $found;
    }
}
