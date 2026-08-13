<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Exceptions\AgentScaffoldCollisionException;
use ClarionApp\LlmClient\Exceptions\AgentScaffoldDestinationException;
use ClarionApp\LlmClient\Services\AgentDefinitionScaffoldWriter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for AgentDefinitionScaffoldWriter (089-agent-scaffolding-cli,
 * Phase 3/US1, contracts §4, data-model.md §4, research.md D7/D8).
 *
 * Written before AgentDefinitionScaffoldWriter exists — every test in this
 * file is expected to fail with a "class not found"-style error until
 * Phase 3's own Implementation tasks (T016) create it. That is the
 * intended RED state, not a mistake.
 *
 * Uses a real OS temp directory per test (Grounding note 14 —
 * GitDefinitionFileReaderTest.php's own established pattern), never a
 * filesystem mock. This is the first test file in this package to use
 * chmod() to simulate an unwritable directory; the test process runs as
 * uid 1000 (non-root), so a 0500 directory is genuinely unwritable as far
 * as PHP's own is_writable()/fopen() are concerned.
 */
class AgentDefinitionScaffoldWriterTest extends TestCase
{
    /** @var string[] */
    private array $tempDirs = [];

    /**
     * Directories chmod'd to 0500 during a test — restored to 0777
     * unconditionally in tearDown(), even on assertion failure, so a
     * failed test never leaves an unwritable directory behind for a
     * later test run (or the OS temp cleanup) to trip over.
     *
     * @var string[]
     */
    private array $lockedDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->lockedDirs as $dir) {
            @chmod($dir, 0777);
        }
        $this->lockedDirs = [];

        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
        $this->tempDirs = [];

        parent::tearDown();
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/agent_scaffold_writer_test_'.uniqid('', true);
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        // Restore permissions unconditionally before attempting removal,
        // in case a prior assertion failure left this directory locked.
        @chmod($path, 0777);

        foreach (new \FilesystemIterator($path) as $item) {
            if ($item->isDir()) {
                $this->removeDirectory($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }

    private function writer(): AgentDefinitionScaffoldWriter
    {
        return new AgentDefinitionScaffoldWriter();
    }

    /**
     * @return string[] non-"."/".." entries directly inside $dir
     */
    private function directoryEntries(string $dir): array
    {
        return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    }

    // ---------------------------------------------------------------
    // 1. Happy path
    // ---------------------------------------------------------------

    #[Test]
    public function writes_the_file_with_exact_content_and_returns_the_absolute_path(): void
    {
        $dir = $this->makeTempDir();
        $content = "name: weather-agent\n";

        $path = $this->writer()->write($dir, 'weather-agent.yaml', $content);

        $this->assertSame($dir.'/weather-agent.yaml', $path);
        $this->assertFileExists($path);
        $this->assertSame($content, file_get_contents($path));
    }

    // ---------------------------------------------------------------
    // 2. Collision — the existing file is never touched (research.md D7,
    //    FR-009, mutation-checklist row 4 precursor)
    // ---------------------------------------------------------------

    #[Test]
    public function a_collision_leaves_the_existing_file_completely_untouched_and_creates_no_temp_file(): void
    {
        $dir = $this->makeTempDir();
        $existingPath = $dir.'/dup.yaml';
        file_put_contents($existingPath, "name: dup\n");
        touch($existingPath, time() - 1000);
        clearstatcache(true, $existingPath);
        $originalContent = file_get_contents($existingPath);
        $originalMtime = filemtime($existingPath);

        try {
            $this->writer()->write($dir, 'dup.yaml', 'different content');
            $this->fail('Expected AgentScaffoldCollisionException to be thrown.');
        } catch (AgentScaffoldCollisionException $e) {
            $this->assertStringContainsString($existingPath, $e->getMessage());
        }

        clearstatcache(true, $existingPath);
        $this->assertSame($originalContent, file_get_contents($existingPath), 'The existing file\'s content must be byte-for-byte unchanged.');
        $this->assertSame($originalMtime, filemtime($existingPath), 'The existing file\'s mtime must be exactly unchanged.');

        $strayTempFiles = glob($dir.'/.agent-scaffold-*');
        $this->assertSame([], $strayTempFiles, 'No temp file may ever be created before the collision check runs.');
    }

    // ---------------------------------------------------------------
    // 3. Destination directory does not exist (research.md D8, FR-011)
    // ---------------------------------------------------------------

    #[Test]
    public function a_missing_destination_directory_throws_with_reason_not_found(): void
    {
        $dir = $this->makeTempDir();
        $missing = $dir.'/does-not-exist';

        try {
            $this->writer()->write($missing, 'x.yaml', 'content');
            $this->fail('Expected AgentScaffoldDestinationException to be thrown.');
        } catch (AgentScaffoldDestinationException $e) {
            $this->assertSame('not_found', $e->getReason());
        }

        $this->assertDirectoryDoesNotExist($missing);
    }

    // ---------------------------------------------------------------
    // 4. Destination directory exists but is not writable (research.md D8,
    //    FR-011) + 5. No-partial-result guarantee (research.md D8,
    //    mutation-checklist row 3 precursor)
    // ---------------------------------------------------------------

    #[Test]
    public function an_unwritable_destination_directory_throws_with_reason_not_writable_and_leaves_nothing_behind(): void
    {
        $dir = $this->makeTempDir();
        chmod($dir, 0500);
        $this->lockedDirs[] = $dir;

        try {
            $this->writer()->write($dir, 'x.yaml', 'content');
            $this->fail('Expected AgentScaffoldDestinationException to be thrown.');
        } catch (AgentScaffoldDestinationException $e) {
            $this->assertSame('not_writable', $e->getReason());
        }

        // Restore permissions before inspecting the directory's contents —
        // the directory itself must be inspectable, but the *inability to
        // write* is the property under test, already asserted above.
        chmod($dir, 0777);

        $this->assertSame(
            [],
            $this->directoryEntries($dir),
            'No stray temp file or partial write of any kind may be left behind after a failed write.'
        );
    }
}
