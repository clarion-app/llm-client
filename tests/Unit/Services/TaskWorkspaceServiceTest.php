<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\TaskWorkspaceEntry;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\ManagerService;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\TaskWorkspaceService;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 108-shared-task-workspace, Phase 3 (US1), tasks.md T013.
 *
 * Unit tests for `TaskWorkspaceService::recordEntry()` (data-model.md §4):
 * happy-path insert on an in_progress task; empty-content refusal (both
 * for genuinely empty input and for content that -- once run through
 * ContentSanitizer::truncate() -- collapses to the same empty-string
 * check, since recordEntry()'s own guard is `$truncated === ''` applied
 * AFTER truncate(), not a separate pre-check on the raw $content); silent
 * per-entry truncation via a tiny max_entry_bytes override (content is
 * capped, not refused -- a deliberately different outcome from the
 * empty-content case above, contracts/task-workspace-meta-tool.md §1).
 *
 * Does NOT test the status !== 'in_progress' refusal (deferred to Phase
 * 8/US6 per tasks.md's own Ordering rationale).
 */
class TaskWorkspaceServiceTest extends TestCase
{
    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);

        app(RoleAssignmentService::class)->set(ModelRole::Inference, $this->user->id, $this->server->id, 'test-model');
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('task_workspace_entries')->delete();
        DB::table('managed_task_parts')->delete();
        DB::table('managed_tasks')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function seedOperationCatalog(): void
    {
        $doc = ['paths' => []];

        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);

        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->andReturn($doc);
        $this->app->instance(Generator::class, $generator);
    }

    private function clearOperationCatalog(): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function makeAgent(string $name)
    {
        return app(AgentService::class)->create($this->user->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    private function makeManagedTask(): ManagedTask
    {
        $manager = $this->makeAgent('manager-'.uniqid());

        return app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A task with a shared workspace.');
    }

    // =================================================================
    // Happy path
    // =================================================================

    #[Test]
    public function records_an_entry_on_an_in_progress_task_with_every_field_correct(): void
    {
        $task = $this->makeManagedTask();
        $authorAgentId = (string) Str::uuid();

        $entry = app(TaskWorkspaceService::class)->recordEntry($task, $authorAgentId, 'The vendor API requires an auth header now.');

        $this->assertNotNull($entry);
        $this->assertInstanceOf(TaskWorkspaceEntry::class, $entry);
        $this->assertSame($task->id, $entry->managed_task_id);
        $this->assertSame($task->owner_user_id, $entry->owner_user_id);
        $this->assertSame($authorAgentId, $entry->author_agent_id);
        $this->assertSame('The vendor API requires an auth header now.', $entry->content);
        $this->assertNotNull($entry->created_at);

        $this->assertDatabaseHas('task_workspace_entries', [
            'id' => $entry->id,
            'managed_task_id' => $task->id,
            'owner_user_id' => $task->owner_user_id,
            'author_agent_id' => $authorAgentId,
        ]);
    }

    // =================================================================
    // Empty-content refusal
    // =================================================================

    #[Test]
    public function refuses_genuinely_empty_content_and_writes_no_row(): void
    {
        $task = $this->makeManagedTask();

        $entry = app(TaskWorkspaceService::class)->recordEntry($task, (string) Str::uuid(), '');

        $this->assertNull($entry);
        $this->assertSame(0, TaskWorkspaceEntry::where('managed_task_id', $task->id)->count());
    }

    #[Test]
    public function refuses_content_that_collapses_to_empty_after_truncate_regardless_of_the_configured_cap(): void
    {
        $task = $this->makeManagedTask();

        // recordEntry()'s guard is `$truncated === ''` -- applied AFTER
        // ContentSanitizer::truncate(), not a raw-input pre-check. Empty
        // input truncates to itself (unchanged) under any cap, proving
        // the refusal is genuinely evaluated post-truncate rather than
        // merely on the raw argument.
        config(['llm-client.task_workspace.max_entry_bytes' => 4]);

        $entry = app(TaskWorkspaceService::class)->recordEntry($task, (string) Str::uuid(), '');

        $this->assertNull($entry);
        $this->assertSame(0, TaskWorkspaceEntry::where('managed_task_id', $task->id)->count());
    }

    // =================================================================
    // Silent per-entry truncation (distinct from empty-content refusal)
    // =================================================================

    #[Test]
    public function silently_truncates_an_oversized_entry_instead_of_refusing_it(): void
    {
        $task = $this->makeManagedTask();
        config(['llm-client.task_workspace.max_entry_bytes' => 80]);

        $longContent = str_repeat('This finding is quite long. ', 20);
        $this->assertGreaterThan(80, strlen($longContent), 'fixture sanity -- content must exceed the tiny cap');

        $entry = app(TaskWorkspaceService::class)->recordEntry($task, (string) Str::uuid(), $longContent);

        $this->assertNotNull($entry, 'an oversized entry must still be recorded (truncated), not refused');
        $this->assertNotSame($longContent, $entry->content, 'the stored content must actually be truncated');
        $this->assertStringStartsWith('This finding', $entry->content);
        $this->assertSame(1, TaskWorkspaceEntry::where('managed_task_id', $task->id)->count());
    }

    // =================================================================
    // US2 (Phase 4, T023): structural proof of FR-007 -- no update path
    // exists anywhere in TaskWorkspaceService, not merely "none is
    // exercised by the tests above."
    // =================================================================

    #[Test]
    public function record_entry_is_the_only_public_method_that_touches_an_individual_rows_fields(): void
    {
        $publicMethods = array_values(array_filter(
            get_class_methods(TaskWorkspaceService::class),
            fn (string $name) => $name !== '__construct' && strpos($name, '__') !== 0
        ));

        // Phase 4 (US2): only recordEntry() exists. Phase 8 (US6) adds
        // exactly two more -- trimToCap() (count-cap eviction, never
        // touches an individual row's content/author_agent_id/created_at,
        // only whether the row exists at all) and discardForTask() (bulk
        // delete, same "existence only" character). Neither is a
        // *content*-mutating update path, so this assertion is written to
        // stay true across both phases rather than pin an exact count
        // that Phase 8 would immediately break.
        $this->assertContains('recordEntry', $publicMethods);

        $allowedAfterPhase8 = ['recordEntry', 'trimToCap', 'discardForTask'];
        $unexpected = array_diff($publicMethods, $allowedAfterPhase8);
        $this->assertSame(
            [],
            $unexpected,
            'TaskWorkspaceService must expose no public method beyond recordEntry()/trimToCap()/discardForTask() -- '.
            'found: '.implode(', ', $unexpected)
        );

        // The structural core of FR-007's "MUST NOT allow" phrasing:
        // recordEntry() is the sole method whose implementation writes to
        // an individual TaskWorkspaceEntry's content/author_agent_id/
        // created_at fields at all -- confirmed by reflecting over the
        // service's method bodies and asserting none but recordEntry()
        // references TaskWorkspaceEntry::create() or ->save()/->update()
        // on a fetched instance.
        $reflection = new \ReflectionClass(TaskWorkspaceService::class);
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || $method->getName() === 'recordEntry') {
                continue;
            }

            $source = $this->methodSource($method);
            $this->assertStringNotContainsString(
                'TaskWorkspaceEntry::create',
                $source,
                "{$method->getName()}() must not construct a new TaskWorkspaceEntry -- only recordEntry() may."
            );
            $this->assertDoesNotMatchRegularExpression(
                '/->save\s*\(|->update\s*\(/',
                $source,
                "{$method->getName()}() must not call ->save()/->update() on an individual row -- ".
                'only bulk, field-blind operations (count, delete) are permitted outside recordEntry().'
            );
        }
    }

    // =================================================================
    // US4 (Phase 6, T032): FR-009 -- concurrent writes never clobber.
    // recordEntry() is a bare, lock-free TaskWorkspaceEntry::create()
    // (Phase 2) -- every writer gets its own row/PK, so there is no
    // shared mutable state to race over. The guarantee is structural,
    // not timing-dependent, so ordinary sequential PHPUnit execution
    // with no artificial stagger between calls already exercises it
    // (tasks.md's own "no true multi-process test required" call).
    // =================================================================

    #[Test]
    public function five_near_simultaneous_writers_each_survive_intact_with_no_cross_contamination(): void
    {
        $task = $this->makeManagedTask();
        $service = app(TaskWorkspaceService::class);

        $writers = [];
        for ($i = 1; $i <= 5; $i++) {
            $writers[] = [
                'author_agent_id' => (string) Str::uuid(),
                'content' => "Distinct finding number {$i} from writer {$i}: unique-marker-{$i}-".Str::random(8),
            ];
        }

        $entries = [];
        foreach ($writers as $writer) {
            // No artificial stagger between calls -- the guarantee is
            // structural (independent INSERTs), not timing-dependent.
            $entries[] = $service->recordEntry($task, $writer['author_agent_id'], $writer['content']);
        }

        $this->assertSame(5, TaskWorkspaceEntry::where('managed_task_id', $task->id)->count());

        foreach ($entries as $i => $entry) {
            $this->assertNotNull($entry, "writer {$i} must have its own entry recorded");
        }

        // Every entry has its own distinct primary key.
        $entryIds = array_map(fn (TaskWorkspaceEntry $e) => $e->id, $entries);
        $this->assertSame(5, count(array_unique($entryIds)), 'each writer must get its own distinct entry_id');

        // Each entry carries its own writer's correct author_agent_id and
        // its own writer's exact content -- no cross-contamination.
        foreach ($entries as $i => $entry) {
            $this->assertSame($writers[$i]['author_agent_id'], $entry->author_agent_id);
            $this->assertSame($writers[$i]['content'], $entry->content);
        }

        // No entry's content contains a fragment of another entry's
        // content -- pairwise cross-check across all 5 entries.
        foreach ($entries as $i => $entryA) {
            foreach ($entries as $j => $entryB) {
                if ($i === $j) {
                    continue;
                }

                $this->assertStringNotContainsString(
                    $entryB->content,
                    $entryA->content,
                    "entry {$i}'s content must not contain a fragment of entry {$j}'s content"
                );
            }
        }
    }

    // =================================================================
    // US6 (Phase 8, T040): trimToCap() -- oldest-first eviction, uniform
    // regardless of content (never favors one side of a contradictory
    // pair), keeping only the most recently written entries.
    // =================================================================

    #[Test]
    public function trims_to_the_cap_keeping_the_most_recent_entries_and_evicting_a_contradictory_pair_by_age_alone(): void
    {
        config(['llm-client.task_workspace.max_entries' => 5]);

        $task = $this->makeManagedTask();
        $service = app(TaskWorkspaceService::class);

        // Entries 1-2 are a plainly contradictory pair (quickstart scenario
        // 6's own shape) seeded first -- the oldest of the 12 -- so the cap
        // must evict both of them purely because they are old, never
        // because trimToCap() weighed which one "won" the disagreement.
        $contents = [
            1 => 'The API requires auth.',
            2 => 'The API is unauthenticated.',
        ];
        for ($i = 3; $i <= 12; $i++) {
            $contents[$i] = "Entry number {$i}.";
        }

        $entries = [];
        foreach ($contents as $i => $content) {
            $entries[$i] = $service->recordEntry($task, (string) Str::uuid(), $content);
            $this->assertNotNull($entries[$i], "entry {$i} must be recorded");

            $countAfterWrite = TaskWorkspaceEntry::where('managed_task_id', $task->id)->count();
            $this->assertLessThanOrEqual(5, $countAfterWrite, "count must never exceed the cap of 5, checked immediately after writing entry {$i}");

            // Distinct created_at ordering for every entry -- without this,
            // several entries could share one timestamp and the
            // oldest-first eviction/read ordering would be ambiguous.
            usleep(1000);
        }

        $this->assertSame(5, TaskWorkspaceEntry::where('managed_task_id', $task->id)->count(), 'exactly 5 entries survive the cap');

        // Entries 1-7 -- including BOTH sides of the contradictory pair
        // (1 and 2) -- are gone with no way to recover them.
        foreach (range(1, 7) as $i) {
            $this->assertDatabaseMissing('task_workspace_entries', ['id' => $entries[$i]->id]);
        }

        // The 5 remaining are exactly the 5 most recently written
        // (entries 8-12), in their original write order.
        $remaining = TaskWorkspaceEntry::where('managed_task_id', $task->id)->orderBy('created_at')->pluck('content')->all();
        $expected = array_map(fn ($i) => $contents[$i], range(8, 12));
        $this->assertSame($expected, $remaining, 'the 5 remaining entries must be exactly the 5 most recently written (8-12)');
    }

    // =================================================================
    // US6 (Phase 8, T043): a write attempted after conclusion is refused
    // and never orphans a row.
    // =================================================================

    #[Test]
    public function refuses_a_write_against_a_concluded_task_and_leaves_no_orphaned_row(): void
    {
        $task = $this->makeManagedTask();
        $service = app(TaskWorkspaceService::class);

        // Conclude the task via ManagerService::finalize() -- the
        // completion sub-case of T042, the simplest of the three
        // termination paths to set up directly in this file.
        $this->assertNull(app(ManagerService::class)->finalizeRefusal($task, null), 'fixture sanity -- no parts exist, finalize must be admitted');
        app(ManagerService::class)->finalize($task, 'Concluded before this write attempt.', null);
        $task->refresh();
        $this->assertNotSame('in_progress', $task->status, 'fixture sanity -- the task must now be terminal');

        $entry = $service->recordEntry($task, (string) Str::uuid(), 'A write attempted directly against the now-terminal task.');

        $this->assertNull($entry, 'recordEntry() must refuse a write against a concluded task');
        $this->assertSame(0, TaskWorkspaceEntry::where('managed_task_id', $task->id)->count(), 'no row may be written and then orphaned by the discard');
    }

    private function methodSource(\ReflectionMethod $method): string
    {
        $filename = $method->getDeclaringClass()->getFileName();
        $lines = file($filename);
        $start = $method->getStartLine() - 1;
        $length = $method->getEndLine() - $start;

        return implode('', array_slice($lines, $start, $length));
    }
}
