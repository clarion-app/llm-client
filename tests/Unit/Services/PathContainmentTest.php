<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Services\PathContainment;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for PathContainment::validate() (112-coding-agent,
 * Foundational, D4, data-model.md §3) — the realpath()-based,
 * symlink-resistant containment check every coding-workspace file
 * operation is scoped by.
 *
 * Driven against a real filesystem (a real temp directory, a real
 * symlink) rather than mocks, per research.md D8 — a string-prefix check
 * alone cannot prove a symlink escape is actually caught.
 */
class PathContainmentTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = sys_get_temp_dir().'/coding_project_'.bin2hex(random_bytes(8));
        mkdir($this->projectRoot);
        mkdir($this->projectRoot.'/subdir');
        file_put_contents($this->projectRoot.'/subdir/existing.txt', 'hello');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectRoot);

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir) && !is_link($dir)) {
            return;
        }

        if (is_link($dir)) {
            unlink($dir);

            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    // ---------------------------------------------------------------
    // A literal ".." segment is rejected before resolution
    // ---------------------------------------------------------------

    #[Test]
    public function a_literal_dot_dot_segment_is_rejected(): void
    {
        $result = PathContainment::validate($this->projectRoot, '../etc/passwd');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('traversal', $result['reason']);
    }

    #[Test]
    public function a_dot_dot_segment_buried_inside_an_otherwise_valid_path_is_rejected(): void
    {
        $result = PathContainment::validate($this->projectRoot, 'subdir/../../outside.txt');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('traversal', $result['reason']);
    }

    // ---------------------------------------------------------------
    // A real symlink inside the project pointing outside it is rejected
    // (realpath()-resolved, not string-matched)
    // ---------------------------------------------------------------

    #[Test]
    public function a_symlink_inside_the_project_pointing_outside_it_is_rejected(): void
    {
        $outside = sys_get_temp_dir().'/coding_project_outside_'.bin2hex(random_bytes(8));
        mkdir($outside);
        file_put_contents($outside.'/secret.txt', 'top secret');

        $link = $this->projectRoot.'/escape';
        symlink($outside, $link);

        try {
            $result = PathContainment::validate($this->projectRoot, 'escape/secret.txt');

            $this->assertFalse($result['valid'], 'a symlink escape must not be reported valid');
            $this->assertStringContainsString('outside', $result['reason']);
        } finally {
            unlink($link);
            $this->removeDirectory($outside);
        }
    }

    // ---------------------------------------------------------------
    // Write's not-yet-existing target is checked via its PARENT directory
    // ---------------------------------------------------------------

    #[Test]
    public function a_not_yet_existing_write_target_is_valid_when_its_parent_is_contained(): void
    {
        $result = PathContainment::validate($this->projectRoot, 'subdir/new-file.txt', targetMustExist: false);

        $this->assertTrue($result['valid']);
        $this->assertSame(
            realpath($this->projectRoot.'/subdir').'/new-file.txt',
            $result['resolved_path'],
        );
    }

    #[Test]
    public function a_write_whose_parent_directory_does_not_exist_is_rejected(): void
    {
        $result = PathContainment::validate($this->projectRoot, 'no-such-dir/new-file.txt', targetMustExist: false);

        $this->assertFalse($result['valid']);
    }

    // ---------------------------------------------------------------
    // A target resolving to exactly root_path itself is valid
    // ---------------------------------------------------------------

    #[Test]
    public function the_project_root_itself_is_a_valid_target(): void
    {
        $result = PathContainment::validate($this->projectRoot, '');

        $this->assertTrue($result['valid']);
        $this->assertSame(realpath($this->projectRoot), $result['resolved_path']);
    }

    // ---------------------------------------------------------------
    // A gone project directory is rejected with a DISTINCT reason,
    // checked BEFORE path resolution begins
    // ---------------------------------------------------------------

    #[Test]
    public function a_project_directory_that_no_longer_exists_is_rejected_with_a_distinct_reason(): void
    {
        $gone = sys_get_temp_dir().'/coding_project_gone_'.bin2hex(random_bytes(8));
        // Deliberately never created.

        $result = PathContainment::validate($gone, 'subdir/existing.txt');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('not reachable', $result['reason']);
    }

    // ---------------------------------------------------------------
    // An ordinary existing target inside the project is valid
    // ---------------------------------------------------------------

    #[Test]
    public function an_ordinary_existing_target_inside_the_project_is_valid(): void
    {
        $result = PathContainment::validate($this->projectRoot, 'subdir/existing.txt');

        $this->assertTrue($result['valid']);
        $this->assertSame(realpath($this->projectRoot.'/subdir/existing.txt'), $result['resolved_path']);
    }

    #[Test]
    public function a_target_that_does_not_exist_when_it_must_is_rejected(): void
    {
        $result = PathContainment::validate($this->projectRoot, 'subdir/missing.txt');

        $this->assertFalse($result['valid']);
    }
}
