<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * A closed-set guard over every call site that evaluates work against
 * ConversationWorkGate.
 *
 * Unlike RateLimitGate/BudgetGate, this gate has no entry-edge call site at
 * all — it is checked only inside an already-in-progress agent loop, at the
 * exact points a tool call or a schema-validation retry is about to
 * execute. This guard exists to catch a *future* addition that wires
 * ConversationWorkGate into a genuinely new call site by analogy with how
 * the sibling rate limiter and spending ceiling are enforced — for example
 * admitInteractiveWork()/admitResumedWork(), MessageController::store(), or
 * RunTraceRecorder::traceSystemRun() — none of which this feature ever
 * touches, because system-initiated and admission-time work are not the
 * question this ceiling answers.
 *
 * Equality, not containment: a subset check would notice a new,
 * unauthorized call site but say nothing when one of the four intended call
 * sites disappears — silently un-gating a whole entry path while this test
 * keeps passing.
 *
 * A file-level check alone is not enough here, unlike the rate limiter's
 * own guard: three of the four call sites this feature requires
 * (run()'s tool-call loop, run()'s schema-retry branch, and resumeSync()'s
 * tool-call loop) all live in the same file, Services/AgentLoopService.php.
 * A regression that silently deletes just one of those three — the
 * schema-retry branch in particular, since it is the one call site no
 * tool-call-batch scenario ever reaches — would still leave the file in the
 * matched set and a containment-only guard green. This guard therefore also
 * asserts the exact number of times the literal construct appears within
 * each matched file, not merely that it appears at all.
 *
 * This test needs no database and no application container.
 */
class ConversationWorkCallSiteGuardTest extends TestCase
{
    /**
     * The exact set of files permitted to call
     * ConversationWorkGate::evaluate(), relative to src/, together with the
     * exact number of times the literal construct must appear in each —
     * one entry per in-loop call site named above.
     */
    private const EXPECTED_OCCURRENCES = [
        'Services/AgentLoopService.php' => 3,
        'AgentLoopStreamHandler.php' => 1,
    ];

    private string $srcDir;

    protected function setUp(): void
    {
        $this->srcDir = dirname(__DIR__, 2).'/src';
    }

    #[Test]
    public function the_set_of_files_calling_conversation_work_gate_evaluate_equals_the_allowlist(): void
    {
        $found = array_keys($this->occurrencesByFile());
        $expected = array_keys(self::EXPECTED_OCCURRENCES);

        $unexpected = array_values(array_diff($found, $expected));
        $missing = array_values(array_diff($expected, $found));

        $this->assertSame(
            [],
            $unexpected,
            "A new call site evaluates work through ConversationWorkGate outside the closed set:\n"
            .implode("\n", $unexpected)
            ."\nEither remove this call site or, if it is genuinely a fifth in-loop point, name the reason it "
            .'belongs here and add it to the allowlist deliberately.'
        );

        $this->assertSame(
            [],
            $missing,
            "An allowlisted file no longer calls ConversationWorkGate::evaluate():\n"
            .implode("\n", $missing)
            ."\nA member that has stopped evaluating work through the gate is a stale allowlist entry, or a "
            .'silently un-gated in-loop call site — find out which.'
        );

        sort($expected);
        sort($found);
        $this->assertSame($expected, $found);
    }

    /**
     * The property a file-set check alone cannot see: three of the four
     * required call sites share one file. Asserting the exact occurrence
     * count per file is what catches one of those three silently
     * disappearing while the file itself still matches.
     */
    #[Test]
    public function each_allowlisted_file_calls_evaluate_exactly_the_expected_number_of_times(): void
    {
        $occurrences = $this->occurrencesByFile();

        foreach (self::EXPECTED_OCCURRENCES as $file => $expectedCount) {
            $this->assertSame(
                $expectedCount,
                $occurrences[$file] ?? 0,
                "{$file} must call ConversationWorkGate::evaluate() exactly {$expectedCount} time(s) — one per "
                .'distinct in-loop call site (a tool-call loop or a schema-validation retry branch), not merely '
                .'at least once.'
            );
        }
    }

    /**
     * ConversationWorkGate has no entry-edge call site to re-enter from —
     * it is never reached from RunTraceRecorder::traceSystemRun() or from
     * any deferred/system-initiated path, because this ceiling answers
     * "has this response already done too much", a question with no
     * meaning at admission time.
     */
    #[Test]
    public function run_trace_recorder_never_calls_conversation_work_gate(): void
    {
        $path = $this->srcDir.'/Services/RunTraceRecorder.php';
        $this->assertFileExists($path);

        $content = file_get_contents($path);

        $this->assertStringNotContainsString(
            'ConversationWorkGate',
            $content,
            'RunTraceRecorder must never reach ConversationWorkGate — this feature has no entry-edge call site '
            .'at all, by design, not exempted case by case'
        );
    }

    /**
     * @return array<string,int> Relative path (sorted) => exact number of
     *                            occurrences of ConversationWorkGate::class)->evaluate(
     */
    private function occurrencesByFile(): array
    {
        $occurrences = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->srcDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach (new RegexIterator($iterator, '/\.php$/') as $file) {
            $content = file_get_contents($file->getPathname());

            if ($content === false) {
                continue;
            }

            $count = substr_count($content, 'ConversationWorkGate::class)->evaluate(');

            if ($count > 0) {
                $relative = str_replace($this->srcDir.'/', '', $file->getPathname());
                $occurrences[$relative] = $count;
            }
        }

        ksort($occurrences);

        return $occurrences;
    }
}
