<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * A structural companion to ConversationWorkCallSiteGuardTest, not a new
 * mechanism of its own. That guard already proves the set of files calling
 * ConversationWorkGate::evaluate() is exactly {Services/AgentLoopService.php,
 * AgentLoopStreamHandler.php} — which, read the other way round, is the
 * same fact FR-002's scoping of the counted quantity to "work the agent
 * generates on its own within a response" needs one layer further, so a
 * conversation is never charged for work it never initiated: no
 * system-initiated path (a scheduled
 * job, a queued embedding generation, a title-generation request, or any
 * other caller of RunTraceRecorder::traceSystemRun()) is ever presented to
 * ConversationWorkGate at all, because none of those files appear in the
 * closed set.
 *
 * This test is deliberately thin. It exists to make that "true by
 * construction" claim falsifiable by re-deriving it from a second angle
 * rather than merely restating ConversationWorkCallSiteGuardTest's own
 * assertion: it re-runs the identical closed-set check, and additionally
 * confirms ConversationWorkGate is never referenced by RunTraceRecorder
 * itself or by any of the files that call its traceSystemRun() wrapper —
 * the concrete, file-level shape of "system-initiated work is exempt".
 *
 * This test needs no database and no application container.
 */
class SystemInitiatedWorkExemptGuardTest extends TestCase
{
    /**
     * The exact set of files permitted to call
     * ConversationWorkGate::evaluate(), together with the exact number of
     * times the literal construct must appear in each — identical to
     * ConversationWorkCallSiteGuardTest's own allowlist. Duplicated here,
     * not imported, so this test independently re-derives the same
     * guarantee rather than merely inheriting it.
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
    public function the_closed_set_of_call_sites_still_holds(): void
    {
        $found = $this->occurrencesByFile();

        $expectedFiles = array_keys(self::EXPECTED_OCCURRENCES);
        $foundFiles = array_keys($found);
        sort($expectedFiles);
        sort($foundFiles);

        $this->assertSame(
            $expectedFiles,
            $foundFiles,
            'The set of files calling ConversationWorkGate::evaluate() must remain exactly the four in-loop '
            .'call sites — a system-initiated path gaining a call site here would mean it is no longer exempt.'
        );

        foreach (self::EXPECTED_OCCURRENCES as $file => $expectedCount) {
            $this->assertSame(
                $expectedCount,
                $found[$file] ?? 0,
                "{$file} must call ConversationWorkGate::evaluate() exactly {$expectedCount} time(s)."
            );
        }
    }

    /**
     * RunTraceRecorder is the shared instrumentation surface every
     * system-initiated path routes through via traceSystemRun(). It must
     * never reach ConversationWorkGate itself — this ceiling has no
     * entry-edge call site at all, by design, so there is nothing for a
     * system-initiated run to be exempted from case by case.
     */
    #[Test]
    public function run_trace_recorder_never_references_conversation_work_gate(): void
    {
        $path = $this->srcDir.'/Services/RunTraceRecorder.php';
        $this->assertFileExists($path);

        $content = file_get_contents($path);

        $this->assertStringNotContainsString(
            'ConversationWorkGate',
            $content,
            'RunTraceRecorder must never reference ConversationWorkGate.'
        );
    }

    /**
     * Every file that calls RunTraceRecorder::traceSystemRun() is, by
     * construction, a system-initiated path (a scheduled job, a queued
     * embedding generation, a title-generation request, and so on — never
     * a live agent-loop response). None of them may reference
     * ConversationWorkGate, directly or otherwise, since none of them are
     * in the closed call-site set this test's first case already proved.
     */
    #[Test]
    public function no_caller_of_trace_system_run_references_conversation_work_gate(): void
    {
        $callers = $this->filesContaining('traceSystemRun(');
        $this->assertNotEmpty(
            $callers,
            'Precondition: at least one file must call traceSystemRun() for this assertion to mean anything.'
        );

        $offenders = [];

        foreach ($callers as $path) {
            $content = file_get_contents($path);

            if ($content !== false && str_contains($content, 'ConversationWorkGate')) {
                $offenders[] = str_replace($this->srcDir.'/', '', $path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A caller of traceSystemRun() references ConversationWorkGate, which means a system-initiated path "
            .'is being presented to a gate meant only for live, in-loop agent work: '.implode(', ', $offenders)
        );
    }

    /**
     * EmbeddingService::generate() is the clearest instance of work that
     * falls outside FR-002's "work the agent generates on its own within a
     * response" (a queued embedding run is system-initiated, not part of a
     * live response). Named
     * directly here, not only implicitly via the traceSystemRun() scan
     * above, so a future refactor that stops routing embeddings through
     * traceSystemRun() cannot silently drop this file from coverage.
     */
    #[Test]
    public function embedding_service_never_references_conversation_work_gate(): void
    {
        $path = $this->srcDir.'/Services/EmbeddingService.php';
        $this->assertFileExists($path);

        $content = file_get_contents($path);

        $this->assertStringNotContainsString(
            'ConversationWorkGate',
            $content,
            'EmbeddingService must never reference ConversationWorkGate — generating an embedding is '
            .'system-initiated work, not a live agent-loop response.'
        );
    }

    /**
     * @return array<string,int> Relative path => exact number of
     *                            occurrences of ConversationWorkGate::class)->evaluate(
     */
    private function occurrencesByFile(): array
    {
        $occurrences = [];

        foreach ($this->allPhpFiles() as $file) {
            $content = file_get_contents($file);

            if ($content === false) {
                continue;
            }

            $count = substr_count($content, 'ConversationWorkGate::class)->evaluate(');

            if ($count > 0) {
                $relative = str_replace($this->srcDir.'/', '', $file);
                $occurrences[$relative] = $count;
            }
        }

        ksort($occurrences);

        return $occurrences;
    }

    /**
     * @return string[] Absolute paths of every .php file under src/ whose
     *                   contents contain the given literal substring.
     */
    private function filesContaining(string $needle): array
    {
        $matches = [];

        foreach ($this->allPhpFiles() as $file) {
            $content = file_get_contents($file);

            if ($content !== false && str_contains($content, $needle)) {
                $matches[] = $file;
            }
        }

        sort($matches);

        return $matches;
    }

    /**
     * @return string[] Absolute paths of every .php file under src/.
     */
    private function allPhpFiles(): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->srcDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach (new RegexIterator($iterator, '/\.php$/') as $file) {
            $files[] = $file->getPathname();
        }

        return $files;
    }
}
