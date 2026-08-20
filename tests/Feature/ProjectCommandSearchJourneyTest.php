<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\OperationsSearchService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 128-project-command-indexing, Phase 3 (US1), T014.
 *
 * Drives AgentLoopService::executeMetaTool('search_operations', ...)
 * directly, mirroring tests/Feature/AgentLoopServiceRunCommandEnvelopeTest.
 * php's own direct-call convention -- no live model, no mocked LLM turn.
 * A real, temp-dir-backed CodingProject with one valid
 * .claude/commands/deploy.md template is indexed through the real
 * llm-client:reindex-project-commands Artisan command against the real
 * operation_search_index table (defineOperationSearchIndexSchema(), plain
 * INSERT writes -- no MySQL-only SQL involved), so index *population* is
 * under test alongside querying, exactly as tests/RealDatabase/
 * OperationDiscoverySearchTest.php's own convention does for the real-db
 * suite.
 *
 * The read side binds a mocked OperationsSearchService whose search()
 * still queries the real, just-populated table -- via a plain `LIKE`
 * predicate instead of MySQL-only `MATCH ... AGAINST` -- exactly the same
 * accommodation tests/Feature/ExternalToolSearchResultBoundJourneyTest.php
 * already documents and uses ("this package's own test harness, tests/
 * TestCase.php, does not create that table [for a real query] at all...
 * OperationsSearchService::tableExists() would otherwise genuinely return
 * false here"; grounding note 14 in tasks.md: `MATCH`/`AGAINST` has no
 * SQLite equivalent, which is exactly why the ranking-dependent guarantee
 * is proven separately, for real, in tests/RealDatabase/
 * ProjectCommandSearchScopingTest.php, T017). This file's own job is the
 * *wiring* AgentLoopService::handleSearchOperations() must do -- passing
 * $conversation->coding_project_id through and labeling each row by its
 * own type -- not fulltext ranking.
 *
 * Every case here is expected to fail against the current tree:
 * AgentLoopService::handleSearchOperations() still calls
 * $searchService->search($query) with no $codingProjectId argument, and its
 * result-formatting loop has no 'project_command' branch and no 'source'
 * label field on any branch (Grounding note 4) -- this is the correct
 * "genuinely red" state for Phase 3 (T013/T014 precede T015's
 * implementation).
 */
class ProjectCommandSearchJourneyTest extends TestCase
{
    private User $user;

    /** @var list<string> temp directories created by this test, removed in tearDown */
    private array $tmpDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        // tests/TestCase.php's operation_search_index schema is opt-in
        // (defineOperationSearchIndexSchema()'s own doc comment) -- this
        // journey genuinely reads and writes that table via a real
        // Artisan::call() plus a direct built-in-row insert, so it opts in
        // here, exactly as tests/Unit/Commands/
        // ReindexProjectCommandsCommandTest.php already does.
        $this->defineOperationSearchIndexSchema();

        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        foreach ($this->tmpDirs as $dir) {
            if (is_dir($dir)) {
                $this->removeDirectory($dir);
            }
        }

        if (DB::getSchemaBuilder()->hasTable('operation_search_index')) {
            DB::table('operation_search_index')->delete();
        }
        if (DB::getSchemaBuilder()->hasTable('coding_projects')) {
            DB::table('coding_projects')->delete();
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixture helpers (mirrors ReindexProjectCommandsCommandTest.php /
    // CommandPackDiscoveryJourneyTest.php).
    // -----------------------------------------------------------------

    private function makeProjectDir(): string
    {
        $dir = sys_get_temp_dir().'/project_command_search_journey_'.uniqid('', true);
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
            'user_id' => $this->user->id,
            'name' => 'search journey project',
            'root_path' => $rootPath,
            'test_command' => null,
        ]);
    }

    /**
     * Direct insert mirroring OperationIndexFixture's own row shape
     * (tests/RealDatabase/Support/OperationIndexFixture.php) -- a
     * built-in, package-owned 'operation' row, global (coding_project_id
     * left null).
     */
    private function insertBuiltinOperationRow(string $operationId, string $summary): void
    {
        DB::table('operation_search_index')->insert([
            'operation_id' => $operationId,
            'package_name' => '@clarion-app/ops',
            'type' => 'operation',
            'coding_project_id' => null,
            'summary' => $summary,
            'method' => 'POST',
            'path' => '/api/'.$operationId,
            'searchable_text' => $summary,
            'param_schema' => null,
            'prompt_content' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Binds a mocked OperationsSearchService whose search() still reads
     * the real, already-reindexed operation_search_index table -- via a
     * plain `LIKE '%deploy%'` predicate rather than MySQL-only
     * `MATCH ... AGAINST` -- so this fast-suite (SQLite) journey proves
     * real indexing plus AgentLoopService's own labeling/wiring, without
     * depending on MySQL-only fulltext SQL (that ranking-dependent
     * guarantee belongs to, and is proven for real by, tests/RealDatabase/
     * ProjectCommandSearchScopingTest.php, T017).
     */
    private function bindPlainTextSearch(): void
    {
        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $searchServiceMock->shouldReceive('tableExists')->andReturn(true);
        $searchServiceMock->shouldReceive('search')->andReturnUsing(
            function (string $query, ?string $codingProjectId = null, ?int $limit = null) {
                $term = strtolower(explode(' ', $query)[0] ?? $query);

                return DB::table('operation_search_index')
                    ->select(
                        'operation_id as operationId',
                        'package_name',
                        'type',
                        'summary',
                        'method',
                        'path',
                        'param_schema as paramSchema',
                        'prompt_content as promptContent'
                    )
                    ->where(function ($q) use ($term) {
                        $q->where('searchable_text', 'like', "%{$term}%")
                            ->orWhere('summary', 'like', "%{$term}%");
                    })
                    ->orderBy('operation_id')
                    ->get()
                    ->toArray();
            }
        );

        app()->instance(OperationsSearchService::class, $searchServiceMock);
    }

    private function decodeResults(string $raw): array
    {
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'search_operations response must decode as JSON');
        $this->assertArrayHasKey('results', $decoded, 'search_operations response must carry a results array: '.$raw);

        return $decoded['results'];
    }

    private function callSearchOperations(Conversation $conversation, string $query): string
    {
        return app(AgentLoopService::class)->executeMetaTool(
            'search_operations',
            ['query' => $query],
            $conversation,
        );
    }

    // -----------------------------------------------------------------
    // AS1/AS2 -- a project's own command is findable by content, labeled
    // as project-sourced, with its description visible with no further
    // lookup.
    // -----------------------------------------------------------------

    #[Test]
    public function a_workspaces_own_command_is_findable_by_content_and_labeled_as_project_sourced(): void
    {
        $projectDir = $this->makeProjectDir();
        $this->write($projectDir, '.claude/commands/deploy.md', <<<'MD'
---
description: Deploy the current branch
---
Run the deployment pipeline for the current branch and report the outcome.
MD);
        $project = $this->makeProject($projectDir);

        Artisan::call('llm-client:reindex-project-commands');

        $this->bindPlainTextSearch();

        $conversation = Conversation::create([
            'user_id' => $this->user->id,
            'title' => 'project command search journey',
            'coding_project_id' => $project->id,
        ]);

        $raw = $this->callSearchOperations($conversation, 'deploy the branch');
        $results = $this->decodeResults($raw);

        $projectEntries = array_values(array_filter(
            $results,
            fn ($r) => ($r['type'] ?? null) === 'project_command'
        ));

        $this->assertNotEmpty($projectEntries, 'the project-defined deploy command must appear in results: '.$raw);

        $entry = $projectEntries[0];

        // A distinct label/type value distinguishable from a built-in's.
        $this->assertSame('project_command', $entry['type']);
        $this->assertArrayHasKey('source', $entry, 'a project-command entry must carry an explicit source label');
        $this->assertNotSame('Built-in capability', $entry['source']);

        // Content/description matches the template, visible without any
        // further lookup or invocation call.
        $this->assertSame('Deploy the current branch', $entry['summary']);
        $this->assertStringContainsString('Run the deployment pipeline', $entry['content']);
    }

    // -----------------------------------------------------------------
    // AS3 -- a differently-matching built-in appears alongside the
    // project command, each independently and correctly labeled.
    // -----------------------------------------------------------------

    #[Test]
    public function a_matching_built_in_and_the_project_command_both_appear_each_correctly_labeled(): void
    {
        $projectDir = $this->makeProjectDir();
        $this->write($projectDir, '.claude/commands/deploy.md', <<<'MD'
---
description: Deploy the current branch
---
Run the deployment pipeline for the current branch and report the outcome.
MD);
        $project = $this->makeProject($projectDir);

        Artisan::call('llm-client:reindex-project-commands');

        // A built-in row whose summary also clearly matches the query, but
        // under an unrelated operation_id -- the non-colliding case.
        $this->insertBuiltinOperationRow('releases.publish', 'Publish a release and deploy the branch to production');

        $this->bindPlainTextSearch();

        $conversation = Conversation::create([
            'user_id' => $this->user->id,
            'title' => 'project command search journey',
            'coding_project_id' => $project->id,
        ]);

        $raw = $this->callSearchOperations($conversation, 'deploy the branch');
        $results = $this->decodeResults($raw);

        $projectEntries = array_values(array_filter($results, fn ($r) => ($r['type'] ?? null) === 'project_command'));
        $builtinEntries = array_values(array_filter($results, fn ($r) => ($r['operationId'] ?? $r['id'] ?? null) === 'releases.publish'));

        $this->assertNotEmpty($projectEntries, 'the project-defined deploy command must appear: '.$raw);
        $this->assertNotEmpty($builtinEntries, 'the matching built-in operation must appear: '.$raw);

        $this->assertSame('project_command', $projectEntries[0]['type']);
        $this->assertSame('operation', $builtinEntries[0]['type']);
        $this->assertNotSame($projectEntries[0]['source'] ?? null, $builtinEntries[0]['source'] ?? null);
    }

    // -----------------------------------------------------------------
    // Named colliding-name edge case -- a project command and a built-in
    // sharing the exact same short name both survive, independently
    // labeled, neither dropped/overwritten/merged.
    // -----------------------------------------------------------------

    #[Test]
    public function a_project_command_and_a_same_named_built_in_both_survive_independently_labeled(): void
    {
        $projectDir = $this->makeProjectDir();
        $this->write($projectDir, '.claude/commands/deploy.md', <<<'MD'
---
description: Deploy the current branch
---
Run the deployment pipeline for the current branch and report the outcome.
MD);
        $project = $this->makeProject($projectDir);

        Artisan::call('llm-client:reindex-project-commands');

        // A built-in row whose summary matches, AND whose short name is
        // literally "deploy" -- the exact same name as the project's own
        // command (spec.md's own named Edge Case).
        $this->insertBuiltinOperationRow('deploy', 'Deploy the application to production infrastructure');

        $this->bindPlainTextSearch();

        $conversation = Conversation::create([
            'user_id' => $this->user->id,
            'title' => 'project command search journey',
            'coding_project_id' => $project->id,
        ]);

        $raw = $this->callSearchOperations($conversation, 'deploy the branch');
        $results = $this->decodeResults($raw);

        $projectEntries = array_values(array_filter($results, fn ($r) => ($r['type'] ?? null) === 'project_command'));
        $builtinEntries = array_values(array_filter(
            $results,
            fn ($r) => ($r['type'] ?? null) !== 'project_command' && ($r['operationId'] ?? $r['id'] ?? null) === 'deploy'
        ));

        // Neither row is dropped, overwritten, or merged despite the
        // colliding short name (research.md D5 -- two distinct rows, no
        // winner/loser computed).
        $this->assertCount(1, $projectEntries, 'the project-defined "deploy" command must survive the name collision: '.$raw);
        $this->assertCount(1, $builtinEntries, 'the built-in "deploy" operation must survive the name collision: '.$raw);

        $this->assertSame('project_command', $projectEntries[0]['type']);
        $this->assertNotSame($projectEntries[0]['source'] ?? null, $builtinEntries[0]['source'] ?? null);

        // Each still resolves back to its own distinct underlying identity
        // -- the project entry's operationId decomposes to this project's
        // id plus "deploy", never bare "deploy" (data-model.md §2).
        $this->assertSame("{$project->id}:deploy", $projectEntries[0]['operationId'] ?? $projectEntries[0]['id'] ?? null);
    }
}
