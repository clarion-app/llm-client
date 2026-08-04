<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Exceptions\RoleAssignmentFailedException;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\EmbeddingService;
use ClarionApp\LlmClient\Services\RoleResolver;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for EmbeddingService integration with role resolution.
 *
 * Every test drives the real EmbeddingService, not just the resolver: the
 * properties under test (FR-016, FR-017, FR-014) live in the service's
 * branching, so a resolver-only assertion would pass with the service's
 * role branch deleted entirely (quickstart.md's non-vacuousness clause).
 * Each test therefore asserts *which server* the provider was built from
 * and *which model name* reached the provider.
 */
class EmbeddingServiceRoleTest extends TestCase
{
    protected ProviderRegistry $registry;

    /** @var Server[] Servers a provider was resolved for, in call order. */
    protected array $resolvedFor = [];

    /** @var array<int, array{0: array, 1: array}> Arguments each embed() call received. */
    protected array $embedCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = app(ProviderRegistry::class);
        $this->resolvedFor = [];
        $this->embedCalls = [];

        Config::set('llm-client.memory.embedding.enabled', true);
    }

    protected function tearDown(): void
    {
        DB::table('llm_role_assignments')->delete();
        DB::table('language_models')->delete();
        DB::table('llm_servers')->delete();
        parent::tearDown();
    }

    /**
     * Register a provider factory that records the server it was built for and
     * the arguments of every embed() call, so the test can assert *where* the
     * request went rather than only that one was made.
     */
    protected function registerRecordingProvider(?callable $embed = null): void
    {
        $embed ??= fn () => ['embeddings' => [[0.1, 0.2, 0.3]]];

        $this->registry->register(ProviderType::OpenAI, function (Server $server) use ($embed) {
            $this->resolvedFor[] = $server;

            $provider = $this->createMock(LlmProvider::class);
            $provider->method('embed')->willReturnCallback(function (array $inputs, array $options) use ($embed) {
                $this->embedCalls[] = [$inputs, $options];

                return $embed($inputs, $options);
            });

            return $provider;
        });
    }

    protected function makeServer(string $name): Server
    {
        return Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'server_url' => 'https://'.Str::slug($name).'.example.com',
            'provider_type' => 'openai',
        ]);
    }

    protected function service(): EmbeddingService
    {
        return new EmbeddingService($this->registry, app(RoleResolver::class));
    }

    /* -----------------------------------------------------------------
     * Resolved via role takes precedence over config values (FR-016/FR-017)
     * ----------------------------------------------------------------- */

    #[Test]
    public function resolved_via_role_takes_precedence_over_config(): void
    {
        $userId = (string) Str::uuid();
        $roleServer = $this->makeServer('Role Server');
        $configServer = $this->makeServer('Config Server');

        Config::set('llm-client.memory.embedding.server_id', $configServer->id);
        Config::set('llm-client.memory.embedding.model', 'config-embed-model');

        RoleAssignment::create([
            'role' => 'embedding',
            'user_id' => $userId,
            'server_id' => $roleServer->id,
            'model' => 'role-embed-model',
        ]);

        $this->registerRecordingProvider();

        $embedding = $this->service()->generate('hello', null, $userId);

        $this->assertEquals([0.1, 0.2, 0.3], $embedding);

        // The request went to the *assigned* server, not the configured one.
        $this->assertNotEmpty($this->resolvedFor);
        foreach ($this->resolvedFor as $server) {
            $this->assertEquals($roleServer->id, $server->id);
            $this->assertNotEquals($configServer->id, $server->id);
        }

        // ...and carried the assignment's model name, not the config file's.
        $this->assertCount(1, $this->embedCalls);
        $this->assertEquals('role-embed-model', $this->embedCalls[0][1]['model']);
    }

    #[Test]
    public function installation_scope_assignment_is_used_when_user_has_none(): void
    {
        $userId = (string) Str::uuid();
        $installationServer = $this->makeServer('Installation Server');
        $configServer = $this->makeServer('Config Server');

        Config::set('llm-client.memory.embedding.server_id', $configServer->id);
        Config::set('llm-client.memory.embedding.model', 'config-embed-model');

        RoleAssignment::create([
            'role' => 'embedding',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $installationServer->id,
            'model' => 'installation-embed-model',
        ]);

        $this->registerRecordingProvider();

        $this->service()->generate('hello', null, $userId);

        $this->assertEquals($installationServer->id, $this->resolvedFor[0]->id);
        $this->assertEquals('installation-embed-model', $this->embedCalls[0][1]['model']);
    }

    /* -----------------------------------------------------------------
     * Unassigned at both scopes falls back to config value unchanged (FR-017)
     * ----------------------------------------------------------------- */

    #[Test]
    public function unassigned_at_both_scopes_falls_back_to_config(): void
    {
        $userId = (string) Str::uuid();
        $configServer = $this->makeServer('Config Server');

        Config::set('llm-client.memory.embedding.server_id', $configServer->id);
        Config::set('llm-client.memory.embedding.model', 'config-embed-model');

        $this->registerRecordingProvider();

        // No role assignments exist at either scope.
        $this->service()->generate('hello', null, $userId);

        $this->assertEquals($configServer->id, $this->resolvedFor[0]->id);
        $this->assertEquals('config-embed-model', $this->embedCalls[0][1]['model']);
    }

    /* -----------------------------------------------------------------
     * Broken does NOT fall back to config and throws (FR-013/SC-006)
     * ----------------------------------------------------------------- */

    #[Test]
    public function broken_does_not_fall_back_to_config_throws_exception(): void
    {
        $userId = (string) Str::uuid();
        $deletedServer = $this->makeServer('Deleted Server');
        $deletedServer->delete(); // Soft delete

        $configServer = $this->makeServer('Config Server');
        Config::set('llm-client.memory.embedding.server_id', $configServer->id);
        Config::set('llm-client.memory.embedding.model', 'config-embed-model');

        RoleAssignment::create([
            'role' => 'embedding',
            'user_id' => $userId,
            'server_id' => $deletedServer->id,
            'model' => 'vanished-embed-model',
        ]);

        $this->registerRecordingProvider();

        try {
            $this->service()->generate('hello', null, $userId);
            $this->fail('Expected RoleAssignmentFailedException for a broken assignment.');
        } catch (RoleAssignmentFailedException $e) {
            $this->assertEquals(ModelRole::Embedding, $e->role);
            $this->assertEquals('vanished-embed-model', $e->model);
            $this->assertEquals('server deleted', $e->reason);
            $this->assertStringContainsString('vanished-embed-model', $e->getMessage());
            $this->assertStringContainsString('server deleted', $e->getMessage());
        }

        // The config-file fallback must NOT have been reached: a broken
        // assignment is "someone configured it and it broke", not
        // "nobody has configured anything yet" (research.md D7 step 4).
        $this->assertSame([], $this->resolvedFor);
        $this->assertSame([], $this->embedCalls);
    }

    #[Test]
    public function broken_model_removed_does_not_fall_back_to_config(): void
    {
        $userId = (string) Str::uuid();
        $server = $this->makeServer('Active Server');

        LanguageModel::create([
            'id' => (string) Str::uuid(),
            'server_id' => $server->id,
            'name' => 'old-embed-model',
        ]);
        LanguageModel::where('server_id', $server->id)
            ->where('name', 'old-embed-model')
            ->first()
            ->delete(); // Soft delete — the model was removed by a refresh

        $configServer = $this->makeServer('Config Server');
        Config::set('llm-client.memory.embedding.server_id', $configServer->id);
        Config::set('llm-client.memory.embedding.model', 'config-embed-model');

        RoleAssignment::create([
            'role' => 'embedding',
            'user_id' => $userId,
            'server_id' => $server->id,
            'model' => 'old-embed-model',
        ]);

        $this->registerRecordingProvider();

        try {
            $this->service()->getProvider($userId);
            $this->fail('Expected RoleAssignmentFailedException for a removed model.');
        } catch (RoleAssignmentFailedException $e) {
            $this->assertEquals('old-embed-model', $e->model);
            $this->assertEquals('model removed', $e->reason);
        }

        $this->assertSame([], $this->resolvedFor);
    }

    /**
     * SC-006: a broken assignment must not escape as an unhandled error from a
     * consumer that degrades on RuntimeException. The exception type is what
     * makes that true, so assert it directly (contracts §3.4).
     */
    #[Test]
    public function role_assignment_failure_is_a_runtime_exception(): void
    {
        $e = new RoleAssignmentFailedException(ModelRole::Embedding, 'some-model', 'server deleted');

        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    /* -----------------------------------------------------------------
     * FR-014: an unsuitable assignment fails at first use, naming role + model
     * ----------------------------------------------------------------- */

    #[Test]
    public function unsuitable_assigned_model_fails_naming_role_and_model(): void
    {
        $userId = (string) Str::uuid();
        $roleServer = $this->makeServer('Role Server');

        RoleAssignment::create([
            'role' => 'embedding',
            'user_id' => $userId,
            'server_id' => $roleServer->id,
            'model' => 'a-chat-model',
        ]);

        // A chat model assigned to the embedding role: the provider accepts the
        // request and fails on it. Nothing at assignment time could have known.
        $this->registerRecordingProvider(function () {
            throw new RuntimeException('404 model does not support embeddings');
        });

        try {
            $this->service()->generate('hello', null, $userId);
            $this->fail('Expected RoleAssignmentFailedException for an unsuitable model.');
        } catch (RoleAssignmentFailedException $e) {
            $this->assertEquals(ModelRole::Embedding, $e->role);
            $this->assertEquals('a-chat-model', $e->model);
            $this->assertStringContainsString('embedding', $e->getMessage());
            $this->assertStringContainsString('a-chat-model', $e->getMessage());
            // The provider's own error is preserved, not discarded.
            $this->assertStringContainsString('does not support embeddings', $e->getMessage());
            $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }
    }

    #[Test]
    public function config_file_model_failure_is_not_reported_as_a_role_failure(): void
    {
        $userId = (string) Str::uuid();
        $configServer = $this->makeServer('Config Server');

        Config::set('llm-client.memory.embedding.server_id', $configServer->id);
        Config::set('llm-client.memory.embedding.model', 'config-embed-model');

        $this->registerRecordingProvider(function () {
            throw new RuntimeException('provider exploded');
        });

        // No assignment at either scope — the failure belongs to the config
        // file's model, so it must not be dressed up as a role failure (D8).
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('provider exploded');

        try {
            $this->service()->generate('hello', null, $userId);
        } catch (RoleAssignmentFailedException $e) {
            $this->fail('A config-file model failure must not surface as a role assignment failure.');
        }
    }
}
