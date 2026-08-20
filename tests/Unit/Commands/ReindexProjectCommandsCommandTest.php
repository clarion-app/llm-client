<?php

namespace ClarionApp\LlmClient\Tests\Unit\Commands;

use ClarionApp\LlmClient\Models\CodingProject;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 128-project-command-indexing, Phase 2 (Foundational), T006.
 *
 * contracts/reindex-project-commands-command.md, data-model.md §1. Uses
 * persisted CodingProject::create() fixtures pointed at real temporary
 * directories, mirroring tests/Unit/McpPromptRegistryCommandPacksTest.php's
 * own fixture convention (Grounding note 8) -- not
 * CommandPackLoaderTest.php's in-memory-only `new CodingProject([...])`,
 * since this command genuinely queries the database via
 * CodingProject::query()->cursor().
 *
 * Every case here is expected to fail against the current tree: the
 * migration adding operation_search_index.coding_project_id does not exist,
 * the llm-client:reindex-project-commands Artisan command does not exist,
 * and the command's own row-writing logic does not exist. This is the
 * correct "genuinely red" state for this phase.
 */
class ReindexProjectCommandsCommandTest extends TestCase
{
    /** @var list<string> temp directories created by this test, removed in tearDown */
    private array $tmpDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        // tests/TestCase.php's operation_search_index schema is opt-in
        // (defineOperationSearchIndexSchema()'s own doc comment explains
        // why it is not called unconditionally from
        // defineDatabaseMigrations()) -- this test genuinely reads and
        // writes that table via a real Artisan::call(), so it opts in here.
        $this->defineOperationSearchIndexSchema();
    }

    protected function tearDown(): void
    {
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
    // Fixture helpers (mirrors McpPromptRegistryCommandPacksTest.php).
    // -----------------------------------------------------------------

    private function makeProjectDir(): string
    {
        $dir = sys_get_temp_dir().'/reindex_project_commands_'.uniqid('', true);
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

    private function makeProject(?string $rootPath = null, ?string $userId = null): CodingProject
    {
        return CodingProject::create([
            'user_id' => $userId ?? (string) Str::uuid(),
            'name' => 'test project',
            'root_path' => $rootPath ?? $this->makeProjectDir(),
            'test_command' => null,
        ]);
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

    private static function deployTemplate(string $description = 'Deploy the current branch'): string
    {
        return <<<MD
---
description: {$description}
---
Run the deployment pipeline against the current branch and report the result.
MD;
    }

    /**
     * Fixed description, variable body -- lets a freshness-journey test
     * change only the instructions (which feed searchable_text/
     * prompt_content) between reindex runs without also changing the
     * summary, so an assertion that the old body is gone is unambiguous.
     */
    private static function reviewTemplate(string $body): string
    {
        return <<<MD
---
description: Review a pull request for issues.
---
{$body}
MD;
    }

    // -----------------------------------------------------------------
    // 128-project-command-indexing, Phase 5 (US3), T029.
    //
    // Freshness journey (quickstart Scenario 3): an empty workspace, then
    // add / edit / remove a template file -- each change is reflected
    // only on the *next* reindex run, never before it (research.md D3's
    // "no hot-path scan" guarantee, made observable), and never partially
    // (the old body must be genuinely absent after an edit, not merely
    // superseded alongside the new one).
    //
    // Expected red today: T029 has not been implemented against
    // production code yet, but every assertion here is already
    // satisfiable by Foundational's T009 implementation -- these cases
    // extend coverage of already-working behavior with the freshness-
    // specific assertions (the latency check in particular) that no
    // existing test makes. If any of these are unexpectedly green before
    // Phase 5's own production tasks run, that is Foundational already
    // covering this ground and must be recorded as such, not silently
    // treated as "nothing to do here."
    // -----------------------------------------------------------------

    #[Test]
    public function freshness_journey_add_edit_remove_reflects_only_on_the_next_reindex_run(): void
    {
        $projectDir = $this->makeProjectDir();
        $project = $this->makeProject($projectDir);

        // An empty fixture workspace, reindexed -- zero project_command rows.
        $exitCode = Artisan::call('llm-client:reindex-project-commands');
        $this->assertSame(0, $exitCode);
        $this->assertSame(0, DB::table('operation_search_index')->where('type', 'project_command')->count());

        // Write review.md -- but do NOT reindex yet.
        $this->write($projectDir, '.claude/commands/review.md', self::reviewTemplate(
            'Review the current diff for correctness bugs and report findings.'
        ));

        // Latency check, without a second reindex run: the change must
        // not be visible yet -- proving the change is only ever surfaced
        // through the reindex command, never through a hot-path scan.
        $this->assertSame(
            0,
            DB::table('operation_search_index')->where('operation_id', "{$project->id}:review")->count(),
            'writing a template file must not appear in the index until the next reindex run (research.md D3)'
        );

        // Reindex -- review is now a row, findable via direct row
        // presence (not search()'s own MATCH/AGAINST call -- SQLite has
        // no fulltext index in this test harness, Grounding note 14).
        Artisan::call('llm-client:reindex-project-commands');
        $row = DB::table('operation_search_index')->where('operation_id', "{$project->id}:review")->first();
        $this->assertNotNull($row, 'review must be indexed by the reindex run that follows its creation');
        $this->assertSame($project->id, $row->coding_project_id);
        $this->assertStringContainsString('Review the current diff for correctness bugs and report findings.', $row->searchable_text);
        $this->assertStringContainsString('Review the current diff for correctness bugs and report findings.', $row->prompt_content);

        // Edit review.md's body, reindex -- the row's searchable_text/
        // prompt_content reflect the new body; the OLD text is genuinely
        // absent, not merely present alongside the new text.
        $this->write($projectDir, '.claude/commands/review.md', self::reviewTemplate(
            'Check the diff for security vulnerabilities instead.'
        ));
        Artisan::call('llm-client:reindex-project-commands');
        $row = DB::table('operation_search_index')->where('operation_id', "{$project->id}:review")->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('Check the diff for security vulnerabilities instead.', $row->searchable_text);
        $this->assertStringNotContainsString(
            'Review the current diff for correctness bugs and report findings.',
            $row->searchable_text,
            'the old body must be genuinely gone after an edit, not merely superseded alongside the new text'
        );
        $this->assertStringContainsString('Check the diff for security vulnerabilities instead.', $row->prompt_content);
        $this->assertStringNotContainsString(
            'Review the current diff for correctness bugs and report findings.',
            $row->prompt_content,
            'the old body must be genuinely gone from prompt_content after an edit'
        );

        // Delete review.md, reindex -- the row is gone.
        @unlink($projectDir.'/.claude/commands/review.md');
        Artisan::call('llm-client:reindex-project-commands');
        $this->assertSame(
            0,
            DB::table('operation_search_index')->where('operation_id', "{$project->id}:review")->count(),
            'deleting the template file must remove its row on the next reindex run'
        );
    }

    // -----------------------------------------------------------------
    // 128-project-command-indexing, Phase 5 (US3), T029.
    //
    // Workspace removal (quickstart Scenario 6, FR-009/SC-006,
    // research.md D7): a soft-deleted workspace's indexed row disappears
    // on the next reindex run and stays gone on a subsequent run with no
    // further change -- not resurrected.
    // -----------------------------------------------------------------

    #[Test]
    public function workspace_removal_removes_its_row_and_a_second_reindex_does_not_resurrect_it(): void
    {
        $projectDir = $this->makeProjectDir();
        $this->write($projectDir, '.claude/commands/review.md', self::reviewTemplate(
            'Review the current diff for correctness bugs and report findings.'
        ));
        $project = $this->makeProject($projectDir);

        Artisan::call('llm-client:reindex-project-commands');
        $this->assertSame(
            1,
            DB::table('operation_search_index')->where('operation_id', "{$project->id}:review")->count(),
            'the row must be present before the workspace is removed'
        );

        $project->delete();
        $this->assertTrue($project->trashed(), 'delete() on a CodingProject must be a soft delete');

        Artisan::call('llm-client:reindex-project-commands');
        $this->assertSame(
            0,
            DB::table('operation_search_index')->where('operation_id', "{$project->id}:review")->count(),
            'a soft-deleted workspace\'s row must be gone after the next reindex run'
        );

        // A second reindex run with no further change leaves it gone --
        // not resurrected.
        Artisan::call('llm-client:reindex-project-commands');
        $this->assertSame(
            0,
            DB::table('operation_search_index')->where('operation_id', "{$project->id}:review")->count(),
            'a second reindex run must not resurrect a soft-deleted workspace\'s row'
        );
    }

    // -----------------------------------------------------------------
    // Single project, single valid template -- exact row shape.
    // -----------------------------------------------------------------

    #[Test]
    public function a_single_valid_template_is_indexed_with_the_expected_row_shape(): void
    {
        $projectDir = $this->makeProjectDir();
        $this->write($projectDir, '.claude/commands/deploy.md', self::deployTemplate());
        $project = $this->makeProject($projectDir);

        $exitCode = Artisan::call('llm-client:reindex-project-commands');

        $this->assertSame(0, $exitCode);

        $rows = DB::table('operation_search_index')->where('type', 'project_command')->get();
        $this->assertCount(1, $rows, 'exactly one project_command row must be written for one valid template');

        $row = $rows->first();
        $this->assertSame("{$project->id}:deploy", $row->operation_id);
        $this->assertSame($project->id, $row->coding_project_id);
        $this->assertNull($row->package_name, 'a project command has no owning package');
        $this->assertSame('Deploy the current branch', $row->summary);
        $this->assertNull($row->method);
        $this->assertNull($row->path);
        $this->assertNull($row->param_schema);

        // searchable_text must include the name, the description, and the
        // full instructions body -- not just the summary (data-model.md §1,
        // "the full instructions body participates in MATCH/AGAINST
        // ranking, not just the description").
        $this->assertStringContainsString('deploy', $row->searchable_text);
        $this->assertStringContainsString('Deploy the current branch', $row->searchable_text);
        $this->assertStringContainsString('Run the deployment pipeline against the current branch and report the result.', $row->searchable_text);

        $this->assertStringContainsString('Run the deployment pipeline against the current branch and report the result.', $row->prompt_content);
    }

    // -----------------------------------------------------------------
    // Idempotency -- delete-then-repopulate, not duplicate-insert.
    // -----------------------------------------------------------------

    #[Test]
    public function running_the_command_twice_with_no_filesystem_change_leaves_exactly_one_row(): void
    {
        $projectDir = $this->makeProjectDir();
        $this->write($projectDir, '.claude/commands/deploy.md', self::deployTemplate());
        $this->makeProject($projectDir);

        Artisan::call('llm-client:reindex-project-commands');
        Artisan::call('llm-client:reindex-project-commands');

        $count = DB::table('operation_search_index')->where('type', 'project_command')->count();
        $this->assertSame(1, $count, 'a second run with no filesystem change must not duplicate the row (delete-then-repopulate, not duplicate-insert)');
    }

    // -----------------------------------------------------------------
    // Zero recognized template files -- zero rows, not an error.
    // -----------------------------------------------------------------

    #[Test]
    public function a_project_with_zero_recognized_template_files_contributes_zero_rows(): void
    {
        $this->makeProject($this->makeProjectDir());

        $exitCode = Artisan::call('llm-client:reindex-project-commands');

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, DB::table('operation_search_index')->where('type', 'project_command')->count());
    }

    // -----------------------------------------------------------------
    // Two projects, each with a differently-named command -- two rows,
    // each correctly attributed to its own coding_project_id.
    // -----------------------------------------------------------------

    #[Test]
    public function two_projects_each_contribute_their_own_correctly_attributed_row(): void
    {
        $projectADir = $this->makeProjectDir();
        $this->write($projectADir, '.claude/commands/deploy.md', self::deployTemplate());
        $projectA = $this->makeProject($projectADir);

        $projectBDir = $this->makeProjectDir();
        $this->write($projectBDir, '.claude/commands/rollback.md', <<<'MD'
---
description: Roll back the last deployment.
---
Revert the most recent deployment and restore the prior release.
MD);
        $projectB = $this->makeProject($projectBDir);

        $exitCode = Artisan::call('llm-client:reindex-project-commands');

        $this->assertSame(0, $exitCode);

        $rows = DB::table('operation_search_index')->where('type', 'project_command')->get()->keyBy('operation_id');
        $this->assertCount(2, $rows);

        $this->assertTrue($rows->has("{$projectA->id}:deploy"));
        $this->assertSame($projectA->id, $rows->get("{$projectA->id}:deploy")->coding_project_id);

        $this->assertTrue($rows->has("{$projectB->id}:rollback"));
        $this->assertSame($projectB->id, $rows->get("{$projectB->id}:rollback")->coding_project_id);
    }

    // -----------------------------------------------------------------
    // --dry-run: reports counts, writes nothing.
    // -----------------------------------------------------------------

    #[Test]
    public function dry_run_reports_counts_without_writing_any_row(): void
    {
        $projectDir = $this->makeProjectDir();
        $this->write($projectDir, '.claude/commands/deploy.md', self::deployTemplate());
        $this->makeProject($projectDir);

        $exitCode = Artisan::call('llm-client:reindex-project-commands', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('1', $output, 'the dry-run summary must report the counts (workspaces scanned, commands that would be indexed) contract §Behavior step 5 calls for');
        $this->assertSame(0, DB::table('operation_search_index')->where('type', 'project_command')->count(), 'a dry run must never write a row');
    }

    // -----------------------------------------------------------------
    // A pre-seeded 'operation' row is untouched -- the delete-then-
    // repopulate step is scoped to type = 'project_command' only.
    // -----------------------------------------------------------------

    #[Test]
    public function a_preseeded_builtin_operation_row_survives_a_run_byte_for_byte_unchanged(): void
    {
        DB::table('operation_search_index')->insert([
            'operation_id' => 'contacts.store',
            'package_name' => '@clarion-app/contacts',
            'type' => 'operation',
            'summary' => 'Store a new contact',
            'method' => 'POST',
            'path' => '/api/contacts',
            'searchable_text' => 'Store a new contact POST /api/contacts',
            'param_schema' => null,
            'prompt_content' => null,
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $before = (array) DB::table('operation_search_index')->where('operation_id', 'contacts.store')->first();

        $projectDir = $this->makeProjectDir();
        $this->write($projectDir, '.claude/commands/deploy.md', self::deployTemplate());
        $this->makeProject($projectDir);

        $exitCode = Artisan::call('llm-client:reindex-project-commands');

        $this->assertSame(0, $exitCode);

        $after = (array) DB::table('operation_search_index')->where('operation_id', 'contacts.store')->first();
        $this->assertSame($before, $after, "a pre-existing type = 'operation' row, unrelated to any workspace, must survive a reindex run byte-for-byte unchanged");
    }

    // -----------------------------------------------------------------
    // 128-project-command-indexing, Phase 6 (US4), T035.
    //
    // A malformed template in one workspace must never hide that same
    // workspace's valid siblings, nor affect a second, unrelated
    // workspace indexed in the same run (FR-007/FR-008, quickstart
    // Scenario 4).
    // -----------------------------------------------------------------

    private static function brokenFrontmatterTemplate(): string
    {
        return <<<'MD'
---
not: [valid, yaml:
---
body
MD;
    }

    #[Test]
    public function a_malformed_template_contributes_no_row_and_never_hides_its_own_workspace_or_an_unrelated_workspace(): void
    {
        // Workspace A: two valid templates plus one malformed one.
        $projectADir = $this->makeProjectDir();
        $this->write($projectADir, '.claude/commands/review.md', self::reviewTemplate(
            'Review the current diff for correctness bugs and report findings.'
        ));
        $this->write($projectADir, '.claude/commands/deploy.md', self::deployTemplate());
        $this->write($projectADir, '.claude/commands/broken.md', self::brokenFrontmatterTemplate());
        $projectA = $this->makeProject($projectADir);

        // Workspace B: unrelated, one valid template.
        $projectBDir = $this->makeProjectDir();
        $this->write($projectBDir, '.claude/commands/build.md', <<<'MD'
---
description: Build the project.
---
Run the build pipeline and report the result.
MD);
        $projectB = $this->makeProject($projectBDir);

        $exitCode = Artisan::call('llm-client:reindex-project-commands');

        $this->assertSame(0, $exitCode);

        $rows = DB::table('operation_search_index')->where('type', 'project_command')->get()->keyBy('operation_id');

        // A's two valid commands are rows.
        $this->assertTrue($rows->has("{$projectA->id}:review"), 'review.md must be indexed');
        $this->assertSame($projectA->id, $rows->get("{$projectA->id}:review")->coding_project_id);
        $this->assertTrue($rows->has("{$projectA->id}:deploy"), 'deploy.md must be indexed');
        $this->assertSame($projectA->id, $rows->get("{$projectA->id}:deploy")->coding_project_id);

        // broken.md contributes no row of any kind, for this workspace
        // or any other -- there is no derivable name to look up under,
        // so the only correct check is that A's row count is exactly the
        // two valid templates, not three.
        $aRows = $rows->filter(fn ($row) => $row->coding_project_id === $projectA->id);
        $this->assertCount(2, $aRows, 'broken.md must not contribute a third row for workspace A');

        // B's command is a row too, unaffected by A's malformed file.
        $this->assertTrue($rows->has("{$projectB->id}:build"), 'build.md in the unrelated workspace must be indexed');
        $this->assertSame($projectB->id, $rows->get("{$projectB->id}:build")->coding_project_id);

        $this->assertCount(3, $rows, 'exactly the three valid commands across both workspaces must be indexed');
    }

    // -----------------------------------------------------------------
    // 128-project-command-indexing, Phase 6 (US4), T035.
    //
    // Fault-injection proof (research.md D4): a real, uncaught failure
    // inside CommandPackLoader::discover() for one workspace must not
    // abort the whole command or roll back another workspace's already-
    // staged rows in the same transaction.
    //
    // CommandPackLoader and CommandTemplateParser are both `final`
    // (src/Services/CommandPackLoader.php, src/Services/
    // CommandTemplateParser.php) with no extracted interface, so neither
    // can be subclassed or mocked in-process -- confirmed experimentally:
    // `new class extends CommandPackLoader {}` raises an uncatchable PHP
    // fatal error ("cannot extend final class"), which terminates the
    // whole PHPUnit process rather than failing a single test. Binding a
    // literal fake into the container as tasks.md describes is therefore
    // not achievable without first changing production code, which is
    // T036's job, not this task's.
    //
    // This test instead drives the *same* uncaught-exception path
    // through the real, unmodified CommandPackLoader: it chmod(0000)s
    // workspace A's .claude/commands directory so that
    // RecursiveDirectoryIterator's own constructor throws
    // UnexpectedValueException (a RuntimeException subclass) the moment
    // discover() tries to open it -- exactly the "transient filesystem/
    // permission error discover()'s own code doesn't itself guard
    // against" example research.md D4 names as this defense-in-depth
    // wrap's target. This is a genuine fault, not a synthetic one, and
    // requires no change to CommandPackLoader/CommandTemplateParser to
    // exercise.
    // -----------------------------------------------------------------

    #[Test]
    public function an_unanticipated_failure_discovering_one_workspace_does_not_abort_indexing_of_another(): void
    {
        $projectADir = $this->makeProjectDir();
        $commandsDir = $projectADir.'/.claude/commands';
        mkdir($commandsDir, 0777, true);
        // Locked empty -- nothing needs to survive teardown's recursive
        // delete once permissions are restored below.
        chmod($commandsDir, 0000);

        try {
            $projectA = $this->makeProject($projectADir);

            $projectBDir = $this->makeProjectDir();
            $this->write($projectBDir, '.claude/commands/build.md', <<<'MD'
---
description: Build the project.
---
Run the build pipeline and report the result.
MD);
            $projectB = $this->makeProject($projectBDir);

            // Today (pre-T036) this call itself throws
            // UnexpectedValueException -- uncaught anywhere in
            // ReindexProjectCommandsCommand::handle(), it propagates
            // straight through Artisan::call() into this test, which
            // PHPUnit reports as a genuinely red Error for this one
            // test (not a process crash). After T036's per-project
            // try/catch, the exception is caught and logged for
            // workspace A only, and the loop continues to workspace B.
            $exitCode = Artisan::call('llm-client:reindex-project-commands');

            $this->assertSame(0, $exitCode, 'one workspace\'s unexpected discovery failure must not fail the command');

            $rows = DB::table('operation_search_index')->where('type', 'project_command')->get()->keyBy('operation_id');

            $this->assertTrue(
                $rows->has("{$projectB->id}:build"),
                'workspace B must still be indexed even though workspace A raised an uncaught exception ahead of it in the same run'
            );
            $this->assertSame($projectB->id, $rows->get("{$projectB->id}:build")->coding_project_id);

            $this->assertFalse(
                $rows->has("{$projectA->id}:review"),
                'the failing workspace itself contributes no row -- only its own indexing is skipped, not anyone else\'s'
            );
            $this->assertCount(1, $rows, 'only workspace B\'s command is indexed; workspace A\'s failure yields zero rows for A, not an aborted run');
        } finally {
            // Restore permissions unconditionally so tearDown's
            // recursive delete (which does not chmod as it goes) can
            // actually remove the directory, whether this test passed,
            // failed, or errored.
            @chmod($commandsDir, 0777);
        }
    }
}
