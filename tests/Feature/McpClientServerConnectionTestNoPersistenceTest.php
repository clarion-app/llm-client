<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\McpClientServer;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ReferenceMcpServer\Protocol;
use Tests\Fixtures\ReferenceMcpServer\ReferenceMcpServer;
use Tests\TestCase;

/**
 * FR-004/FR-012: a test-connection attempt, whatever its outcome, must
 * never leave a trace in mcp_client_servers or in GET /mcp-client-server's
 * response -- only an explicit store() call ever creates a server row.
 * Proven across every outcome T026 exercises (passed, and each of the
 * three failure categories), not just one.
 */
class McpClientServerConnectionTestNoPersistenceTest extends TestCase
{
    private User $user;

    private ?ReferenceMcpServer $referenceServer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['password' => Hash::make('password')]);
    }

    protected function tearDown(): void
    {
        $this->referenceServer?->stopHttp();
        $this->referenceServer = null;

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: array}>
     */
    public static function outcomeProvider(): array
    {
        return [
            'passed' => [Protocol::MODE_HAPPY_PATH, []],
            'unreachable' => [Protocol::MODE_UNREACHABLE, []],
            'auth_failed' => [Protocol::MODE_HAPPY_PATH, ['expected_token' => 'the-real-token']],
            'protocol_error' => [Protocol::MODE_MISBEHAVING, []],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('outcomeProvider')]
    public function a_test_connection_attempt_leaves_zero_rows_in_the_server_table_and_an_unchanged_list(string $mode, array $options): void
    {
        $beforeListResponse = $this->actingAs($this->user)->getJson('/api/clarion-app/llm-client/mcp-client-server');
        $beforeListResponse->assertStatus(200);
        $beforeList = $beforeListResponse->json();

        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp($mode, $options);

        $credential = isset($options['expected_token']) ? 'the-wrong-token' : null;

        $startResponse = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/mcp-client-server/test-connection', [
            'transport' => 'streamable_http',
            'url' => $url,
            'credential' => $credential,
        ]);
        $startResponse->assertStatus(202);
        $testId = $startResponse->json('id');

        // Poll once -- 'sync' queue connection already ran the job inline,
        // so the row is already terminal by the time this returns.
        $this->actingAs($this->user)->getJson("/api/clarion-app/llm-client/mcp-client-server/test-connection/{$testId}")
            ->assertStatus(200);

        $this->assertSame(0, McpClientServer::query()->count(), "a test-connection attempt in mode {$mode} must never create a mcp_client_servers row");

        $afterListResponse = $this->actingAs($this->user)->getJson('/api/clarion-app/llm-client/mcp-client-server');
        $afterListResponse->assertStatus(200);

        $this->assertSame($beforeList, $afterListResponse->json(), "the server list must be byte-for-byte unchanged after an abandoned test-connection attempt in mode {$mode}");
    }
}
