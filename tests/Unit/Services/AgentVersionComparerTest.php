<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;
use ClarionApp\LlmClient\Exceptions\InvalidAgentVersionComparisonException;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\AgentVersionComparer;
use ClarionApp\LlmClient\ValueObjects\InvalidAgentVersionComparisonKind;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for AgentVersionComparer::compare() (090-agent-version-binding,
 * Phase 5/US3, tasks.md T031, contracts §4, data-model.md §2-§6,
 * research.md D6-D8).
 *
 * Written before AgentVersionComparer exists — every test in this file is
 * expected to fail with a "Class ...AgentVersionComparer not found" error
 * until T035 creates it.
 */
class AgentVersionComparerTest extends TestCase
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

        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('language_models')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function comparer(): AgentVersionComparer
    {
        return new AgentVersionComparer(new AgentDefinitionParser());
    }

    /**
     * Seeds both of ApiManager's live-catalog seams — required before any
     * *valid* AgentDefinitionParser::parse() call, since parse()
     * unconditionally resolves the operation catalog once per call
     * (AgentVersionResolverTest/ConversationAgentDefinitionResolverTest's
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

    // ---------------------------------------------------------------
    // Field-difference correctness.
    // ---------------------------------------------------------------

    #[Test]
    public function field_differences_name_exactly_the_changed_scalar_and_memory_fields(): void
    {
        $agent = app(AgentService::class)->create(
            $this->user->id,
            "name: weather-agent\ninstructions: Always respond in English.",
        );
        $v1Id = $agent->current_version_id;

        $agent = app(AgentService::class)->update(
            $agent,
            $this->user->id,
            "name: weather-agent\ninstructions: Always respond in French.\nmemory:\n  long_term: disabled",
        );
        $v2Id = $agent->current_version_id;

        $comparison = $this->comparer()->compare($this->user->id, $v1Id, $v2Id);

        $this->assertFalse($comparison->identical);
        $this->assertSame([], $comparison->listDifferences);
        $this->assertCount(2, $comparison->fieldDifferences);

        $byField = [];
        foreach ($comparison->fieldDifferences as $diff) {
            $byField[$diff->field] = $diff;
        }

        $this->assertArrayHasKey('instructions', $byField);
        $this->assertSame('Always respond in English.', $byField['instructions']->from);
        $this->assertSame('Always respond in French.', $byField['instructions']->to);

        $this->assertArrayHasKey('memory.long_term', $byField);
        $this->assertTrue($byField['memory.long_term']->from);
        $this->assertFalse($byField['memory.long_term']->to);
    }

    // ---------------------------------------------------------------
    // List-difference correctness, as a true set diff, not positional
    // (mutation-checklist row 7).
    // ---------------------------------------------------------------

    #[Test]
    public function list_differences_are_a_true_set_diff_not_positional(): void
    {
        $this->seedOperationCatalog([
            'contacts.list' => ['path' => '/api/contacts', 'method' => 'get', 'summary' => 'List contacts'],
            'contacts.create' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Create contact'],
            'calendar.list' => ['path' => '/api/calendar', 'method' => 'get', 'summary' => 'List calendar'],
        ]);

        $agent = app(AgentService::class)->create(
            $this->user->id,
            "name: list-agent\ninstructions: Do things.\ntools:\n  allow:\n    - contacts.list\n    - calendar.list",
        );
        $v1Id = $agent->current_version_id;

        // Reordered-but-unchanged (contacts.list stays, just moved), one
        // pattern removed (calendar.list), one pattern added (contacts.create).
        $agent = app(AgentService::class)->update(
            $agent,
            $this->user->id,
            "name: list-agent\ninstructions: Do things.\ntools:\n  allow:\n    - contacts.create\n    - contacts.list",
        );
        $v2Id = $agent->current_version_id;

        $comparison = $this->comparer()->compare($this->user->id, $v1Id, $v2Id);

        $this->assertFalse($comparison->identical);
        $this->assertSame([], $comparison->fieldDifferences);
        $this->assertCount(1, $comparison->listDifferences);

        $listDiff = $comparison->listDifferences[0];
        $this->assertSame('toolsAllow', $listDiff->field);
        $this->assertEqualsCanonicalizing(['contacts.create'], $listDiff->added);
        $this->assertEqualsCanonicalizing(['calendar.list'], $listDiff->removed);
    }

    // ---------------------------------------------------------------
    // Two identical versions report no differences (AC2, FR-008/SC-004).
    // ---------------------------------------------------------------

    #[Test]
    public function two_identical_versions_report_no_differences(): void
    {
        $raw = "name: identical-agent\ninstructions: Stay the same.";

        $agent = app(AgentService::class)->create($this->user->id, $raw);
        $v1Id = $agent->current_version_id;

        $agent = app(AgentService::class)->update($agent, $this->user->id, $raw);
        $v2Id = $agent->current_version_id;

        $comparison = $this->comparer()->compare($this->user->id, $v1Id, $v2Id);

        $this->assertTrue($comparison->identical);
        $this->assertSame([], $comparison->fieldDifferences);
        $this->assertSame([], $comparison->listDifferences);
    }

    // ---------------------------------------------------------------
    // An unchanged setting never appears (AC3, FR-009, mutation-checklist
    // row 6).
    // ---------------------------------------------------------------

    #[Test]
    public function an_unchanged_setting_never_appears_in_the_comparison(): void
    {
        $agent = app(AgentService::class)->create(
            $this->user->id,
            "name: stable-agent\ninstructions: Version one.",
        );
        $v1Id = $agent->current_version_id;

        $agent = app(AgentService::class)->update(
            $agent,
            $this->user->id,
            "name: stable-agent\ninstructions: Version two.",
        );
        $v2Id = $agent->current_version_id;

        $comparison = $this->comparer()->compare($this->user->id, $v1Id, $v2Id);

        $fields = array_map(static fn ($diff) => $diff->field, $comparison->fieldDifferences);

        $this->assertNotContains('name', $fields, 'name did not change and must never appear');
        $this->assertNotContains('model', $fields, 'model did not change (both null) and must never appear');
    }

    // ---------------------------------------------------------------
    // Comparing a version against itself is refused (Edge Cases, FR-010,
    // mutation-checklist row 4).
    // ---------------------------------------------------------------

    #[Test]
    public function comparing_a_version_against_itself_is_refused(): void
    {
        $agent = app(AgentService::class)->create($this->user->id, "name: solo-agent");
        $v1Id = $agent->current_version_id;

        try {
            $this->comparer()->compare($this->user->id, $v1Id, $v1Id);
            $this->fail('Expected InvalidAgentVersionComparisonException to be thrown');
        } catch (InvalidAgentVersionComparisonException $e) {
            $this->assertSame(InvalidAgentVersionComparisonKind::SameVersion, $e->kind);
            $this->assertSame($v1Id, $e->leftVersionId);
            $this->assertSame($v1Id, $e->rightVersionId);
        }
    }

    // ---------------------------------------------------------------
    // Comparing versions from different agents is refused, distinctly
    // (Edge Cases, FR-010, mutation-checklist row 5).
    // ---------------------------------------------------------------

    #[Test]
    public function comparing_versions_from_different_agents_is_refused(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a");
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b");

        try {
            $this->comparer()->compare($this->user->id, $agentA->current_version_id, $agentB->current_version_id);
            $this->fail('Expected InvalidAgentVersionComparisonException to be thrown');
        } catch (InvalidAgentVersionComparisonException $e) {
            $this->assertSame(InvalidAgentVersionComparisonKind::DifferentAgents, $e->kind);
        }
    }

    // ---------------------------------------------------------------
    // A version whose raw_definition fails to parse causes the underlying
    // exception to propagate uncaught (research.md D8) — comparison is NOT
    // best-effort, unlike a plain version read or a turn-time resolution.
    // ---------------------------------------------------------------

    #[Test]
    public function an_unresolvable_versions_parse_failure_propagates_uncaught(): void
    {
        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Primary']);
        $model = LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'retiring-model', 'server_id' => $server->id]);

        $agent = app(AgentService::class)->create(
            $this->user->id,
            "name: model-agent\nmodel: retiring-model",
        );
        $v1Id = $agent->current_version_id;

        $agent = app(AgentService::class)->update(
            $agent,
            $this->user->id,
            "name: model-agent\ninstructions: unrelated change.",
        );
        $v2Id = $agent->current_version_id;

        // The first version's named model no longer exists on this
        // installation — its raw_definition can no longer resolve.
        $model->delete();

        $this->expectException(AgentDefinitionResolutionException::class);

        $this->comparer()->compare($this->user->id, $v1Id, $v2Id);
    }
}
