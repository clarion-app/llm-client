<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\ValueObjects\AgentChangeSource;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
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
    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function service(): AgentService
    {
        return new AgentService(new AgentDefinitionParser());
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
}
