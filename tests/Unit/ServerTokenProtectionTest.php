<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ServerTokenProtectionTest extends TestCase
{
    use RefreshDatabase;

    /** @test T035 — Server JSON serialization excludes token field */
    public function server_json_excludes_token()
    {
        $server = Server::create([
            'name' => 'TestServer',
            'server_url' => 'http://localhost:11434/v1/chat/completions',
            'token' => 'sk-secret-token-value',
        ]);

        $json = $server->toArray();
        $this->assertArrayNotHasKey('token', $json);
    }

    /** @test T036 — Server token is encrypted in database, decrypted transparently */
    public function server_token_is_encrypted_at_rest()
    {
        $plainToken = 'sk-secret-token-value';
        $server = Server::create([
            'name' => 'TestServer',
            'server_url' => 'http://localhost:11434/v1/chat/completions',
            'token' => $plainToken,
        ]);

        // Read raw from DB — should not be the plaintext
        $rawValue = \DB::table('llm_servers')->where('id', $server->id)->value('token');
        $this->assertNotEquals($plainToken, $rawValue);

        // Access via model — should be decrypted
        $server->refresh();
        $this->assertEquals($plainToken, $server->token);
    }
}
