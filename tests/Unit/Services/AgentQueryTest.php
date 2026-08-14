<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\AgentShareGrant;
use ClarionApp\LlmClient\Services\AgentQuery;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\GitDefinitionFileReader;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for AgentQuery::findAgentIncludingTrashed() (091, data-model.md
 * §1/entity-relationship summary, research.md D5) — the trash-inclusive
 * counterpart to findAgent(), used by clone()'s own source resolution
 * (FR-013) and by a copy's recorded-origin display (FR-008).
 *
 * The first dedicated unit test file for AgentQuery. findAgentIncludingTrashed()
 * itself was already implemented in Phase 2 of this feature; this file
 * formalizes/locks in the behavior contracts/agent-clone-api.md and
 * data-model.md already describe for it, mirroring AgentServiceTest.php's
 * own tearDown()/user-factory/seedOperationCatalog() conventions.
 */
class AgentQueryTest extends TestCase
{
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
    // Helpers
    // ---------------------------------------------------------------

    private function query(): AgentQuery
    {
        return new AgentQuery(new AgentDefinitionParser());
    }

    private function service(): AgentService
    {
        return new AgentService(new AgentDefinitionParser(), new GitDefinitionFileReader());
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    /**
     * Seeds both of ApiManager's live-catalog seams — required before any
     * *valid* AgentDefinitionParser::parse() call (AgentServiceTest's own
     * established convention).
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
    // findAgentIncludingTrashed()
    // ---------------------------------------------------------------

    #[Test]
    public function finds_an_ordinary_active_agent_the_caller_owns(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();
        $agent = $this->service()->create($user->id, 'name: weather-agent');

        $found = $this->query()->findAgentIncludingTrashed($user->id, $agent->id);

        $this->assertNotNull($found, 'must find an active agent just like findAgent() would');
        $this->assertSame($agent->id, $found->id);
        $this->assertNull($found->deleted_at);
    }

    #[Test]
    public function finds_a_retired_soft_deleted_agent_the_caller_owns_unlike_find_agent(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();
        $agent = $this->service()->create($user->id, 'name: weather-agent');
        $agent->delete();
        $this->assertNotNull($agent->fresh()->deleted_at, 'fixture sanity: the agent must actually be soft-deleted');

        $foundTrashInclusive = $this->query()->findAgentIncludingTrashed($user->id, $agent->id);
        $foundOrdinary = $this->query()->findAgent($user->id, $agent->id);

        $this->assertNotNull($foundTrashInclusive, 'findAgentIncludingTrashed() must find a retired agent');
        $this->assertSame($agent->id, $foundTrashInclusive->id);
        $this->assertNotNull($foundTrashInclusive->deleted_at);

        $this->assertNull($foundOrdinary, 'findAgent() must NOT find a retired agent — the two methods must contrast');
    }

    #[Test]
    public function returns_null_for_an_agent_belonging_to_a_different_user_active_or_trashed(): void
    {
        $this->seedOperationCatalog();
        $owner = $this->user();
        $intruder = $this->user();

        $activeAgent = $this->service()->create($owner->id, 'name: owners-active-agent');
        $trashedAgent = $this->service()->create($owner->id, 'name: owners-trashed-agent');
        $trashedAgent->delete();

        $this->assertNull(
            $this->query()->findAgentIncludingTrashed($intruder->id, $activeAgent->id),
            'a foreign-owned active agent must resolve to null, identical to findAgent()\'s own contract',
        );
        $this->assertNull(
            $this->query()->findAgentIncludingTrashed($intruder->id, $trashedAgent->id),
            'a foreign-owned trashed agent must resolve to null too — ownership scoping applies regardless of trash state',
        );
    }

    #[Test]
    public function returns_null_for_a_genuinely_nonexistent_id(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();

        $found = $this->query()->findAgentIncludingTrashed($user->id, (string) Str::uuid());

        $this->assertNull($found);
    }

    // ---------------------------------------------------------------
    // findAccessibleAgent() / findEditableAgent() (096-agent-sharing,
    // Phase 2/Foundational, data-model.md §3) — the access-relaxation
    // read methods every user story in that feature depends on. Grants
    // are seeded directly via the AgentShareGrant model, bypassing the
    // not-yet-built AgentShareService.
    // ---------------------------------------------------------------

    private function grant(string $agentId, string $ownerUserId, string $recipientUserId, string $permission): AgentShareGrant
    {
        return AgentShareGrant::create([
            'agent_id' => $agentId,
            'owner_user_id' => $ownerUserId,
            'recipient_user_id' => $recipientUserId,
            'permission' => $permission,
        ]);
    }

    #[Test]
    public function an_owned_agent_is_found_by_both_accessible_and_editable(): void
    {
        $this->seedOperationCatalog();
        $owner = $this->user();
        $agent = $this->service()->create($owner->id, 'name: owned-agent');

        $accessible = $this->query()->findAccessibleAgent($owner->id, $agent->id);
        $editable = $this->query()->findEditableAgent($owner->id, $agent->id);

        $this->assertNotNull($accessible, 'the owner must find their own agent via findAccessibleAgent()');
        $this->assertSame($agent->id, $accessible->id);
        $this->assertNotNull($editable, 'the owner must find their own agent via findEditableAgent()');
        $this->assertSame($agent->id, $editable->id);
    }

    #[Test]
    public function a_use_only_grant_is_found_by_accessible_but_not_editable(): void
    {
        $this->seedOperationCatalog();
        $owner = $this->user();
        $recipient = $this->user();
        $agent = $this->service()->create($owner->id, 'name: use-only-agent');
        $this->grant($agent->id, $owner->id, $recipient->id, 'use');

        $accessible = $this->query()->findAccessibleAgent($recipient->id, $agent->id);
        $editable = $this->query()->findEditableAgent($recipient->id, $agent->id);

        $this->assertNotNull($accessible, 'a use grant must satisfy findAccessibleAgent()');
        $this->assertSame($agent->id, $accessible->id);
        $this->assertNull($editable, 'a use-only grant must NOT satisfy findEditableAgent()');
    }

    #[Test]
    public function a_use_and_edit_grant_is_found_by_both(): void
    {
        $this->seedOperationCatalog();
        $owner = $this->user();
        $recipient = $this->user();
        $agent = $this->service()->create($owner->id, 'name: use-and-edit-agent');
        $this->grant($agent->id, $owner->id, $recipient->id, 'use_and_edit');

        $accessible = $this->query()->findAccessibleAgent($recipient->id, $agent->id);
        $editable = $this->query()->findEditableAgent($recipient->id, $agent->id);

        $this->assertNotNull($accessible, 'a use_and_edit grant must satisfy findAccessibleAgent()');
        $this->assertSame($agent->id, $accessible->id);
        $this->assertNotNull($editable, 'a use_and_edit grant must also satisfy findEditableAgent()');
        $this->assertSame($agent->id, $editable->id);
    }

    #[Test]
    public function a_revoked_grant_is_found_by_neither(): void
    {
        $this->seedOperationCatalog();
        $owner = $this->user();
        $recipient = $this->user();
        $agent = $this->service()->create($owner->id, 'name: revoked-grant-agent');
        $revoked = $this->grant($agent->id, $owner->id, $recipient->id, 'use_and_edit');
        $revoked->delete();
        $this->assertNotNull($revoked->fresh()->deleted_at, 'fixture sanity: the grant must actually be soft-deleted');

        $accessible = $this->query()->findAccessibleAgent($recipient->id, $agent->id);
        $editable = $this->query()->findEditableAgent($recipient->id, $agent->id);

        $this->assertNull($accessible, 'a revoked grant must not satisfy findAccessibleAgent()');
        $this->assertNull($editable, 'a revoked grant must not satisfy findEditableAgent()');
    }

    #[Test]
    public function an_agent_never_shared_with_the_caller_is_found_by_neither(): void
    {
        $this->seedOperationCatalog();
        $owner = $this->user();
        $stranger = $this->user();
        $agent = $this->service()->create($owner->id, 'name: never-shared-agent');

        $accessible = $this->query()->findAccessibleAgent($stranger->id, $agent->id);
        $editable = $this->query()->findEditableAgent($stranger->id, $agent->id);

        $this->assertNull($accessible, 'an agent never shared with the caller must not satisfy findAccessibleAgent()');
        $this->assertNull($editable, 'an agent never shared with the caller must not satisfy findEditableAgent()');
    }

    #[Test]
    public function a_nonexistent_agent_id_returns_null_from_both(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();

        $accessible = $this->query()->findAccessibleAgent($user->id, (string) Str::uuid());
        $editable = $this->query()->findEditableAgent($user->id, (string) Str::uuid());

        $this->assertNull($accessible);
        $this->assertNull($editable);
    }
}
