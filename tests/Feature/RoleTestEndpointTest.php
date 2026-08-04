<?php

namespace Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

use PHPUnit\Framework\Attributes\Test;

class RoleTestEndpointTest extends TestCase
{
    private User $user;

    /** @var Server[] Servers a provider was resolved for, in call order. */
    protected array $resolvedFor = [];

    /** @var array<int, array{0: array, 1: array, 2: string}> [args, options, method] for every provider call. */
    protected array $providerCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->resolvedFor = [];
        $this->providerCalls = [];
    }

    protected function tearDown(): void
    {
        DB::table('llm_role_assignments')->delete();
        DB::table('llm_server_statuses')->delete();
        DB::table('language_models')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();
        \Mockery::close();
        parent::tearDown();
    }

    /* -----------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------- */

    private function makeServer(string $name): Server
    {
        return Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'server_url' => 'https://'.Str::slug($name).'.example.com',
            'provider_type' => 'openai',
        ]);
    }

    /**
     * Register a provider factory that records the server it was built for
     * and the arguments/method of every chat()/embed() call.
     */
    private function registerRecordingProvider(?callable $chat = null, ?callable $embed = null): void
    {
        $chat ??= fn () => ['choices' => [['message' => ['role' => 'assistant', 'content' => 'pong']]]];
        $embed ??= fn () => ['embeddings' => [[0.1, 0.2, 0.3]]];

        /** @var ProviderRegistry $registry */
        $registry = app(ProviderRegistry::class);

        $registry->register(ProviderType::OpenAI, function (Server $server) use ($chat, $embed) {
            $this->resolvedFor[] = $server;

            $provider = $this->createMock(LlmProvider::class);
            $provider->method('chat')->willReturnCallback(function (array $messages, array $tools = [], array $options = []) use ($chat) {
                $this->providerCalls[] = [$messages, $options, 'chat'];

                return $chat($messages, $tools, $options);
            });
            $provider->method('embed')->willReturnCallback(function (array $inputs, array $options = []) use ($embed) {
                $this->providerCalls[] = [$inputs, $options, 'embed'];

                return $embed($inputs, $options);
            });

            return $provider;
        });
    }

    /**
     * Register a provider that throws if either chat() or embed() is
     * invoked, so any assertion that "no provider call was made" is a
     * genuine assertion rather than an accident of an unregistered type
     * (which would 500/RuntimeException for an unrelated reason).
     */
    private function registerExplodingProvider(): void
    {
        /** @var ProviderRegistry $registry */
        $registry = app(ProviderRegistry::class);

        $registry->register(ProviderType::OpenAI, function (Server $server) {
            $provider = $this->createMock(LlmProvider::class);
            $provider->method('chat')->willReturnCallback(function () {
                throw new RuntimeException('provider must not be called for this outcome');
            });
            $provider->method('embed')->willReturnCallback(function () {
                throw new RuntimeException('provider must not be called for this outcome');
            });

            return $provider;
        });
    }

    private function postTest(string $role): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)
            ->postJson('/api/clarion-app/llm-client/role-assignment/test', ['role' => $role]);
    }

    /* -----------------------------------------------------------------
     * Validation
     * ----------------------------------------------------------------- */

    #[Test]
    public function role_is_required(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/clarion-app/llm-client/role-assignment/test', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['role']);
    }

    #[Test]
    public function role_must_be_one_of_the_three_known_roles(): void
    {
        $response = $this->postTest('nonexistent_role');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['role']);
    }

    /* -----------------------------------------------------------------
     * pass outcome
     * ----------------------------------------------------------------- */

    #[Test]
    public function inference_pass_outcome_exercises_chat_with_timeout(): void
    {
        $server = $this->makeServer('Chat Server');
        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => $this->user->id,
            'server_id' => $server->id,
            'model' => 'gpt-4',
        ]);

        $this->registerRecordingProvider();

        $response = $this->postTest('inference');

        $response->assertStatus(200);
        $response->assertJson([
            'role' => 'inference',
            'outcome' => 'pass',
            'model' => 'gpt-4',
        ]);
        $response->assertJsonPath('server.id', $server->id);
        $response->assertJsonStructure(['role', 'outcome', 'model', 'server', 'message', 'duration_ms']);

        // A single bounded chat call, capped at 1 token and a 20s timeout.
        $this->assertCount(1, $this->providerCalls);
        [$messages, $options, $method] = $this->providerCalls[0];
        $this->assertEquals('chat', $method);
        $this->assertEquals(1, $options['max_tokens']);
        $this->assertEquals(20000, $options['timeout_ms']);
    }

    #[Test]
    public function embedding_pass_outcome_exercises_embed_with_timeout(): void
    {
        $server = $this->makeServer('Embed Server');
        RoleAssignment::create([
            'role' => 'embedding',
            'user_id' => $this->user->id,
            'server_id' => $server->id,
            'model' => 'text-embedding-3-small',
        ]);

        $this->registerRecordingProvider();

        $response = $this->postTest('embedding');

        $response->assertStatus(200);
        $response->assertJson([
            'role' => 'embedding',
            'outcome' => 'pass',
            'model' => 'text-embedding-3-small',
        ]);
        $response->assertJsonPath('server.id', $server->id);

        // A single bounded embed call with a 20s timeout.
        $this->assertCount(1, $this->providerCalls);
        [$inputs, $options, $method] = $this->providerCalls[0];
        $this->assertEquals('embed', $method);
        $this->assertEquals(['clarion role test'], $inputs);
        $this->assertEquals(20000, $options['timeout_ms']);
    }

    /* -----------------------------------------------------------------
     * fail outcome — model and server still populated (FR-024)
     * ----------------------------------------------------------------- */

    #[Test]
    public function inference_fail_outcome_still_names_model_and_server(): void
    {
        $server = $this->makeServer('Flaky Server');
        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => $this->user->id,
            'server_id' => $server->id,
            'model' => 'flaky-model',
        ]);

        $this->registerRecordingProvider(chat: function () {
            throw new RuntimeException('HTTP 404 from http://localhost:8081/v1/chat/completions');
        });

        $response = $this->postTest('inference');

        $response->assertStatus(200);
        $response->assertJson([
            'role' => 'inference',
            'outcome' => 'fail',
            'model' => 'flaky-model',
        ]);
        $response->assertJsonPath('server.id', $server->id);
        $response->assertJsonPath('server.name', 'Flaky Server');
        $this->assertNotEmpty($response->json('message'));
    }

    #[Test]
    public function embedding_fail_outcome_does_not_write_to_role_assignments_or_server_status(): void
    {
        $server = $this->makeServer('Flaky Embed Server');
        $assignment = RoleAssignment::create([
            'role' => 'embedding',
            'user_id' => $this->user->id,
            'server_id' => $server->id,
            'model' => 'flaky-embed-model',
        ]);

        $this->registerRecordingProvider(embed: function () {
            throw new RuntimeException('malformed response');
        });

        $before = DB::table('llm_role_assignments')->orderBy('id')->get()->toArray();
        $statusCountBefore = DB::table('llm_server_statuses')->count();

        $response = $this->postTest('embedding');

        $response->assertStatus(200);
        $response->assertJsonPath('outcome', 'fail');

        // FR-024a: writes nothing, even on a failing test.
        $after = DB::table('llm_role_assignments')->orderBy('id')->get()->toArray();
        $this->assertEquals($before, $after);
        $this->assertEquals($statusCountBefore, DB::table('llm_server_statuses')->count());

        // The assignment itself is unchanged.
        $assignment->refresh();
        $this->assertEquals('flaky-embed-model', $assignment->model);
        $this->assertEquals($server->id, $assignment->server_id);
    }

    /* -----------------------------------------------------------------
     * not_testable outcome — role = image
     * ----------------------------------------------------------------- */

    #[Test]
    public function image_role_is_not_testable(): void
    {
        $server = $this->makeServer('Image Server');
        RoleAssignment::create([
            'role' => 'image',
            'user_id' => $this->user->id,
            'server_id' => $server->id,
            'model' => 'dall-e-3',
        ]);

        // No provider factory registered at all — if the endpoint tried to
        // exercise anything for `image`, ProviderRegistry::resolve() would
        // throw for lack of a registered factory, failing the test loudly.

        $response = $this->postTest('image');

        $response->assertStatus(200);
        $response->assertJsonPath('outcome', 'not_testable');
        $response->assertJsonPath('role', 'image');
        $this->assertNotEmpty($response->json('message'));
    }

    /* -----------------------------------------------------------------
     * no_effective_model outcome — unassigned or broken; no provider call
     * ----------------------------------------------------------------- */

    #[Test]
    public function unassigned_role_is_no_effective_model_and_makes_no_provider_call(): void
    {
        $this->registerExplodingProvider();

        // No RoleAssignment exists at either scope for `inference`.
        $response = $this->postTest('inference');

        $response->assertStatus(200);
        $response->assertJsonPath('outcome', 'no_effective_model');
        $this->assertEmpty($this->providerCalls);
    }

    #[Test]
    public function broken_role_is_no_effective_model_and_makes_no_provider_call(): void
    {
        $server = $this->makeServer('Deleted Server');
        RoleAssignment::create([
            'role' => 'embedding',
            'user_id' => $this->user->id,
            'server_id' => $server->id,
            'model' => 'vanished-model',
        ]);
        $server->delete(); // Soft delete -> broken assignment.

        $this->registerExplodingProvider();

        $response = $this->postTest('embedding');

        $response->assertStatus(200);
        $response->assertJsonPath('outcome', 'no_effective_model');
        $this->assertEmpty($this->providerCalls);
    }
}
