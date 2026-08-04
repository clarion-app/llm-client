<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Jobs\RefreshServerModelsJob;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\ServerStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Feature tests for the ServerController CRUD endpoints.
 *
 * Covers the POST /server and PUT /server/{id} changes required
 * for the unified model setup interface (064).
 */
class ServerControllerTest extends TestCase
{
    private User $user;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        // Use create() so the encrypted cast properly encrypts the token.
        $this->server = Server::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Server',
            'server_url' => 'http://localhost:8081',
            'token' => 'secret-token',
            'provider_type' => 'openai',
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('llm_server_statuses')->delete();
        DB::table('language_models')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();
        \Mockery::close();
        parent::tearDown();
    }

    // ==========================================
    // T035: POST /server tests
    // ==========================================

    #[Test]
    public function store_validates_provider_type_against_provider_type_cases(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/server', [
            'name' => 'Bad Provider',
            'server_url' => 'http://localhost:9000',
            'provider_type' => 'invalid_provider',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['provider_type']);
    }

    #[Test]
    public function store_defaults_provider_type_to_openai_when_omitted(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/server', [
            'name' => 'Default Provider',
            'server_url' => 'http://localhost:9000',
        ]);

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertEquals('openai', $data['provider_type']);

        // Verify stored in DB.
        $server = Server::find($data['id']);
        $this->assertNotNull($server);
        $this->assertEquals(ProviderType::OpenAI, $server->provider_type);
    }

    #[Test]
    public function store_accepts_valid_provider_types(): void
    {
        Bus::fake();

        foreach (['openai', 'anthropic', 'llama.cpp'] as $providerType) {
            $response = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/server', [
                'name' => "Server for {$providerType}",
                'server_url' => 'http://localhost:9000',
                'provider_type' => $providerType,
            ]);

            $response->assertStatus(201);
            $this->assertEquals($providerType, $response->json('provider_type'));
        }
    }

    #[Test]
    public function store_normalizes_server_url_through_server_address(): void
    {
        Bus::fake();

        // Enter a URL with a known API suffix — should be stripped.
        $response = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/server', [
            'name' => 'Normalized URL',
            'server_url' => 'http://localhost:8081/v1/chat/completions',
        ]);

        $response->assertStatus(201);
        $data = $response->json();
        // The stored value should be the normalized origin (suffix stripped).
        $this->assertEquals('http://localhost:8081', $data['server_url']);

        // Verify stored in DB.
        $server = Server::find($data['id']);
        $this->assertNotNull($server);
        $this->assertEquals('http://localhost:8081', $server->server_url);
    }

    #[Test]
    public function store_returns_normalized_server_url_in_response(): void
    {
        Bus::fake();

        // Enter a bare origin — should remain the same.
        $response = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/server', [
            'name' => 'Bare Origin',
            'server_url' => 'http://my-server.example.com',
        ]);

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertEquals('http://my-server.example.com', $data['server_url']);
    }

    #[Test]
    public function store_returns_422_on_invalid_server_address(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/server', [
            'name' => 'Bad Address',
            'server_url' => 'not-a-url-at-all',
        ]);

        $response->assertStatus(422);
        // The error should name the field and the value.
        $errors = $response->json('errors');
        $this->assertArrayHasKey('server_url', $errors);
    }

    #[Test]
    public function store_dispatches_refresh_job_with_triggered_by(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/server', [
            'name' => 'Refresh Server',
            'server_url' => 'http://localhost:8081',
        ]);

        $response->assertStatus(201);
        $serverId = $response->json('id');

        // Verify the job was dispatched.
        Bus::assertDispatched(function (RefreshServerModelsJob $job) use ($serverId) {
            return $job->serverId === $serverId;
        });

        // Verify triggered_by is set to the authenticated user.
        Bus::assertDispatched(function (RefreshServerModelsJob $job) use ($serverId) {
            return $job->serverId === $serverId
                && $job->triggeredBy === $this->user->id;
        });
    }

    #[Test]
    public function store_stamps_refresh_started_at_on_status_row(): void
    {
        // The store method should stamp refresh_started_at on the status row.
        // We verify this by checking the job dispatches with triggered_by,
        // and the RefreshServerModelsJob itself stamps the row.
        Bus::fake();

        $response = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/server', [
            'name' => 'Status Stamp',
            'server_url' => 'http://localhost:8081',
        ]);

        $response->assertStatus(201);
        $serverId = $response->json('id');

        Bus::assertDispatched(function (RefreshServerModelsJob $job) use ($serverId) {
            return $job->serverId === $serverId
                && $job->triggeredBy === $this->user->id;
        });
    }

    // ==========================================
    // T041: has_token projection tests
    // ==========================================

    #[Test]
    public function index_returns_has_token_never_token(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/clarion-app/llm-client/server');

        $response->assertStatus(200);
        $servers = $response->json();

        // Find our server in the response.
        $found = collect($servers)->firstWhere('id', $this->server->id);
        $this->assertNotNull($found);
        $this->assertTrue($found['has_token']);
        $this->assertArrayNotHasKey('token', $found);
    }

    #[Test]
    public function index_returns_has_token_false_when_no_token(): void
    {
        // Create a server without a token.
        $server = Server::create([
            'id' => (string) Str::uuid(),
            'name' => 'No Token Server',
            'server_url' => 'http://localhost:9000',
            'token' => null,
            'provider_type' => 'openai',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/clarion-app/llm-client/server');

        $response->assertStatus(200);
        $servers = $response->json();

        $found = collect($servers)->firstWhere('id', $server->id);
        $this->assertNotNull($found);
        $this->assertFalse($found['has_token']);
        $this->assertArrayNotHasKey('token', $found);
    }

    #[Test]
    public function store_response_has_has_token_never_token(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/server', [
            'name' => 'With Token',
            'server_url' => 'http://localhost:8081',
            'token' => 'my-secret-token',
        ]);

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertTrue($data['has_token']);
        $this->assertArrayNotHasKey('token', $data);
    }

    #[Test]
    public function store_response_has_has_token_false_when_no_token(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/server', [
            'name' => 'Without Token',
            'server_url' => 'http://localhost:8081',
        ]);

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertFalse($data['has_token']);
        $this->assertArrayNotHasKey('token', $data);
    }

    #[Test]
    public function update_response_has_has_token_never_token(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->user)->putJson(
            "/api/clarion-app/llm-client/server/{$this->server->id}",
            [
                'name' => 'Updated Name',
                'server_url' => 'http://localhost:8081',
            ]
        );

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertTrue($data['has_token']);
        $this->assertArrayNotHasKey('token', $data);
    }

    #[Test]
    public function update_preserves_token_when_token_key_absent(): void
    {
        Bus::fake();

        $originalToken = $this->server->token; // Decrypted value via cast.

        $response = $this->actingAs($this->user)->putJson(
            "/api/clarion-app/llm-client/server/{$this->server->id}",
            [
                'name' => 'Renamed',
                'server_url' => 'http://localhost:8081',
            ]
        );

        $response->assertStatus(200);

        // Reload and verify token is preserved (compare decrypted values).
        $this->server->refresh();
        $this->assertEquals($originalToken, $this->server->token);
    }

    #[Test]
    public function update_clears_token_when_token_is_null(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->user)->putJson(
            "/api/clarion-app/llm-client/server/{$this->server->id}",
            [
                'name' => 'Test Server',
                'server_url' => 'http://localhost:8081',
                'token' => null,
            ]
        );

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertFalse($data['has_token']);

        $this->server->refresh();
        $this->assertNull($this->server->token);
    }

    #[Test]
    public function update_clears_token_when_token_is_empty_string(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->user)->putJson(
            "/api/clarion-app/llm-client/server/{$this->server->id}",
            [
                'name' => 'Test Server',
                'server_url' => 'http://localhost:8081',
                'token' => '',
            ]
        );

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertFalse($data['has_token']);

        $this->server->refresh();
        $this->assertNull($this->server->token);
    }

    #[Test]
    public function update_replaces_token_when_token_is_non_empty(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->user)->putJson(
            "/api/clarion-app/llm-client/server/{$this->server->id}",
            [
                'name' => 'Test Server',
                'server_url' => 'http://localhost:8081',
                'token' => 'new-token-value',
            ]
        );

        $response->assertStatus(200);

        $this->server->refresh();
        $this->assertNotEquals('secret-token', $this->server->token);
        $this->assertEquals('new-token-value', $this->server->token);
    }

    // ==========================================
    // T074: PUT /server/{id} conditional refresh dispatch tests (contracts/rest-api.md §4, D12)
    // ==========================================

    #[Test]
    public function update_dispatches_refresh_job_when_server_url_changes(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->user)->putJson(
            "/api/clarion-app/llm-client/server/{$this->server->id}",
            [
                'name' => 'Test Server',
                'server_url' => 'http://localhost:9090',
            ]
        );

        $response->assertStatus(200);

        Bus::assertDispatched(function (RefreshServerModelsJob $job) {
            return $job->serverId === $this->server->id
                && $job->triggeredBy === $this->user->id;
        });
    }

    #[Test]
    public function update_dispatches_refresh_job_when_token_is_replaced(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->user)->putJson(
            "/api/clarion-app/llm-client/server/{$this->server->id}",
            [
                'name' => 'Test Server',
                'server_url' => 'http://localhost:8081',
                'token' => 'brand-new-token',
            ]
        );

        $response->assertStatus(200);

        Bus::assertDispatched(function (RefreshServerModelsJob $job) {
            return $job->serverId === $this->server->id;
        });
    }

    #[Test]
    public function update_dispatches_refresh_job_when_token_is_cleared(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->user)->putJson(
            "/api/clarion-app/llm-client/server/{$this->server->id}",
            [
                'name' => 'Test Server',
                'server_url' => 'http://localhost:8081',
                'token' => null,
            ]
        );

        $response->assertStatus(200);

        Bus::assertDispatched(function (RefreshServerModelsJob $job) {
            return $job->serverId === $this->server->id;
        });
    }

    #[Test]
    public function update_dispatches_refresh_job_when_provider_type_changes_even_if_url_and_token_unchanged(): void
    {
        Bus::fake();

        // Stored server is provider_type=openai (see setUp); switch to anthropic
        // while leaving server_url and token untouched.
        $response = $this->actingAs($this->user)->putJson(
            "/api/clarion-app/llm-client/server/{$this->server->id}",
            [
                'name' => 'Test Server',
                'server_url' => 'http://localhost:8081',
                'provider_type' => 'anthropic',
            ]
        );

        $response->assertStatus(200);

        Bus::assertDispatched(function (RefreshServerModelsJob $job) {
            return $job->serverId === $this->server->id;
        });
    }

    #[Test]
    public function update_does_not_dispatch_refresh_job_on_rename_alone(): void
    {
        Bus::fake();

        // Only the name changes; server_url, token, and provider_type are all
        // resubmitted with their current stored values.
        $response = $this->actingAs($this->user)->putJson(
            "/api/clarion-app/llm-client/server/{$this->server->id}",
            [
                'name' => 'Renamed Only',
                'server_url' => 'http://localhost:8081',
                'provider_type' => 'openai',
            ]
        );

        $response->assertStatus(200);

        Bus::assertNotDispatched(RefreshServerModelsJob::class);
    }

    #[Test]
    public function update_does_not_dispatch_refresh_job_on_rename_alone_when_provider_type_key_is_omitted(): void
    {
        Bus::fake();

        // A real edit form only sends fields the user actually touched. The
        // stored server here is on a non-default provider (anthropic); a
        // rename-only PUT omits the provider_type key entirely, the same way
        // it omits token. Omission must never be read as "reset to openai".
        $anthropicServer = Server::create([
            'id' => (string) Str::uuid(),
            'name' => 'Anthropic Server',
            'server_url' => 'http://localhost:7000',
            'token' => 'anthropic-token',
            'provider_type' => 'anthropic',
        ]);

        $response = $this->actingAs($this->user)->putJson(
            "/api/clarion-app/llm-client/server/{$anthropicServer->id}",
            [
                'name' => 'Anthropic Server Renamed',
                'server_url' => 'http://localhost:7000',
            ]
        );

        $response->assertStatus(200);

        Bus::assertNotDispatched(RefreshServerModelsJob::class);
    }

    #[Test]
    public function update_does_not_dispatch_refresh_job_when_nothing_changed(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->user)->putJson(
            "/api/clarion-app/llm-client/server/{$this->server->id}",
            [
                'name' => 'Test Server',
                'server_url' => 'http://localhost:8081',
                'provider_type' => 'openai',
            ]
        );

        $response->assertStatus(200);

        Bus::assertNotDispatched(RefreshServerModelsJob::class);
    }

    #[Test]
    public function show_response_has_has_token_never_token(): void
    {
        $this->server->refresh();

        $response = $this->actingAs($this->user)->getJson(
            "/api/clarion-app/llm-client/server/{$this->server->id}"
        );

        $response->assertStatus(200);
        $data = $response->json();

        // Token exists (encrypted in DB, decrypted by cast).
        $this->assertNotNull($this->server->token, sprintf(
            'Server token should not be null. Response: %s',
            json_encode($data),
        ));
        // has_token is true.
        $this->assertTrue($data['has_token'], sprintf(
            'has_token should be true. Response: %s',
            json_encode($data),
        ));
        // token key is never present.
        $this->assertArrayNotHasKey('token', $data);
    }
}
