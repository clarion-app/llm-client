<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;
use ClarionApp\LlmClient\Exceptions\AgentNameAlreadyInUseException;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\GitDefinitionFileReader;
use ClarionApp\LlmClient\ValueObjects\AgentChangeSource;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionResolutionErrorKind;
use ClarionApp\LlmClient\ValueObjects\MemoryKind;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Unit tests for AgentService::create()/update() (Phase 3/US1, contracts
 * §12, research.md D1/D4/D7) — the sole write path for `agents`/
 * `agent_versions`, mirroring EvalCaseService::addCase()/editCase()'s own
 * DB::transaction() + MAX(version_number) + 1 pattern verbatim
 * (EvalCaseServiceTest is this file's own direct structural precedent).
 *
 * restore()/link()/syncFromFile()/unlink() are User Story 2/3's own scope
 * (Phase 4/5) and are not covered here.
 */
class AgentServiceTest extends TestCase
{
    /** @var string[] */
    private array $tempRepoPaths = [];

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_memory_entries')->delete();
        DB::table('users')->delete();

        foreach ($this->tempRepoPaths as $path) {
            $this->removeDirectory($path);
        }
        $this->tempRepoPaths = [];

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function service(): AgentService
    {
        // GitDefinitionFileReader is only exercised by link()/syncFromFile()
        // (Phase 5/US3's own scope, not covered by this file) — a plain
        // instance is enough to satisfy AgentService's constructor here.
        return new AgentService(new AgentDefinitionParser(), new GitDefinitionFileReader());
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    private function validYaml(string $name = 'weather-agent'): string
    {
        return "name: {$name}";
    }

    /**
     * Names a capability no ReducibleTool case recognizes — rejected by
     * AgentDefinitionParser::parse() before it ever reaches catalog
     * resolution (086's own step 6, ahead of steps 8-10), so no operation
     * catalog needs to be seeded for this fixture to fail.
     */
    private function unresolvableYaml(string $name = 'broken-agent'): string
    {
        return <<<YAML
name: {$name}
capabilities: [web_browsing]
YAML;
    }

    /**
     * Seeds both of ApiManager's live-catalog seams (the
     * AgentDefinitionMinimalJourneyTest/AgentDefinitionUnknownNameJourneyTest
     * precedent) — required before any *valid* parse() call, since parse()
     * unconditionally resolves the operation catalog once per call.
     */
    private function seedOperationCatalog(array $operations = []): void
    {
        $paths = [];
        foreach ($operations as $operationId => $entry) {
            $paths[$entry['path']][$entry['method']] = [
                'operationId' => $operationId,
                'summary' => $entry['summary'],
            ];
        }
        $doc = ['paths' => $paths];

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

    /**
     * A real, throwaway git repository under a tmp directory — the same
     * fixture convention GitDefinitionFileReaderTest.php establishes (091,
     * tasks.md T011's own corrected guidance): never a mock of
     * GitDefinitionFileReader.
     */
    private function createGitRepo(): string
    {
        $repoPath = sys_get_temp_dir().'/agent_service_clone_test_'.uniqid('', true);
        mkdir($repoPath, 0777, true);
        $this->tempRepoPaths[] = $repoPath;

        $this->runGit(['init'], $repoPath);
        $this->runGit(['config', 'user.name', 'Test Author'], $repoPath);
        $this->runGit(['config', 'user.email', 'test-author@example.test'], $repoPath);
        $this->runGit(['config', 'commit.gpgsign', 'false'], $repoPath);

        return $repoPath;
    }

    private function runGit(array $args, string $cwd): void
    {
        (new Process(array_merge(['git'], $args), $cwd))->mustRun();
    }

    private function writeFile(string $repoPath, string $relPath, string $content): void
    {
        file_put_contents($repoPath.'/'.$relPath, $content);
    }

    private function commitAll(string $repoPath, string $message): void
    {
        $this->runGit(['add', '.'], $repoPath);
        $this->runGit(['commit', '-m', $message], $repoPath);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (new \FilesystemIterator($path) as $item) {
            if ($item->isDir()) {
                $this->removeDirectory($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }

    // ---------------------------------------------------------------
    // create() — the happy path (FR-001/SC-001)
    // ---------------------------------------------------------------

    #[Test]
    public function create_inserts_exactly_one_agent_and_one_version_one_row_inside_one_transaction(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();
        $raw = $this->validYaml('weather-agent');

        $agent = $this->service()->create($user->id, $raw);

        $this->assertInstanceOf(Agent::class, $agent);
        $this->assertSame($user->id, $agent->user_id);
        $this->assertSame('weather-agent', $agent->name);
        $this->assertSame(1, DB::table('agents')->count());
        $this->assertSame(1, DB::table('agent_versions')->count());

        $version = AgentVersion::find($agent->current_version_id);
        $this->assertNotNull($version, 'agent.current_version_id must point at the just-created version');
        $this->assertSame(1, $version->version_number);
        $this->assertSame(AgentChangeSource::Created->value, $version->source);
        $this->assertSame($user->id, $version->changed_by_user_id);
        $this->assertSame($raw, $version->raw_definition, 'raw_definition must be byte-for-byte what was submitted');
        $this->assertSame(hash('sha256', $raw), $version->content_hash);
    }

    // ---------------------------------------------------------------
    // create() — invalid content, no partial write (contracts §1's 422 path)
    // ---------------------------------------------------------------

    #[Test]
    public function create_with_unresolvable_content_throws_and_writes_no_row_at_all(): void
    {
        // collect() (088-agent-definition-validator) always evaluates every
        // one of the 11 steps, including the operation-catalog-dependent
        // tools/safety steps, regardless of an earlier step's own outcome
        // (FR-001) -- so the catalog is now reached even for a document
        // whose only problem is unresolvableYaml()'s unrelated capability,
        // unlike 086's fail-fast parse() which never got this far for this
        // document.
        $this->seedOperationCatalog();

        $user = $this->user();

        try {
            $this->service()->create($user->id, $this->unresolvableYaml());
            $this->fail('Expected AgentDefinitionResolutionException for an unrecognized capability.');
        } catch (AgentDefinitionResolutionException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertSame(0, DB::table('agents')->count(), 'A rejected create() must write no agent row');
        $this->assertSame(0, DB::table('agent_versions')->count(), 'A rejected create() must write no version row');
    }

    // ---------------------------------------------------------------
    // update() — the happy path (FR-002/SC-002)
    // ---------------------------------------------------------------

    #[Test]
    public function update_inserts_exactly_one_new_version_row_and_repoints_current_version_id(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();
        $agent = $this->service()->create($user->id, $this->validYaml('original-name'));
        $v1Id = $agent->current_version_id;

        $updated = $this->service()->update($agent, $user->id, $this->validYaml('updated-name'));

        $this->assertSame(1, DB::table('agents')->count(), 'update() must not create a new agent row');
        $this->assertSame(2, DB::table('agent_versions')->count(), 'update() must insert exactly one new version row');

        $this->assertNotSame($v1Id, $updated->current_version_id, 'current_version_id must repoint to the new version');
        $this->assertSame('updated-name', $updated->name);
        $this->assertSame(2, $updated->currentVersion->version_number);
        $this->assertSame(AgentChangeSource::ProductEdit->value, $updated->currentVersion->source);
        $this->assertSame($user->id, $updated->currentVersion->changed_by_user_id);
    }

    /**
     * Mutation-checklist row 1 — the single most important property this
     * whole feature makes structurally true: the previous version's row is
     * never mutated in place when a new one is written.
     */
    #[Test]
    public function updating_an_agent_leaves_the_previous_version_byte_identical_before_and_after(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();
        $raw = $this->validYaml('original-name');
        $agent = $this->service()->create($user->id, $raw);
        $v1Id = $agent->current_version_id;
        $v1Before = AgentVersion::find($v1Id)->toArray();

        $this->service()->update($agent, $user->id, $this->validYaml('updated-name'));

        $v1After = AgentVersion::find($v1Id);

        $this->assertNotNull($v1After, 'The previous version row must still exist, untouched');
        $v1After = $v1After->toArray();

        $this->assertSame($v1Before['raw_definition'], $v1After['raw_definition']);
        $this->assertSame($v1Before['content_hash'], $v1After['content_hash']);
        $this->assertSame($v1Before['version_number'], $v1After['version_number']);
        $this->assertSame($v1Before['source'], $v1After['source']);
        $this->assertSame($v1Before['changed_by_user_id'], $v1After['changed_by_user_id']);
        $this->assertSame($v1Before['created_at'], $v1After['created_at']);
        $this->assertSame($v1Before['updated_at'], $v1After['updated_at']);
    }

    // ---------------------------------------------------------------
    // update() — invalid content, no partial write
    // ---------------------------------------------------------------

    #[Test]
    public function update_with_unresolvable_content_throws_and_leaves_current_version_id_unchanged(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();
        $agent = $this->service()->create($user->id, $this->validYaml('original-name'));
        $currentVersionIdBefore = $agent->current_version_id;
        $versionCountBefore = DB::table('agent_versions')->where('agent_id', $agent->id)->count();

        try {
            $this->service()->update($agent, $user->id, $this->unresolvableYaml());
            $this->fail('Expected AgentDefinitionResolutionException for an unrecognized capability.');
        } catch (AgentDefinitionResolutionException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertSame(
            $versionCountBefore,
            DB::table('agent_versions')->where('agent_id', $agent->id)->count(),
            'A rejected update() must write no new version row',
        );
        $this->assertSame(
            $currentVersionIdBefore,
            $agent->fresh()->current_version_id,
            'A rejected update() must leave the previous version as the current one',
        );
    }

    // ---------------------------------------------------------------
    // version_number is MAX(version_number) + 1, never COUNT(*)
    // (gap-tolerance regression test)
    // ---------------------------------------------------------------

    #[Test]
    public function version_number_sequencing_is_derived_from_the_maximum_not_a_row_count(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();
        $agent = $this->service()->create($user->id, $this->validYaml('original-name'));

        // Simulate a gap in the version sequence: two rows exist
        // (COUNT = 2) but the highest version_number is 5, not 2. If
        // update() ever derived the next number from COUNT(*) instead of
        // MAX(version_number), the two formulas would silently diverge
        // here — COUNT(*) + 1 = 3, but MAX(version_number) + 1 = 6 — and
        // the wrong one would either collide with the unique
        // (agent_id, version_number) constraint or renumber a version that
        // already exists.
        AgentVersion::create([
            'agent_id' => $agent->id,
            'version_number' => 5,
            'raw_definition' => 'name: out-of-order',
            'content_hash' => hash('sha256', 'name: out-of-order'),
            'source' => AgentChangeSource::ProductEdit->value,
            'changed_by_user_id' => $user->id,
        ]);

        $this->assertSame(2, DB::table('agent_versions')->where('agent_id', $agent->id)->count());

        $updated = $this->service()->update($agent->fresh(), $user->id, $this->validYaml('updated-name'));

        $this->assertSame(
            6,
            $updated->currentVersion->version_number,
            'The next version_number must be MAX(version_number) + 1 (6), not COUNT(*) + 1 (3)',
        );
    }

    // ---------------------------------------------------------------
    // restore() — the happy path (FR-006/FR-007, contracts §12/§7,
    // research.md D7)
    // ---------------------------------------------------------------

    #[Test]
    public function restore_inserts_one_new_version_byte_identical_to_the_target_and_leaves_every_existing_version_unchanged(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();
        $agent = $this->service()->create($user->id, $this->validYaml('v1-name'));
        $target = AgentVersion::find($agent->current_version_id);
        $agent = $this->service()->update($agent, $user->id, $this->validYaml('v2-name'));
        $v2Id = $agent->current_version_id;
        $v2Before = AgentVersion::find($v2Id)->toArray();
        $targetBefore = $target->fresh()->toArray();

        $restored = $this->service()->restore($agent, $user->id, $target);

        $this->assertSame(1, DB::table('agents')->count(), 'restore() must not create a new agent row');
        $this->assertSame(3, DB::table('agent_versions')->where('agent_id', $agent->id)->count(), 'restore() must insert exactly one new version row');

        $newVersion = $restored->currentVersion;
        $this->assertSame(3, $newVersion->version_number, 'version_number must be previous max + 1');
        $this->assertSame(AgentChangeSource::Restoration->value, $newVersion->source);
        $this->assertSame($user->id, $newVersion->changed_by_user_id);
        $this->assertSame($target->id, $newVersion->restored_from_version_id);
        $this->assertSame($target->raw_definition, $newVersion->raw_definition, 'the new version must be byte-identical to the target');
        $this->assertSame($target->content_hash, $newVersion->content_hash);
        $this->assertNotSame($target->id, $newVersion->id, 'restore() must never repoint at the existing target row itself');

        // Every existing version, including the target, must be
        // byte-for-byte unchanged.
        $targetAfter = $target->fresh()->toArray();
        $v2After = AgentVersion::find($v2Id)->toArray();
        $this->assertSame($targetBefore, $targetAfter, 'the restored-from target version must be untouched');
        $this->assertSame($v2Before, $v2After, 'the version in between must be untouched');
    }

    #[Test]
    public function restore_against_an_unresolvable_target_throws_and_leaves_current_version_id_unchanged(): void
    {
        $this->seedOperationCatalog();
        $server = \ClarionApp\LlmClient\Models\Server::forceCreate(['id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Primary']);
        $model = \ClarionApp\LlmClient\Models\LanguageModel::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'restore-model', 'server_id' => $server->id]);

        $user = $this->user();
        $agent = $this->service()->create($user->id, "name: v1-name\nmodel: restore-model");
        $target = AgentVersion::find($agent->current_version_id);
        $agent = $this->service()->update($agent, $user->id, $this->validYaml('v2-name'));
        $currentVersionIdBefore = $agent->current_version_id;

        // The target's named model no longer exists on this installation.
        $model->delete();

        try {
            $this->service()->restore($agent, $user->id, $target);
            $this->fail('Expected AgentDefinitionResolutionException for a since-deleted model.');
        } catch (AgentDefinitionResolutionException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertSame(
            $currentVersionIdBefore,
            $agent->fresh()->current_version_id,
            'A rejected restore() must leave current_version_id unchanged — no broken version is ever made current',
        );
        $this->assertSame(
            2,
            DB::table('agent_versions')->where('agent_id', $agent->id)->count(),
            'A rejected restore() must write no new version row',
        );
    }

    /**
     * Mutation-checklist row 2 — restoring to the agent's own current
     * version still produces a new version (never a no-op); gap-tolerance
     * regression: after that new version's row is deleted directly at the
     * DB level, writing a further version via update() must still be
     * derived from MAX(version_number) over whatever rows currently
     * survive, never from COUNT(*) (verbatim the formula
     * EvalCaseService::editCase() already established, and this file's own
     * version_number_sequencing_is_derived_from_the_maximum_not_a_row_count
     * test above).
     *
     * Correction (found while implementing Phase 4/US2, T036): this test
     * originally asserted the post-delete update() call produces
     * version_number 3, on the theory that reusing 2 would be a collision.
     * That assertion was arithmetically impossible for any implementation
     * following the documented MAX(version_number)+1 rule (research.md D1,
     * data-model.md §2): once version 2's row is genuinely gone, the only
     * surviving row for this agent is version 1, so MAX(version_number)+1
     * is unambiguously 2 — there is no live row with number 2 for a new
     * row to collide with, and nothing in this feature's own write paths
     * ever hard-deletes an agent_versions row in the first place (this
     * scenario is a deliberately adversarial simulation, not a real
     * lifecycle event). The corrected assertion below still exercises the
     * property that matters: the next number is derived from the current
     * MAX over whatever rows survive right now, not from a stale count
     * that would still include the deleted row.
     */
    #[Test]
    public function version_numbering_after_a_restore_and_a_deleted_gap_never_collides_with_a_surviving_row(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();
        $agent = $this->service()->create($user->id, $this->validYaml('v1-name'));
        $v1 = AgentVersion::find($agent->current_version_id);

        // Restore to the agent's own current version — v1 -> v2.
        $agent = $this->service()->restore($agent, $user->id, $v1);
        $this->assertSame(2, $agent->currentVersion->version_number);
        $v2Id = $agent->current_version_id;

        // Simulate a gap: delete version 2's row directly at the DB level
        // (a hard delete, not the soft-delete Eloquent's delete() would
        // perform, so the row is genuinely gone rather than merely
        // excluded by the SoftDeletes global scope).
        DB::table('agent_versions')->where('id', $v2Id)->delete();
        $this->assertSame(1, DB::table('agent_versions')->where('agent_id', $agent->id)->count());

        $agent = $this->service()->update($agent->fresh(), $user->id, $this->validYaml('v3-name'));

        $this->assertSame(
            2,
            $agent->currentVersion->version_number,
            'the next version_number must be MAX(version_number) + 1 (2) over the surviving rows — the deleted version 2 row no longer exists to collide with',
        );
    }

    // ---------------------------------------------------------------
    // clone() — US1 (AC1-AC4, FR-002/FR-003/FR-004/FR-005/FR-006,
    // contracts §1's internal service surface, research.md D1/D2/D2a)
    // ---------------------------------------------------------------

    #[Test]
    public function clone_carries_instructions_permitted_operations_and_settings_under_a_new_name(): void
    {
        $this->seedOperationCatalog(['contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact']]);
        $user = $this->user();
        $source = $this->service()->create(
            $user->id,
            "name: weather-agent\ninstructions: Always be polite.\ntools:\n  allow: [contacts.*]",
        );

        $clone = $this->service()->clone($source, $user->id, 'weather-agent-copy');

        $this->assertInstanceOf(Agent::class, $clone);
        $this->assertNotSame($source->id, $clone->id);
        $this->assertSame('weather-agent-copy', $clone->name, 'the clone must carry the new name, not the source\'s');
        $this->assertSame($user->id, $clone->user_id);

        $definition = (new AgentDefinitionParser())->parse($clone->currentVersion->raw_definition);
        $this->assertSame('Always be polite.', $definition->instructions, 'instructions must match the source at the moment of copying');
        $this->assertSame(['contacts.*'], $definition->toolsAllow, 'permitted operations must match the source at the moment of copying');
    }

    #[Test]
    public function clones_own_version_history_starts_fresh_never_referencing_the_sources_chain(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();
        $source = $this->service()->create($user->id, $this->validYaml('weather-agent'));
        $source = $this->service()->update($source, $user->id, $this->validYaml('weather-agent'));
        $source = $this->service()->update($source, $user->id, $this->validYaml('weather-agent'));
        $this->assertSame(3, DB::table('agent_versions')->where('agent_id', $source->id)->count(), 'fixture sanity: the source must have 3 versions before cloning');

        $clone = $this->service()->clone($source, $user->id, 'weather-agent-copy');

        $this->assertSame(
            1,
            DB::table('agent_versions')->where('agent_id', $clone->id)->count(),
            'the clone must start with exactly one version, never inheriting the source\'s history',
        );

        $version = AgentVersion::find($clone->current_version_id);
        $this->assertNotNull($version);
        $this->assertSame(1, $version->version_number);
        $this->assertSame(AgentChangeSource::Created->value, $version->source);
        $this->assertNull($version->restored_from_version_id, 'a clone\'s first version must never reference any of the source\'s version ids');
    }

    #[Test]
    public function editing_the_original_after_cloning_never_affects_the_clone(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();
        $source = $this->service()->create($user->id, "name: weather-agent\ninstructions: Original instructions.");
        $clone = $this->service()->clone($source, $user->id, 'weather-agent-copy');

        $this->service()->update($source->fresh(), $user->id, "name: weather-agent\ninstructions: Completely different now.");

        $cloneReread = Agent::find($clone->id);
        $definition = (new AgentDefinitionParser())->parse($cloneReread->currentVersion->raw_definition);
        $this->assertSame('Original instructions.', $definition->instructions, 'editing the source after cloning must never affect the already-made clone');
    }

    #[Test]
    public function editing_the_clone_after_cloning_never_affects_the_original(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();
        $source = $this->service()->create($user->id, "name: weather-agent\ninstructions: Original instructions.");
        $clone = $this->service()->clone($source, $user->id, 'weather-agent-copy');

        $this->service()->update($clone->fresh(), $user->id, "name: weather-agent-copy\ninstructions: Completely different now.");

        $sourceReread = Agent::find($source->id);
        $definition = (new AgentDefinitionParser())->parse($sourceReread->currentVersion->raw_definition);
        $this->assertSame('Original instructions.', $definition->instructions, 'editing the clone must never affect the original it was cloned from');
    }

    #[Test]
    public function a_clone_is_never_linked_to_the_sources_git_file_even_when_the_source_is_linked(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();
        $repoPath = $this->createGitRepo();
        $this->writeFile($repoPath, 'agent.yaml', "name: linked-agent\n");
        $this->commitAll($repoPath, 'Initial commit');

        $source = $this->service()->create($user->id, $this->validYaml('linked-agent'));
        $source = $this->service()->link($source, $user->id, $repoPath, 'agent.yaml');
        $this->assertNotNull($source->linked_repository_path, 'fixture sanity: the source must actually be linked before cloning');

        $clone = $this->service()->clone($source, $user->id, 'linked-agent-copy');

        $this->assertNull($clone->linked_repository_path, 'a clone must never inherit the source\'s link');
        $this->assertNull($clone->linked_file_path);
        $this->assertNull($clone->linked_synced_file_hash);
    }

    // ---------------------------------------------------------------
    // clone() — US3 (AC1-AC4, FR-010/FR-011/FR-012/FR-013/FR-014,
    // contracts §1's internal service surface, research.md D3/D5/D6/D9/D10)
    // ---------------------------------------------------------------

    #[Test]
    public function cloning_a_retired_soft_deleted_source_produces_a_fully_active_non_trashed_clone(): void
    {
        $this->seedOperationCatalog(['contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact']]);
        $user = $this->user();
        $source = $this->service()->create(
            $user->id,
            "name: weather-agent\ninstructions: Last known state.\ntools:\n  allow: [contacts.*]",
        );
        $source->delete();
        $this->assertNotNull($source->fresh()->deleted_at, 'fixture sanity: the source must actually be soft-deleted before cloning');

        $clone = $this->service()->clone(Agent::withTrashed()->find($source->id), $user->id, 'copy-of-retired');

        $this->assertNull($clone->deleted_at, 'a clone of a retired agent must itself be fully active, not trashed');
        $definition = (new AgentDefinitionParser())->parse($clone->currentVersion->raw_definition);
        $this->assertSame('Last known state.', $definition->instructions, 'the clone must match the retired source\'s last state before retirement');
        $this->assertSame(['contacts.*'], $definition->toolsAllow);
    }

    #[Test]
    public function cloning_under_a_name_that_collides_with_the_destination_owners_own_agent_throws_and_writes_no_row(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();
        $agentA = $this->service()->create($user->id, $this->validYaml('agent-a'));
        $this->service()->create($user->id, $this->validYaml('agent-b'));

        $agentsBefore = DB::table('agents')->count();
        $versionsBefore = DB::table('agent_versions')->count();

        try {
            $this->service()->clone($agentA, $user->id, 'agent-b');
            $this->fail('Expected AgentNameAlreadyInUseException for a colliding name.');
        } catch (AgentNameAlreadyInUseException $e) {
            $this->assertSame('agent-b', $e->name);
        }

        $this->assertSame($agentsBefore, DB::table('agents')->count(), 'a rejected clone() must write no agent row');
        $this->assertSame($versionsBefore, DB::table('agent_versions')->count(), 'a rejected clone() must write no version row');
    }

    #[Test]
    public function cloning_under_a_name_only_used_by_a_different_users_own_agent_succeeds_the_collision_check_is_per_owner_not_global(): void
    {
        // Added during the T025 mutation-testing pass (091-agent-clone-fork,
        // quickstart.md row 10): dropping the user_id scope from clone()'s
        // name-collision check went undetected by every other named test in
        // this file, since none of them exercises two distinct users' own
        // agents sharing a name. This test closes that gap directly.
        $this->seedOperationCatalog();
        $userA = $this->user();
        $userB = $this->user();
        $this->service()->create($userA->id, $this->validYaml('shared-name'));
        $sourceForB = $this->service()->create($userB->id, $this->validYaml('agent-owned-by-b'));

        $clone = $this->service()->clone($sourceForB, $userB->id, 'shared-name');

        $this->assertSame('shared-name', $clone->name, 'a name already used by a *different* user\'s own agent must never collide for this user');
        $this->assertSame($userB->id, $clone->user_id);
    }

    #[Test]
    public function a_name_freed_by_retiring_an_agent_is_immediately_reusable_by_a_clone(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();
        $agentA = $this->service()->create($user->id, $this->validYaml('agent-a'));
        $agentA->delete();
        $other = $this->service()->create($user->id, $this->validYaml('agent-b'));

        $clone = $this->service()->clone($other, $user->id, 'agent-a');

        $this->assertSame('agent-a', $clone->name, 'a name freed by soft-deleting an agent must be immediately reusable by a clone');
    }

    #[Test]
    public function clones_memory_kind_settings_are_plain_carried_settings_never_touching_any_memoryentry_row(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();
        $source = $this->service()->create($user->id, "name: weather-agent\nmemory:\n  scratch: disabled");

        DB::table('llm_memory_entries')->insert([
            'id' => (string) Str::uuid(),
            'scope' => 'long_term',
            'agent_id' => $source->id,
            'user_id' => $user->id,
            'key' => 'some-key',
            'content' => 'some remembered content',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $memoryCountBefore = DB::table('llm_memory_entries')->count();

        $clone = $this->service()->clone($source, $user->id, 'weather-agent-copy');

        $this->assertSame(
            $memoryCountBefore,
            DB::table('llm_memory_entries')->count(),
            'clone() must never write (or read from) any MemoryEntry row',
        );
        $definition = (new AgentDefinitionParser())->parse($clone->currentVersion->raw_definition);
        $this->assertFalse($definition->memoryEnabled(MemoryKind::Scratch), 'a clone\'s memory-kind settings are plain carried settings, matching the source exactly');
    }

    #[Test]
    public function the_sources_own_current_definition_failing_to_re_resolve_is_refused_cleanly_with_no_partial_write(): void
    {
        $this->seedOperationCatalog();
        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Primary']);
        $model = LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'clone-model', 'server_id' => $server->id]);

        $user = $this->user();
        $source = $this->service()->create($user->id, "name: weather-agent\nmodel: clone-model");

        // The source's own current definition no longer resolves against
        // live installation state — the model it names has since been
        // deleted. clone() re-validates the rewritten document via
        // AgentDefinitionParser::parse() (not a string-replace-only copy),
        // so this must be caught here too, exactly as restore()'s own
        // analogous test above expects.
        $model->delete();

        $agentsBefore = DB::table('agents')->count();
        $versionsBefore = DB::table('agent_versions')->count();

        try {
            $this->service()->clone($source, $user->id, 'weather-agent-copy');
            $this->fail('Expected AgentDefinitionResolutionException for a since-deleted model.');
        } catch (AgentDefinitionResolutionException $e) {
            $this->assertSame(AgentDefinitionResolutionErrorKind::UnknownModel, $e->kind);
        }

        $this->assertSame($agentsBefore, DB::table('agents')->count(), 'a rejected clone() must write no agent row');
        $this->assertSame($versionsBefore, DB::table('agent_versions')->count(), 'a rejected clone() must write no version row');
    }
}
