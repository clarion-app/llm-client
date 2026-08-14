<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        DB::table('usage_records')->delete();
        DB::table('tool_invocation_records')->delete();
        DB::table('cost_summaries')->delete();
        DB::table('tool_reliability_summaries')->delete();
        DB::table('agent_runs')->delete();
        DB::table('model_prices')->delete();
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

    // =================================================================
    // T010 — 095-agent-summary-cards, US1 HTTP-level scenarios
    // (contracts/agent-summary-cards-api.md §1, data-model.md §7/§8):
    // purpose/capabilities/operation_count/memory_enabled/
    // current_version_number surfaced correctly through GET /agents/search.
    // =================================================================

    #[Test]
    public function a_card_shows_purpose_capability_set_and_operation_count(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
            'contacts.index' => ['path' => '/api/contacts', 'method' => 'get', 'summary' => 'List contacts'],
            'weather.get_forecast' => ['path' => '/api/weather', 'method' => 'get', 'summary' => 'Get forecast'],
        ]);

        $yaml = <<<YAML
name: card-fields-agent
instructions: Handles refund requests for the billing team.
capabilities:
  - memory_read
  - memory_search
tools:
  allow:
    - contacts.*
YAML;

        $agentId = $this->createAgent($yaml);

        $response = $this->actingAs($this->user)->getJson($this->searchUrl());
        $response->assertStatus(200);

        $entry = collect($response->json('data'))->firstWhere('id', $agentId);
        $this->assertNotNull($entry);
        $this->assertSame('Handles refund requests for the billing team.', trim($entry['purpose']));
        $this->assertSame(['memory_read', 'memory_search'], $entry['capabilities']);
        $this->assertSame(2, $entry['operation_count'], 'contacts.* must match exactly the two seeded contacts operations, never the weather one');
    }

    #[Test]
    public function memory_access_is_visibly_distinguished_between_agents(): void
    {
        $memoryOnYaml = <<<YAML
name: memory-on-agent
instructions: Uses scratch memory throughout a conversation.
memory:
  scratch: enabled
  short_term: disabled
  long_term: disabled
  episodic: disabled
  declarative: disabled
YAML;

        $memoryOffYaml = <<<YAML
name: memory-off-agent
instructions: Never touches memory of any kind.
memory:
  scratch: disabled
  short_term: disabled
  long_term: disabled
  episodic: disabled
  declarative: disabled
YAML;

        $onId = $this->createAgent($memoryOnYaml);
        $offId = $this->createAgent($memoryOffYaml);

        $response = $this->actingAs($this->user)->getJson($this->searchUrl());
        $response->assertStatus(200);

        $onEntry = collect($response->json('data'))->firstWhere('id', $onId);
        $offEntry = collect($response->json('data'))->firstWhere('id', $offId);
        $this->assertNotNull($onEntry);
        $this->assertNotNull($offEntry);

        $this->assertTrue($onEntry['memory_enabled'], 'scratch is explicitly enabled on this agent');
        $this->assertFalse($offEntry['memory_enabled'], 'every memory kind is explicitly disabled on this agent');
    }

    #[Test]
    public function search_reports_the_current_version_number_not_an_earlier_one(): void
    {
        $agentId = $this->createAgent('name: version-tracking-agent');

        $this->actingAs($this->user)
            ->putJson($this->agentUrl($agentId), ['definition' => 'name: version-tracking-agent-v2'])
            ->assertStatus(200);
        $this->actingAs($this->user)
            ->putJson($this->agentUrl($agentId), ['definition' => 'name: version-tracking-agent-v3'])
            ->assertStatus(200);

        $v1Id = DB::table('agent_versions')->where('agent_id', $agentId)->where('version_number', 1)->value('id');
        $this->assertNotNull($v1Id, 'fixture sanity: version 1 must exist before restoring to it');

        $this->actingAs($this->user)
            ->postJson($this->agentUrl($agentId).'/versions/'.$v1Id.'/restore')
            ->assertStatus(200);

        $this->assertSame(4, DB::table('agent_versions')->where('agent_id', $agentId)->count(), 'restoring must create a new version 4, never repoint at version 1');

        $response = $this->actingAs($this->user)->getJson($this->searchUrl());
        $response->assertStatus(200);

        $entry = collect($response->json('data'))->firstWhere('id', $agentId);
        $this->assertNotNull($entry);
        $this->assertSame(4, $entry['current_version_number'], 'the card must report the current (4th) version, never the restored-from (1st) one');
    }

    #[Test]
    public function zero_operation_count_edge_case_still_shows_a_card(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
        ]);

        // Each individual pattern resolves to a non-empty set against the
        // catalog (so creation-time validation, which checks tools.allow/
        // tools.deny patterns individually for
        // AgentDefinitionResolutionErrorKind::EmptyOperationPattern, lets
        // this definition through) -- but allow and deny name the exact
        // same operations, so the *final* permitted set (allow minus deny)
        // resolves to zero at read time. This is the realistic way an
        // "unusably narrow configuration" reaches operation_count: 0
        // without ever tripping the creation-time empty-pattern check.
        $yaml = <<<YAML
name: unusably-narrow-agent
instructions: An allow/deny pair that cancels out to zero usable operations.
tools:
  allow:
    - contacts.*
  deny:
    - contacts.*
YAML;

        $agentId = $this->createAgent($yaml);

        $response = $this->actingAs($this->user)->getJson($this->searchUrl());
        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($agentId), 'a zero-operation-count agent must still appear in the list, never be excluded');

        $entry = collect($response->json('data'))->firstWhere('id', $agentId);
        $this->assertSame(0, $entry['operation_count']);
    }

    // =================================================================
    // T011 — 095-agent-summary-cards, US2 HTTP-level scenarios
    // (contracts/agent-summary-cards-api.md §1's worked examples,
    // data-model.md §7/§8): usage.has_run/run_count/reliability.*/cost.*
    // surfaced correctly through GET /agents/search, including the
    // never-run/used-but-quiet/retention-aged-out distinctions.
    // =================================================================

    private function seedPrice(array $overrides = []): ModelPrice
    {
        return ModelPrice::create(array_merge([
            'provider_type' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'reused_input_rate' => '0.30000000',
            'fresh_input_rate' => '3.00000000',
            'output_rate' => '15.00000000',
            'effective_from' => Carbon::now()->subDay(),
            'effective_until' => null,
        ], $overrides));
    }

    private function recordUsageFor(string $agentId, array $providerUsage): void
    {
        $conversationId = Conversation::create(['user_id' => $this->user->id, 'title' => 'usage fixture'])->id;

        (new MetricsRecorder())->recordUsage(
            conversationId: $conversationId,
            userId: $this->user->id,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: $providerUsage,
            inputText: 'input text',
            outputText: 'output text',
            model: 'claude-sonnet-5',
            providerType: 'anthropic',
            agentId: $agentId,
        );
    }

    private function recordToolCallFor(string $agentId, bool $success): void
    {
        (new MetricsRecorder())->recordToolInvocation(
            conversationId: (string) Str::uuid(),
            userId: $this->user->id,
            attemptGroupId: (string) Str::uuid(),
            toolName: 'search_documents',
            success: $success,
            agentId: $agentId,
        );
    }

    private function seedAgentRun(string $agentId): void
    {
        AgentRun::create([
            'kind' => RunKind::Interactive,
            'user_id' => $this->user->id,
            'agent_id' => $agentId,
            'started_at' => Carbon::now(),
        ]);
    }

    /**
     * Direct table inserts (bypassing MetricsRecorder), mirroring
     * AgentSummaryQueryTest.php's own seedCostSummary()/
     * seedReliabilitySummary() helpers -- required by the
     * retention-aged-out scenario below, which must produce rollup
     * activity with deliberately zero surviving agent_runs rows.
     */
    private function seedCostSummaryRow(array $overrides = []): void
    {
        DB::table('cost_summaries')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'entity_type' => 'agent',
            'entity_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'period_date' => Carbon::now()->toDateString(),
            'request_count' => 1,
            'priced_cost_total' => '1.0000000000',
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => Carbon::now(),
        ], $overrides));
    }

    private function seedReliabilitySummaryRow(array $overrides = []): void
    {
        DB::table('tool_reliability_summaries')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'tool_name' => 'search_documents',
            'agent_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'period_date' => Carbon::now()->toDateString(),
            'invocation_count' => 1,
            'success_count' => 1,
            'failure_count' => 0,
            'failure_timeout_count' => 0,
            'failure_connection_failure_count' => 0,
            'failure_authentication_failure_count' => 0,
            'failure_invalid_input_count' => 0,
            'failure_server_error_count' => 0,
            'failure_other_count' => 0,
            'failure_uncategorized_count' => 0,
            'updated_at' => Carbon::now(),
        ], $overrides));
    }

    #[Test]
    public function a_never_run_agent_shows_an_honest_not_yet_used_state(): void
    {
        $agentId = $this->createAgent('name: never-run-agent');

        $response = $this->actingAs($this->user)->getJson($this->searchUrl());
        $response->assertStatus(200);

        $entry = collect($response->json('data'))->firstWhere('id', $agentId);
        $this->assertNotNull($entry);
        $this->assertFalse($entry['usage']['has_run'], 'an agent with zero recorded activity must never read as used');
    }

    #[Test]
    public function a_used_agent_reflects_real_activity(): void
    {
        $this->seedPrice();
        $agentId = $this->createAgent('name: genuinely-used-agent');

        // Two recordUsage() calls with deliberately different token counts
        // so the resulting priced_cost_total is a genuine sum of two
        // different amounts, not one figure doubled.
        $this->recordUsageFor($agentId, ['prompt_tokens' => 1000, 'completion_tokens' => 500, 'total_tokens' => 1500]);
        $this->recordUsageFor($agentId, ['prompt_tokens' => 2000, 'completion_tokens' => 100, 'total_tokens' => 2100]);

        // Mixed success/failure tool invocations.
        $this->recordToolCallFor($agentId, true);
        $this->recordToolCallFor($agentId, true);
        $this->recordToolCallFor($agentId, false);

        $this->seedAgentRun($agentId);
        $this->seedAgentRun($agentId);

        $response = $this->actingAs($this->user)->getJson($this->searchUrl());
        $response->assertStatus(200);

        $entry = collect($response->json('data'))->firstWhere('id', $agentId);
        $this->assertNotNull($entry);
        $usage = $entry['usage'];

        $this->assertTrue($usage['has_run']);
        $this->assertSame(2, $usage['run_count']);
        $this->assertSame(3, $usage['reliability']['invocation_count']);
        $this->assertSame(2, $usage['reliability']['success_count']);
        $this->assertSame(1, $usage['reliability']['failure_count']);
        $this->assertFalse($usage['reliability']['no_activity']);

        // 1000*3/1e6 + 500*15/1e6 = 0.0105; 2000*3/1e6 + 100*15/1e6 = 0.0075;
        // sum = 0.018 (fresh_input_rate 3.0, output_rate 15.0, no cache/
        // reused tokens supplied so the entire input costs at the fresh rate).
        $this->assertEqualsWithDelta(0.018, (float) $usage['cost']['priced_cost_total'], 0.0000001);
        $this->assertSame(2, $usage['cost']['request_count']);
    }

    #[Test]
    public function never_run_and_fully_successful_agents_are_never_mistaken_for_each_other(): void
    {
        $this->seedPrice();

        $neverRunId = $this->createAgent('name: never-run-side-by-side-agent');
        $usedId = $this->createAgent('name: fully-successful-side-by-side-agent');

        $this->recordUsageFor($usedId, ['prompt_tokens' => 1000, 'completion_tokens' => 500, 'total_tokens' => 1500]);
        $this->recordToolCallFor($usedId, true);
        $this->seedAgentRun($usedId);

        $response = $this->actingAs($this->user)->getJson($this->searchUrl());
        $response->assertStatus(200);

        $neverRunEntry = collect($response->json('data'))->firstWhere('id', $neverRunId);
        $usedEntry = collect($response->json('data'))->firstWhere('id', $usedId);
        $this->assertNotNull($neverRunEntry);
        $this->assertNotNull($usedEntry);

        $this->assertFalse($neverRunEntry['usage']['has_run']);
        $this->assertTrue($usedEntry['usage']['has_run']);

        $this->assertSame(array_keys($neverRunEntry), array_keys($usedEntry), 'the two rows must be shape-identical at the top level');
        $this->assertSame(array_keys($neverRunEntry['usage']), array_keys($usedEntry['usage']), 'usage must carry the same keys regardless of has_run');
        $this->assertSame(array_keys($neverRunEntry['usage']['reliability']), array_keys($usedEntry['usage']['reliability']));
        $this->assertSame(array_keys($neverRunEntry['usage']['cost']), array_keys($usedEntry['usage']['cost']));
    }

    #[Test]
    public function a_used_but_tool_quiet_agent_is_distinguished_from_never_run(): void
    {
        $this->seedPrice();
        $agentId = $this->createAgent('name: tool-quiet-agent');

        // recordUsage() only -- deliberately zero recordToolInvocation()
        // calls, so this agent's only signal of activity is cost, never
        // reliability.
        $this->recordUsageFor($agentId, ['prompt_tokens' => 800, 'completion_tokens' => 200, 'total_tokens' => 1000]);
        $this->recordUsageFor($agentId, ['prompt_tokens' => 600, 'completion_tokens' => 150, 'total_tokens' => 750]);

        $this->assertSame(0, DB::table('tool_reliability_summaries')->count(), 'fixture sanity: this scenario must produce zero reliability rows');

        $response = $this->actingAs($this->user)->getJson($this->searchUrl());
        $response->assertStatus(200);

        $entry = collect($response->json('data'))->firstWhere('id', $agentId);
        $this->assertNotNull($entry);

        $this->assertTrue($entry['usage']['has_run'], 'cost activity alone must still read as used');
        $this->assertTrue($entry['usage']['reliability']['no_activity'], 'no tool was ever invoked, so reliability must show no_activity');
        $this->assertSame(0, $entry['usage']['reliability']['invocation_count']);
    }

    #[Test]
    public function retention_aged_out_run_count_still_reads_as_has_been_used(): void
    {
        $agentId = $this->createAgent('name: retention-aged-out-agent');

        // Rollups seeded directly, simulating post-purge state -- no
        // agent_runs row is ever created for this agent (Constitution
        // Article V forbids a destructive purge command in a verification
        // step, so the aged-out state is simulated by construction instead).
        $this->seedCostSummaryRow([
            'entity_id' => $agentId,
            'user_id' => $this->user->id,
            'request_count' => 12,
            'priced_cost_total' => '0.3300000000',
        ]);
        $this->seedReliabilitySummaryRow([
            'agent_id' => $agentId,
            'user_id' => $this->user->id,
            'invocation_count' => 12,
            'success_count' => 12,
            'failure_count' => 0,
        ]);

        $this->assertSame(0, DB::table('agent_runs')->where('agent_id', $agentId)->count(), 'fixture sanity: no surviving agent_runs row for this agent');

        $response = $this->actingAs($this->user)->getJson($this->searchUrl());
        $response->assertStatus(200);

        $entry = collect($response->json('data'))->firstWhere('id', $agentId);
        $this->assertNotNull($entry);
        $this->assertTrue($entry['usage']['has_run'], 'surviving cost/reliability rollups must still read as "has been used" even once agent_runs has aged out');
        $this->assertSame(0, $entry['usage']['run_count'], 'run_count is a recent-window figure sourced from agent_runs, which has aged out here');
    }

    // =================================================================
    // T023 — US3 (spec.md Acceptance Scenarios, FR-009, SC-002,
    // quickstart.md step 11). Expected to pass immediately against Phase
    // 3's already-shipped searchForUser()/search() — this phase adds zero
    // new backend production code (tasks.md's Ordering grounding note).
    // Proves the pagination mechanism Phase 3 built holds up at spec.md's
    // own "hundreds of agents" scale, both for the full unfiltered list and
    // for a query-narrowed subset.
    // =================================================================

    #[Test]
    public function pagination_holds_under_a_larger_seeded_count_and_narrows_correctly(): void
    {
        // 22 agents carry a distinctive marker in their name so the
        // narrowed-search assertions below have a known subset whose count
        // (22) differs from both the full seeded count (120) and its own
        // page count (last_page = 2, vs. 6 for the full list) — proving
        // meta.total/meta.last_page are recomputed from the narrowed set,
        // not merely re-reported from the full one.
        $markerCount = 22;
        $totalCount = 120;

        for ($i = 0; $i < $markerCount; $i++) {
            $this->createAgent(sprintf('name: distinctivemarkerterm-agent-%03d', $i));
        }
        for ($i = $markerCount; $i < $totalCount; $i++) {
            $this->createAgent(sprintf('name: filler-agent-%03d', $i));
        }

        // No `page` supplied at all: defaults to page 1, default per_page 20.
        $firstPage = $this->actingAs($this->user)->getJson($this->searchUrl());
        $firstPage->assertStatus(200);
        $this->assertCount(20, $firstPage->json('data'), 'default page 1 must hold 20 rows');
        $this->assertSame(120, $firstPage->json('meta.total'));
        $this->assertSame(120, $firstPage->json('total_unfiltered'));
        $this->assertSame(1, $firstPage->json('meta.current_page'));
        $this->assertSame(20, $firstPage->json('meta.per_page'));
        $this->assertSame(6, $firstPage->json('meta.last_page'), '120 agents at 20/page is exactly 6 pages');

        // The last page holds the remaining rows (120 - 5*20 = 20, an exact
        // final page here since 120 is evenly divisible by 20).
        $lastPage = $this->actingAs($this->user)->getJson($this->searchUrl().'?page=6');
        $lastPage->assertStatus(200);
        $this->assertCount(20, $lastPage->json('data'), 'the final page must hold the remaining rows');
        $this->assertSame(6, $lastPage->json('meta.current_page'));
        $this->assertSame(120, $lastPage->json('meta.total'));

        // Every id across page 1 and page 6 must be distinct (no overlap,
        // no gap-induced duplication from an unstable ordering).
        $firstPageIds = collect($firstPage->json('data'))->pluck('id');
        $lastPageIds = collect($lastPage->json('data'))->pluck('id');
        $this->assertCount(0, $firstPageIds->intersect($lastPageIds), 'page 1 and page 6 must not overlap');

        // Narrowing by a term matching only the 22-agent marker subset
        // paginates against the NARROWED count, not the full 120.
        $start = microtime(true);
        $narrowed = $this->actingAs($this->user)->getJson($this->searchUrl().'?q=distinctivemarkerterm&page=1');
        $elapsed = microtime(true) - $start;

        $narrowed->assertStatus(200);
        $this->assertCount(20, $narrowed->json('data'), 'narrowed page 1 holds min(per_page, narrowed total) rows');
        $this->assertSame($markerCount, $narrowed->json('meta.total'), 'meta.total must reflect the narrowed count, not the full 120');
        $this->assertSame(120, $narrowed->json('total_unfiltered'), 'total_unfiltered must still reflect the full unfiltered count');
        $this->assertSame(2, $narrowed->json('meta.last_page'), 'ceil(22 / 20) = 2 pages for the narrowed set, distinct from the full list\'s 6');

        foreach ($narrowed->json('data') as $entry) {
            $this->assertStringContainsString('distinctivemarkerterm', $entry['name']);
        }

        // Soft performance assertion (SC-002: locate a specific agent among
        // 100+ in under 10 seconds) — a concrete recorded figure for this
        // 120-agent request, not a strict microbenchmark gate that would
        // make the suite flaky under CI load.
        $this->assertLessThan(
            10.0,
            $elapsed,
            "GET /agents/search?q=...&page=1 against 120 seeded agents took {$elapsed}s, expected well under SC-002's 10s budget"
        );
    }
}
