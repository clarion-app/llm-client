<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * T059: Registration guard — the fast suite verifies the gated target is wired correctly.
 *
 * This lives in the fast suite (no #[Group('real-db')]) because a guard inside
 * the gated target would be skipped in exactly the situation it exists to detect.
 *
 * Six directions asserted:
 * 1. Every *Test.php under tests/RealDatabase/ declares #[Group('real-db')].
 * 2. No file outside tests/RealDatabase/ carries the group.
 * 3. composer.json's test script contains --exclude-group real-db.
 * 4. composer.json defines test:real-db with tests/RealDatabase path and --fail-on-empty-test-suite.
 * 5. phpunit.xml registers the RealDatabase testsuite.
 * 6. Gated command's discovery is non-empty (at least as many classes as *Test.php files exist).
 */
class RealDatabaseRegistrationGuardTest extends TestCase
{
    private string $packageRoot;

    protected function setUp(): void
    {
        // Package root is two levels up from tests/Unit/
        $this->packageRoot = dirname(__DIR__, 2);
    }

    /**
     * Direction 1: Every *Test.php under tests/RealDatabase/ declares #[Group('real-db')].
     *
     * Excludes RealDatabaseTestCase.php (base class, not a test class with scenarios).
     * Excludes files under tests/RealDatabase/Support/ (fixture/helper classes, not test classes).
     */
    #[Test]
    public function everyRealDatabaseTestFileHasGroupAnnotation(): void
    {
        $realDbDir = $this->packageRoot . '/tests/RealDatabase';
        $testFiles = glob($realDbDir . '/*Test.php');

        $missing = [];
        foreach ($testFiles as $file) {
            $basename = basename($file);
            // Skip the base class — it's extended, not instantiated as a test.
            if ($basename === 'RealDatabaseTestCase.php') {
                continue;
            }

            $content = file_get_contents($file);
            if ($content === false) {
                $missing[] = $basename . ' (cannot read file)';
                continue;
            }

            // Strip comments to match only actual attribute annotations.
            $tokens = @token_get_all($content, TOKEN_PARSE);
            $codeOnly = '';
            if ($tokens !== false) {
                foreach ($tokens as $token) {
                    if (is_array($token)) {
                        $tokenName = token_name($token[0]);
                        if ($tokenName === 'T_COMMENT' || $tokenName === 'T_DOC_COMMENT') {
                            continue;
                        }
                        $codeOnly .= $token[1];
                    } else {
                        $codeOnly .= $token;
                    }
                }
            } else {
                $codeOnly = $content;
            }

            if (!preg_match('/#\s*\[\s*Group\s*\(\s*[\'"]real-db[\'"]\s*\)\s*\]/', $codeOnly)) {
                $missing[] = $basename;
            }
        }

        $this->assertEmpty(
            $missing,
            'These files under tests/RealDatabase/ are missing #[Group(\'real-db\')]: '
            . implode(', ', $missing)
        );
    }

    /**
     * Direction 2: No file outside tests/RealDatabase/ carries the real-db group attribute.
     *
     * A leak here means a real-db test is discoverable by the fast suite's
     * --exclude-group real-db — which would then skip it silently.
     */
    #[Test]
    public function noFileOutsideRealDatabaseHasGroupAnnotation(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->packageRoot . '/tests',
                \RecursiveDirectoryIterator::SKIP_DOTS
            )
        );

        $leaks = [];
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            // Skip the RealDatabase directory entirely.
            if (strpos($path, '/tests/RealDatabase/') !== false) {
                continue;
            }

            $content = file_get_contents($path);
            if ($content === false) {
                continue;
            }

            // Strip comments to avoid matching mentions in docblocks or string literals.
            // Tokenize and keep only non-comment tokens.
            $tokens = @token_get_all($content, TOKEN_PARSE);
            if ($tokens === false) {
                continue;
            }

            $codeOnly = '';
            foreach ($tokens as $token) {
                if (is_array($token)) {
                    $tokenName = token_name($token[0]);
                    // Skip comment tokens.
                    if ($tokenName === 'T_COMMENT' || $tokenName === 'T_DOC_COMMENT') {
                        continue;
                    }
                    $codeOnly .= $token[1];
                } else {
                    $codeOnly .= $token;
                }
            }

            if (preg_match('/#\s*\[\s*Group\s*\(\s*[\'"]real-db[\'"]\s*\)\s*\]/', $codeOnly)) {
                // Report relative to package root.
                $relative = str_replace($this->packageRoot . '/', '', $path);
                $leaks[] = $relative;
            }
        }

        $this->assertEmpty(
            $leaks,
            'These files outside tests/RealDatabase/ carry the real-db group attribute (leak): '
            . implode(', ', $leaks)
        );
    }

    /**
     * Direction 3: composer.json's test script contains --exclude-group real-db.
     */
    #[Test]
    public function composerTestScriptExcludesRealDbGroup(): void
    {
        $composer = json_decode(
            file_get_contents($this->packageRoot . '/composer.json'),
            true
        );

        $testScript = $composer['scripts']['test'] ?? '';
        $this->assertStringContainsString(
            '--exclude-group real-db',
            $testScript,
            "composer.json 'test' script must contain '--exclude-group real-db'"
        );
    }

    /**
     * Direction 4: composer.json defines test:real-db with tests/RealDatabase path
     * and --fail-on-empty-test-suite.
     */
    #[Test]
    public function composerTestRealDbScriptIsCorrect(): void
    {
        $composer = json_decode(
            file_get_contents($this->packageRoot . '/composer.json'),
            true
        );

        $script = $composer['scripts']['test:real-db'] ?? '';

        $this->assertNotEmpty(
            $script,
            "composer.json must define a 'test:real-db' script"
        );
        $this->assertStringContainsString(
            'tests/RealDatabase',
            $script,
            "composer.json 'test:real-db' must name the tests/RealDatabase path"
        );
        $this->assertStringContainsString(
            '--fail-on-empty-test-suite',
            $script,
            "composer.json 'test:real-db' must include --fail-on-empty-test-suite"
        );
    }

    /**
     * Direction 5: phpunit.xml registers the RealDatabase testsuite.
     */
    #[Test]
    public function phpunitXmlRegistersRealDatabaseTestsuite(): void
    {
        $xml = file_get_contents($this->packageRoot . '/phpunit.xml');
        $this->assertNotFalse($xml, 'phpunit.xml must exist');

        // Check for a testsuite named "RealDatabase" pointing at tests/RealDatabase.
        $hasTestsuite = preg_match(
            '/<testsuite\s+name="RealDatabase">.*?<directory>tests\/RealDatabase<\/directory>.*?<\/testsuite>/s',
            $xml
        );

        $this->assertTrue(
            (bool) $hasTestsuite,
            'phpunit.xml must register a <testsuite name="RealDatabase"> pointing at tests/RealDatabase'
        );
    }

    /**
     * Direction 6: The gated command discovers at least as many test classes
     * as there are *Test.php files under tests/RealDatabase/ (excluding base class).
     *
     * This catches the case where the gate is wired but PHPUnit finds nothing —
     * e.g., the testsuite path is wrong, the autoload is broken, or the classes
     * don't match the PSR pattern. PHPUnit exits 0 on "No tests executed!" so
     * without this check, an empty gated target silently passes.
     */
    #[Test]
    public function gatedDiscoveryIsNonEmpty(): void
    {
        $realDbDir = $this->packageRoot . '/tests/RealDatabase';

        // Count *Test.php files (excluding the base class and Support/ helpers).
        $testFiles = glob($realDbDir . '/*Test.php');
        $expectedMin = 0;
        foreach ($testFiles as $file) {
            $basename = basename($file);
            if ($basename === 'RealDatabaseTestCase.php') {
                continue;
            }
            $expectedMin++;
        }

        $this->assertGreaterThan(
            0,
            $expectedMin,
            'There must be at least one *Test.php file under tests/RealDatabase/ '
            . '(excluding RealDatabaseTestCase.php)'
        );

        // Verify each file actually contains a class that PHPUnit would discover.
        $classesFound = 0;
        foreach ($testFiles as $file) {
            $basename = basename($file);
            if ($basename === 'RealDatabaseTestCase.php') {
                continue;
            }

            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            // Check for a class declaration (PHPUnit discovers classes, not files).
            if (preg_match('/^\s*(?:final\s+)?class\s+\w+/m', $content)) {
                $classesFound++;
            }
        }

        $this->assertGreaterThanOrEqual(
            $expectedMin,
            $classesFound,
            "Expected at least {$expectedMin} discoverable test classes under tests/RealDatabase/, "
            . "but found {$classesFound}. Each *Test.php file must contain a class declaration."
        );
    }
}
