<?php

namespace Tests\RealDatabase;

use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Services\OperationsSearchService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * 128-project-command-indexing, Phase 3 (US1), T017.
 *
 * Seeds the real operation_search_index table by running the actual
 * llm-client:reindex-project-commands Artisan command against a real,
 * temp-dir-backed CodingProject fixture -- mirroring
 * tests/RealDatabase/OperationDiscoverySearchTest.php's own "index
 * population is under test alongside querying" convention -- plus one
 * directly-inserted built-in row also matching the same query text
 * (mirroring tests/RealDatabase/Support/OperationIndexFixture.php's own
 * row shape). (new OperationsSearchService())->search('deploy the branch',
 * $project->id) is then run against the real MySQL/MariaDB connection, to
 * prove the scoping predicate composes correctly with a genuine
 * MATCH/AGAINST-ranked query -- not merely the mocked-shape assertions
 * tests/Unit/Services/OperationsSearchServiceScopingTest.php (T005) and
 * OperationsSearchServiceProjectCommandLabelTest.php (T013) already make.
 *
 * This exercises the exact same code path Phase 2 (Foundational) already
 * implemented (OperationsSearchService::search()'s $codingProjectId
 * scoping predicate, ReindexProjectCommandsCommand's row-writing), so
 * unlike T013/T014 this file's own expected state is reported honestly
 * rather than forced red (per this phase's own task instructions) -- see
 * the Progress Log / handoff notes for the actual observed result.
 */
#[Group('real-db')]
class ProjectCommandSearchScopingTest extends RealDatabaseTestCase
{
    protected array $seedTables = ['operation_search_index', 'coding_projects'];

    /** @var list<string> temp directories created by this test, removed in tearDown */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            if (is_dir($dir)) {
                $this->removeDirectory($dir);
            }
        }

        parent::tearDown();
    }

    private function makeProjectDir(): string
    {
        $dir = sys_get_temp_dir().'/project_command_scoping_realdb_'.uniqid('', true);
        mkdir($dir, 0777, true);
        $this->tmpDirs[] = $dir;

        return $dir;
    }

    private function write(string $projectDir, string $relativePath, string $content): void
    {
        $full = $projectDir.'/'.$relativePath;
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, $content);
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
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function makeProject(string $rootPath): CodingProject
    {
        return CodingProject::create([
            'user_id' => (string) Str::uuid(),
            'name' => 'real-db scoping project',
            'root_path' => $rootPath,
            'test_command' => null,
        ]);
    }

    /**
     * Direct insert mirroring OperationIndexFixture's own row shape -- a
     * built-in, package-owned 'operation' row, global (coding_project_id
     * left null), whose summary also clearly matches the same query text
     * the project command's own content matches.
     */
    private function insertBuiltinOperationRow(): void
    {
        DB::table('operation_search_index')->insert([
            'operation_id' => 'releases.publish',
            'package_name' => '@clarion-app/ops',
            'type' => 'operation',
            'coding_project_id' => null,
            'summary' => 'Publish a release and deploy the branch to production',
            'method' => 'POST',
            'path' => '/api/releases/publish',
            'searchable_text' => 'Publish a release and deploy the branch to production',
            'param_schema' => null,
            'prompt_content' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_scoped_search_returns_the_workspaces_own_command_among_genuinely_ranked_results(): void
    {
        $this->assertReady();

        $projectDir = $this->makeProjectDir();
        $this->write($projectDir, '.claude/commands/deploy.md', <<<'MD'
---
description: Deploy the current branch
---
Run the deployment pipeline for the current branch and report the outcome.
MD);
        $project = $this->makeProject($projectDir);

        $exitCode = Artisan::call('llm-client:reindex-project-commands');
        $this->assertSame(
            0,
            $exitCode,
            'llm-client:reindex-project-commands must exit successfully: '.Artisan::output()
        );

        $this->insertBuiltinOperationRow();

        // Population is genuinely under test too: the reindex command must
        // have actually written a project_command row for this workspace,
        // not merely appeared to via a mocked path.
        $indexedRow = DB::table('operation_search_index')
            ->where('type', 'project_command')
            ->where('coding_project_id', $project->id)
            ->first();

        $this->assertNotNull(
            $indexedRow,
            'the reindex command must write a real project_command row for the workspace, none found'
        );
        $this->assertSame("{$project->id}:deploy", $indexedRow->operation_id);

        $service = new OperationsSearchService();
        $results = $service->search('deploy the branch', $project->id);

        $projectResults = array_values(array_filter(
            $results,
            fn ($row) => $row->type === 'project_command'
        ));

        $this->assertNotEmpty(
            $projectResults,
            'the scoped search must return the workspace\'s own project_command row among genuinely '
            .'MATCH/AGAINST-ranked results, none found. Full result set: '
            .json_encode(array_map(fn ($r) => ['operationId' => $r->operationId, 'type' => $r->type], $results))
        );
        $this->assertSame("{$project->id}:deploy", $projectResults[0]->operationId);
        $this->assertSame('Deploy the current branch', $projectResults[0]->summary);

        // The matching built-in is not crowded out -- both are genuinely
        // ranked and present in the same result set (FR-010's
        // non-colliding case, proven against a real engine).
        $builtinResults = array_values(array_filter(
            $results,
            fn ($row) => $row->operationId === 'releases.publish'
        ));
        $this->assertNotEmpty(
            $builtinResults,
            'the matching built-in operation must also appear in the same scoped search: '
            .json_encode(array_map(fn ($r) => ['operationId' => $r->operationId, 'type' => $r->type], $results))
        );
    }
}
