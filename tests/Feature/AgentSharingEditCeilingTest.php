<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentShareGrant;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 096-agent-sharing, Phase 6 (US4) — the edit-rights ceiling (FR-011,
 * SC-004, research.md D4). A pure proving phase: D4 confirms
 * AgentDefinition::isOperationPermitted()/isConfirmationRequired()
 * (ValueObjects/AgentDefinition.php) already union the definition's own
 * tools.allow/tools.deny with the installation-wide
 * config('llm-client.api_denylist') ceiling unconditionally, and
 * StoredAgentController::update() (the PUT /agents/{id} write path) is the
 * identical AgentService::update() call whether the caller is the agent's
 * owner or a use_and_edit share recipient (AgentQuery::findEditableAgent()
 * is the only thing that varies). This file adds no production code — it
 * exercises the existing write path as a share recipient and confirms the
 * installation ceiling still governs the saved result, then confirms the
 * identical shape holds for an owner editing their own agent, proving the
 * two callers share one mechanism rather than two implementations that
 * merely happen to agree today.
 *
 * Fixtures/conventions reused verbatim from this feature's own sibling
 * tests: the operation-catalog seeding seam from
 * AgentDefinitionSafetyCeilingJourneyTest.php (itself precedent-following
 * 086/088's AgentDefinitionSafetyCeilingJourneyTest, tasks.md Grounding
 * note 8), and the owner/recipient/stranger + grant()/makeAgent() fixture
 * conventions from AgentShareGrantJourneyTest.php.
 */
class AgentSharingEditCeilingTest extends TestCase
{
    private User $owner;

    private User $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->recipient = User::factory()->create();
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_share_grants')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Operation catalog seam (AgentDefinitionSafetyCeilingJourneyTest's
    // own established convention) — required before any *valid*
    // AgentDefinitionParser::parse()/isOperationPermitted() call.
    // ---------------------------------------------------------------

    /**
     * @param array<string, array{path: string, method: string, summary: string}> $operations
     */
    private function seedOperationCatalog(array $operations): void
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
    // Fixture helpers (AgentShareGrantJourneyTest's own conventions)
    // ---------------------------------------------------------------

    private function agentUrl(string $id): string
    {
        return '/api/clarion-app/llm-client/agents/'.$id;
    }

    private function makeAgent(User $owner, string $definition): Agent
    {
        return app(AgentService::class)->create($owner->id, $definition);
    }

    private function grant(Agent $agent, User $owner, User $recipient, string $permission = 'use_and_edit'): AgentShareGrant
    {
        return AgentShareGrant::create([
            'agent_id' => $agent->id,
            'owner_user_id' => $owner->id,
            'recipient_user_id' => $recipient->id,
            'permission' => $permission,
        ]);
    }

    /**
     * The installation's own denylist matches this operation's resolved
     * path, exactly the same fnmatch() normalization
     * ApiCallValidator::validate() applies — path-pattern, not
     * operationId-pattern (AgentDefinitionSafetyCeilingJourneyTest's own
     * Grounding-note-1 comment).
     */
    private function seedDenylistedCatalog(): void
    {
        $this->seedOperationCatalog([
            'contacts.destroy' => ['path' => '/api/contacts/{id}', 'method' => 'delete', 'summary' => 'Delete a contact'],
            'weather.get_forecast' => ['path' => '/api/weather', 'method' => 'get', 'summary' => 'Get forecast'],
        ]);
        $this->app['config']->set('llm-client.api_denylist', ['/api/contacts/*']);
    }

    // ---------------------------------------------------------------
    // T057 — AC1 (FR-011): the installation-wide denylist ceiling binds
    // the shared-edit path exactly as it binds an owner's own edit.
    // ---------------------------------------------------------------

    #[Test]
    public function ac1_a_use_and_edit_recipient_cannot_widen_a_shared_agent_past_the_installation_denylist(): void
    {
        $this->seedDenylistedCatalog();

        $agent = $this->makeAgent($this->owner, "name: shared-ceiling-agent\ninstructions: Assist.");
        $this->grant($agent, $this->owner, $this->recipient, 'use_and_edit');

        $response = $this->actingAs($this->recipient, 'api')->putJson($this->agentUrl($agent->id), [
            'definition' => <<<YAML
            name: shared-ceiling-agent
            instructions: Assist.
            tools:
              allow:
                - contacts.destroy
            YAML,
        ]);

        // The save itself succeeds — AgentDefinitionValidator::check() only
        // rejects structurally invalid documents (unknown keys, unresolved
        // patterns, etc.); the installation ceiling is not a save-time
        // "problem," it is enforced live by isOperationPermitted(). If this
        // ever started 422ing instead, the assertion below (which depends
        // on reading back a genuinely saved version) would need rethinking
        // — but today it must be 200.
        $response->assertStatus(200);

        $savedDefinition = (new AgentDefinitionParser())->parse(
            $agent->fresh()->currentVersion->raw_definition
        );

        // This is the crux of FR-011: B's own definition explicitly listed
        // contacts.destroy under tools.allow, yet the saved version's own
        // isOperationPermitted() must still say no — the installation
        // denylist union in AgentDefinition::isOperationPermitted() is what
        // refuses it, not anything B's edit itself declined to request.
        // Dropping that union (ValueObjects/AgentDefinition.php's
        // isDeniedByInstallation() check) would flip this to true and turn
        // this assertion red.
        $this->assertFalse($savedDefinition->isOperationPermitted('contacts.destroy'));
    }

    #[Test]
    public function ac1_an_owner_editing_their_own_agent_hits_the_identical_ceiling_in_the_identical_shape(): void
    {
        $this->seedDenylistedCatalog();

        $ownedAgent = $this->makeAgent($this->owner, "name: owned-ceiling-agent\ninstructions: Assist.");

        $response = $this->actingAs($this->owner, 'api')->putJson($this->agentUrl($ownedAgent->id), [
            'definition' => <<<YAML
            name: owned-ceiling-agent
            instructions: Assist.
            tools:
              allow:
                - contacts.destroy
            YAML,
        ]);

        // The identical outcome shape as B's shared-edit attempt above:
        // the write itself succeeds (200)...
        $response->assertStatus(200);

        $savedDefinition = (new AgentDefinitionParser())->parse(
            $ownedAgent->fresh()->currentVersion->raw_definition
        );

        // ...and the installation denylist still refuses the operation on
        // the saved version — proving the owned-edit path and the
        // shared-edit path above are bound by the same
        // isOperationPermitted() code, not two implementations that merely
        // happen to agree today.
        $this->assertFalse($savedDefinition->isOperationPermitted('contacts.destroy'));
    }

    // ---------------------------------------------------------------
    // T058 — AC2: an edit that stays within the ceiling succeeds
    // normally, attributed to the actual editor (B), never the owner (A).
    // ---------------------------------------------------------------

    #[Test]
    public function ac2_a_use_and_edit_recipients_edit_within_the_ceiling_succeeds_and_is_attributed_to_the_recipient(): void
    {
        $this->seedDenylistedCatalog();

        $agent = $this->makeAgent($this->owner, "name: shared-within-ceiling-agent\ninstructions: Assist.");
        $this->grant($agent, $this->owner, $this->recipient, 'use_and_edit');

        $response = $this->actingAs($this->recipient, 'api')->putJson($this->agentUrl($agent->id), [
            // weather.get_forecast is permitted (not denylisted, not a
            // confirm_methods verb by default) — an edit fully within the
            // recipient's own bounds, not merely "the request happened not
            // to be refused."
            'definition' => <<<YAML
            name: shared-within-ceiling-agent
            instructions: Assist.
            tools:
              allow:
                - weather.get_forecast
            YAML,
        ]);

        $response->assertStatus(200);

        $savedDefinition = (new AgentDefinitionParser())->parse(
            $agent->fresh()->currentVersion->raw_definition
        );
        $this->assertTrue($savedDefinition->isOperationPermitted('weather.get_forecast'));

        $latestVersion = AgentVersion::where('agent_id', $agent->id)
            ->orderByDesc('version_number')
            ->first();
        $this->assertNotNull($latestVersion);
        $this->assertSame($this->recipient->id, $latestVersion->changed_by_user_id);
        $this->assertNotSame($this->owner->id, $latestVersion->changed_by_user_id);
    }
}
