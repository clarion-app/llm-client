<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentSummaryQuery;
use ClarionApp\LlmClient\Services\CostRollupQuery;
use ClarionApp\LlmClient\Services\ToolReliabilityQuery;
use ClarionApp\LlmClient\ValueObjects\AgentChangeSource;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for AgentSummaryQuery (095-agent-summary-cards, T007/T008,
 * data-model.md §7/§8, research.md D1-D6). AgentSummaryQuery does not exist
 * yet at the time this file is written -- every test fails with a
 * class-not-found error until Phase 3's implementation (T017/T018) adds it.
 *
 * T007 (US1) covers step 5's own purpose/capabilities/operation_count/
 * memory_enabled assembly, including its per-agent parse-failure degrade.
 * T008 (US2, appended below, sequenced after T007 in this same file per
 * tasks.md's own "not [P]" instruction) covers steps 2-4's batched
 * run-count/cost/reliability lookups feeding usage.*, plus the query-count
 * assertion (Grounding note 10's resolved 3-per-call figure).
 *
 * Fixtures are built via direct Agent/AgentVersion model creation (mirroring
 * AgentQueryTest.php's own fixture style, tasks.md Grounding note 8) rather
 * than through AgentService::create()/the HTTP layer -- required so a
 * deliberately malformed raw_definition can be seeded at all, since
 * AgentService::create() validates via AgentDefinitionParser::parse()
 * before ever writing a row.
 */
class AgentSummaryQueryTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_runs')->delete();
        DB::table('cost_summaries')->delete();
        DB::table('tool_reliability_summaries')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function query(): AgentSummaryQuery
    {
        return new AgentSummaryQuery(
            new AgentDefinitionParser(),
            new CostRollupQuery(),
            new ToolReliabilityQuery(),
        );
    }

    /**
     * Seeds both of ApiManager's live-catalog seams — required before any
     * *valid* AgentDefinitionParser::parse() call (AgentDefinitionTest.php/
     * AgentQueryTest.php's own established convention).
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
     * Direct Agent + AgentVersion model creation, bypassing
     * AgentService::create() entirely so a deliberately malformed
     * raw_definition can be seeded (AgentService::create() would refuse to
     * write one at all).
     */
    private function makeAgent(string $name, string $rawYaml): Agent
    {
        $agent = Agent::create([
            'user_id' => $this->user->id,
            'name' => $name,
            'current_version_id' => null,
        ]);

        $version = AgentVersion::create([
            'agent_id' => $agent->id,
            'version_number' => 1,
            'raw_definition' => $rawYaml,
            'content_hash' => hash('sha256', $rawYaml),
            'source' => AgentChangeSource::Created->value,
            'changed_by_user_id' => $this->user->id,
        ]);

        $agent->current_version_id = $version->id;
        $agent->save();

        return $agent->fresh(['currentVersion']);
    }

    private function seedCostSummary(array $overrides = []): void
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

    private function seedReliabilitySummary(array $overrides = []): void
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

    private function seedAgentRun(string $agentId, string $userId): void
    {
        AgentRun::create([
            'kind' => RunKind::Interactive,
            'user_id' => $userId,
            'agent_id' => $agentId,
            'started_at' => Carbon::now(),
        ]);
    }

    /**
     * Counts every SELECT statement issued while $fn runs (mirroring
     * EntryPathCoverageJourneyTest.php's own consumptionReadsDuring()
     * DB::listen() technique, generalized to every table rather than one
     * named one — Grounding note 10's own resolution: summariesFor() alone
     * must issue exactly 3, run-count/cost/reliability, regardless of how
     * many agents it is called with).
     */
    private function countSelectQueriesDuring(callable $fn): int
    {
        $count = 0;

        DB::listen(function ($query) use (&$count) {
            if (stripos(ltrim($query->sql), 'select') === 0) {
                $count++;
            }
        });

        $fn();

        return $count;
    }

    // =================================================================
    // T007 — US1: purpose/capabilities/operation_count/memory_enabled
    // =================================================================

    #[Test]
    public function each_agents_own_purpose_capabilities_operation_count_and_memory_enabled_are_reported_correctly_and_never_cross_contaminate(): void
    {
        $operations = [
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
            'contacts.index' => ['path' => '/api/contacts', 'method' => 'get', 'summary' => 'List contacts'],
            'weather.get_forecast' => ['path' => '/api/weather', 'method' => 'get', 'summary' => 'Get forecast'],
        ];
        $this->seedOperationCatalog($operations);

        $refundYaml = <<<YAML
name: refund-agent
instructions: Handles refund requests for the billing team.
capabilities:
  - memory_read
  - memory_search
memory:
  scratch: enabled
  short_term: disabled
  long_term: disabled
  episodic: disabled
  declarative: disabled
tools:
  allow:
    - contacts.*
YAML;

        $weatherYaml = <<<YAML
name: weather-agent
instructions: Reports the current weather forecast.
capabilities:
  - memory_create
memory:
  scratch: disabled
  short_term: disabled
  long_term: disabled
  episodic: disabled
  declarative: disabled
tools:
  allow:
    - weather.*
YAML;

        $wideOpenYaml = <<<YAML
name: wide-open-agent
instructions: Has broad access to everything in the catalog.
capabilities: []
tools:
  allow:
    - "*"
YAML;

        $refundAgent = $this->makeAgent('refund-agent', $refundYaml);
        $weatherAgent = $this->makeAgent('weather-agent', $weatherYaml);
        $wideOpenAgent = $this->makeAgent('wide-open-agent', $wideOpenYaml);

        $result = $this->query()->summariesFor([$refundAgent, $weatherAgent, $wideOpenAgent], $this->user->id);

        $refund = $result[$refundAgent->id];
        $this->assertSame('Handles refund requests for the billing team.', trim($refund['purpose']));
        $this->assertSame(['memory_read', 'memory_search'], $refund['capabilities']);
        $this->assertSame(2, $refund['operation_count'], 'contacts.* matches exactly contacts.store and contacts.index, never weather.get_forecast');
        $this->assertTrue($refund['memory_enabled'], 'scratch is enabled');

        $weather = $result[$weatherAgent->id];
        $this->assertSame('Reports the current weather forecast.', trim($weather['purpose']));
        $this->assertSame(['memory_create'], $weather['capabilities']);
        $this->assertSame(1, $weather['operation_count'], 'weather.* matches exactly weather.get_forecast, never a contacts.* operation');
        $this->assertFalse($weather['memory_enabled'], 'every memory kind is explicitly disabled');

        $wideOpen = $result[$wideOpenAgent->id];
        $this->assertSame('Has broad access to everything in the catalog.', trim($wideOpen['purpose']));
        $this->assertSame([], $wideOpen['capabilities'], 'an explicitly empty capabilities list must stay empty, never default');
        $this->assertSame(3, $wideOpen['operation_count'], '"*" matches every seeded catalog operation');
        $this->assertTrue($wideOpen['memory_enabled'], 'memory omitted entirely defaults every kind to enabled');
    }

    #[Test]
    public function a_malformed_raw_definition_degrades_only_that_one_agents_fields_rather_than_aborting_the_whole_call(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
        ]);

        $goodYaml = <<<YAML
name: healthy-agent
instructions: A perfectly valid agent definition.
tools:
  allow:
    - contacts.*
YAML;

        $healthyAgent = $this->makeAgent('healthy-agent', $goodYaml);
        // Deliberately malformed: a YAML list root, not a mapping -- fails
        // AgentDefinitionParser::collect()'s own step 0 precondition
        // (MalformedYaml).
        $brokenAgent = $this->makeAgent('broken-agent', "- this\n- is\n- a\n- list\n- not\n- a\n- mapping\n");

        $result = $this->query()->summariesFor([$healthyAgent, $brokenAgent], $this->user->id);

        $this->assertSame('A perfectly valid agent definition.', trim($result[$healthyAgent->id]['purpose']), 'the healthy agent must be entirely unaffected by the broken one');
        $this->assertSame(1, $result[$healthyAgent->id]['operation_count']);

        $broken = $result[$brokenAgent->id];
        $this->assertSame('', $broken['purpose'], 'a parse failure must degrade purpose to empty, never throw or abort the whole call');
        $this->assertSame([], $broken['capabilities']);
        $this->assertSame(0, $broken['operation_count']);
        $this->assertFalse($broken['memory_enabled']);
    }

    // =================================================================
    // T008 — US2: usage.has_run/run_count/reliability.*/cost.*, plus the
    // 3-SELECT-regardless-of-agent-count assertion.
    // =================================================================

    #[Test]
    public function usage_reflects_exactly_the_seeded_run_count_cost_and_reliability_figures_for_an_active_agent_and_the_full_default_shape_for_an_unused_one(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
        ]);

        $activeYaml = "name: active-agent\ninstructions: Handles contact management.\ntools:\n  allow:\n    - contacts.*\n";
        $quietYaml = "name: never-run-agent\ninstructions: Has never been invoked.\ntools:\n  allow:\n    - contacts.*\n";

        $activeAgent = $this->makeAgent('active-agent', $activeYaml);
        $quietAgent = $this->makeAgent('never-run-agent', $quietYaml);

        // Cost: two cost_summaries rows (different period_date buckets) for
        // the active agent, none at all for the quiet one.
        $this->seedCostSummary([
            'entity_id' => $activeAgent->id,
            'user_id' => $this->user->id,
            'request_count' => 7,
            'priced_cost_total' => '1.2340500000',
        ]);
        $this->seedCostSummary([
            'entity_id' => $activeAgent->id,
            'user_id' => $this->user->id,
            'period_date' => Carbon::now()->subDay()->toDateString(),
            'request_count' => 3,
            'priced_cost_total' => '0.5000000000',
        ]);

        // Reliability: two tool_reliability_summaries rows, different
        // tools, same agent -- proving the cross-tool sum.
        $this->seedReliabilitySummary([
            'agent_id' => $activeAgent->id,
            'user_id' => $this->user->id,
            'tool_name' => 'search_documents',
            'invocation_count' => 8,
            'success_count' => 7,
            'failure_count' => 1,
            'failure_timeout_count' => 1,
        ]);
        $this->seedReliabilitySummary([
            'agent_id' => $activeAgent->id,
            'user_id' => $this->user->id,
            'tool_name' => 'send_email',
            'invocation_count' => 4,
            'success_count' => 4,
        ]);

        // Runs: three agent_runs rows for the active agent, none for the
        // quiet one.
        $this->seedAgentRun($activeAgent->id, $this->user->id);
        $this->seedAgentRun($activeAgent->id, $this->user->id);
        $this->seedAgentRun($activeAgent->id, $this->user->id);

        $result = $this->query()->summariesFor([$activeAgent, $quietAgent], $this->user->id);

        $activeUsage = $result[$activeAgent->id]['usage'];
        $this->assertTrue($activeUsage['has_run']);
        $this->assertSame(3, $activeUsage['run_count']);
        $this->assertSame(12, $activeUsage['reliability']['invocation_count'], 'summed across both tool_name rows, not just one');
        $this->assertSame(11, $activeUsage['reliability']['success_count']);
        $this->assertSame(1, $activeUsage['reliability']['failure_count']);
        $this->assertFalse($activeUsage['reliability']['no_activity']);
        $this->assertFalse($activeUsage['reliability']['low_sample'], '12 summed invocations clears the threshold of 10');
        $this->assertEqualsWithDelta(1.73405, (float) $activeUsage['cost']['priced_cost_total'], 0.0000001, '1.2340500000 + 0.5000000000');
        $this->assertSame(10, $activeUsage['cost']['request_count'], '7 + 3');
        $this->assertFalse($activeUsage['cost']['has_estimated_cost']);

        $quietUsage = $result[$quietAgent->id]['usage'];
        $this->assertFalse($quietUsage['has_run'], 'a genuinely never-run agent must never show has_run: true');
        $this->assertSame(0, $quietUsage['run_count']);
        $this->assertSame(0, $quietUsage['reliability']['invocation_count']);
        $this->assertTrue($quietUsage['reliability']['no_activity']);
        $this->assertSame(0, $quietUsage['cost']['request_count']);
        $this->assertEqualsWithDelta(0.0, (float) $quietUsage['cost']['priced_cost_total'], 0.0000001);
        $this->assertFalse($quietUsage['cost']['has_estimated_cost']);
    }

    #[Test]
    public function summaries_for_issues_exactly_three_select_queries_regardless_of_agent_count(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
        ]);

        $makeSeededAgent = function (string $label) {
            $yaml = "name: {$label}\ninstructions: query-count fixture {$label}.\ntools:\n  allow:\n    - contacts.*\n";
            $agent = $this->makeAgent($label, $yaml);

            $this->seedCostSummary(['entity_id' => $agent->id, 'user_id' => $this->user->id]);
            $this->seedReliabilitySummary(['agent_id' => $agent->id, 'user_id' => $this->user->id]);
            $this->seedAgentRun($agent->id, $this->user->id);

            return $agent;
        };

        $threeAgents = [];
        for ($i = 0; $i < 3; $i++) {
            $threeAgents[] = $makeSeededAgent("query-count-agent-three-{$i}");
        }

        $fifteenAgents = $threeAgents;
        for ($i = 3; $i < 15; $i++) {
            $fifteenAgents[] = $makeSeededAgent("query-count-agent-fifteen-{$i}");
        }

        $threeCount = $this->countSelectQueriesDuring(fn () => $this->query()->summariesFor($threeAgents, $this->user->id));
        $fifteenCount = $this->countSelectQueriesDuring(fn () => $this->query()->summariesFor($fifteenAgents, $this->user->id));

        $this->assertSame(3, $threeCount, 'summariesFor() alone must issue exactly 3 SELECT queries: run-count, cost, reliability -- catalog resolution issues none against this app\'s own tables');
        $this->assertSame($threeCount, $fifteenCount, 'the query count must not scale with agent count');
    }
}
