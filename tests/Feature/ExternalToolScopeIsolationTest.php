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
