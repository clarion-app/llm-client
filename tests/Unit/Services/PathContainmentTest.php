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

    // ---------------------------------------------------------------
    // A symlink already sitting at a write (not-yet-existing) leaf is
    // refused, regardless of where it points -- realpath() never
    // resolves the leaf itself in this mode, so this is the one shape
    // realpath()-based resolution alone cannot catch.
    // ---------------------------------------------------------------

    #[Test]
    public function a_symlink_already_sitting_at_a_write_leaf_pointing_outside_the_project_is_rejected(): void
    {
        $outside = sys_get_temp_dir().'/coding_project_outside_'.bin2hex(random_bytes(8));
        mkdir($outside);
        file_put_contents($outside.'/secret.txt', 'top secret');

        $leaf = $this->projectRoot.'/write-target.txt';
        symlink($outside.'/secret.txt', $leaf);

        try {
            $result = PathContainment::validate($this->projectRoot, 'write-target.txt', targetMustExist: false);

            $this->assertFalse($result['valid'], 'a symlink already at the write leaf must be refused before any comparison of where it points');
            $this->assertSame('outside the registered project', $result['reason']);
        } finally {
            unlink($leaf);
            $this->removeDirectory($outside);
        }
    }

    #[Test]
    public function a_symlink_already_sitting_at_a_write_leaf_pointing_inside_the_project_is_also_rejected(): void
    {
        $leaf = $this->projectRoot.'/write-target-inside.txt';
        symlink($this->projectRoot.'/subdir/existing.txt', $leaf);

        try {
            $result = PathContainment::validate($this->projectRoot, 'write-target-inside.txt', targetMustExist: false);

            $this->assertFalse($result['valid'], 'the guard fires because the leaf is a link at all, not because of where it happens to point');
            $this->assertSame('outside the registered project', $result['reason']);
        } finally {
            unlink($leaf);
        }
    }

    #[Test]
    public function a_genuinely_new_create_target_is_unaffected_by_the_symlink_leaf_guard(): void
    {
        $result = PathContainment::validate($this->projectRoot, 'brand-new-file.txt', targetMustExist: false);

        $this->assertTrue($result['valid'], 'a genuinely new leaf has nothing sitting at it to be a symlink, so the guard must never fire here');
    }

    // ---------------------------------------------------------------
    // A hard-linked file is rejected in both the target-must-exist and
    // create/overwrite branches -- an ordinary directory must never be
    // misidentified as hard-linked.
    // ---------------------------------------------------------------

    #[Test]
    public function a_hard_linked_file_is_rejected_when_the_target_must_already_exist(): void
    {
        $target = $this->projectRoot.'/hardlink-target.txt';
        file_put_contents($target, 'hard link content');
        $linked = $this->projectRoot.'/hardlink-existing.txt';
        link($target, $linked);

        $result = PathContainment::validate($this->projectRoot, 'hardlink-existing.txt', targetMustExist: true);

        $this->assertFalse($result['valid'], 'a hard-linked file must be refused exactly like a symlink escape');
        $this->assertSame('outside the registered project', $result['reason']);
    }

    #[Test]
    public function a_hard_linked_file_already_sitting_at_a_write_leaf_is_rejected(): void
    {
        $target = $this->projectRoot.'/hardlink-target-2.txt';
        file_put_contents($target, 'hard link content');
        $linked = $this->projectRoot.'/hardlink-write.txt';
        link($target, $linked);

        $result = PathContainment::validate($this->projectRoot, 'hardlink-write.txt', targetMustExist: false);

        $this->assertFalse($result['valid'], 'a hard link already occupying a write leaf must be refused, covering the overwrite-an-existing-hard-link case');
        $this->assertSame('outside the registered project', $result['reason']);
    }

    #[Test]
    public function an_ordinary_directory_with_a_subdirectory_is_not_misidentified_as_hard_linked(): void
    {
        // A directory's own link count is greater than one for entirely
        // ordinary reasons -- the hard-link guard must be scoped to
        // regular files only, never firing here.
        mkdir($this->projectRoot.'/subdir/nested-subdir');

        $result = PathContainment::validate($this->projectRoot, 'subdir', targetMustExist: true);

        $this->assertTrue($result['valid'], 'the hard-link guard must never misfire on an ordinary directory containing a subdirectory');
    }

    // ---------------------------------------------------------------
    // resolved_identity: a {dev, ino} fingerprint of the resolved
    // target, present when it exists, null on a genuine create.
    // ---------------------------------------------------------------

    #[Test]
    public function resolved_identity_matches_a_real_lstat_when_the_target_exists(): void
    {
        $result = PathContainment::validate($this->projectRoot, 'subdir/existing.txt', targetMustExist: true);

        $this->assertTrue($result['valid']);
        $this->assertArrayHasKey('resolved_identity', $result);

        $expected = lstat($this->projectRoot.'/subdir/existing.txt');
        $this->assertSame($expected['dev'], $result['resolved_identity']['dev']);
        $this->assertSame($expected['ino'], $result['resolved_identity']['ino']);
    }

    #[Test]
    public function resolved_identity_is_null_when_the_target_does_not_yet_exist(): void
    {
        $result = PathContainment::validate($this->projectRoot, 'subdir/pure-create.txt', targetMustExist: false);

        $this->assertTrue($result['valid']);
        $this->assertArrayHasKey('resolved_identity', $result);
        $this->assertNull($result['resolved_identity']);
    }
}
