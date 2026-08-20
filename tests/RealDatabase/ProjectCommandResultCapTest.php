<?php

namespace Tests\RealDatabase;

use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Services\OperationsSearchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * 128-project-command-indexing, Phase 7 (US5), T042.
 *
 * Proves the crowding bound (FR-011/SC-005, contracts/operations-search-service.md
 * postcondition 3, research.md D6, quickstart Scenario 5) holds under genuine
 * MATCH/AGAINST ranking, not merely a row-count argument against a mocked
 * connection (tests/Unit/Services/OperationsSearchServiceScopingTest.php's own
 * T040 coverage owns the mocked-shape half of this guarantee).
 *
 * Seeds one workspace with 30 project-command rows via direct
 * DB::table(...)->insert() rather than a real reindex run against 30 real
 * fixture files: the reindex command's own row-writing path is already
 * proven correct by tests/RealDatabase/ProjectCommandSearchScopingTest.php
 * (T017) and tests/Unit/Commands/ReindexProjectCommandsCommandTest.php
 * (T006), and this test's own focus is search()'s cap/merge behavior
 * against a realistically large row set -- not the reindex command's
 * row-writing again -- so a direct insert of the row shape
 * ReindexProjectCommandsCommand itself would write is the faster, equally
 * valid choice the task's own instructions call out.
 *
 * A large number of filler rows unrelated to the query term are also
 * seeded. MySQL/MariaDB's NATURAL LANGUAGE MODE full-text search (the mode
 * OperationsSearchService::search() hard-codes) treats a word as an
 * effective stopword -- excluded from matching entirely -- once it is
 * present in roughly half or more of the rows in the table. Without this
 * padding, all 31 of a bare 31-row table (30 project-command rows + 1
 * built-in row, 100%) would contain "widget", which would make this
 * test's own query return nothing to assert on, for a reason entirely
 * unrelated to the crowding bound actually under test.
 */
#[Group('real-db')]
class ProjectCommandResultCapTest extends RealDatabaseTestCase
{
    protected array $seedTables = ['operation_search_index', 'coding_projects'];

    private function makeProject(): CodingProject
    {
        return CodingProject::create([
            'user_id' => (string) Str::uuid(),
            'name' => 'real-db result-cap project',
            'root_path' => sys_get_temp_dir().'/project_command_result_cap_realdb_'.uniqid('', true),
            'test_command' => null,
        ]);
    }

    /**
     * Mirrors the row shape ReindexProjectCommandsCommand itself writes
     * (data-model.md §1) for a type = 'project_command' row: package_name
     * null, method/path null, prompt_content populated. Each row's body
     * loosely (not overwhelmingly) mentions "widget" once, so the 30 rows
     * are realistically similar to each other in relevance strength --
     * exactly the scenario in which an uncapped query would let sheer
     * volume, not distinctiveness, decide which rows make the top N.
     */
    private function insertProjectCommandRow(string $codingProjectId, int $n): void
    {
        DB::table('operation_search_index')->insert([
            'operation_id' => "{$codingProjectId}:widget-command-{$n}",
            'package_name' => null,
            'type' => 'project_command',
            'coding_project_id' => $codingProjectId,
            'summary' => "Project command number {$n}",
            'method' => null,
            'path' => null,
            'searchable_text' => "Project command number {$n}. This command loosely relates to a widget.",
            'param_schema' => null,
            'prompt_content' => "Run project command number {$n} for the widget subsystem.",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * A built-in, package-owned, global (coding_project_id null) row whose
     * summary clearly and strongly matches "widget" -- the row that must
     * never be crowded out purely by the workspace's own command volume
     * (AS1, FR-011, SC-005).
     */
    private function insertBuiltinOperationRow(): void
    {
        DB::table('operation_search_index')->insert([
            'operation_id' => 'widgets.manage',
            'package_name' => '@clarion-app/widgets',
            'type' => 'operation',
            'coding_project_id' => null,
            'summary' => 'Manage the widget inventory: create, update, and list every widget',
            'method' => 'POST',
            'path' => '/api/widgets',
            'searchable_text' => 'Manage the widget inventory: create, update, and list every widget. '
                .'Widget widget widget widget widget.',
            'param_schema' => null,
            'prompt_content' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Filler rows sharing no vocabulary with "widget" or with each other's
     * distinguishing terms, so that "widget" never approaches the
     * ~50%-of-table natural-language stopword threshold.
     */
    private function insertFillerRows(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('operation_search_index')->insert([
                'operation_id' => "filler.unrelated_operation_{$i}",
                'package_name' => '@clarion-app/filler',
                'type' => 'operation',
                'coding_project_id' => null,
                'summary' => "Unrelated filler capability number {$i}",
                'method' => 'GET',
                'path' => "/api/filler/{$i}",
                'searchable_text' => "Unrelated filler capability number {$i} concerning gadgets and sprockets.",
                'param_schema' => null,
                'prompt_content' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    #[Test]
    public function a_workspace_full_of_project_commands_never_crowds_out_a_clearly_matching_builtin(): void
    {
        $this->assertReady();

        $project = $this->makeProject();

        for ($n = 1; $n <= 30; $n++) {
            $this->insertProjectCommandRow($project->id, $n);
        }
        $this->insertBuiltinOperationRow();
        $this->insertFillerRows(50);

        $totalRows = DB::table('operation_search_index')->count();
        $this->assertSame(81, $totalRows, 'sanity check on the seeded row count before searching');

        $service = new OperationsSearchService();
        $results = $service->search('widget', $project->id, limit: 10);

        $projectResults = array_values(array_filter(
            $results,
            fn ($row) => $row->type === 'project_command'
        ));

        $cap = (int) config('llm-client.operations_search.project_command_result_cap', 5);

        $this->assertLessThanOrEqual(
            $cap,
            count($projectResults),
            "the workspace's project_command rows must never exceed the configured cap ({$cap}) in a single "
            .'result set, regardless of how many exist for the workspace or how well they match the query. '
            .'Full result set: '
            .json_encode(array_map(fn ($r) => ['operationId' => $r->operationId, 'type' => $r->type], $results))
        );

        $builtinResults = array_values(array_filter(
            $results,
            fn ($row) => $row->operationId === 'widgets.manage'
        ));

        $this->assertNotEmpty(
            $builtinResults,
            'the clearly-matching built-in must still appear in the result set, not displaced purely by the '
            ."workspace's project-command volume. Full result set: "
            .json_encode(array_map(fn ($r) => ['operationId' => $r->operationId, 'type' => $r->type], $results))
        );
    }
}
