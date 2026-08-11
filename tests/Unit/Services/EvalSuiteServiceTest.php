<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\EvalSuite;
use ClarionApp\LlmClient\Services\EvalSuiteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for EvalSuiteService — the sole write path for eval_suites,
 * covering data-model.md §1's create-time validation and C1: name/
 * agent_identifier are required and length-bounded, and (agent_identifier,
 * name) uniqueness is scoped to the pair and to live suites only
 * (research.md D7).
 *
 * rename()/archive() are User Story 2's concern and are added to this file
 * in Phase 4 — this file covers only create()/list()/find(), the surface
 * User Story 1 needs.
 */
class EvalSuiteServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('eval_suites')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function service(): EvalSuiteService
    {
        return new EvalSuiteService();
    }

    private function liveRowCount(): int
    {
        return DB::table('eval_suites')->whereNull('deleted_at')->count();
    }

    private function totalRowCount(): int
    {
        return DB::table('eval_suites')->count();
    }

    private function assertCreateRejected(string $name, string $agentIdentifier, string $message): void
    {
        $before = $this->totalRowCount();

        try {
            $this->service()->create($name, $agentIdentifier);
            $this->fail($message);
        } catch (\InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage(), 'A rejection must say what was wrong');
        }

        $this->assertSame($before, $this->totalRowCount(), 'A rejected create must write nothing at all');
    }

    // ---------------------------------------------------------------
    // Creation
    // ---------------------------------------------------------------

    #[Test]
    public function create_saves_a_suite_with_the_declared_name_and_agent_identifier(): void
    {
        $suite = $this->service()->create('Contact management sanity checks', 'home-automation-agent');

        $this->assertInstanceOf(EvalSuite::class, $suite);
        $this->assertSame('Contact management sanity checks', $suite->name);
        $this->assertSame('home-automation-agent', $suite->agent_identifier);
        $this->assertSame(1, $this->liveRowCount());
    }

    #[Test]
    public function name_is_required_and_rejected_when_empty_after_trim(): void
    {
        foreach (['', '   ', "\t\n"] as $name) {
            $this->assertCreateRejected($name, 'home-automation-agent', "name '{$name}' must be rejected");
        }
    }

    #[Test]
    public function agent_identifier_is_required_and_rejected_when_empty_after_trim(): void
    {
        foreach (['', '   ', "\t\n"] as $agentIdentifier) {
            $this->assertCreateRejected('A suite', $agentIdentifier, "agent_identifier '{$agentIdentifier}' must be rejected");
        }
    }

    #[Test]
    public function a_name_over_the_configured_max_identifier_length_is_rejected(): void
    {
        config(['llm-client.eval_suites.max_identifier_length' => 20]);

        $this->assertCreateRejected(
            str_repeat('a', 21),
            'home-automation-agent',
            'A name over the configured max_identifier_length must be rejected',
        );
    }

    #[Test]
    public function an_agent_identifier_over_the_configured_max_identifier_length_is_rejected(): void
    {
        config(['llm-client.eval_suites.max_identifier_length' => 20]);

        $this->assertCreateRejected(
            'A suite',
            str_repeat('a', 21),
            'An agent_identifier over the configured max_identifier_length must be rejected',
        );
    }

    #[Test]
    public function a_name_at_exactly_the_configured_max_identifier_length_is_accepted(): void
    {
        config(['llm-client.eval_suites.max_identifier_length' => 20]);

        // agent_identifier is kept short here on purpose: it is bounded by
        // the identical config value (data-model.md §1), so a fixture at
        // or under the configured 20-character limit is required to
        // isolate this test to the `name` boundary alone.
        $suite = $this->service()->create(str_repeat('a', 20), 'agent');

        $this->assertSame(str_repeat('a', 20), $suite->name);
    }

    // ---------------------------------------------------------------
    // Uniqueness — (agent_identifier, name), live suites only (D7)
    // ---------------------------------------------------------------

    #[Test]
    public function a_second_create_for_the_same_live_agent_identifier_and_name_pair_is_rejected(): void
    {
        $this->service()->create('Contact management sanity checks', 'home-automation-agent');

        $this->assertCreateRejected(
            'Contact management sanity checks',
            'home-automation-agent',
            'A duplicate live (agent_identifier, name) pair must be rejected',
        );

        $this->assertSame(1, $this->liveRowCount(), 'The rejected create must not add a second live row');
    }

    #[Test]
    public function two_suites_with_the_same_name_but_different_agent_identifiers_are_both_accepted(): void
    {
        $service = $this->service();

        $first = $service->create('Basic Sanity Checks', 'home-automation-agent');
        $second = $service->create('Basic Sanity Checks', 'billing-agent');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('Basic Sanity Checks', $first->name);
        $this->assertSame('Basic Sanity Checks', $second->name);
        $this->assertSame(2, $this->liveRowCount(), 'Uniqueness is scoped to the (agent_identifier, name) pair, not to name alone');
    }

    // ---------------------------------------------------------------
    // list()
    // ---------------------------------------------------------------

    #[Test]
    public function list_returns_only_live_suites(): void
    {
        $service = $this->service();

        $live = $service->create('Live suite', 'home-automation-agent');
        $toArchive = $service->create('Archived suite', 'home-automation-agent');

        DB::table('eval_suites')->where('id', $toArchive->id)->update(['deleted_at' => now()]);

        $ids = $service->list()->pluck('id')->all();

        $this->assertContains($live->id, $ids);
        $this->assertNotContains($toArchive->id, $ids);
    }

    #[Test]
    public function list_returns_an_empty_collection_when_no_suite_exists(): void
    {
        $this->assertCount(0, $this->service()->list());
    }

    // ---------------------------------------------------------------
    // find()
    // ---------------------------------------------------------------

    #[Test]
    public function find_returns_the_live_suite_by_id(): void
    {
        $service = $this->service();

        $suite = $service->create('Findable suite', 'home-automation-agent');

        $found = $service->find($suite->id);

        $this->assertNotNull($found);
        $this->assertSame($suite->id, $found->id);
    }

    #[Test]
    public function find_returns_null_for_an_unknown_id(): void
    {
        $this->assertNull($this->service()->find((string) Str::uuid()));
    }

    #[Test]
    public function find_returns_null_for_an_archived_suite(): void
    {
        $service = $this->service();

        $suite = $service->create('Archived suite', 'home-automation-agent');

        DB::table('eval_suites')->where('id', $suite->id)->update(['deleted_at' => now()]);

        $this->assertNull($service->find($suite->id), 'An archived suite is "not found" through this method (contracts §5)');
    }
}
