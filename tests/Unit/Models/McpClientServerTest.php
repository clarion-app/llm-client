<?php

namespace ClarionApp\LlmClient\Tests\Unit\Models;

use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\ValueObjects\McpTransportKind;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * McpClientServer -- the bridged, user-owned connection record for a
 * third-party MCP server. Follows ServerProviderTypeTest's own approach
 * for the graceful-degradation case and ConversationScopeTest's own
 * approach for the eligibility-scope case.
 */
class McpClientServerTest extends TestCase
{
    #[Test]
    public function eligible_for_returns_the_callers_own_server_and_the_installation_scoped_one_but_not_another_users(): void
    {
        $userId = (string) Str::uuid();
        $otherUserId = (string) Str::uuid();

        $own = McpClientServer::create([
            'name' => 'My Server',
            'transport' => McpTransportKind::StreamableHttp,
            'url' => 'https://example.test/mcp',
            'user_id' => $userId,
        ]);

        $installation = McpClientServer::create([
            'name' => 'Shared Server',
            'transport' => McpTransportKind::StreamableHttp,
            'url' => 'https://shared.example.test/mcp',
            'user_id' => McpClientServer::INSTALLATION_SCOPE_ID,
        ]);

        $someoneElses = McpClientServer::create([
            'name' => 'Not Mine',
            'transport' => McpTransportKind::StreamableHttp,
            'url' => 'https://other.example.test/mcp',
            'user_id' => $otherUserId,
        ]);

        $results = McpClientServer::eligibleFor($userId)->get();

        $this->assertTrue($results->contains('id', $own->id), "the caller's own server must be included");
        $this->assertTrue($results->contains('id', $installation->id), 'an installation-scoped server must be included');
        $this->assertFalse($results->contains('id', $someoneElses->id), "another user's server must be excluded");
    }

    #[Test]
    public function credential_never_appears_in_array_or_json_serialization(): void
    {
        $server = McpClientServer::create([
            'name' => 'Credentialed Server',
            'transport' => McpTransportKind::StreamableHttp,
            'url' => 'https://example.test/mcp',
            'credential' => 'super-secret-token',
            'user_id' => (string) Str::uuid(),
        ]);

        $server->refresh();

        $this->assertArrayNotHasKey('credential', $server->toArray());
        $this->assertStringNotContainsString('super-secret-token', $server->toJson());
        // Still usable internally -- only excluded from serialization.
        $this->assertSame('super-secret-token', $server->credential);
    }

    #[Test]
    public function an_unrecognized_transport_value_degrades_to_streamable_http_instead_of_fataling(): void
    {
        $server = new McpClientServer();
        $server->setRawAttributes(['transport' => 'some-legacy-value-nobody-uses']);

        $this->assertSame(McpTransportKind::StreamableHttp, $server->transport);
    }

    #[Test]
    public function a_null_transport_value_degrades_to_streamable_http(): void
    {
        $server = new McpClientServer();
        $server->setRawAttributes(['transport' => null]);

        $this->assertSame(McpTransportKind::StreamableHttp, $server->transport);
    }

    #[Test]
    public function transport_is_not_a_native_enum_cast(): void
    {
        // A cast would fatal on an unrecognized/legacy value; the accessor
        // falls back gracefully instead. See getTransportAttribute()'s own
        // docblock.
        $server = new McpClientServer();

        $this->assertArrayNotHasKey('transport', $server->getCasts());
    }
}
