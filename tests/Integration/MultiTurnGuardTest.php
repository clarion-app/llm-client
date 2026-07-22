<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SimpleXMLElement;

/**
 * Phase 8 (T056-T058): cross-cutting guards for the multi-turn verification
 * suite, mirroring NoMocksGuardTest's grep-based pattern (053).
 */
class MultiTurnGuardTest extends TestCase
{
    /**
     * This feature's scenario files (Phases 3-7). MultiTurnGuardTest itself
     * is a guard, not a scenario, and is excluded from the scan.
     */
    private const SCENARIO_FILES = [
        'LongConversationJourneyTest.php',
        'RetainedInstructionJourneyTest.php',
        'OperationRecallJourneyTest.php',
        'ConversationRecordJourneyTest.php',
        'SessionBoundaryJourneyTest.php',
    ];

    /**
     * Response-builder methods whose string argument is text the *script*
     * supplies (never the product) — S8/SC-001a.
     */
    private const RESPONSE_BUILDER_METHODS = [
        'finalAnswer',
        'summary',
        'condensationSummary',
        'episodicSummary',
    ];

    /**
     * Assertion methods whose first argument is the expected value.
     */
    private const ASSERTION_METHODS = [
        'assertSame',
        'assertEquals',
        'assertStringContainsString',
        'assertStringContainsStringIgnoringCase',
    ];

    /**
     * T056 / FR-022 / SC-008 / research R11 — the exact 058 lesson: a
     * <testsuite> whose <directory> entry silently never executes a whole
     * directory of test files, so a suite reports green having run nothing
     * from it (058's `tests/Integration` was never declared; the entire
     * FR-022 matrix there never ran). Written over the whole tests/ tree,
     * not just this feature's files, so it also guards any test file added
     * later by any other feature.
     */
    public function test_every_added_file_is_registered_in_phpunit_configuration(): void
    {
        $root = dirname(__DIR__, 2);
        $phpunitXmlPath = $root . '/phpunit.xml';
        $this->assertFileExists($phpunitXmlPath, 'phpunit.xml must exist at the package root.');

        $xml = new SimpleXMLElement((string) file_get_contents($phpunitXmlPath));

        $configuredDirectories = [];
        foreach ($xml->testsuites->testsuite as $testsuite) {
            foreach ($testsuite->directory as $directory) {
                $configuredDirectories[] = $root . '/' . ltrim((string) $directory, '/');
            }
        }
        $this->assertNotEmpty(
            $configuredDirectories,
            'phpunit.xml declared no <directory> entries under any <testsuite> — nothing would ever run.'
        );

        $coveredFiles = [];
        foreach ($configuredDirectories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            foreach ($this->findTestFiles($directory) as $file) {
                $coveredFiles[$file] = true;
            }
        }

        $allTestFiles = $this->findTestFiles($root . '/tests');

        $uncovered = [];
        foreach ($allTestFiles as $file) {
            if (!isset($coveredFiles[$file])) {
                $uncovered[] = substr($file, strlen($root) + 1);
            }
        }

        $this->assertEmpty(
            $uncovered,
            "The following test file(s) exist under tests/ but are not covered by any <testsuite><directory> "
                . "declared in phpunit.xml, and would never run (they would pass silently by never executing):\n"
                . implode("\n", $uncovered)
        );
    }

    /**
     * T057 / FR-017 / research R3 / contract B6 — enqueue-only assertions
     * are exactly the pattern that hid the premature-capture defect this
     * feature exists to catch: they prove work was *queued*, never that it
     * ran or produced the right artifact. Forbidden across this feature's
     * scenario files: Bus::fake() (any use), Queue::fake() with no explicit
     * class list (a bare Queue::fake() fakes every queued job, masking
     * whether GenerateEpisodicMemoryJob actually ran), and
     * Queue::assertPushed() (proves dispatch, not effect).
     */
    public function test_no_enqueue_only_assertions_in_multi_turn_scenarios(): void
    {
        $violations = [];
        foreach (self::SCENARIO_FILES as $filename) {
            $path = __DIR__ . '/' . $filename;
            $this->assertFileExists($path, "Expected scenario file {$filename} to exist.");
            $code = $this->stripComments((string) file_get_contents($path));

            $violations = array_merge($violations, $this->grepPattern(
                $code,
                $filename,
                '/\bBus::fake\s*\(/',
                'Bus::fake() is forbidden in this suite (FR-017)'
            ));
            $violations = array_merge($violations, $this->grepPattern(
                $code,
                $filename,
                '/\bQueue::fake\s*\(\s*\)/',
                'Queue::fake() with no explicit class list is forbidden in this suite (FR-017) — it fakes every queued job'
            ));
            $violations = array_merge($violations, $this->grepPattern(
                $code,
                $filename,
                '/\bQueue::assertPushed\s*\(/',
                'Queue::assertPushed() is forbidden in this suite (FR-017, B6) — assert on the resulting artifact instead'
            ));
        }

        $this->assertEmpty(
            $violations,
            "Enqueue-only assertions are forbidden in this suite (FR-017, contract B6) — deferred work must be "
                . "asserted on its resulting artifact, never on the fact of dispatch:\n" . implode("\n", $violations)
        );
    }

    /**
     * T058 / contract S8 / SC-001a — an assertion whose expected-value
     * string literal is also text the scenario's own script fed into a
     * response builder (`finalAnswer()`, `summary()`, `condensationSummary()`,
     * `episodicSummary()`) is checking that scripted text came back out
     * verbatim, not that the product did anything. Assertions must rest on
     * delivered payloads, persisted artifacts, or which rule fired.
     */
    public function test_no_assertion_rests_on_scripted_response_text(): void
    {
        $violations = [];
        foreach (self::SCENARIO_FILES as $filename) {
            $path = __DIR__ . '/' . $filename;
            $this->assertFileExists($path, "Expected scenario file {$filename} to exist.");
            $code = $this->stripComments((string) file_get_contents($path));

            $scriptedStrings = $this->extractFirstStringArguments($code, self::RESPONSE_BUILDER_METHODS);
            $assertedStrings = $this->extractFirstStringArguments($code, self::ASSERTION_METHODS);

            // Ignore trivial/empty strings — not meaningful scripted content.
            $overlap = array_filter(
                array_unique(array_intersect($scriptedStrings, $assertedStrings)),
                fn (string $s) => trim($s) !== ''
            );

            foreach ($overlap as $literal) {
                $violations[] = sprintf(
                    "%s: an assertion expects the literal '%s', which is also text the script supplies via a "
                        . 'response builder in the same file — contract S8/SC-001a forbids asserting on scripted '
                        . 'response wording.',
                    $filename,
                    $literal
                );
            }
        }

        $this->assertEmpty($violations, implode("\n", $violations));
    }

    /**
     * @return list<string>
     */
    private function findTestFiles(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        $regex = new RegexIterator($iterator, '/Test\.php$/');
        foreach ($regex as $file) {
            $realPath = $file->getRealPath();
            if ($realPath !== false) {
                $files[] = $realPath;
            }
        }

        return $files;
    }

    /**
     * Strips PHP comments (// # and block/doc comments) while preserving
     * line numbers, so a pattern mentioned only in prose (e.g. this file's
     * own docblocks, or SessionBoundaryJourneyTest's B5 comment explaining
     * why Queue::fake() is NOT used) never trips a violation.
     */
    private function stripComments(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                [$id, $text] = $token;
                if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                    // Preserve line numbering for any later offset math.
                    $out .= str_repeat("\n", substr_count($text, "\n"));
                    continue;
                }
                $out .= $text;
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function grepPattern(string $code, string $filename, string $pattern, string $label): array
    {
        $violations = [];
        if (preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE)) {
            $lines = explode("\n", $code);
            foreach ($matches[0] as $match) {
                $lineNum = substr_count($code, "\n", 0, $match[1]) + 1;
                $violations[] = sprintf(
                    '%s:%d: %s — %s',
                    $filename,
                    $lineNum,
                    $label,
                    trim($lines[$lineNum - 1] ?? '')
                );
            }
        }

        return $violations;
    }

    /**
     * Extracts the first argument of each call to any of the given method
     * names, where that argument is a plain (non-interpolated) string
     * literal — single- or double-quoted.
     *
     * @param list<string> $methodNames
     * @return list<string>
     */
    private function extractFirstStringArguments(string $code, array $methodNames): array
    {
        $names = implode('|', array_map(fn (string $n) => preg_quote($n, '/'), $methodNames));
        $literals = [];

        if (preg_match_all('/\b(?:' . $names . ')\s*\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $code, $matches)) {
            $literals = array_merge($literals, $matches[1]);
        }

        if (preg_match_all('/\b(?:' . $names . ')\s*\(\s*"((?:[^"\\\\]|\\\\.)*)"/', $code, $matches)) {
            foreach ($matches[1] as $literal) {
                // Skip interpolated double-quoted strings — not a fixed literal.
                if (strpos($literal, '$') === false) {
                    $literals[] = $literal;
                }
            }
        }

        return array_map(static fn (string $s) => stripcslashes($s), $literals);
    }
}
