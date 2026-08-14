<?php

namespace ClarionApp\LlmClient\Tests\Feature;

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
 * FR-008/SC-004 (spec.md, research.md D8, contracts/agent-summary-cards-api.md
 * §1's "Scoping" section, quickstart.md step 9) — the Constitution
 * §II-required Feature-level test proving a summary's usage/reliability/cost
 * figures reflect only the viewing user's own activity, never another
 * user's, even when both are attributed to the identical agent_id.
 *
 * No agent-sharing mechanism exists yet in this system (spec.md's own
 * documented Assumption, mirroring 094-agent-search-listing's own), so the
 * shared-agent scenario is simulated at the row level rather than through
 * ordinary ownership: one Agent, owned by user A in the ordinary sense, with
 * cost_summaries/tool_reliability_summaries/agent_runs rows carrying that
 * same agent_id but attributed to two different user_ids (research.md D8's
 * own "structural guarantee for the day this changes").
 *
 * AgentSummaryQuery does not exist yet at the time this file is written —
 * every test fails with a class-not-found error until Phase 3's
 * implementation (T017/T018) adds it.
 */
class AgentSummaryCardScopingJourneyTest extends TestCase
{
    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();
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

    private function query(): AgentSummaryQuery
    {
        return new AgentSummaryQuery(
            new AgentDefinitionParser(),
            new CostRollupQuery(),
            new ToolReliabilityQuery(),
        );
    }

    #[Test]
    public function each_callers_usage_figures_include_only_their_own_seeded_activity_never_the_others(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
        ]);

        // Owned by user A in the ordinary sense -- the shared-agent
        // scenario is simulated purely by attributing activity ROWS below
        // to two different user_ids against this one agent_id.
        $agent = Agent::create([
            'user_id' => $this->userA->id,
            'name' => 'shared-agent',
            'current_version_id' => null,
        ]);
        $yaml = "name: shared-agent\ninstructions: Shared between two contributors.\ntools:\n  allow:\n    - contacts.*\n";
        $version = AgentVersion::create([
            'agent_id' => $agent->id,
            'version_number' => 1,
            'raw_definition' => $yaml,
            'content_hash' => hash('sha256', $yaml),
            'source' => AgentChangeSource::Created->value,
            'changed_by_user_id' => $this->userA->id,
        ]);
        $agent->current_version_id = $version->id;
        $agent->save();
        $agent = $agent->fresh(['currentVersion']);

        // --- User A's own activity: distinct, recognizable figures. ---
        DB::table('cost_summaries')->insert([
            'id' => (string) Str::uuid(),
            'entity_type' => 'agent',
            'entity_id' => $agent->id,
            'user_id' => $this->userA->id,
            'period_date' => Carbon::now()->toDateString(),
            'request_count' => 11,
            'priced_cost_total' => '1.1100000000',
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => Carbon::now(),
        ]);
        DB::table('tool_reliability_summaries')->insert([
            'id' => (string) Str::uuid(),
            'tool_name' => 'search_documents',
            'agent_id' => $agent->id,
            'user_id' => $this->userA->id,
            'period_date' => Carbon::now()->toDateString(),
            'invocation_count' => 11,
            'success_count' => 10,
            'failure_count' => 1,
            'failure_timeout_count' => 1,
            'failure_connection_failure_count' => 0,
            'failure_authentication_failure_count' => 0,
            'failure_invalid_input_count' => 0,
            'failure_server_error_count' => 0,
            'failure_other_count' => 0,
            'failure_uncategorized_count' => 0,
            'updated_at' => Carbon::now(),
        ]);
        AgentRun::create([
            'kind' => RunKind::Interactive,
            'user_id' => $this->userA->id,
            'agent_id' => $agent->id,
            'started_at' => Carbon::now(),
        ]);

        // --- User B's own activity, on the SAME agent_id: different,
        // recognizable figures. ---
        DB::table('cost_summaries')->insert([
            'id' => (string) Str::uuid(),
            'entity_type' => 'agent',
            'entity_id' => $agent->id,
            'user_id' => $this->userB->id,
            'period_date' => Carbon::now()->toDateString(),
            'request_count' => 77,
            'priced_cost_total' => '7.7700000000',
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => Carbon::now(),
        ]);
        DB::table('tool_reliability_summaries')->insert([
            'id' => (string) Str::uuid(),
            'tool_name' => 'search_documents',
            'agent_id' => $agent->id,
            'user_id' => $this->userB->id,
            'period_date' => Carbon::now()->toDateString(),
            'invocation_count' => 77,
            'success_count' => 70,
            'failure_count' => 7,
            'failure_timeout_count' => 0,
            'failure_connection_failure_count' => 0,
            'failure_authentication_failure_count' => 0,
            'failure_invalid_input_count' => 0,
            'failure_server_error_count' => 7,
            'failure_other_count' => 0,
            'failure_uncategorized_count' => 0,
            'updated_at' => Carbon::now(),
        ]);
        for ($i = 0; $i < 7; $i++) {
            AgentRun::create([
                'kind' => RunKind::Interactive,
                'user_id' => $this->userB->id,
                'agent_id' => $agent->id,
                'started_at' => Carbon::now(),
            ]);
        }

        $asA = $this->query()->summariesFor([$agent], $this->userA->id);
        $asB = $this->query()->summariesFor([$agent], $this->userB->id);

        $usageA = $asA[$agent->id]['usage'];
        $this->assertTrue($usageA['has_run']);
        $this->assertSame(1, $usageA['run_count'], "user A's own single run only, never B's seven");
        $this->assertSame(11, $usageA['reliability']['invocation_count'], "user A's own reliability figures only");
        $this->assertSame(10, $usageA['reliability']['success_count']);
        $this->assertSame(1, $usageA['reliability']['failure_count']);
        $this->assertSame(11, $usageA['cost']['request_count'], "user A's own cost contribution only");
        $this->assertEqualsWithDelta(1.11, (float) $usageA['cost']['priced_cost_total'], 0.0000001);

        $usageB = $asB[$agent->id]['usage'];
        $this->assertTrue($usageB['has_run']);
        $this->assertSame(7, $usageB['run_count'], "user B's own seven runs only, never A's one");
        $this->assertSame(77, $usageB['reliability']['invocation_count'], "user B's own reliability figures only");
        $this->assertSame(70, $usageB['reliability']['success_count']);
        $this->assertSame(7, $usageB['reliability']['failure_count']);
        $this->assertSame(77, $usageB['cost']['request_count'], "user B's own cost contribution only");
        $this->assertEqualsWithDelta(7.77, (float) $usageB['cost']['priced_cost_total'], 0.0000001);
    }
}
