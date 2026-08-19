<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Services\CommandChangeDetector;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 124-command-limit-controls, US4, T034 (data-model.md §3, research.md
 * R4). A real temporary directory, no Docker required.
 *
 * snapshot() records (mtime, size) per relative path via a realpath-based
 * traversal that never follows a symlink out of the workspace root
 * (mirroring PathContainment's/WorkspaceSearchService::walk()'s own
 * containment discipline). diff() reports a path present after but not
 * before as created, present before but not after as deleted, present in
 * both with a differing (mtime, size) pair as modified, and present in
 * both with an identical pair as no entry at all -- not even a
 * zero-effect one.
 */
class CommandChangeDetectorTest extends TestCase
{
    private string $root;

    private CommandChangeDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/command-change-detector-'.Str::random(12);
        mkdir($this->root, 0777, true);

        $this->detector = new CommandChangeDetector();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    // -----------------------------------------------------------------
    // snapshot()
    // -----------------------------------------------------------------

    #[Test]
    public function snapshot_records_mtime_and_size_per_relative_path_including_nested_directories(): void
    {
        file_put_contents($this->root.'/top.txt', 'hello');
        mkdir($this->root.'/sub');
        file_put_contents($this->root.'/sub/nested.txt', 'world!!');

        $snapshot = $this->detector->snapshot($this->root);

        $this->assertArrayHasKey('top.txt', $snapshot);
        $this->assertArrayHasKey('sub/nested.txt', $snapshot);
        $this->assertSame(5, $snapshot['top.txt']['size']);
        $this->assertSame(7, $snapshot['sub/nested.txt']['size']);
        $this->assertSame(filemtime($this->root.'/top.txt'), $snapshot['top.txt']['mtime']);
        $this->assertArrayNotHasKey('sub', $snapshot, 'directories themselves are not recorded, only regular files');
    }

    #[Test]
    public function snapshot_never_follows_a_symlink_out_of_the_workspace_root(): void
    {
        $outside = sys_get_temp_dir().'/command-change-detector-outside-'.Str::random(12);
        mkdir($outside, 0777, true);
        file_put_contents($outside.'/secret.txt', 'top secret content that must never be reachable');

        symlink($outside, $this->root.'/escape-link');

        $snapshot = $this->detector->snapshot($this->root);

        $this->assertArrayNotHasKey('escape-link', $snapshot);
        foreach (array_keys($snapshot) as $path) {
            $this->assertStringNotContainsString('secret.txt', $path, 'a symlinked directory must never be traversed into');
        }

        $this->removeDirectory($outside);
    }

    #[Test]
    public function snapshot_skips_a_symlinked_file_directly_inside_the_root(): void
    {
        $outside = sys_get_temp_dir().'/command-change-detector-outside-file-'.Str::random(12);
        mkdir($outside, 0777, true);
        file_put_contents($outside.'/real.txt', 'outside content');

        symlink($outside.'/real.txt', $this->root.'/link.txt');

        $snapshot = $this->detector->snapshot($this->root);

        $this->assertArrayNotHasKey('link.txt', $snapshot, 'a symlinked file must never itself be recorded');

        $this->removeDirectory($outside);
    }

    #[Test]
    public function snapshot_returns_an_empty_array_for_a_root_that_does_not_exist(): void
    {
        $gone = $this->root.'/does-not-exist-'.Str::random(8);

        $this->assertSame([], $this->detector->snapshot($gone));
    }

    #[Test]
    public function snapshot_returns_an_empty_array_for_an_empty_directory(): void
    {
        $this->assertSame([], $this->detector->snapshot($this->root));
    }

    // -----------------------------------------------------------------
    // diff()
    // -----------------------------------------------------------------

    #[Test]
    public function diff_reports_a_path_present_after_but_not_before_as_created(): void
    {
        $before = [];
        $after = ['new.txt' => ['mtime' => 100, 'size' => 5]];

        $result = $this->detector->diff($before, $after);

        $this->assertSame([['path' => 'new.txt', 'operation' => 'created']], $result);
    }

    #[Test]
    public function diff_reports_a_path_present_before_but_not_after_as_deleted(): void
    {
        $before = ['gone.txt' => ['mtime' => 100, 'size' => 5]];
        $after = [];

        $result = $this->detector->diff($before, $after);

        $this->assertSame([['path' => 'gone.txt', 'operation' => 'deleted']], $result);
    }

    #[Test]
    public function diff_reports_a_path_present_in_both_with_a_differing_size_as_modified(): void
    {
        $before = ['file.txt' => ['mtime' => 100, 'size' => 5]];
        $after = ['file.txt' => ['mtime' => 100, 'size' => 9]];

        $result = $this->detector->diff($before, $after);

        $this->assertSame([['path' => 'file.txt', 'operation' => 'modified']], $result);
    }

    #[Test]
    public function diff_reports_a_path_present_in_both_with_a_differing_mtime_only_as_modified(): void
    {
        $before = ['file.txt' => ['mtime' => 100, 'size' => 5]];
        $after = ['file.txt' => ['mtime' => 200, 'size' => 5]];

        $result = $this->detector->diff($before, $after);

        $this->assertSame([['path' => 'file.txt', 'operation' => 'modified']], $result);
    }

    #[Test]
    public function diff_reports_no_entry_at_all_for_a_path_present_in_both_with_an_identical_pair(): void
    {
        $before = ['unchanged.txt' => ['mtime' => 100, 'size' => 5]];
        $after = ['unchanged.txt' => ['mtime' => 100, 'size' => 5]];

        $result = $this->detector->diff($before, $after);

        $this->assertSame([], $result, 'an unchanged file must produce no diff entry at all, not even a zero-effect one');
    }

    #[Test]
    public function diff_against_a_real_untouched_directory_produces_zero_entries(): void
    {
        file_put_contents($this->root.'/stable.txt', 'unchanged content');

        $before = $this->detector->snapshot($this->root);
        $after = $this->detector->snapshot($this->root);

        $this->assertSame($before, $after);
        $this->assertSame([], $this->detector->diff($before, $after));
    }

    #[Test]
    public function diff_handles_a_mixed_batch_of_created_modified_deleted_and_unchanged_paths_independently(): void
    {
        $before = [
            'unchanged.txt' => ['mtime' => 100, 'size' => 5],
            'to-modify.txt' => ['mtime' => 100, 'size' => 5],
            'to-delete.txt' => ['mtime' => 100, 'size' => 5],
        ];
        $after = [
            'unchanged.txt' => ['mtime' => 100, 'size' => 5],
            'to-modify.txt' => ['mtime' => 100, 'size' => 9],
            'to-create.txt' => ['mtime' => 200, 'size' => 3],
        ];

        $result = $this->detector->diff($before, $after);

        $this->assertCount(3, $result, 'exactly the three genuinely-changed paths, never the unchanged one');

        $byPath = [];
        foreach ($result as $change) {
            $byPath[$change['path']] = $change['operation'];
        }

        $this->assertSame('modified', $byPath['to-modify.txt']);
        $this->assertSame('created', $byPath['to-create.txt']);
        $this->assertSame('deleted', $byPath['to-delete.txt']);
        $this->assertArrayNotHasKey('unchanged.txt', $byPath);
    }
}
