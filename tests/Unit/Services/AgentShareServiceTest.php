<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentShareGrant;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\AgentShareService;
use ClarionApp\LlmClient\Services\GitDefinitionFileReader;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for the not-yet-built AgentShareService::grant()
 * (096-agent-sharing, Phase 3/US1, tasks.md T016, data-model.md §4).
 *
 * grant()'s rejection cases (non-owner caller, self-share, unknown
 * recipient, invalid permission) are all asserted only as *some*
 * \RuntimeException, not any one specific exception class name — data-
 * model.md §4 leaves the exact exception type to the implementation, and
 * this package's own established convention (RoleAssignmentFailedException,
 * AgentNameAlreadyInUseException, LastActiveAgentException all extend
 * \RuntimeException) makes \RuntimeException the natural parent a service-
 * layer failure like this would use so a controller can catch it generically
 * and turn it into the package's uniform 404/422 shapes (contracts §1).
 * grant()'s own declared return type (data-model.md §4: `: AgentShareGrant`,
 * not nullable) is itself why every rejection here is modeled as a thrown
 * exception rather than a null return.
 *
 * Written first, confirmed RED: AgentShareService does not exist yet.
 */
class AgentShareServiceTest extends TestCase
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

    private function service(): AgentShareService
    {
        return app(AgentShareService::class);
    }

    private function agentService(): AgentService
    {
        return new AgentService(new AgentDefinitionParser(), new GitDefinitionFileReader());
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    /**
     * Seeds both of ApiManager's live-catalog seams — required before any
     * *valid* AgentDefinitionParser::parse() call (AgentQueryTest's own
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

    private function agent(User $owner, string $name = 'shareable-agent'): Agent
    {
        $this->seedOperationCatalog();

        return $this->agentService()->create($owner->id, "name: {$name}\ninstructions: Assist customers.");
    }

    // ---------------------------------------------------------------
    // grant()
    // ---------------------------------------------------------------

    #[Test]
    public function grant_succeeds_for_the_owner_of_the_target_agent(): void
    {
        $owner = $this->user();
        $recipient = $this->user();
        $agent = $this->agent($owner);

        $result = $this->service()->grant($owner->id, $agent->id, $recipient->id, 'use');

        $this->assertInstanceOf(AgentShareGrant::class, $result);
        $this->assertSame($agent->id, $result->agent_id);
        $this->assertSame($owner->id, $result->owner_user_id);
        $this->assertSame($recipient->id, $result->recipient_user_id);
        $this->assertSame('use', $result->permission);
        $this->assertDatabaseHas('agent_share_grants', [
            'agent_id' => $agent->id,
            'owner_user_id' => $owner->id,
            'recipient_user_id' => $recipient->id,
            'permission' => 'use',
        ]);
    }

    #[Test]
    public function grant_rejects_when_the_caller_does_not_own_the_agent(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $recipient = $this->user();
        $agent = $this->agent($owner);

        try {
            $this->service()->grant($stranger->id, $agent->id, $recipient->id, 'use');
            $this->fail('grant() must reject a caller who does not own the target agent');
        } catch (\RuntimeException $e) {
            // expected — owner-not-found-equivalent behavior.
        }

        $this->assertSame(0, AgentShareGrant::count(), 'a rejected grant attempt must not create a row');
    }

    #[Test]
    public function grant_rejects_a_self_share(): void
    {
        $owner = $this->user();
        $agent = $this->agent($owner);

        try {
            $this->service()->grant($owner->id, $agent->id, $owner->id, 'use');
            $this->fail('grant() must reject recipient_user_id === owner_user_id');
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertSame(0, AgentShareGrant::count());
    }

    #[Test]
    public function grant_rejects_an_unknown_recipient_user_id(): void
    {
        $owner = $this->user();
        $agent = $this->agent($owner);

        try {
            $this->service()->grant($owner->id, $agent->id, (string) Str::uuid(), 'use');
            $this->fail('grant() must reject a recipient_user_id naming no real user');
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertSame(0, AgentShareGrant::count());
    }

    #[Test]
    public function grant_rejects_a_permission_value_outside_the_allowed_set(): void
    {
        $owner = $this->user();
        $recipient = $this->user();
        $agent = $this->agent($owner);

        try {
            $this->service()->grant($owner->id, $agent->id, $recipient->id, 'read_only');
            $this->fail('grant() must reject a permission value outside {use, use_and_edit}');
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertSame(0, AgentShareGrant::count());
    }

    #[Test]
    public function a_first_grant_call_for_a_pair_creates_exactly_one_row(): void
    {
        $owner = $this->user();
        $recipient = $this->user();
        $agent = $this->agent($owner);

        $this->service()->grant($owner->id, $agent->id, $recipient->id, 'use');

        $this->assertSame(
            1,
            AgentShareGrant::where('agent_id', $agent->id)->where('recipient_user_id', $recipient->id)->count(),
        );
    }

    #[Test]
    public function a_second_grant_call_for_the_same_still_active_pair_updates_permission_in_place(): void
    {
        $owner = $this->user();
        $recipient = $this->user();
        $agent = $this->agent($owner);

        $first = $this->service()->grant($owner->id, $agent->id, $recipient->id, 'use');
        $second = $this->service()->grant($owner->id, $agent->id, $recipient->id, 'use_and_edit');

        $this->assertSame(
            1,
            AgentShareGrant::where('agent_id', $agent->id)->where('recipient_user_id', $recipient->id)->count(),
            'a second grant() call for the same still-active pair must update in place, never insert a second row',
        );
        $this->assertSame($first->id, $second->id, 'the same underlying row must be reused, not replaced');
        $this->assertSame('use_and_edit', $second->fresh()->permission);
    }
}
