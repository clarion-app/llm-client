<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentVersionResolver;
use ClarionApp\LlmClient\ValueObjects\AgentChangeSource;
use ClarionApp\LlmClient\ValueObjects\AgentDefinition;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for AgentVersionResolver::currentDefinitionFor() (Phase 6/
 * Polish, contracts §12, research.md D6) — the forward-looking hot-path
 * entry point roadmap item 4.2.1 is expected to consume. Not wired into any
 * conversation-start path by this feature (research.md D12); verified here
 * in isolation.
 *
 * Covers quickstart.md step 15 / mutation-checklist row 9: resolution must
 * cost exactly one query against agents/agent_versions combined, regardless
 * of how many prior versions an agent has accumulated — an
 * eager-loaded-relation PK/FK lookup via current_version_id, never a
 * MAX(version_number)-style scan over agent_versions.agent_id.
 */
class AgentVersionResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::disableQueryLog();
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function resolver(): AgentVersionResolver
    {
        return new AgentVersionResolver(new AgentDefinitionParser());
    }

    /**
     * Seeds both of ApiManager's live-catalog seams — required before any
     * *valid* AgentDefinitionParser::parse() call, since parse()
     * unconditionally resolves the operation catalog once per call
     * (AgentServiceTest's own established convention).
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
     * Seeds an agent with $count accumulated versions (bulk-inserted
     * directly, bypassing AgentService, so seeding itself contributes no
     * queries to the query log captured later) and points
     * current_version_id at the final one — the definition it holds names
     * no `model:`, so AgentDefinitionParser::parse() performs no
     * LanguageModel::exists() query of its own, keeping the resolver's own
     * query count isolated from 086's own, separately-documented model
     * lookup.
     */
    private function seedAgentWithAccumulatedVersions(string $userId, int $count): Agent
    {
        $agent = Agent::create([
            'user_id' => $userId,
            'name' => 'seed-name',
            'current_version_id' => null,
        ]);

        $now = now();
        $rows = [];
        $currentVersionId = null;

        for ($n = 1; $n <= $count; $n++) {
            $id = (string) \Illuminate\Support\Str::uuid();
            $raw = "name: version-{$n}-agent";

            $rows[] = [
                'id' => $id,
                'agent_id' => $agent->id,
                'version_number' => $n,
                'raw_definition' => $raw,
                'content_hash' => hash('sha256', $raw),
                'source' => AgentChangeSource::ProductEdit->value,
                'changed_by_user_id' => $userId,
                'restored_from_version_id' => null,
                'git_commit_hash' => null,
                'git_author_name' => null,
                'git_committed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ];

            $currentVersionId = $id;
        }

        DB::table('agent_versions')->insert($rows);

        $agent->current_version_id = $currentVersionId;
        $agent->name = "version-{$count}-agent";
        $agent->save();

        return $agent;
    }

    // ---------------------------------------------------------------
    // The resolved definition is correct (the ordinary happy path)
    // ---------------------------------------------------------------

    #[Test]
    public function resolves_the_current_versions_definition(): void
    {
        $this->seedOperationCatalog();
        $user = User::factory()->create();
        $agent = $this->seedAgentWithAccumulatedVersions($user->id, 3);

        $definition = $this->resolver()->currentDefinitionFor($agent->fresh());

        $this->assertInstanceOf(AgentDefinition::class, $definition);
        $this->assertSame('version-3-agent', $definition->name, 'must resolve the *current* version, not the first or an arbitrary one');
    }

    // ---------------------------------------------------------------
    // quickstart.md step 15 / mutation-checklist row 9 — flat query cost
    // regardless of version-history size
    // ---------------------------------------------------------------

    #[Test]
    public function resolution_costs_exactly_one_query_regardless_of_accumulated_version_history_size(): void
    {
        $this->seedOperationCatalog();
        $user = User::factory()->create();
        $agent = $this->seedAgentWithAccumulatedVersions($user->id, 55);

        // Re-fetch a fresh, unloaded instance — currentVersion must not
        // already be eager-loaded, so the resolver's own loadMissing() is
        // the thing actually exercised.
        $fresh = Agent::find($agent->id);
        $this->assertFalse($fresh->relationLoaded('currentVersion'));

        DB::flushQueryLog();
        DB::enableQueryLog();

        $definition = $this->resolver()->currentDefinitionFor($fresh);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame('version-55-agent', $definition->name);

        $this->assertCount(
            1,
            $log,
            'currentDefinitionFor() must execute exactly one query, regardless of how many versions exist — got: '
                . json_encode(array_column($log, 'query')),
        );

        $executed = $log[0]['query'];

        // A PK/FK lookup via current_version_id names "id" in its WHERE
        // clause (Eloquent's belongsTo/loadMissing() eager-load shape:
        // WHERE agent_versions.id IN (?)), never agent_versions.agent_id
        // with an ORDER BY version_number scan — the shape a
        // MAX(version_number)-style implementation would produce instead
        // (mutation-checklist row 9's exact guard).
        $normalized = str_replace('`', '"', $executed);
        $this->assertMatchesRegularExpression('/"id"\s*(=|in)/i', $normalized, 'the executed query must be a direct id lookup (the current_version_id target), not an agent_id scan');
        $this->assertStringNotContainsStringIgnoringCase('order by', $executed, 'the executed query must never be an ORDER BY-based scan over version history');
        $this->assertStringNotContainsString('"agent_id" = ?', str_replace('`', '"', $executed), 'the executed query must not be scoped by agent_id — that would scale with version-history size');
    }

    #[Test]
    public function resolution_costs_exactly_one_query_when_the_relation_is_already_loaded(): void
    {
        $this->seedOperationCatalog();
        $user = User::factory()->create();
        $agent = $this->seedAgentWithAccumulatedVersions($user->id, 55);

        $fresh = Agent::with('currentVersion')->find($agent->id);
        $this->assertTrue($fresh->relationLoaded('currentVersion'));

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->resolver()->currentDefinitionFor($fresh);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // loadMissing() is a true no-op once already loaded — zero further
        // queries, not "one, redundantly."
        $this->assertCount(0, $log, 'loadMissing() must not re-query a relation that is already loaded');
    }

    // ---------------------------------------------------------------
    // A model-naming definition adds exactly the one already-existing
    // 086 LanguageModel::exists() query, on top of the resolver's own
    // single lookup — never more, regardless of version-history size.
    // ---------------------------------------------------------------

    #[Test]
    public function a_model_naming_definition_adds_exactly_the_one_already_existing_language_model_lookup(): void
    {
        $this->seedOperationCatalog();
        $user = User::factory()->create();

        $server = \ClarionApp\LlmClient\Models\Server::forceCreate(['id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Primary']);
        \ClarionApp\LlmClient\Models\LanguageModel::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'resolver-model', 'server_id' => $server->id]);

        $agent = Agent::create(['user_id' => $user->id, 'name' => 'model-agent', 'current_version_id' => null]);
        $raw = "name: model-agent\nmodel: resolver-model";
        $version = AgentVersion::create([
            'agent_id' => $agent->id,
            'version_number' => 1,
            'raw_definition' => $raw,
            'content_hash' => hash('sha256', $raw),
            'source' => AgentChangeSource::Created->value,
            'changed_by_user_id' => $user->id,
        ]);
        $agent->current_version_id = $version->id;
        $agent->save();

        $fresh = Agent::find($agent->id);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $definition = $this->resolver()->currentDefinitionFor($fresh);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame('resolver-model', $definition->model);
        $this->assertCount(
            2,
            $log,
            'a model-naming definition must cost exactly two queries: the resolver\'s own current_version_id lookup, plus 086\'s own LanguageModel::exists() check — never more',
        );
    }
}
