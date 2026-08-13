<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\MemoryScope;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\MemoryService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * spec.md US1/US3, contracts/agent-clone-api.md §1, quickstart.md steps
 * 1-5 (US1, T014) and 10/11-13/14-18 (US3, T015) — the end-to-end HTTP
 * acceptance scenarios for `POST /agents/{id}/clone`, mirroring
 * AgentFirstVersionJourneyTest.php's own base()/seedOperationCatalog()/
 * clearOperationCatalog()/actingAs()/tearDown() pattern.
 *
 * Written first, confirmed RED: no `POST agents/{id}/clone` route exists
 * yet (Phase 3's own implementation, T016-T018, comes after these tests).
 */
class AgentCloneJourneyTest extends TestCase
{
    private User $user;

    /** @var string[] */
    private array $tempRepoPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog();
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('llm_memory_entries')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        foreach ($this->tempRepoPaths as $path) {
            $this->removeDirectory($path);
        }
        $this->tempRepoPaths = [];

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // URL helpers
    // ---------------------------------------------------------------

    private function agentsUrl(): string
    {
        return '/api/clarion-app/llm-client/agents';
    }

    private function agentUrl(string $id): string
    {
        return $this->agentsUrl().'/'.$id;
    }

    /**
     * base(string $id) is the clone endpoint itself (tasks.md T014's own
     * exact wording): `POST /agents/{id}/clone`.
     */
    private function base(string $id): string
    {
        return "/api/clarion-app/llm-client/agents/{$id}/clone";
    }

    private function versionsUrl(string $agentId): string
    {
        return $this->agentUrl($agentId).'/versions';
    }

    private function versionUrl(string $agentId, string $versionId): string
    {
        return $this->versionsUrl($agentId).'/'.$versionId;
    }

    private function divergenceUrl(string $agentId): string
    {
        return $this->agentUrl($agentId).'/divergence';
    }

    // ---------------------------------------------------------------
    // Operation catalog seam — required before any *valid*
    // AgentDefinitionParser::parse() call (AgentServiceTest's own
    // established convention).
    // ---------------------------------------------------------------

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
    // Fixture helpers
    // ---------------------------------------------------------------

    private function createAgent(string $definition, ?User $as = null): string
    {
        return $this->actingAs($as ?? $this->user)
            ->postJson($this->agentsUrl(), ['definition' => $definition])
            ->assertStatus(201)
            ->json('id');
    }

    private function rawDefinitionFor(string $agentId): string
    {
        $versions = $this->actingAs($this->user)->getJson($this->versionsUrl($agentId));
        $versionId = $versions->json('data.0.id');

        return $this->actingAs($this->user)->getJson($this->versionUrl($agentId, $versionId))->json('raw_definition');
    }

    private function createGitRepo(): string
    {
        $repoPath = sys_get_temp_dir().'/agent_clone_journey_test_'.uniqid('', true);
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

    // =================================================================
    // T014 — US1 (quickstart steps 1-5)
    // =================================================================

    #[Test]
    public function a_copy_carries_instructions_permitted_operations_and_settings_as_they_were_at_the_moment_of_copying(): void
    {
        $this->seedOperationCatalog(['contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact']]);

        $sourceId = $this->createAgent(
            "name: source-agent\ninstructions: Always be polite.\ntools:\n  allow: [contacts.*]\ncapabilities: [memory_create]",
        );

        $cloneResponse = $this->actingAs($this->user)->postJson($this->base($sourceId), ['name' => 'copy-1']);
        $cloneResponse->assertStatus(201);
        $cloneId = $cloneResponse->json('id');

        $show = $this->actingAs($this->user)->getJson($this->agentUrl($cloneId));
        $show->assertStatus(200);

        $this->assertSame('copy-1', $show->json('name'), 'the clone must carry the new name, not the source\'s');
        $this->assertSame('Always be polite.', $show->json('definition.instructions'));
        $this->assertSame(['contacts.*'], $show->json('definition.toolsAllow'));
        $this->assertSame(['memory_create'], $show->json('definition.capabilities'));
    }

    #[Test]
    public function the_copys_own_version_history_starts_fresh_never_referencing_the_sources_chain(): void
    {
        $sourceId = $this->createAgent('name: source-agent');

        $this->actingAs($this->user)->putJson($this->agentUrl($sourceId), ['definition' => 'name: source-agent-v2'])->assertStatus(200);
        $this->actingAs($this->user)->putJson($this->agentUrl($sourceId), ['definition' => 'name: source-agent-v3'])->assertStatus(200);

        $cloneResponse = $this->actingAs($this->user)->postJson($this->base($sourceId), ['name' => 'copy-2']);
        $cloneResponse->assertStatus(201);
        $cloneId = $cloneResponse->json('id');

        $versionsResponse = $this->actingAs($this->user)->getJson($this->versionsUrl($cloneId));
        $versionsResponse->assertStatus(200);

        $this->assertCount(1, $versionsResponse->json('data'), 'the clone must start with exactly one version');
        $this->assertSame(1, $versionsResponse->json('data.0.version_number'));
        $this->assertSame('created', $versionsResponse->json('data.0.source'));

        $cloneVersionId = $versionsResponse->json('data.0.id');
        $this->assertNull(
            DB::table('agent_versions')->where('id', $cloneVersionId)->value('restored_from_version_id'),
            'the clone\'s first version must never reference any of the source\'s version ids',
        );
    }

    #[Test]
    public function editing_the_original_afterward_never_affects_the_copy(): void
    {
        $sourceId = $this->createAgent("name: source-agent\ninstructions: Original instructions.");

        $cloneId = $this->actingAs($this->user)
            ->postJson($this->base($sourceId), ['name' => 'copy-3'])
            ->assertStatus(201)
            ->json('id');

        $this->actingAs($this->user)->putJson($this->agentUrl($sourceId), [
            'definition' => "name: source-agent\ninstructions: Completely different now.",
        ])->assertStatus(200);

        $show = $this->actingAs($this->user)->getJson($this->agentUrl($cloneId));
        $show->assertStatus(200);
        $this->assertSame('Original instructions.', $show->json('definition.instructions'));
    }

    #[Test]
    public function editing_the_copy_afterward_never_affects_the_original(): void
    {
        $sourceId = $this->createAgent("name: source-agent\ninstructions: Original instructions.");

        $cloneId = $this->actingAs($this->user)
            ->postJson($this->base($sourceId), ['name' => 'copy-4'])
            ->assertStatus(201)
            ->json('id');

        $this->actingAs($this->user)->putJson($this->agentUrl($cloneId), [
            'definition' => "name: copy-4\ninstructions: Completely different now.",
        ])->assertStatus(200);

        $show = $this->actingAs($this->user)->getJson($this->agentUrl($sourceId));
        $show->assertStatus(200);
        $this->assertSame('Original instructions.', $show->json('definition.instructions'));
    }

    #[Test]
    public function a_copy_is_never_linked_to_the_sources_git_file(): void
    {
        $repoPath = $this->createGitRepo();
        $this->writeFile($repoPath, 'agent.yaml', "name: linked-source\n");
        $this->commitAll($repoPath, 'Initial commit');

        $sourceId = $this->createAgent('name: linked-source');

        $this->actingAs($this->user)->putJson($this->agentUrl($sourceId).'/link', [
            'repository_path' => $repoPath,
            'file_path' => 'agent.yaml',
        ])->assertStatus(200);

        $cloneId = $this->actingAs($this->user)
            ->postJson($this->base($sourceId), ['name' => 'linked-source-copy'])
            ->assertStatus(201)
            ->json('id');

        $show = $this->actingAs($this->user)->getJson($this->agentUrl($cloneId));
        $show->assertStatus(200);
        $this->assertFalse($show->json('linked'), 'a clone must never be linked, even when the source is');

        $divergence = $this->actingAs($this->user)->getJson($this->divergenceUrl($cloneId));
        $divergence->assertStatus(200);
        $this->assertSame('not_linked', $divergence->json('state'));

        // Editing the file on disk and syncing the *source* must have no
        // effect whatsoever on the copy's own divergence state.
        $this->writeFile($repoPath, 'agent.yaml', "name: linked-source-edited\n");
        $this->commitAll($repoPath, 'Edited on disk');
        $this->actingAs($this->user)->postJson($this->agentUrl($sourceId).'/sync-from-file')->assertStatus(200);

        $divergenceAfter = $this->actingAs($this->user)->getJson($this->divergenceUrl($cloneId));
        $divergenceAfter->assertStatus(200);
        $this->assertSame('not_linked', $divergenceAfter->json('state'), 'the copy\'s divergence state must be entirely unaffected by syncing the source\'s file');
    }

    // =================================================================
    // T015 — US3 (quickstart steps 10, 11-13, 14-18)
    // =================================================================

    #[Test]
    public function fr009_the_copy_is_governed_by_the_current_installation_ceiling_not_a_copy_time_frozen_snapshot(): void
    {
        $this->seedOperationCatalog([
            'contacts.list' => ['path' => '/api/contacts', 'method' => 'get', 'summary' => 'List contacts'],
        ]);

        $sourceId = $this->createAgent("name: ceiling-source\ntools:\n  allow: [contacts.list]");
        $cloneId = $this->actingAs($this->user)
            ->postJson($this->base($sourceId), ['name' => 'ceiling-copy'])
            ->assertStatus(201)
            ->json('id');

        $sourceDefinition = (new AgentDefinitionParser())->parse($this->rawDefinitionFor($sourceId));
        $cloneDefinition = (new AgentDefinitionParser())->parse($this->rawDefinitionFor($cloneId));

        $this->assertTrue($cloneDefinition->isOperationPermitted('contacts.list'));

        $this->app['config']->set('llm-client.api_denylist', ['/api/contacts']);

        $this->assertFalse($sourceDefinition->isOperationPermitted('contacts.list'), 'the original must also now be denied by the current ceiling');
        $this->assertFalse($cloneDefinition->isOperationPermitted('contacts.list'), 'the copy must be governed by the CURRENT installation ceiling, not a copy-time-frozen snapshot');
    }

    #[Test]
    public function no_stored_memory_or_conversation_history_appears_in_the_copy(): void
    {
        $sourceId = $this->createAgent('name: memory-source');

        $conversation = Conversation::create([
            'user_id' => $this->user->id,
            'agent_id' => $sourceId,
            'character' => 'weather-bot',
        ]);

        (new MemoryService())->create(
            MemoryScope::SCRATCH,
            $conversation->character ?? $conversation->id,
            $this->user->id,
            $conversation->id,
            null,
            'note',
            'remember this',
        );

        $memoryCountBefore = DB::table('llm_memory_entries')->count();
        $conversationCountBefore = DB::table('conversations')->count();

        $this->actingAs($this->user)
            ->postJson($this->base($sourceId), ['name' => 'memory-copy'])
            ->assertStatus(201);

        $this->assertSame($memoryCountBefore, DB::table('llm_memory_entries')->count(), 'clone() must never write a MemoryEntry row');
        $this->assertSame($conversationCountBefore, DB::table('conversations')->count(), 'clone() must never write a Conversation row');
    }

    #[Test]
    public function no_credential_shaped_content_appears_in_the_copy(): void
    {
        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Primary']);
        $model = LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'clone-http-model', 'server_id' => $server->id]);

        $sourceId = $this->createAgent("name: model-agent\nmodel: clone-http-model");
        $cloneId = $this->actingAs($this->user)
            ->postJson($this->base($sourceId), ['name' => 'model-agent-copy'])
            ->assertStatus(201)
            ->json('id');

        $rawDefinition = $this->rawDefinitionFor($cloneId);
        $parsed = Yaml::parse($rawDefinition);

        $closedKeySet = ['format_version', 'name', 'version', 'instructions', 'model', 'memory', 'capabilities', 'tools', 'safety'];
        foreach (array_keys($parsed) as $key) {
            $this->assertContains($key, $closedKeySet, "the rewritten YAML must only ever contain the closed 086 schema's own keys — found unexpected key \"{$key}\"");
        }

        $this->assertStringContainsString('clone-http-model', $rawDefinition);
        foreach (['password', 'token', 'api_key', 'apikey', 'secret', 'credential'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $rawDefinition, "the rewritten YAML must never contain anything credential-shaped (found \"{$needle}\")");
        }
    }

    #[Test]
    public function copying_a_retired_source_produces_a_fully_usable_new_agent_over_http(): void
    {
        $sourceId = $this->createAgent("name: retired-source\ninstructions: Last known state.");
        Agent::find($sourceId)->delete();
        $this->assertNotNull(Agent::withTrashed()->find($sourceId)->deleted_at, 'fixture sanity: the source must actually be soft-deleted');

        $cloneResponse = $this->actingAs($this->user)->postJson($this->base($sourceId), ['name' => 'copy-of-retired-http']);
        $cloneResponse->assertStatus(201, 'a retired source must be found and cloned, never 404');
        $cloneId = $cloneResponse->json('id');

        $show = $this->actingAs($this->user)->getJson($this->agentUrl($cloneId));
        $show->assertStatus(200);
        $this->assertSame('Last known state.', $show->json('definition.instructions'));

        $this->assertNull(Agent::find($cloneId)->deleted_at, 'the resulting copy must itself not be trashed');
    }

    #[Test]
    public function a_name_collision_is_refused_clearly_before_anything_is_written(): void
    {
        $agentAId = $this->createAgent('name: agent-a');
        $this->createAgent('name: agent-b');

        $agentsBefore = DB::table('agents')->count();
        $versionsBefore = DB::table('agent_versions')->count();

        $response = $this->actingAs($this->user)->postJson($this->base($agentAId), ['name' => 'agent-b']);

        $response->assertStatus(409);
        $this->assertSame('agent_name_already_in_use', $response->json('error'));

        $this->assertSame($agentsBefore, DB::table('agents')->count(), 'a refused clone must write no agent row');
        $this->assertSame($versionsBefore, DB::table('agent_versions')->count(), 'a refused clone must write no version row');
    }

    #[Test]
    public function a_name_freed_by_retiring_an_agent_is_immediately_reusable_by_a_clone_over_http(): void
    {
        $agentAId = $this->createAgent('name: agent-a');
        Agent::find($agentAId)->delete();
        $otherId = $this->createAgent('name: agent-other');

        $response = $this->actingAs($this->user)->postJson($this->base($otherId), ['name' => 'agent-a']);

        $response->assertStatus(201);
    }

    #[Test]
    public function per_user_isolation_holds_for_the_clone_source_exactly_as_it_does_for_every_other_action(): void
    {
        $userB = User::factory()->create();
        $agentId = $this->createAgent('name: user-a-agent');

        $agentsBefore = DB::table('agents')->count();

        $response = $this->actingAs($userB)->postJson($this->base($agentId), ['name' => 'stolen-copy']);

        $response->assertStatus(404);
        $this->assertSame($agentsBefore, DB::table('agents')->count(), "user B's attempt must not have created any agent");
    }

    #[Test]
    public function the_sources_own_current_definition_failing_to_re_resolve_is_refused_cleanly_over_http(): void
    {
        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Primary']);
        $model = LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'clone-error-http-model', 'server_id' => $server->id]);

        $sourceId = $this->createAgent("name: model-agent-2\nmodel: clone-error-http-model");
        $model->delete();

        $agentsBefore = DB::table('agents')->count();
        $versionsBefore = DB::table('agent_versions')->count();

        $response = $this->actingAs($this->user)->postJson($this->base($sourceId), ['name' => 'model-agent-2-copy']);

        $response->assertStatus(422);
        $this->assertSame('UnknownModel', $response->json('kind'));

        $this->assertSame($agentsBefore, DB::table('agents')->count(), 'a refused clone must write no agent row');
        $this->assertSame($versionsBefore, DB::table('agent_versions')->count(), 'a refused clone must write no version row');
    }

    // =================================================================
    // T021 — US2 (quickstart steps 6-9)
    //
    // Written first, confirmed RED: agentResource() (StoredAgentController
    // L513-522/547-556) has no `cloned_from` block yet — Phase 4's own
    // implementation (T022) comes after these tests.
    // =================================================================

    #[Test]
    public function a_copy_records_which_agent_it_came_from(): void
    {
        $sourceId = $this->createAgent('name: provenance-source');

        $cloneResponse = $this->actingAs($this->user)->postJson($this->base($sourceId), ['name' => 'provenance-copy']);
        $cloneResponse->assertStatus(201);
        $cloneId = $cloneResponse->json('id');

        $this->assertSame($sourceId, $cloneResponse->json('cloned_from.id'), 'the 201 response itself must name the source agent');

        $show = $this->actingAs($this->user)->getJson($this->agentUrl($cloneId));
        $show->assertStatus(200);
        $this->assertSame($sourceId, $show->json('cloned_from.id'), 'a later, separate GET must still show the identical origin id');
    }

    #[Test]
    public function the_recorded_origin_survives_the_original_being_edited(): void
    {
        $sourceId = $this->createAgent('name: weather-bot');

        $cloneId = $this->actingAs($this->user)
            ->postJson($this->base($sourceId), ['name' => 'weather-bot-copy'])
            ->assertStatus(201)
            ->json('id');

        $this->actingAs($this->user)->putJson($this->agentUrl($sourceId), [
            'definition' => 'name: weather-bot-v2',
        ])->assertStatus(200);

        $show = $this->actingAs($this->user)->getJson($this->agentUrl($cloneId));
        $show->assertStatus(200);

        $this->assertSame($sourceId, $show->json('cloned_from.id'), 'cloned_from.id must be unchanged by the source being edited');
        $this->assertSame('weather-bot-v2', $show->json('cloned_from.name'), 'cloned_from.name must reflect the source\'s CURRENT name, not a frozen snapshot');
    }

    #[Test]
    public function the_recorded_origin_survives_the_original_being_removed(): void
    {
        $sourceId = $this->createAgent('name: soon-to-be-retired');

        $cloneId = $this->actingAs($this->user)
            ->postJson($this->base($sourceId), ['name' => 'retired-origin-copy'])
            ->assertStatus(201)
            ->json('id');

        Agent::find($sourceId)->delete();
        $this->assertNotNull(Agent::withTrashed()->find($sourceId)->deleted_at, 'fixture sanity: the origin must actually be soft-deleted');

        $show = $this->actingAs($this->user)->getJson($this->agentUrl($cloneId));
        $show->assertStatus(200, 'the copy itself must still be ordinarily readable');
        $this->assertNotNull($show->json('cloned_from'), 'cloned_from must remain present and readable after the origin is removed');
        $this->assertSame($sourceId, $show->json('cloned_from.id'));
        $this->assertSame('soon-to-be-retired', $show->json('cloned_from.name'), 'the origin\'s name must still resolve via findAgentIncludingTrashed(), not silently drop to null or error');
    }

    #[Test]
    public function copying_a_copy_records_the_immediate_source_not_the_original_further_back(): void
    {
        $agentAId = $this->createAgent('name: chain-agent-a');

        $copyBId = $this->actingAs($this->user)
            ->postJson($this->base($agentAId), ['name' => 'chain-agent-b'])
            ->assertStatus(201)
            ->json('id');

        $copyCResponse = $this->actingAs($this->user)->postJson($this->base($copyBId), ['name' => 'chain-agent-c']);
        $copyCResponse->assertStatus(201);

        $this->assertSame($copyBId, $copyCResponse->json('cloned_from.id'), 'C\'s cloned_from must name B, its immediate source');
        $this->assertNotSame($agentAId, $copyCResponse->json('cloned_from.id'), 'C\'s cloned_from must never name A, further back in the chain');
    }

    #[Test]
    public function an_agent_with_no_recorded_origin_omits_the_cloned_from_block_entirely(): void
    {
        $agentId = $this->createAgent('name: never-cloned');

        $show = $this->actingAs($this->user)->getJson($this->agentUrl($agentId));
        $show->assertStatus(200);

        $show->assertJsonMissingPath('cloned_from');
        $this->assertArrayNotHasKey('cloned_from', $show->json(), 'a plain, directly-created agent must have no cloned_from key at all — not null, not an empty object');
    }
}
