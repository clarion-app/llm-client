<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * spec.md US1+US2 (Phase 3, 094-agent-search-listing),
 * contracts/agent-search-api.md §1, data-model.md §4/§5, research.md
 * D1/D3/D6/D7/D8 — the end-to-end HTTP acceptance scenarios for
 * `GET /agents/search`, mirroring AgentActivationJourneyTest.php's own
 * setUp()/tearDown()/seedOperationCatalog()/clearOperationCatalog()/
 * agentsUrl()-style helper pattern (tasks.md Grounding note 5).
 *
 * Written first, confirmed RED: no `GET /agents/search` route exists yet
 * (Phase 3's own implementation, T011-T014, comes after these tests) — every
 * request in this file currently falls through to the pre-existing
 * `GET agents/{id}` wildcard route with `$id === 'search'`, which 404s via
 * `StoredAgentController::show()`'s own notFoundResponse().
 *
 * T006 (US2) is appended below T005 (US1) in this same file, sequenced
 * after it per tasks.md's own "not [P] relative to T005: same file"
 * instruction.
 */
class AgentSearchListingJourneyTest extends TestCase
{
    private User $user;

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

        DB::table('messages')->delete();
        DB::table('conversations')->delete();
        DB::table('llm_memory_entries')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

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

    private function searchUrl(): string
    {
        return $this->agentsUrl().'/search';
    }

    private function deactivateUrl(string $id): string
    {
        return "{$this->agentUrl($id)}/deactivate";
    }

    // ---------------------------------------------------------------
    // Operation catalog seam — required before any *valid*
    // AgentDefinitionParser::parse() call (AgentActivationJourneyTest's own
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

    /**
     * A convenience wrapper over createAgent() that also sets a
     * multi-word `instructions:` block — needed by every US2 scenario that
     * searches against instructions text rather than the agent's name.
     */
    private function createAgentWithInstructions(string $name, string $instructions, ?User $as = null): string
    {
        $indented = str_replace("\n", "\n  ", $instructions);
        $yaml = "name: {$name}\ninstructions: |\n  {$indented}\n";

        return $this->createAgent($yaml, $as);
    }

    // =================================================================
    // T005 — US1 (spec.md Acceptance Scenarios, FR-001/FR-002/FR-003/
    // FR-006/FR-007/FR-010, contracts/agent-search-api.md's routing-order
    // note, quickstart.md steps 1, 2, 3, 9, 13)
    // =================================================================

    #[Test]
    public function every_owned_agent_appears_correctly_marked_with_totals(): void
    {
        $agentAId = $this->createAgent('name: agent-alpha');
        $agentBId = $this->createAgent('name: agent-beta');
        $agentCId = $this->createAgent('name: agent-gamma');

        $this->actingAs($this->user)->postJson($this->deactivateUrl($agentBId))->assertStatus(200);

        $response = $this->actingAs($this->user)->getJson($this->searchUrl());
        $response->assertStatus(200);

        $data = collect($response->json('data'));
        $this->assertCount(3, $data, 'every owned agent must appear');

        $entryA = $data->firstWhere('id', $agentAId);
        $entryB = $data->firstWhere('id', $agentBId);
        $entryC = $data->firstWhere('id', $agentCId);

        $this->assertNotNull($entryA);
        $this->assertNotNull($entryB);
        $this->assertNotNull($entryC);

        $this->assertTrue($entryA['is_active'], 'agent-alpha was never deactivated');
        $this->assertFalse($entryB['is_active'], 'agent-beta was deactivated');
        $this->assertTrue($entryC['is_active'], 'agent-gamma was never deactivated');

        foreach ($data as $entry) {
            $this->assertTrue($entry['can_use'], 'can_use must always be true today (research.md D6)');
        }

        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(3, $response->json('total_unfiltered'));
    }

    #[Test]
    public function a_user_with_zero_agents_gets_the_empty_account_signal(): void
    {
        $lonelyUser = User::factory()->create();

        foreach ([$this->searchUrl(), $this->searchUrl().'?q=anything'] as $url) {
            $response = $this->actingAs($lonelyUser)->getJson($url);
            $response->assertStatus(200);
            $this->assertSame([], $response->json('data'));
            $this->assertSame(0, $response->json('meta.total'));
            $this->assertSame(0, $response->json('total_unfiltered'));
        }
    }

    #[Test]
    public function an_edited_agents_entry_reflects_the_edit_not_stale_state(): void
    {
        $agentId = $this->createAgent('name: freshness-agent');
        $this->createAgent('name: sibling-agent'); // sibling, so freshness-agent is never the caller's last active one

        $this->actingAs($this->user)->postJson($this->deactivateUrl($agentId))->assertStatus(200);

        $afterDeactivate = $this->actingAs($this->user)->getJson($this->searchUrl());
        $afterDeactivate->assertStatus(200);
        $entry = collect($afterDeactivate->json('data'))->firstWhere('id', $agentId);
        $this->assertNotNull($entry);
        $this->assertFalse($entry['is_active'], 'a fresh search must reflect the just-applied deactivation, not stale state');

        $this->actingAs($this->user)->putJson($this->agentUrl($agentId), ['definition' => "name: renamed-agent\n"])->assertStatus(200);

        $afterRename = $this->actingAs($this->user)->getJson($this->searchUrl());
        $afterRename->assertStatus(200);
        $entryAfterRename = collect($afterRename->json('data'))->firstWhere('id', $agentId);
        $this->assertNotNull($entryAfterRename);
        $this->assertSame('renamed-agent', $entryAfterRename['name']);
    }

    #[Test]
    public function server_side_scoping_a_caller_never_sees_another_users_agents(): void
    {
        $userB = User::factory()->create();

        $agentAId = $this->createAgent('name: shared-term-agent-a');
        $agentBId = $this->createAgent('name: shared-term-agent-b', $userB);

        foreach ([$this->searchUrl(), $this->searchUrl().'?q=shared-term'] as $url) {
            $response = $this->actingAs($this->user)->getJson($url);
            $response->assertStatus(200);

            $body = json_encode($response->json());
            $this->assertStringNotContainsString($agentBId, (string) $body, "user B's agent id must never appear in user A's response");
            $this->assertStringNotContainsString('shared-term-agent-b', (string) $body, "user B's agent name must never appear in user A's response");

            $ids = collect($response->json('data'))->pluck('id');
            $this->assertTrue($ids->contains($agentAId));
            $this->assertFalse($ids->contains($agentBId));
        }
    }

    #[Test]
    public function get_agents_search_is_not_swallowed_by_the_agents_id_wildcard_route(): void
    {
        $this->createAgent('name: route-order-agent');

        $response = $this->actingAs($this->user)->getJson($this->searchUrl());

        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page', 'total', 'last_page'], 'total_unfiltered']);
        $this->assertNotSame(
            'agent_not_found',
            $response->json('code'),
            "GET /agents/search must never resolve to show()'s single-agent 404 body for an agent literally id'd \"search\""
        );
    }

    // =================================================================
    // T006 — US2 (spec.md Acceptance Scenarios, FR-004/FR-005/FR-006/
    // FR-008/FR-009, research.md D1's precision guard, quickstart.md steps
    // 4, 5, 6, 7, 8, 10, 12)
    // =================================================================

    #[Test]
    public function a_term_matching_only_an_agents_instructions_returns_it(): void
    {
        $matchId = $this->createAgentWithInstructions('Agent Seven', 'This assistant handles refund requests for the billing team.');
        $otherId = $this->createAgent('name: agent-unrelated');

        foreach (['refund', 'billing'] as $term) {
            $response = $this->actingAs($this->user)->getJson($this->searchUrl()."?q={$term}");
            $response->assertStatus(200);
            $ids = collect($response->json('data'))->pluck('id');
            $this->assertTrue($ids->contains($matchId), "q={$term} must return the agent whose instructions contain it");
            $this->assertFalse($ids->contains($otherId), "q={$term} must not return an unrelated agent");
        }
    }

    #[Test]
    public function a_term_matching_only_an_agents_name_also_returns_it(): void
    {
        $agentId = $this->createAgentWithInstructions('Weather Helper', 'Provides general assistance with everyday tasks.');

        $response = $this->actingAs($this->user)->getJson($this->searchUrl().'?q=weather');
        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($agentId));
    }

    #[Test]
    public function a_search_matching_nothing_is_distinguishable_from_the_empty_account_state(): void
    {
        $this->createAgent('name: agent-one');
        $this->createAgent('name: agent-two');

        $response = $this->actingAs($this->user)->getJson($this->searchUrl().'?q=xyzzynonexistentterm');
        $response->assertStatus(200);
        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, $response->json('meta.total'));
        $this->assertGreaterThan(0, $response->json('total_unfiltered'));
    }

    #[Test]
    public function an_edit_to_instructions_is_found_by_its_new_wording_with_no_special_cache_invalidation(): void
    {
        $agentId = $this->createAgentWithInstructions('freshness-agent', 'Handles general customer questions.');

        $before = $this->actingAs($this->user)->getJson($this->searchUrl().'?q=invoicing');
        $before->assertStatus(200);
        $this->assertFalse(collect($before->json('data'))->pluck('id')->contains($agentId));

        $newYaml = "name: freshness-agent\ninstructions: |\n  This assistant now specializes in invoicing questions.\n";
        $this->actingAs($this->user)->putJson($this->agentUrl($agentId), ['definition' => $newYaml])->assertStatus(200);

        $after = $this->actingAs($this->user)->getJson($this->searchUrl().'?q=invoicing');
        $after->assertStatus(200);
        $this->assertTrue(collect($after->json('data'))->pluck('id')->contains($agentId));
    }

    #[Test]
    public function clearing_a_search_returns_to_the_full_us1_list(): void
    {
        $this->createAgent('name: agent-one');
        $this->createAgent('name: agent-two');
        $this->createAgent('name: agent-three');

        $this->actingAs($this->user)->getJson($this->searchUrl().'?q=xyz')->assertStatus(200);

        $cleared = $this->actingAs($this->user)->getJson($this->searchUrl());
        $cleared->assertStatus(200);

        $fullList = $this->actingAs($this->user)->getJson($this->searchUrl());
        $fullList->assertStatus(200);

        $this->assertSame($fullList->json('data'), $cleared->json('data'), "clearing a search must return exactly the full US1 list");
        $this->assertSame(3, $cleared->json('meta.total'));
    }

    #[Test]
    public function a_retired_agent_is_still_findable_by_search_just_visibly_marked(): void
    {
        $agentId = $this->createAgentWithInstructions('retired-findable-agent', 'Handles onboarding documentation requests.');
        $this->createAgent('name: sibling-agent'); // sibling, so the retired agent is never the caller's last active one

        $this->actingAs($this->user)->postJson($this->deactivateUrl($agentId))->assertStatus(200);

        $response = $this->actingAs($this->user)->getJson($this->searchUrl().'?q=onboarding');
        $response->assertStatus(200);
        $entry = collect($response->json('data'))->firstWhere('id', $agentId);
        $this->assertNotNull($entry, 'a retired agent must still be findable by search');
        $this->assertFalse($entry['is_active']);
    }

    #[Test]
    public function per_page_is_clamped_server_side_never_trusted_from_the_client(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createAgent("name: clamp-agent-{$i}");
        }

        $tooLarge = $this->actingAs($this->user)->getJson($this->searchUrl().'?per_page=99999');
        $tooLarge->assertStatus(200);
        $this->assertSame(100, $tooLarge->json('meta.per_page'));
        $this->assertLessThanOrEqual(100, count($tooLarge->json('data')));

        $tooSmall = $this->actingAs($this->user)->getJson($this->searchUrl().'?per_page=0');
        $tooSmall->assertStatus(200);
        $this->assertSame(20, $tooSmall->json('meta.per_page'));

        $negative = $this->actingAs($this->user)->getJson($this->searchUrl().'?per_page=-5');
        $negative->assertStatus(200);
        $this->assertSame(20, $negative->json('meta.per_page'));
    }

    #[Test]
    public function matching_runs_against_parsed_instructions_never_the_raw_yaml_document(): void
    {
        $agentId = $this->createAgentWithInstructions('precision-guard-agent', 'Handles warehouse shipment logistics tracking.');

        // "instructions" is a structural YAML key present in this agent's
        // own raw_definition document text (the literal "instructions:"
        // key), but appears nowhere in its own name or its actual parsed
        // instructions body — a naive raw-YAML-text match would wrongly
        // return this agent; matching against the parsed field must not.
        $response = $this->actingAs($this->user)->getJson($this->searchUrl().'?q=instructions');
        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse(
            $ids->contains($agentId),
            'matching must run against parsed instructions text, never the raw YAML document containing the literal key name "instructions"'
        );
    }
}
