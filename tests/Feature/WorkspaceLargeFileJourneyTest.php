<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CodingProject;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 120-workspace-file-tools, Phase 4 User Story 2, T018
 * (contracts/workspace-search-operations.md §3, data-model.md §3, spec.md
 * Acceptance Scenarios 1-3, Edge Cases, quickstart Scenario 2). Drives
 * readFile() through real HTTP calls on the real, registered
 * CodingWorkspaceController -- mirroring WorkspaceSearchJourneyTest's own
 * real-filesystem fixture shape -- and, for AS3, also drives the
 * search-content route already registered in Phase 3 to prove the two
 * paths agree on the identical size/binary threshold (FR-008).
 */
class WorkspaceLargeFileJourneyTest extends TestCase
{
    private User $user;

    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'api');

        $this->projectDir = sys_get_temp_dir().'/workspace-large-file-journey-'.Str::random(12);
        mkdir($this->projectDir, 0777, true);
    }

    protected function tearDown(): void
    {
        DB::table('coding_projects')->delete();
        DB::table('users')->delete();

        $this->removeDirectory($this->projectDir);

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

    private function registerProject(string $rootPath): CodingProject
    {
        return CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'large file journey project',
            'root_path' => $rootPath,
            'test_command' => null,
        ]);
    }

    private function write(string $relativePath, string $content): void
    {
        $full = $this->projectDir.'/'.$relativePath;
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, $content);
    }

    private function apiUrl(string $suffix): string
    {
        return '/api/clarion-app/llm-client/'.$suffix;
    }

    /**
     * Exactly 2 MiB (2097152 bytes) of 64-byte lines, with one line -- 8
     * lines from the end, comfortably past the default 256 KiB threshold
     * (4096 lines in) -- replaced by $marker (padded/truncated to the same
     * 63-character width so total file size is unaffected).
     */
    private function buildOversizedTextWithMarker(string $marker): string
    {
        $lineWidth = 63;
        $totalLines = (2 * 1024 * 1024) / ($lineWidth + 1);

        $lines = array_fill(0, (int) $totalLines, str_repeat('x', $lineWidth));
        $lines[(int) $totalLines - 8] = str_pad($marker, $lineWidth, '-');

        return implode("\n", $lines)."\n";
    }

    /**
     * A file comfortably over the default 256 KiB threshold containing
     * $termOccurrences occurrences of $term spread across its lines, for
     * the FR-003 several-large-files bounding case.
     */
    private function buildLargeMatchingFile(string $term, int $termOccurrences, int $targetBytes): string
    {
        $lineWidth = 79;
        $fillerLine = str_repeat('y', $lineWidth);
        $termLine = str_pad("MATCH: {$term}", $lineWidth, '-');

        $lines = [];
        $bytes = 0;
        $i = 0;
        $remainingTerms = $termOccurrences;

        while ($bytes < $targetBytes) {
            if ($remainingTerms > 0 && $i % 50 === 0) {
                $lines[] = $termLine;
                $remainingTerms--;
            } else {
                $lines[] = $fillerLine;
            }
            $bytes += $lineWidth + 1;
            $i++;
        }

        return implode("\n", $lines)."\n";
    }

    // -----------------------------------------------------------------
    // AS1/FR-006 -- oversized text file read is truncated and bounded
    // -----------------------------------------------------------------

    #[Test]
    public function as1_reading_an_oversized_text_file_returns_a_bounded_truncated_result(): void
    {
        $marker = 'MARKER_LINE_NEAR_END_XYZ';
        $content = $this->buildOversizedTextWithMarker($marker);
        $this->assertSame(2 * 1024 * 1024, strlen($content), 'fixture must be exactly 2 MiB');

        $this->write('big.txt', $content);
        $project = $this->registerProject($this->projectDir);

        $response = $this->getJson($this->apiUrl("coding-project/{$project->id}/file?path=big.txt"));

        $response->assertStatus(200);
        $threshold = (int) config('llm-client.coding_agent.file_size_threshold_bytes', 262144);

        $this->assertFalse($response->json('binary'));
        $this->assertTrue($response->json('truncated'), 'an oversized text file must come back truncated: true');
        $this->assertSame(2 * 1024 * 1024, $response->json('size'));

        $returnedContent = $response->json('content');
        $this->assertNotNull($returnedContent, 'a truncated text result must still carry a bounded content prefix');
        $this->assertSame($threshold, strlen($returnedContent), 'content must be exactly the first threshold bytes, never more');
        $this->assertStringNotContainsString($marker, $returnedContent, 'the marker sits past the threshold and must never appear in the bounded prefix');
        $this->assertLessThan(strlen($content), strlen($response->getContent()), 'the full 2MB body must never be sent');
    }

    // -----------------------------------------------------------------
    // AS2/FR-007 -- genuine binary file read is reported binary, no content
    // -----------------------------------------------------------------

    #[Test]
    public function as2_reading_a_binary_file_returns_binary_true_with_no_content_key(): void
    {
        $this->write('image.png', random_bytes(1024));
        $project = $this->registerProject($this->projectDir);

        $response = $this->getJson($this->apiUrl("coding-project/{$project->id}/file?path=image.png"));

        $response->assertStatus(200);
        $this->assertTrue($response->json('binary'));
        $this->assertFalse($response->json('truncated'), 'binary:true and truncated:true must never both be present');
        $this->assertSame(1024, $response->json('size'));
        $this->assertArrayNotHasKey('content', $response->json(), 'a binary result must never embed raw content in JSON');
    }

    // -----------------------------------------------------------------
    // AS3/FR-008 -- readFile and searchContent agree on the same threshold
    // -----------------------------------------------------------------

    #[Test]
    public function as3_readfile_and_searchcontent_agree_on_the_same_oversized_threshold(): void
    {
        $marker = 'UNIQUE_MARKER_TERM_FOR_SEARCH';
        $content = $this->buildOversizedTextWithMarker($marker);
        $this->write('big.txt', $content);
        $project = $this->registerProject($this->projectDir);

        $readResponse = $this->getJson($this->apiUrl("coding-project/{$project->id}/file?path=big.txt"));
        $readResponse->assertStatus(200);
        $this->assertTrue($readResponse->json('truncated'), 'the direct read of this file must be truncated');

        $searchResponse = $this->getJson($this->apiUrl("coding-project/{$project->id}/search-content?query={$marker}"));
        $searchResponse->assertStatus(200);
        $matches = $searchResponse->json('matches');
        $this->assertCount(1, $matches);
        $this->assertSame('big.txt', $matches[0]['path']);
        $this->assertTrue($matches[0]['file_oversized'], 'the same file readFile() truncates must be tagged file_oversized by search');
    }

    // -----------------------------------------------------------------
    // Edge Case -- classification follows sniffed content, never extension
    // -----------------------------------------------------------------

    #[Test]
    public function edge_case_classification_follows_actual_content_never_the_extension(): void
    {
        // Binary content behind a text-like extension.
        $this->write('image.txt', random_bytes(1024));
        // Pure ASCII text behind a binary-looking extension.
        $this->write('data.bin', str_repeat("plain ascii text line\n", 50));

        $project = $this->registerProject($this->projectDir);

        $binaryUnderTextExt = $this->getJson($this->apiUrl("coding-project/{$project->id}/file?path=image.txt"));
        $binaryUnderTextExt->assertStatus(200);
        $this->assertTrue($binaryUnderTextExt->json('binary'), 'binary content must be classified binary regardless of a .txt extension');
        $this->assertFalse($binaryUnderTextExt->json('truncated'));

        $textUnderBinaryExt = $this->getJson($this->apiUrl("coding-project/{$project->id}/file?path=data.bin"));
        $textUnderBinaryExt->assertStatus(200);
        $this->assertFalse($textUnderBinaryExt->json('binary'), 'ASCII text must be classified as text regardless of a .bin extension');
        $this->assertFalse($textUnderBinaryExt->json('truncated'));
        $this->assertNotNull($textUnderBinaryExt->json('content'));
    }

    // -----------------------------------------------------------------
    // FR-003 -- search-content stays bounded across several large files
    // -----------------------------------------------------------------

    #[Test]
    public function fr003_search_content_stays_bounded_across_several_large_matching_files(): void
    {
        Config::set('llm-client.coding_agent.search.max_matches_per_file', 3);
        Config::set('llm-client.coding_agent.search.max_results', 5);

        $term = 'BOUND_TERM_XYZ';
        $threshold = (int) config('llm-client.coding_agent.file_size_threshold_bytes', 262144);
        $targetBytes = $threshold + 40000;

        for ($i = 0; $i < 4; $i++) {
            $this->write("large{$i}.txt", $this->buildLargeMatchingFile($term, 10, $targetBytes));
        }

        $project = $this->registerProject($this->projectDir);

        $response = $this->getJson($this->apiUrl("coding-project/{$project->id}/search-content?query={$term}"));

        $response->assertStatus(200);
        $matches = $response->json('matches');

        $this->assertLessThanOrEqual(5, count($matches), 'total matches must never exceed max_results');
        $this->assertTrue($response->json('truncated'), 'hitting max_results across several large matching files must set truncated: true');

        $perFile = [];
        foreach ($matches as $match) {
            $this->assertTrue($match['file_oversized'], 'every match in this fixture belongs to a file over the threshold');
            $perFile[$match['path']] = ($perFile[$match['path']] ?? 0) + 1;
        }
        foreach ($perFile as $path => $count) {
            $this->assertLessThanOrEqual(3, $count, "file {$path} must never contribute more than max_matches_per_file");
        }
    }
}
