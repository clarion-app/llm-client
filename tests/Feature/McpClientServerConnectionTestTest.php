<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Jobs\TestMcpClientConnectionJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ReferenceMcpServer\Protocol;
use Tests\Fixtures\ReferenceMcpServer\ReferenceMcpServer;
use Tests\TestCase;

/**
 * The two connection-test endpoints (contracts/connection-test-api.md),
 * proven against the real Tests\Fixtures\ReferenceMcpServer fixture --
 * real loopback HTTP, a real ShouldQueue job run through the 'sync' queue
 * connection this suite already runs under (the same mechanism
 * McpClientServerCredentialHiddenTest's own store()-then-show() tests
 * already rely on) -- never a mocked transport. Covers FR-003/FR-010: a
 * test result must be specific, and the three failure categories
 * (unreachable/auth_failed/protocol_error) must be genuinely
 * distinguishable, not collapsed to one generic failure.
 */
class McpClientServerConnectionTestTest extends TestCase
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

    #[Test]
    public function test_connection_returns_202_pending_and_dispatches_the_job_with_the_returned_id(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/mcp-client-server/test-connection', [
            'transport' => 'streamable_http',
            'url' => 'https://mcp.example.com/mcp',
            'credential' => null,
        ]);

        $response->assertStatus(202);
        $response->assertJson(['status' => 'pending']);
        $testId = $response->json('id');
        $this->assertIsString($testId);
        $this->assertNotEmpty($testId);

        Bus::assertDispatched(TestMcpClientConnectionJob::class, fn (TestMcpClientConnectionJob $job) => $job->testId === $testId);
    }

    #[Test]
    public function a_reachable_server_test_passes_with_a_positive_tool_count(): void
    {
        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp(Protocol::MODE_HAPPY_PATH);

        $startResponse = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/mcp-client-server/test-connection', [
            'transport' => 'streamable_http',
            'url' => $url,
            'credential' => null,
        ]);
        $startResponse->assertStatus(202);
        $testId = $startResponse->json('id');

        $showResponse = $this->actingAs($this->user)->getJson("/api/clarion-app/llm-client/mcp-client-server/test-connection/{$testId}");

        $showResponse->assertStatus(200);
        $data = $showResponse->json();
        $this->assertSame('passed', $data['status']);
        $this->assertNull($data['failure_category']);
        $this->assertGreaterThan(0, $data['tool_count']);
    }

    #[Test]
    public function an_unreachable_server_test_fails_with_a_specific_unreachable_reason(): void
    {
        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp(Protocol::MODE_UNREACHABLE);

        $startResponse = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/mcp-client-server/test-connection', [
            'transport' => 'streamable_http',
            'url' => $url,
            'credential' => null,
        ]);
        $testId = $startResponse->json('id');

        $showResponse = $this->actingAs($this->user)->getJson("/api/clarion-app/llm-client/mcp-client-server/test-connection/{$testId}");

        $showResponse->assertStatus(200);
        $data = $showResponse->json();
        $this->assertSame('failed', $data['status']);
        $this->assertSame('unreachable', $data['failure_category']);
        $this->assertNotEmpty($data['message']);
        $this->assertNull($data['tool_count']);
    }

    #[Test]
    public function a_wrong_credential_is_reported_as_auth_failed_never_leaking_the_attempted_value(): void
    {
        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp(Protocol::MODE_HAPPY_PATH, ['expected_token' => 'the-real-token']);

        $startResponse = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/mcp-client-server/test-connection', [
            'transport' => 'streamable_http',
            'url' => $url,
            'credential' => 'the-wrong-token',
        ]);
        $testId = $startResponse->json('id');

        $showResponse = $this->actingAs($this->user)->getJson("/api/clarion-app/llm-client/mcp-client-server/test-connection/{$testId}");

        $showResponse->assertStatus(200);
        $data = $showResponse->json();
        $this->assertSame('failed', $data['status']);
        $this->assertSame('auth_failed', $data['failure_category']);
        $this->assertStringNotContainsString('the-wrong-token', json_encode($data));
        $this->assertNull($data['tool_count']);
    }

    #[Test]
    public function a_misbehaving_server_is_reported_as_protocol_error_distinct_from_unreachable_and_auth_failed(): void
    {
        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp(Protocol::MODE_MISBEHAVING);

        $startResponse = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/mcp-client-server/test-connection', [
            'transport' => 'streamable_http',
            'url' => $url,
            'credential' => null,
        ]);
        $testId = $startResponse->json('id');

        $showResponse = $this->actingAs($this->user)->getJson("/api/clarion-app/llm-client/mcp-client-server/test-connection/{$testId}");

        $showResponse->assertStatus(200);
        $data = $showResponse->json();
        $this->assertSame('failed', $data['status']);
        $this->assertSame('protocol_error', $data['failure_category']);
        $this->assertNotSame('unreachable', $data['failure_category']);
        $this->assertNotSame('auth_failed', $data['failure_category']);
        $this->assertNotEmpty($data['message']);
    }
}
