<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * Guards against mocks in integration tests.
 * Integration tests should use the real wired components, not mocks.
 */
class NoMocksGuardTest extends TestCase
{
    public function test_no_mockery_in_integration_tests(): void
    {
        $integrationDir = __DIR__;
        $violations = $this->grepForPattern($integrationDir, '/Mockery::mock\s*\(/', ['NoMocksGuardTest.php']);
        $this->assertEmpty($violations, 'Mockery::mock() found in Integration tests: ' . implode("\n", $violations));
    }

    public function test_no_createMock_in_integration_tests(): void
    {
        $integrationDir = __DIR__;
        $violations = $this->grepForPattern($integrationDir, '/->createMock\s*\(/', ['NoMocksGuardTest.php']);
        $this->assertEmpty($violations, 'createMock() found in Integration tests: ' . implode("\n", $violations));
    }

    public function test_no_instance_binding_for_llm_client(): void
    {
        $integrationDir = __DIR__;
        $violations = $this->grepForPattern($integrationDir, '/\$app->instance\s*\([^)]*ClarionApp\\\\LlmClient/', ['NoMocksGuardTest.php']);
        $this->assertEmpty($violations, '$app->instance() binding ClarionApp\LlmClient found in Integration tests: ' . implode("\n", $violations));
    }

    /**
     * Self-test: verify the guard actually detects violations.
     */
    public function test_guard_detects_synthetic_violation(): void
    {
        $tempDir = sys_get_temp_dir() . '/mock_guard_test_' . uniqid();
        mkdir($tempDir, 0755, true);
        $tempFile = $tempDir . '/violation.php';
        file_put_contents($tempFile, "<?php Mockery::mock(SomeClass::class);");
        
        $violations = $this->grepForPattern($tempDir, '/Mockery::mock\s*\(/');
        $this->assertNotEmpty($violations, 'Guard should detect synthetic Mockery::mock() violation');
        
        unlink($tempFile);
        rmdir($tempDir);
    }

    protected function grepForPattern(string $directory, string $pattern, array $excludeFiles = []): array
    {
        $violations = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        $files = new RegexIterator($iterator, '/\.php$/');

        foreach ($files as $file) {
            $basename = basename($file->getPathname());
            if (in_array($basename, $excludeFiles, true)) {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                $lines = explode("\n", $content);
                foreach ($matches[0] as $match) {
                    $lineNum = substr_count($content, "\n", 0, $match[1]) + 1;
                    $violations[] = sprintf('%s:%d: %s', $basename, $lineNum, trim($lines[$lineNum - 1]));
                }
            }
        }

        return $violations;
    }
}
