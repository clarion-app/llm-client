<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\Models\McpClientTool;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpClientToolDiscoveryService;
use ClarionApp\LlmClient\ValueObjects\McpTransportKind;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ReferenceMcpServer\Protocol;
use Tests\Fixtures\ReferenceMcpServer\ReferenceMcpServer;
use Tests\TestCase;

/**
 * User Story 2, Acceptance Scenario 3 / FR-017: a server configured at the
 * personal scope must never surface its tools to a different user's own
 * search_operations call, even when that other user's own query text would
 * otherwise match it -- proving the eligibility scoping
 * matchingExternalToolResults() already applies (McpClientServer::
 * eligibleFor(), the same predicate ExternalToolPermissionNarrowingTest's
 * sibling tests already proved at the execute_operation level) genuinely
 * reaches the search path too.
 */
class ExternalToolScopeIsolationTest extends TestCase
{
    private ?ReferenceMcpServer $referenceServer = null;

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
        $this->referenceServer?->stopHttp();
        $this->referenceServer = null;

        DB::table('conversations')->delete();
        DB::table('mcp_client_tools')->delete();
        DB::table('mcp_client_server_statuses')->delete();
        DB::table('mcp_client_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function discoverServerFor(User $owner, string $name): McpClientServer
    {
        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp(Protocol::MODE_HAPPY_PATH);

        $server = McpClientServer::create([
            'name' => $name,
            'transport' => McpTransportKind::StreamableHttp,
            'url' => $url,
            'user_id' => $owner->id,
        ]);

        app(McpClientToolDiscoveryService::class)->discover($server);

        return $server;
    }

    private function searchResultsFor(User $user, string $query): array
    {
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $result = json_decode(
            app(AgentLoopService::class)->executeMetaTool('search_operations', ['query' => $query], $conversation),
            true,
        );

        return $result['results'] ?? [];
    }

    /**
     * Direct-row creation of a server offering 30 currently-active,
     * identically-worded matching tools -- mirrors
     * ExternalToolSearchResultBoundJourneyTest's own makeServer()/
     * makeTools() helpers, reused here to prove the per-server bound and
     * per-user scoping compose: an oversized server owned by one user must
     * consume none of another user's own per-server budget, since it never
     * enters that other user's query at all.
     *
     * @return list<McpClientTool>
     */
    private function makeOversizedServerFor(User $owner, string $name, string $matchWord): array
    {
        $server = McpClientServer::create([
            'name' => $name,
            'transport' => McpTransportKind::StreamableHttp,
            'url' => 'https://example.test/mcp',
            'user_id' => $owner->id,
        ]);

        $seenAt = now();
        $tools = [];
        for ($i = 1; $i <= 30; $i++) {
            $toolName = sprintf('%s_%02d', $matchWord, $i);
            $tools[] = McpClientTool::create([
                'server_id' => $server->id,
                'synthetic_operation_id' => "mcp:{$server->id}:{$toolName}",
                'name' => $toolName,
                'description' => "A tool offered by {$name}.",
                'input_schema' => ['type' => 'object', 'properties' => []],
                'last_seen_at' => $seenAt,
            ]);
        }

        return $tools;
    }

    #[Test]
    public function two_users_each_owning_an_oversized_server_under_the_same_query_term_never_share_results_or_each_others_per_server_budget(): void
    {
        $toolsA = $this->makeOversizedServerFor($this->userA, 'User A Oversized Server', 'gizmo');
        $toolsB = $this->makeOversizedServerFor($this->userB, 'User B Oversized Server', 'gizmo');

        $resultsA = $this->searchResultsFor($this->userA, 'gizmo');
        $resultsB = $this->searchResultsFor($this->userB, 'gizmo');

        $idsA = collect($resultsA)->pluck('operationId')->all();
        $idsB = collect($resultsB)->pluck('operationId')->all();

        // Each user's own response is capped at the configured per-server
        // limit (default 5), exactly as a lone oversized server would be.
        $this->assertLessThanOrEqual(
            5,
            count($idsA),
            'user A\'s own per-server bound must apply regardless of user B\'s separate oversized server; got: '.json_encode($resultsA),
        );
        $this->assertLessThanOrEqual(
            5,
            count($idsB),
            'user B\'s own per-server bound must apply regardless of user A\'s separate oversized server; got: '.json_encode($resultsB),
        );
        $this->assertGreaterThan(0, count($idsA), 'user A must still see their own server\'s matches');
        $this->assertGreaterThan(0, count($idsB), 'user B must still see their own server\'s matches');

        // Cross-contamination: user B's tool ids never appear in user A's
        // results and vice versa -- the other user's oversized server
        // contributes zero results and consumes none of this user's own
        // per-server budget.
        $toolIdsA = collect($toolsA)->pluck('synthetic_operation_id')->all();
        $toolIdsB = collect($toolsB)->pluck('synthetic_operation_id')->all();

        $this->assertEmpty(
            array_intersect($idsA, $toolIdsB),
            'user A\'s search must never surface any of user B\'s oversized server\'s tools; got: '.json_encode($resultsA),
        );
        $this->assertEmpty(
            array_intersect($idsB, $toolIdsA),
            'user B\'s search must never surface any of user A\'s oversized server\'s tools; got: '.json_encode($resultsB),
        );
    }

    #[Test]
    public function a_server_scoped_to_one_users_account_never_surfaces_its_tools_to_a_different_users_search_even_when_the_query_text_would_otherwise_match(): void
    {
        $server = $this->discoverServerFor($this->userA, 'User A Personal Server');
        $tool = McpClientTool::where('server_id', $server->id)->where('name', 'reference_echo')->firstOrFail();

        // Sanity: the owning user genuinely can find it -- otherwise a
        // false negative below (the tool simply not being discoverable at
        // all) would look identical to genuine scope isolation.
        $ownerResults = $this->searchResultsFor($this->userA, 'echo');
        $this->assertNotNull(
            collect($ownerResults)->firstWhere('operationId', $tool->synthetic_operation_id),
            'the owning user must be able to find their own server\'s tool; got: '.json_encode($ownerResults),
        );

        // The other user's own conversation, searching with the identical
        // query text that matched for the owner above, must never see it
        // (FR-017, Acceptance Scenario 3).
        $otherUserResults = $this->searchResultsFor($this->userB, 'echo');
        $match = collect($otherUserResults)->firstWhere('operationId', $tool->synthetic_operation_id);

        $this->assertNull(
            $match,
            'a server scoped to one user\'s account must never surface its tools to a different user\'s search_operations call, even when their own query text would otherwise match; got: '.json_encode($otherUserResults),
        );
    }
}
