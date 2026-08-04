<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\ServerStatus;
use ClarionApp\LlmClient\Services\ServerStatusProjector;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Test;

/**
 * Feature tests for RefreshServerModelsJob.
 *
 * Tests all six classification categories and llm_models reconciliation.
 */
class RefreshServerModelsJobTest extends TestCase
{
    private Server $server;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->server = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Test Server',
            'server_url' => 'http://localhost:8081',
            'token' => 'test-token',
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

    /**
     * 2xx with >= 1 model → models_updated / reachable.
     */
    #[Test]
    public function success_response_classifies_models_updated(): void
    {
        $modelsJson = json_encode([
            'data' => [
                ['id' => 'gpt-4', 'object' => 'model'],
                ['id' => 'gpt-3.5-turbo', 'object' => 'model'],
            ],
        ]);

        $this->mockHttpClient($modelsJson, 200);

        $job = new \ClarionApp\LlmClient\Jobs\RefreshServerModelsJob(
            $this->server->id,
            $this->user->id
        );
        $job->handle(app(\ClarionApp\LlmClient\Services\EndpointResolver::class));

        $status = ServerStatus::where('server_id', $this->server->id)->first();
        $this->assertNotNull($status);
        $this->assertEquals('models_updated', $status->last_outcome);
        $this->assertEquals('reachable', $status->connection_status);
        $this->assertEquals(2, $status->model_count);
        $this->assertEquals($this->user->id, $status->triggered_by);
        $this->assertNotNull($status->refresh_finished_at);

        // Models reconciled
        $modelNames = LanguageModel::where('server_id', $this->server->id)
            ->pluck('name')
            ->sort()
            ->values();
        $this->assertEquals(['gpt-3.5-turbo', 'gpt-4'], $modelNames->toArray());
    }

    /**
     * 2xx with 0 models → zero_models / reachable.
     */
    #[Test]
    public function empty_model_list_classifies_zero_models(): void
    {
        $modelsJson = json_encode(['data' => []]);
        $this->mockHttpClient($modelsJson, 200);

        $job = new \ClarionApp\LlmClient\Jobs\RefreshServerModelsJob(
            $this->server->id,
            $this->user->id
        );
        $job->handle(app(\ClarionApp\LlmClient\Services\EndpointResolver::class));

        $status = ServerStatus::where('server_id', $this->server->id)->first();
        $this->assertEquals('zero_models', $status->last_outcome);
        $this->assertEquals('reachable', $status->connection_status);
        $this->assertEquals(0, $status->model_count);
    }

    /**
     * 401 → auth_rejected / auth_rejected.
     */
    #[Test]
    public function unauthorized_response_classifies_auth_rejected(): void
    {
        $this->mockHttpClient('{"error":{"message":"Invalid API key"}}', 401);

        $job = new \ClarionApp\LlmClient\Jobs\RefreshServerModelsJob(
            $this->server->id,
            $this->user->id
        );
        $job->handle(app(\ClarionApp\LlmClient\Services\EndpointResolver::class));

        $status = ServerStatus::where('server_id', $this->server->id)->first();
        $this->assertEquals('auth_rejected', $status->last_outcome);
        $this->assertEquals('auth_rejected', $status->connection_status);
    }

    /**
     * 403 → auth_rejected / auth_rejected.
     */
    #[Test]
    public function forbidden_response_classifies_auth_rejected(): void
    {
        $this->mockHttpClient('{"error":{"message":"Forbidden"}}', 403);

        $job = new \ClarionApp\LlmClient\Jobs\RefreshServerModelsJob(
            $this->server->id,
            $this->user->id
        );
        $job->handle(app(\ClarionApp\LlmClient\Services\EndpointResolver::class));

        $status = ServerStatus::where('server_id', $this->server->id)->first();
        $this->assertEquals('auth_rejected', $status->last_outcome);
        $this->assertEquals('auth_rejected', $status->connection_status);
    }

    /**
     * Other 4xx/5xx → http_error / unreachable.
     */
    #[Test]
    public function server_error_classifies_http_error(): void
    {
        $this->mockHttpClient('{"error":{"message":"Internal error"}}', 500);

        $job = new \ClarionApp\LlmClient\Jobs\RefreshServerModelsJob(
            $this->server->id,
            $this->user->id
        );
        $job->handle(app(\ClarionApp\LlmClient\Services\EndpointResolver::class));

        $status = ServerStatus::where('server_id', $this->server->id)->first();
        $this->assertEquals('http_error', $status->last_outcome);
        $this->assertEquals('unreachable', $status->connection_status);
    }

    /**
     * Connection/DNS/TLS/timeout → unreachable / unreachable.
     */
    #[Test]
    public function connection_error_classifies_unreachable(): void
    {
        $this->mockConnectionError();

        $job = new \ClarionApp\LlmClient\Jobs\RefreshServerModelsJob(
            $this->server->id,
            $this->user->id
        );
        $job->handle(app(\ClarionApp\LlmClient\Services\EndpointResolver::class));

        $status = ServerStatus::where('server_id', $this->server->id)->first();
        $this->assertEquals('unreachable', $status->last_outcome);
        $this->assertEquals('unreachable', $status->connection_status);
    }

    /**
     * Model reconciliation: creates missing, soft-deletes removed.
     */
    #[Test]
    public function reconciles_models_create_missing_and_soft_delete_removed(): void
    {
        // Seed existing models
        LanguageModel::create([
            'id' => (string) Str::uuid(),
            'server_id' => $this->server->id,
            'name' => 'gpt-4',
        ]);
        LanguageModel::create([
            'id' => (string) Str::uuid(),
            'server_id' => $this->server->id,
            'name' => 'text-davinci-003', // Will be removed
        ]);

        $modelsJson = json_encode([
            'data' => [
                ['id' => 'gpt-4', 'object' => 'model'],           // Keep
                ['id' => 'gpt-3.5-turbo', 'object' => 'model'],   // New
                ['id' => 'gpt-4-turbo', 'object' => 'model'],     // New
            ],
        ]);

        $this->mockHttpClient($modelsJson, 200);

        $job = new \ClarionApp\LlmClient\Jobs\RefreshServerModelsJob(
            $this->server->id,
            $this->user->id
        );
        $job->handle(app(\ClarionApp\LlmClient\Services\EndpointResolver::class));

        // Active models
        $activeNames = LanguageModel::where('server_id', $this->server->id)
            ->pluck('name')
            ->sort()
            ->values();
        $this->assertEquals(['gpt-3.5-turbo', 'gpt-4', 'gpt-4-turbo'], $activeNames->toArray());

        // text-davinci-003 should be soft-deleted
        $deleted = LanguageModel::withTrashed()
            ->where('server_id', $this->server->id)
            ->where('name', 'text-davinci-003')
            ->first();
        $this->assertNotNull($deleted);
        $this->assertNotNull($deleted->deleted_at);
    }

    /**
     * failed() writes a terminal row.
     */
    #[Test]
    public function failed_method_writes_terminal_status(): void
    {
        $job = new \ClarionApp\LlmClient\Jobs\RefreshServerModelsJob(
            $this->server->id,
            $this->user->id
        );

        // Simulate the refresh_started_at being stamped
        ServerStatus::create([
            'server_id' => $this->server->id,
            'connection_status' => 'never_checked',
            'refresh_started_at' => now(),
            'triggered_by' => $this->user->id,
        ]);

        $exception = new \RuntimeException('Queue worker crashed');
        $job->failed($exception);

        $status = ServerStatus::where('server_id', $this->server->id)->first();
        $this->assertEquals('unreachable', $status->last_outcome);
        $this->assertEquals('unreachable', $status->connection_status);
        $this->assertStringContainsString('Queue worker crashed', $status->last_error);
        $this->assertNotNull($status->refresh_finished_at);
    }

    // ─── Helpers ───

    /**
     * Mock the HTTP client for the Guzzle request.
     */
    private function mockHttpClient(string $body, int $statusCode): void
    {
        $mock = new MockHandler([
            new Response($statusCode, ['Content-Type' => 'application/json'], $body),
        ]);
        $handlerStack = HandlerStack::create($mock);

        $this->app->instance(GuzzleClient::class, new GuzzleClient([
            'handler' => $handlerStack,
        ]));
    }

    /**
     * Mock a connection error (DNS resolution failure, etc.).
     */
    private function mockConnectionError(): void
    {
        $request = new PsrRequest('GET', 'http://localhost:8081/v1/models');
        $exception = new ConnectException(
            'cURL error 6: Could not resolve host',
            $request,
        );

        $mock = new MockHandler([$exception]);
        $handlerStack = HandlerStack::create($mock);

        $this->app->instance(GuzzleClient::class, new GuzzleClient([
            'handler' => $handlerStack,
        ]));
    }
}
