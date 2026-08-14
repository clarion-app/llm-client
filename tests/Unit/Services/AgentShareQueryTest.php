<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentShareGrant;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\AgentShareQuery;
use ClarionApp\LlmClient\Services\GitDefinitionFileReader;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for the not-yet-built AgentShareQuery::grantsForAgent()
 * (096-agent-sharing, Phase 3/US1, tasks.md T017, data-model.md §5).
 *
 * grantsForAgent() is owner-only, verified via the existing
 * AgentQuery::findAgent() — null uniformly signals "doesn't exist" and
 * "not yours," mirroring findAgent()'s own contract. Otherwise returns the
 * agent's currently-active (non-revoked) grants, each with a resolvable
 * `recipient` relation for a display name.
 *
 * Written first, confirmed RED: AgentShareQuery does not exist yet.
 */
class AgentShareQueryTest extends TestCase
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

    private function query(): AgentShareQuery
    {
        return app(AgentShareQuery::class);
    }

    private function agentService(): AgentService
    {
        return new AgentService(new AgentDefinitionParser(), new GitDefinitionFileReader());
    }

    private function user(): User
    {
        return User::factory()->create();
    }

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

    private function agent(User $owner, string $name = 'shareable-agent'): Agent
    {
        $this->seedOperationCatalog();

        return $this->agentService()->create($owner->id, "name: {$name}\ninstructions: Assist customers.");
    }

    private function grant(Agent $agent, User $owner, User $recipient, string $permission = 'use'): AgentShareGrant
    {
        return AgentShareGrant::create([
            'agent_id' => $agent->id,
            'owner_user_id' => $owner->id,
            'recipient_user_id' => $recipient->id,
            'permission' => $permission,
        ]);
    }

    // ---------------------------------------------------------------
    // grantsForAgent()
    // ---------------------------------------------------------------

    #[Test]
    public function returns_null_when_the_caller_does_not_own_the_agent(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $recipient = $this->user();
        $agent = $this->agent($owner);
        $this->grant($agent, $owner, $recipient, 'use');

        $result = $this->query()->grantsForAgent($stranger->id, $agent->id);

        $this->assertNull($result, 'a non-owner caller must get the same uniform null findAgent() itself returns');
    }

    #[Test]
    public function returns_null_for_a_genuinely_nonexistent_agent(): void
    {
        $owner = $this->user();

        $result = $this->query()->grantsForAgent($owner->id, (string) Str::uuid());

        $this->assertNull($result);
    }

    #[Test]
    public function returns_only_currently_active_grants_for_an_agent_the_caller_owns(): void
    {
        $owner = $this->user();
        $activeRecipient = $this->user();
        $revokedRecipient = $this->user();
        $agent = $this->agent($owner);

        $this->grant($agent, $owner, $activeRecipient, 'use');
        $revoked = $this->grant($agent, $owner, $revokedRecipient, 'use_and_edit');
        $revoked->delete();
        $this->assertNotNull($revoked->fresh()->deleted_at, 'fixture sanity: the grant must actually be soft-deleted');

        $result = $this->query()->grantsForAgent($owner->id, $agent->id);

        $this->assertNotNull($result);
        $ids = $result->pluck('recipient_user_id')->all();
        $this->assertContains($activeRecipient->id, $ids);
        $this->assertNotContains($revokedRecipient->id, $ids, 'a revoked grant must not be returned');
        $this->assertCount(1, $result);
    }

    #[Test]
    public function each_returned_grant_carries_a_resolvable_recipient_name(): void
    {
        $owner = $this->user();
        $recipient = $this->user();
        $agent = $this->agent($owner);
        $this->grant($agent, $owner, $recipient, 'use');

        $result = $this->query()->grantsForAgent($owner->id, $agent->id);

        $this->assertNotNull($result);
        $grant = $result->first();
        $this->assertNotNull($grant->recipient, 'the recipient relation must be resolvable/eager-loaded');
        $this->assertSame($recipient->name, $grant->recipient->name);
    }
}
