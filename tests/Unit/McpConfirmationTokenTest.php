<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\McpConfirmationToken;
use ClarionApp\LlmClient\Models\McpSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Carbon\Carbon;

class McpConfirmationTokenTest extends TestCase
{
    use RefreshDatabase;

    private McpSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = McpSession::create([
            'user_id' => (string) Str::uuid(),
            'protocol_version' => '2025-03-26',
        ]);
    }

    /** @test */
    public function is_valid_returns_true_for_unused_unexpired_token()
    {
        $token = McpConfirmationToken::create([
            'session_id' => $this->session->id,
            'tool_name' => 'contacts.destroy',
            'arguments_hash' => hash('sha256', 'test'),
            'arguments_snapshot' => ['path_contact' => 'abc'],
            'expires_at' => Carbon::now()->addMinutes(5),
        ]);

        $this->assertTrue($token->isValid(
            $this->session->id,
            'contacts.destroy',
            hash('sha256', 'test')
        ));
    }

    /** @test */
    public function is_valid_returns_false_when_used_at_is_set()
    {
        $token = McpConfirmationToken::create([
            'session_id' => $this->session->id,
            'tool_name' => 'contacts.destroy',
            'arguments_hash' => hash('sha256', 'test'),
            'arguments_snapshot' => ['path_contact' => 'abc'],
            'expires_at' => Carbon::now()->addMinutes(5),
            'used_at' => Carbon::now(),
        ]);

        $this->assertFalse($token->isValid(
            $this->session->id,
            'contacts.destroy',
            hash('sha256', 'test')
        ));
    }

    /** @test */
    public function is_valid_returns_false_when_expired()
    {
        $token = McpConfirmationToken::create([
            'session_id' => $this->session->id,
            'tool_name' => 'contacts.destroy',
            'arguments_hash' => hash('sha256', 'test'),
            'arguments_snapshot' => ['path_contact' => 'abc'],
            'expires_at' => Carbon::now()->subMinutes(1),
        ]);

        $this->assertFalse($token->isValid(
            $this->session->id,
            'contacts.destroy',
            hash('sha256', 'test')
        ));
    }

    /** @test */
    public function is_valid_returns_false_for_wrong_session_id()
    {
        $token = McpConfirmationToken::create([
            'session_id' => $this->session->id,
            'tool_name' => 'contacts.destroy',
            'arguments_hash' => hash('sha256', 'test'),
            'arguments_snapshot' => ['path_contact' => 'abc'],
            'expires_at' => Carbon::now()->addMinutes(5),
        ]);

        $this->assertFalse($token->isValid(
            (string) Str::uuid(),
            'contacts.destroy',
            hash('sha256', 'test')
        ));
    }

    /** @test */
    public function consume_sets_used_at()
    {
        $token = McpConfirmationToken::create([
            'session_id' => $this->session->id,
            'tool_name' => 'contacts.destroy',
            'arguments_hash' => hash('sha256', 'test'),
            'arguments_snapshot' => ['path_contact' => 'abc'],
            'expires_at' => Carbon::now()->addMinutes(5),
        ]);

        $this->assertNull($token->used_at);

        $token->consume();
        $token->refresh();

        $this->assertNotNull($token->used_at);
    }
}
