<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Exceptions\HelperExceedsParentPermissionsException;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentHelperAssignment;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\GitDefinitionFileReader;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for the not-yet-built AgentHelperService::assign()
 * (097-subagent-model, Phase 3/US1+US2, tasks.md T013/T014,
 * data-model.md §3).
 *
 * T013 (US1) covers assign()'s ownership/identity rules: a caller who owns
 * both the parent and the candidate helper succeeds; a caller missing
 * ownership of either side, or attempting self-assignment, is rejected with
 * a plain \RuntimeException — mirroring AgentShareServiceTest.php's own
 * established style (rejection cases asserted only as *some*
 * \RuntimeException, never a specific exception class, matching this
 * package's own AgentShareService::grant() precedent).
 *
 * T014 (US2, appended below, sequenced after T013 per tasks.md's own
 * "not [P]" instruction) covers the subset-of-parent constraint: a helper
 * whose own permitted operations include something the parent cannot
 * itself do is rejected via the specific, typed
 * HelperExceedsParentPermissionsException naming the exact excess
 * operation ids (FR-005's own "clear explanation of what exceeds"); a
 * strict subset or a byte-identical operation set both succeed — an exact
 * match is not itself a violation (spec's own Assumptions).
 *
 * Cycle/depth checks are deliberately not covered here (Phase 4/US3, per
 * the Ordering grounding note) — assign() is not yet expected to guard
 * against either.
 *
 * Written first, confirmed RED: AgentHelperService does not exist yet.
 */
class AgentHelperServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_helper_assignments')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function service(): AgentHelperService
    {
        return app(AgentHelperService::class);
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
     * *valid* AgentDefinitionParser::parse() call (AgentShareServiceTest's
     * own established convention).
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
     * Three-operation catalog shared by every subset/superset scenario
     * below: contacts.* groups two operations, weather.get_forecast a
     * third, disjoint one — the exact shape AgentSummaryQueryTest.php's own
     * established convention uses for the identical purpose.
     */
    private function seedThreeOperationCatalog(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
            'contacts.index' => ['path' => '/api/contacts', 'method' => 'get', 'summary' => 'List contacts'],
            'weather.get_forecast' => ['path' => '/api/weather', 'method' => 'get', 'summary' => 'Get forecast'],
        ]);
    }

    /**
     * @param string $toolsAllowPattern a single, already-YAML-ready list
     *   item value for tools.allow — e.g. '"*"' (quoted wildcard) or
     *   'contacts.*' (unquoted group pattern), matching
     *   AgentSummaryQueryTest.php's own fixture YAML shape.
     */
    private function agent(User $owner, string $name, string $toolsAllowPattern): Agent
    {
        $yaml = <<<YAML
name: {$name}
instructions: Assist customers.
tools:
  allow:
    - {$toolsAllowPattern}
YAML;

        return $this->agentService()->create($owner->id, $yaml);
    }

    // ---------------------------------------------------------------
    // assign() — T013, ownership/identity rules (US1)
    // ---------------------------------------------------------------

    #[Test]
    public function assign_succeeds_for_a_caller_who_owns_both_parent_and_helper_both_within_bounds_of_each_other(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->agent($owner, 'parent-agent', '"*"');
        $helper = $this->agent($owner, 'helper-agent', 'contacts.*');

        $result = $this->service()->assign($owner->id, $parent->id, $helper->id);

        $this->assertInstanceOf(AgentHelperAssignment::class, $result);
        $this->assertSame($parent->id, $result->parent_agent_id);
        $this->assertSame($helper->id, $result->helper_agent_id);
        $this->assertSame($owner->id, $result->owner_user_id);
        $this->assertDatabaseHas('agent_helper_assignments', [
            'parent_agent_id' => $parent->id,
            'helper_agent_id' => $helper->id,
            'owner_user_id' => $owner->id,
        ]);
    }

    #[Test]
    public function assign_rejects_when_the_caller_does_not_own_the_parent(): void
    {
        $parentOwner = $this->user();
        $caller = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->agent($parentOwner, 'not-my-parent', '"*"');
        $helper = $this->agent($caller, 'my-own-helper', 'contacts.*');

        try {
            $this->service()->assign($caller->id, $parent->id, $helper->id);
            $this->fail('assign() must reject a caller who does not own the parent agent');
        } catch (\RuntimeException $e) {
            // expected — owner-not-found-equivalent behavior.
        }

        $this->assertSame(0, AgentHelperAssignment::count(), 'a rejected assign() attempt must not create a row');
    }

    #[Test]
    public function assign_rejects_when_the_caller_does_not_own_the_candidate_helper(): void
    {
        $caller = $this->user();
        $helperOwner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->agent($caller, 'my-own-parent', '"*"');
        $helper = $this->agent($helperOwner, 'not-my-helper', 'contacts.*');

        try {
            $this->service()->assign($caller->id, $parent->id, $helper->id);
            $this->fail('assign() must reject a caller who does not own the candidate helper agent');
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertSame(0, AgentHelperAssignment::count());
    }

    #[Test]
    public function assign_rejects_self_assignment(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $agent = $this->agent($owner, 'self-assign-agent', '"*"');

        try {
            $this->service()->assign($owner->id, $agent->id, $agent->id);
            $this->fail('assign() must reject parentAgentId === helperAgentId');
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertSame(0, AgentHelperAssignment::count());
    }

    // ---------------------------------------------------------------
    // assign() — T014, subset-of-parent rejection cases (US2)
    //
    // Written first, confirmed RED: AgentHelperService does not exist yet
    // (same reason as every T013 case above), and
    // HelperExceedsParentPermissionsException does not exist yet either.
    // ---------------------------------------------------------------

    #[Test]
    public function assign_rejects_with_helper_exceeds_parent_permissions_exception_naming_the_exact_excess_operation_ids(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->agent($owner, 'narrow-parent', 'contacts.*');
        $helper = $this->agent($owner, 'wide-helper', '"*"');

        try {
            $this->service()->assign($owner->id, $parent->id, $helper->id);
            $this->fail('assign() must reject a helper whose own permitted operations exceed the parent\'s');
        } catch (HelperExceedsParentPermissionsException $e) {
            $this->assertSame(
                ['weather.get_forecast'],
                array_values($e->excessOperationIds),
                'the excess must name exactly the one operation the parent cannot itself do',
            );
        }

        $this->assertSame(0, AgentHelperAssignment::count(), 'a rejected assign() attempt must not create a row');
    }

    #[Test]
    public function assign_succeeds_when_the_helpers_operations_are_a_strict_subset_of_the_parents(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->agent($owner, 'wide-parent', '"*"');
        $helper = $this->agent($owner, 'narrow-helper', 'contacts.*');

        $result = $this->service()->assign($owner->id, $parent->id, $helper->id);

        $this->assertInstanceOf(AgentHelperAssignment::class, $result);
        $this->assertDatabaseHas('agent_helper_assignments', [
            'parent_agent_id' => $parent->id,
            'helper_agent_id' => $helper->id,
        ]);
    }

    #[Test]
    public function assign_succeeds_when_the_helpers_operations_are_byte_identical_to_the_parents_own(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->agent($owner, 'identical-parent', 'contacts.*');
        $helper = $this->agent($owner, 'identical-helper', 'contacts.*');

        $result = $this->service()->assign($owner->id, $parent->id, $helper->id);

        $this->assertInstanceOf(AgentHelperAssignment::class, $result, 'a byte-identical operation set is not itself a violation');
        $this->assertDatabaseHas('agent_helper_assignments', [
            'parent_agent_id' => $parent->id,
            'helper_agent_id' => $helper->id,
        ]);
    }

    // ---------------------------------------------------------------
    // remove() -- T053 (US4, data-model.md §1 state-transition table)
    //
    // Mirrors AgentShareService::revoke()'s exact idempotency idiom: finds
    // the active (non-trashed) row directly, returns false (never throws)
    // if none exists, otherwise soft-deletes it and returns true.
    //
    // Written first, confirmed RED: AgentHelperService::remove() does not
    // exist yet.
    // ---------------------------------------------------------------

    #[Test]
    public function remove_soft_deletes_an_active_row_and_returns_true(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->agent($owner, 'remove-parent', '"*"');
        $helper = $this->agent($owner, 'remove-helper', 'contacts.*');

        $this->service()->assign($owner->id, $parent->id, $helper->id);

        $result = $this->service()->remove($owner->id, $parent->id, $helper->id);

        $this->assertTrue($result);
        $row = AgentHelperAssignment::withTrashed()
            ->where('parent_agent_id', $parent->id)
            ->where('helper_agent_id', $helper->id)
            ->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->deleted_at, 'remove() must soft-delete the row, not hard-delete it');
    }

    #[Test]
    public function remove_returns_false_and_never_throws_when_no_active_row_exists_for_the_pair(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->agent($owner, 'remove-noop-parent', '"*"');
        $helper = $this->agent($owner, 'remove-noop-helper', 'contacts.*');

        // Deliberately never assigned -- no active row for this pair.
        $result = $this->service()->remove($owner->id, $parent->id, $helper->id);

        $this->assertFalse($result, 'removing a pair with no active assignment must be a false, idempotent no-op, never an exception');
    }

    #[Test]
    public function assign_after_remove_restores_the_same_row_rather_than_inserting_a_duplicate(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->agent($owner, 'reassign-parent', '"*"');
        $helper = $this->agent($owner, 'reassign-helper', 'contacts.*');

        $original = $this->service()->assign($owner->id, $parent->id, $helper->id);
        $originalId = $original->id;
        $originalCreatedAt = $original->created_at;

        $this->service()->remove($owner->id, $parent->id, $helper->id);

        $restored = $this->service()->assign($owner->id, $parent->id, $helper->id);

        $this->assertSame($originalId, $restored->id, 're-assignment must restore the SAME row, not insert a new one');
        $this->assertEquals($originalCreatedAt, $restored->created_at, 'created_at must be unchanged across remove()+re-assign()');
        $this->assertNull($restored->deleted_at, 'deleted_at must be cleared on restore');
        $this->assertSame(
            1,
            AgentHelperAssignment::withTrashed()
                ->where('parent_agent_id', $parent->id)
                ->where('helper_agent_id', $helper->id)
                ->count(),
            'exactly one lifetime row must exist for this pair, never a duplicate',
        );
    }
}
