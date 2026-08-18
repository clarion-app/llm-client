<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Services\WorkspaceSearchService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for WorkspaceSearchService (120-workspace-file-tools, Phase 3
 * User Story 1, data-model.md §2, research.md D1-D2) against a real
 * temp-directory fixture -- mirrors PathContainmentAdversarialTest's own
 * fixture shape (a real directory tree on disk, real symlinks, recursive
 * teardown) rather than a mock filesystem, since the behavior under test
 * (symlink-skip-at-traversal, streamed reads, vanished-file tolerance) is
 * only meaningfully provable against a real filesystem.
 */
class WorkspaceSearchServiceTest extends TestCase
{
    private string $projectDir;

    private ?string $outsideDir = null;

    private WorkspaceSearchService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = sys_get_temp_dir().'/workspace_search_'.Str::random(12);
        mkdir($this->projectDir, 0777, true);

        $this->service = new WorkspaceSearchService();
    }

    protected function tearDown(): void
    {
        Config::set('llm-client.coding_agent.search.max_results', 100);
        Config::set('llm-client.coding_agent.search.max_files_scanned', 5000);
        Config::set('llm-client.coding_agent.search.max_matches_per_file', 5);
        Config::set('llm-client.coding_agent.file_size_threshold_bytes', 262144);

        $this->removeDirectory($this->projectDir);
        if ($this->outsideDir !== null) {
            $this->removeDirectory($this->outsideDir);
        }

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (is_link($dir)) {
            unlink($dir);

            return;
        }

        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function project(): CodingProject
    {
        return new CodingProject(['root_path' => $this->projectDir]);
    }

    private function write(string $relativePath, string $content): void
    {
        $full = $this->projectDir.'/'.$relativePath;
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, $content);
    }

    // -----------------------------------------------------------------
    // searchFiles()
    // -----------------------------------------------------------------

    #[Test]
    public function search_files_matches_by_basename_even_when_the_pattern_has_no_wildcard(): void
    {
        $this->write('src/Foo.php', '<?php');
        $this->write('docs/Foo.php', '<?php'); // same basename, different directory

        $result = $this->service->searchFiles($this->project(), '', 'Foo.php');
        $paths = array_column($result['matches'], 'path');
        sort($paths);

        $this->assertSame(
            ['docs/Foo.php', 'src/Foo.php'],
            $paths,
            'an exact basename pattern must match regardless of directory, proving basename matching is checked independently of the relative path'
        );
    }

    #[Test]
    public function search_files_matches_by_relative_path_with_a_directory_segment(): void
    {
        $this->write('src/Foo.php', '<?php');
        $this->write('other/Foo.php', '<?php');

        $result = $this->service->searchFiles($this->project(), '', 'src/Foo.php');
        $paths = array_column($result['matches'], 'path');

        $this->assertSame(
            ['src/Foo.php'],
            $paths,
            'a pattern including a directory segment must match against the relative path, excluding a same-basename file in a different directory'
        );
    }

    #[Test]
    public function search_files_never_descends_into_or_returns_a_symlinked_directory_or_file(): void
    {
        $this->outsideDir = sys_get_temp_dir().'/workspace_search_outside_'.Str::random(12);
        mkdir($this->outsideDir, 0777, true);
        file_put_contents($this->outsideDir.'/secret.txt', 'TOP SECRET');

        $this->write('real/inside.txt', 'inside content');
        symlink($this->projectDir.'/real', $this->projectDir.'/link_to_real');
        symlink($this->outsideDir.'/secret.txt', $this->projectDir.'/link_to_secret.txt');

        $this->assertTrue(is_link($this->projectDir.'/link_to_real'), 'fixture sanity');
        $this->assertTrue(is_link($this->projectDir.'/link_to_secret.txt'), 'fixture sanity');

        $result = $this->service->searchFiles($this->project(), '', '*');
        $paths = array_column($result['matches'], 'path');

        $this->assertNotContains('link_to_real', $paths);
        $this->assertNotContains('link_to_secret.txt', $paths);
        foreach ($paths as $path) {
            $this->assertFalse(str_starts_with($path, 'link_to_real/'), 'the symlinked directory must never be descended into');
        }
    }

    #[Test]
    public function search_files_sets_truncated_and_stops_when_max_files_scanned_is_reached(): void
    {
        Config::set('llm-client.coding_agent.search.max_files_scanned', 3);

        for ($i = 0; $i < 10; $i++) {
            $this->write("file{$i}.txt", 'content');
        }

        $result = $this->service->searchFiles($this->project(), '', '*');

        $this->assertTrue($result['truncated']);
        $this->assertSame(3, $result['files_scanned']);
        $this->assertCount(3, $result['matches']);
    }

    #[Test]
    public function search_files_sets_truncated_and_stops_when_max_results_is_reached(): void
    {
        Config::set('llm-client.coding_agent.search.max_results', 3);

        for ($i = 0; $i < 10; $i++) {
            $this->write("file{$i}.txt", 'content');
        }

        $result = $this->service->searchFiles($this->project(), '', '*.txt');

        $this->assertTrue($result['truncated']);
        $this->assertCount(3, $result['matches']);
    }

    #[Test]
    public function search_files_returns_the_documented_shape(): void
    {
        $this->write('a.txt', 'content');

        $result = $this->service->searchFiles($this->project(), '', '*.txt');

        $this->assertArrayHasKey('matches', $result);
        $this->assertArrayHasKey('truncated', $result);
        $this->assertArrayHasKey('files_scanned', $result);
        $this->assertSame('a.txt', $result['matches'][0]['path']);
        $this->assertSame('file', $result['matches'][0]['type']);
    }

    // -----------------------------------------------------------------
    // searchContent()
    // -----------------------------------------------------------------

    #[Test]
    public function search_content_matches_case_insensitively(): void
    {
        $this->write('a.txt', "hello WORLD\nsecond line\n");

        $result = $this->service->searchContent($this->project(), '', 'world');

        $this->assertCount(1, $result['matches']);
        $this->assertSame('a.txt', $result['matches'][0]['path']);
        $this->assertSame(1, $result['matches'][0]['line']);
    }

    #[Test]
    public function search_content_streams_a_large_file_without_loading_it_fully_into_memory(): void
    {
        $filler = str_repeat("filler padding line of ordinary text\n", 90000); // ~3.4MB
        $content = $filler.'MARKER_TARGET_LINE'."\n";
        $this->write('big.txt', $content);
        $fileSize = strlen($content);
        $this->assertGreaterThan(3_000_000, $fileSize, 'fixture sanity: the file must genuinely be large');

        $before = memory_get_peak_usage(true);
        $result = $this->service->searchContent($this->project(), '', 'MARKER_TARGET_LINE');
        $after = memory_get_peak_usage(true);

        $this->assertCount(1, $result['matches']);
        $this->assertSame('big.txt', $result['matches'][0]['path']);
        $this->assertLessThan(
            (int) ($fileSize / 2),
            $after - $before,
            'searchContent() must stream the file line-by-line, not load it fully into memory'
        );
    }

    #[Test]
    public function search_content_skips_binary_files_entirely_and_counts_them(): void
    {
        $this->write('binary.dat', "hello\x00world MATCHME");
        $this->write('text.txt', 'MATCHME in plain text');

        $result = $this->service->searchContent($this->project(), '', 'MATCHME');

        $paths = array_column($result['matches'], 'path');
        $this->assertNotContains('binary.dat', $paths);
        $this->assertContains('text.txt', $paths);
        $this->assertSame(1, $result['skipped_binary_count']);
    }

    #[Test]
    public function search_content_tags_a_match_inside_an_oversized_file_but_still_finds_it(): void
    {
        Config::set('llm-client.coding_agent.file_size_threshold_bytes', 100);

        $this->write('big.txt', str_repeat('padding ', 50).'MATCHME'."\n");
        $this->write('small.txt', 'MATCHME'."\n");

        $result = $this->service->searchContent($this->project(), '', 'MATCHME');

        $byPath = [];
        foreach ($result['matches'] as $match) {
            $byPath[$match['path']] = $match;
        }

        $this->assertTrue($byPath['big.txt']['file_oversized'], 'a match inside a file over the threshold must be tagged file_oversized: true');
        $this->assertFalse($byPath['small.txt']['file_oversized'], 'a match inside a file under the threshold must be tagged file_oversized: false');
    }

    #[Test]
    public function search_content_caps_matches_per_file_without_stopping_the_whole_search(): void
    {
        Config::set('llm-client.coding_agent.search.max_matches_per_file', 3);

        $this->write('many.txt', str_repeat("MATCHME\n", 10));
        $this->write('other.txt', "MATCHME\n");

        $result = $this->service->searchContent($this->project(), '', 'MATCHME');

        $manyCount = count(array_filter($result['matches'], fn ($m) => $m['path'] === 'many.txt'));
        $otherCount = count(array_filter($result['matches'], fn ($m) => $m['path'] === 'other.txt'));

        $this->assertSame(3, $manyCount, 'one file must never contribute more than max_matches_per_file lines');
        $this->assertSame(1, $otherCount, "a capped file must not prevent a later file's own match from being found");
    }

    #[Test]
    public function search_content_matching_nothing_returns_a_clean_empty_result_never_an_error(): void
    {
        $this->write('a.txt', 'nothing relevant here');
        $this->write('b.txt', 'nor here');

        $result = $this->service->searchContent($this->project(), '', 'NO_SUCH_TERM_ANYWHERE');

        $this->assertSame([], $result['matches']);
        $this->assertFalse($result['truncated']);
        $this->assertSame(2, $result['files_scanned']);
    }

    #[Test]
    public function search_content_tolerates_a_file_vanishing_between_traversal_and_content_read(): void
    {
        $this->write('vanish.txt', "MATCHME first\n");
        $this->write('stays.txt', "MATCHME second\n");

        // First call ("traversal yields it"): confirm the file genuinely
        // exists and is visible to the service before it disappears.
        $filesBefore = $this->service->searchFiles($this->project(), '', '*.txt');
        $this->assertContains('vanish.txt', array_column($filesBefore['matches'], 'path'), 'fixture sanity: the file must be seen before it vanishes');

        // Deleted between the two calls -- gone by the time searchContent()
        // would stream its content, exactly like the mid-scan Edge Case
        // (spec.md) describes: no fatal error, best-effort result.
        unlink($this->projectDir.'/vanish.txt');

        $result = $this->service->searchContent($this->project(), '', 'MATCHME');

        $paths = array_column($result['matches'], 'path');
        $this->assertNotContains('vanish.txt', $paths, 'a vanished file must be silently omitted, not raise an error');
        $this->assertContains('stays.txt', $paths, 'the search must continue past the vanished file and still find the remaining match');
    }
}
