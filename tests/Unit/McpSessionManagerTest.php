<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\McpSessionManager;
use ClarionApp\LlmClient\Models\McpSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Carbon\Carbon;

use PHPUnit\Framework\Attributes\Test;

class McpSessionManagerTest extends TestCase
{
    use RefreshDatabase;

    private McpSessionManager $manager;
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new McpSessionManager();
        $this->userId = (string) Str::uuid();
    }

    #[Test]
    public function creates_session_with_valid_params()
    {
        $session = $this->manager->createSession(
            $this->userId,
            '2025-03-26',
            'test-client',
            '1.0.0',
            ['tools' => true]
        );

        $this->assertInstanceOf(McpSession::class, $session);
        $this->assertEquals($this->userId, $session->user_id);
        $this->assertEquals('2025-03-26', $session->protocol_version);
        $this->assertEquals('test-client', $session->client_name);
        $this->assertEquals('1.0.0', $session->client_version);
        $this->assertEquals(['tools' => true], $session->capabilities);
        $this->assertNotNull($session->id);
    }

    #[Test]
    public function creates_session_with_nullable_fields()
    {
        $session = $this->manager->createSession(
            $this->userId,
            '2025-03-26'
        );

        $this->assertNull($session->client_name);
        $this->assertNull($session->client_version);
        $this->assertNull($session->capabilities);
    }

    #[Test]
    public function validates_existing_session_for_correct_user()
    {
        $session = $this->manager->createSession($this->userId, '2025-03-26');

        $validated = $this->manager->validateSession($session->id, $this->userId);

        $this->assertInstanceOf(McpSession::class, $validated);
        $this->assertEquals($session->id, $validated->id);
    }

    #[Test]
    public function rejects_session_for_wrong_user()
    {
        $session = $this->manager->createSession($this->userId, '2025-03-26');
        $otherUserId = (string) Str::uuid();

        $validated = $this->manager->validateSession($session->id, $otherUserId);

        $this->assertNull($validated);
    }

    #[Test]
    public function rejects_nonexistent_session()
    {
        $validated = $this->manager->validateSession((string) Str::uuid(), $this->userId);

        $this->assertNull($validated);
    }

    #[Test]
    public function rejects_soft_deleted_session()
    {
        $session = $this->manager->createSession($this->userId, '2025-03-26');
        $this->manager->terminateSession($session->id, $this->userId);

        $validated = $this->manager->validateSession($session->id, $this->userId);

        $this->assertNull($validated);
    }

    #[Test]
    public function terminates_session_via_soft_delete()
    {
        $session = $this->manager->createSession($this->userId, '2025-03-26');

        $result = $this->manager->terminateSession($session->id, $this->userId);

        $this->assertTrue($result);
        $this->assertSoftDeleted('mcp_sessions', ['id' => $session->id]);
    }

    #[Test]
    public function terminate_returns_false_for_wrong_user()
    {
        $session = $this->manager->createSession($this->userId, '2025-03-26');
        $otherUserId = (string) Str::uuid();

        $result = $this->manager->terminateSession($session->id, $otherUserId);

        $this->assertFalse($result);
    }

    #[Test]
    public function touch_session_updates_updated_at()
    {
        $session = $this->manager->createSession($this->userId, '2025-03-26');
        $originalUpdatedAt = $session->updated_at;

        Carbon::setTestNow(Carbon::now()->addMinutes(5));

        $this->manager->touchSession($session->id);

        $session->refresh();
        $this->assertGreaterThan($originalUpdatedAt, $session->updated_at);

        Carbon::setTestNow();
    }
}
