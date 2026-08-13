<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
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
        return new AgentQuery();
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
}
