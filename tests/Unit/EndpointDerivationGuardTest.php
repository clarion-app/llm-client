<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * T009: Endpoint derivation guard — SC-011.
 *
 * Scans every .php file under llm-client/src/ and fails on forbidden patterns
 * outside the allowlist. This test MUST fail against the current tree (before
 * T010-T016 are done), which is the point — T017 gates on it passing.
 *
 * Three patterns checked:
 * 1. `server_url` identifier — allowlisted in EndpointResolver, ServerAddress,
 *    Server model, ServerController, and Migrations.
 * 2. `/v1/(chat/completions|models|embeddings|messages)` string literal —
 *    allowlisted in EndpointResolver (PATH_TABLE) and ServerAddress (suffix list).
 * 3. `function getBaseUrl` — no allowlist; the method must not exist.
 */
class EndpointDerivationGuardTest extends TestCase
{
    /**
     * Files allowed to reference `server_url`.
     * ServerController names it as a validation key and passes it to ServerAddress::fromInput().
     * Migrations declare the column. Server model declares $fillable.
     * EndpointResolver reads it to pass to urlForValues.
     * ServerAddress normalizes it.
     * Providers read it to pass to EndpointResolver::urlForValues/headersForValues.
     */
    private const SERVER_URL_ALLOWLIST = [
        'Services/EndpointResolver.php',
        'ValueObjects/ServerAddress.php',
        'Models/Server.php',
        'Controllers/ServerController.php',
        'Providers/AnthropicProvider.php',
        'Providers/LlamaCppProvider.php',
        'Providers/OpenAiProvider.php',
    ];

    /**
     * Files allowed to contain /v1/ endpoint path string literals.
     * EndpointResolver has the PATH_TABLE; ServerAddress has the suffix strip list.
     */
    private const V1_PATH_ALLOWLIST = [
        'Services/EndpointResolver.php',
        'ValueObjects/ServerAddress.php',
    ];

    private string $srcDir;

    protected function setUp(): void
    {
        $this->srcDir = dirname(__DIR__, 2) . '/src';
    }

    /**
     * Pattern 1: `server_url` outside the allowlist.
     */
    #[Test]
    public function noServerUrlOutsideAllowlist(): void
    {
        $violations = $this->scanForPattern(
            'server_url',
            self::SERVER_URL_ALLOWLIST,
            // Also allow any file under Migrations/
            fn (string $relativePath) => str_starts_with($relativePath, 'Migrations/')
        );

        $this->assertEmpty(
            $violations,
            "Files reference 'server_url' outside the allowlist (forbidden by SC-011):\n"
            . implode("\n", $violations)
        );
    }

    /**
     * Pattern 2: /v1/ endpoint path string literals outside the allowlist.
     */
    #[Test]
    public function noV1PathLiteralsOutsideAllowlist(): void
    {
        $violations = $this->scanForRegexPattern(
            // Match /v1/ followed by known endpoint paths in string literals.
            // This catches things like '/v1/chat/completions', "/v1/models", etc.
            "/['\"\\/]v1\\/(chat\\/completions|models|embeddings|messages)/",
            self::V1_PATH_ALLOWLIST,
        );

        $this->assertEmpty(
            $violations,
            "Files contain '/v1/' endpoint path string literals outside the allowlist (forbidden by SC-011):\n"
            . implode("\n", $violations)
        );
    }

    /**
     * Pattern 3: `function getBaseUrl` — must not exist anywhere.
     */
    #[Test]
    public function noGetBaseUrlFunction(): void
    {
        $violations = $this->scanForPattern(
            'function getBaseUrl',
            [], // No allowlist — this method must be deleted.
            fn (string $relativePath) => false
        );

        $this->assertEmpty(
            $violations,
            "Files still contain 'function getBaseUrl' (must be deleted; forbidden by SC-011):\n"
            . implode("\n", $violations)
        );
    }

    /**
     * Scan all .php files under src/ for a plain text pattern.
     *
     * @param string[] $allowlist Relative paths (e.g., 'Services/EndpointResolver.php')
     * @param callable(string): bool $extraAllow  Extra allowlist check (e.g., Migrations/*)
     * @return string[] Violation lines
     */
    private function scanForPattern(string $pattern, array $allowlist, callable $extraAllow): array
    {
        return $this->scanFiles(function (string $relativePath, int $lineNumber, string $line) use ($pattern, $allowlist, $extraAllow) {
            if (in_array($relativePath, $allowlist, true)) {
                return null;
            }
            if ($extraAllow($relativePath)) {
                return null;
            }
            if (stripos($line, $pattern) !== false) {
                return "{$relativePath}:{$lineNumber}: {$line}";
            }
            return null;
        });
    }

    /**
     * Scan all .php files under src/ for a regex pattern.
     *
     * @param string[] $allowlist Relative paths
     * @return string[] Violation lines
     */
    private function scanForRegexPattern(string $regex, array $allowlist): array
    {
        return $this->scanFiles(function (string $relativePath, int $lineNumber, string $line) use ($regex, $allowlist) {
            if (in_array($relativePath, $allowlist, true)) {
                return null;
            }
            if (preg_match($regex, $line)) {
                return "{$relativePath}:{$lineNumber}: {$line}";
            }
            return null;
        });
    }

    /**
     * Scan all .php files under src/ with a callback.
     *
     * @param callable(string $relativePath, int $lineNumber, string $line): ?string $check
     * @return string[] Non-null results from $check
     */
    private function scanFiles(callable $check): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->srcDir)
        );
        $phpFiles = new RegexIterator($iterator, '/\.php$/');

        $violations = [];

        foreach ($phpFiles as $file) {
            $path = $file->getPathname();
            $relativePath = str_replace($this->srcDir . '/', '', $path);

            $content = file_get_contents($path);
            if ($content === false) {
                continue;
            }

            $lines = explode("\n", $content);
            foreach ($lines as $lineNumber => $line) {
                $result = $check($relativePath, $lineNumber + 1, $line);
                if ($result !== null) {
                    $violations[] = $result;
                }
            }
        }

        return $violations;
    }
}
