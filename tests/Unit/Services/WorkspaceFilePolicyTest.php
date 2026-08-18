<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Services\WorkspaceFilePolicy;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for WorkspaceFilePolicy (120-workspace-file-tools, Phase 2
 * Foundational, data-model.md §3) — the single shared definition of
 * "oversized" and "binary" that both readFile() and search's content path
 * must use, so a file is never treated as fine in one path and oversized
 * in the other.
 *
 * isBinary() is content-only by design: it takes a raw byte sample and
 * nothing else, never a path or filename, so classification can never be
 * extension-based.
 */
class WorkspaceFilePolicyTest extends TestCase
{
    private string $fixtureDir;

    private WorkspaceFilePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureDir = sys_get_temp_dir().'/workspace_file_policy_'.bin2hex(random_bytes(8));
        mkdir($this->fixtureDir);

        $this->policy = new WorkspaceFilePolicy();
    }

    protected function tearDown(): void
    {
        Config::set('llm-client.coding_agent.file_size_threshold_bytes', 262144);

        foreach (glob($this->fixtureDir.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->fixtureDir);

        parent::tearDown();
    }

    #[Test]
    public function is_oversized_is_false_for_a_file_at_the_threshold(): void
    {
        Config::set('llm-client.coding_agent.file_size_threshold_bytes', 1024);

        $path = $this->fixtureDir.'/at_threshold.txt';
        file_put_contents($path, str_repeat('a', 1024));

        $this->assertFalse($this->policy->isOversized($path));
    }

    #[Test]
    public function is_oversized_is_false_for_a_file_under_the_threshold(): void
    {
        Config::set('llm-client.coding_agent.file_size_threshold_bytes', 1024);

        $path = $this->fixtureDir.'/under_threshold.txt';
        file_put_contents($path, str_repeat('a', 512));

        $this->assertFalse($this->policy->isOversized($path));
    }

    #[Test]
    public function is_oversized_is_true_for_a_file_larger_than_the_threshold(): void
    {
        Config::set('llm-client.coding_agent.file_size_threshold_bytes', 1024);

        $path = $this->fixtureDir.'/over_threshold.txt';
        file_put_contents($path, str_repeat('a', 2048));

        $this->assertTrue($this->policy->isOversized($path));
    }

    #[Test]
    public function is_oversized_never_reads_file_content_it_only_stats_the_size(): void
    {
        // A very low threshold so a huge sparse file is immediately flagged
        // oversized. The fixture is created via ftruncate() to its full
        // size without ever writing real bytes to disk (a "sparse" file),
        // so a correct filesize()-only implementation completes instantly
        // regardless of the file's nominal size, while an implementation
        // that instead read the file's content (e.g. file_get_contents())
        // would have to materialize the full byte range and take
        // measurably longer as the size grows.
        Config::set('llm-client.coding_agent.file_size_threshold_bytes', 1024);

        $path = $this->fixtureDir.'/huge_sparse.bin';
        $handle = fopen($path, 'w');
        ftruncate($handle, 200 * 1024 * 1024); // 200 MiB, no bytes actually written
        fclose($handle);

        $start = microtime(true);
        $result = $this->policy->isOversized($path);
        $elapsed = microtime(true) - $start;

        $this->assertTrue($result);
        $this->assertLessThan(1.0, $elapsed, 'isOversized() took too long for a 200 MiB file — it must stat, never read, the file content');
    }

    #[Test]
    public function is_binary_is_true_for_a_sample_containing_a_null_byte(): void
    {
        $sample = "hello\x00world";

        $this->assertTrue($this->policy->isBinary($sample));
    }

    #[Test]
    public function is_binary_is_true_for_a_sample_over_ten_percent_non_printable_with_no_null_byte(): void
    {
        // Distinct from the null-byte case above: no \x00 anywhere in this
        // sample, but well over 10% of its bytes are non-printable control
        // characters (\x01-\x08), which alone must trip isBinary().
        $nonPrintableChunk = str_repeat(chr(1).chr(2).chr(3).chr(4), 10); // 40 non-printable bytes
        $printablePadding = str_repeat('a', 200); // 200 printable bytes
        $sample = $nonPrintableChunk.$printablePadding; // 40 / 240 ≈ 16.7% non-printable

        $this->assertStringNotContainsString("\x00", $sample);
        $this->assertTrue($this->policy->isBinary($sample));
    }

    #[Test]
    public function is_binary_is_false_for_ordinary_printable_text(): void
    {
        $sample = "The quick brown fox jumps over the lazy dog.\nSecond line here.\n";

        $this->assertFalse($this->policy->isBinary($sample));
    }

    #[Test]
    public function is_binary_is_false_for_ordinary_utf8_text(): void
    {
        $sample = "Café résumé — naïve façade\nsecond ligne\n";

        $this->assertFalse($this->policy->isBinary($sample));
    }

    #[Test]
    public function is_binary_takes_only_a_content_sample_no_path_or_filename_argument(): void
    {
        $reflection = new \ReflectionMethod(WorkspaceFilePolicy::class, 'isBinary');

        $this->assertCount(
            1,
            $reflection->getParameters(),
            'isBinary() must take only a raw content sample — classification must be content-only, never extension-based'
        );
    }
}
