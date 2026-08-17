<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\DataAgentProvisioner;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\StructuredOutputPresetRegistry;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Foundational, security-critical (D5, D9.1, FR-010/FR-011/FR-012) --
 * proves OwnerScopedResultFilter is genuinely wired into the real
 * executeApiCall() dispatch path, not merely correct in isolation
 * (OwnerScopedResultFilterTest covers the decision table at the unit
 * level). Drives AgentLoopService::run() end to end, through a
 * conversation bound to the provisioned data agent, with Http::fake()
 * standing in for the outbound HTTP call.
 *
 * Two fixture shapes:
 *  - contacts-shaped (research.md D4: confirmed real, unscoped
 *    ContactController::index()/show() shape) -- a second user's row/
 *    object must be dropped/replaced by the time the model sees it.
 *  - gtd/lists-shaped (research.md D4 situation 2: no user_id anywhere)
 *    -- proves the passthrough case keeps returning the full, unfiltered
 *    shared data through the exact same code path, unaffected.
 */
class DataAgentAccessScopingTest extends TestCase
{
    private const USER_A_CONTACT_ID = 'contact-owned-by-a';

    private const USER_B_CONTACT_ID = 'contact-owned-by-b';

    private User $userA;

    private User $userB;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.confirm_methods', []);
        $this->app['config']->set('llm-client.api_denylist', []);

        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);
        app(RoleAssignmentService::class)->set(ModelRole::Inference, $this->userA->id, $this->server->id, 'test-model');
        app(RoleAssignmentService::class)->set(ModelRole::Inference, $this->userB->id, $this->server->id, 'test-model');

        if (!Schema::hasTable('mcp_sessions')) {
            Schema::create('mcp_sessions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('protocol_version');
                $table->string('client_name')->nullable();
                $table->string('client_version')->nullable();
                $table->json('capabilities')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        $this->seedOperationCatalog();
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('messages')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        if (Schema::hasTable('mcp_sessions')) {
            DB::table('mcp_sessions')->delete();
        }
        DB::table('llm_role_assignments')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixture scaffolding
    // ---------------------------------------------------------------

    private function seedOperationCatalog(): void
    {
        $operations = [
            'contacts.index' => ['path' => '/contacts', 'method' => 'get'],
            'contacts.show' => ['path' => '/contacts/{id}', 'method' => 'get'],
            'gtd.actions.index' => ['path' => '/gtd/actions', 'method' => 'get'],
        ];

        $paths = [];
        foreach ($operations as $operationId => $entry) {
            $paths[$entry['path']][$entry['method']] = [
                'operationId' => $operationId,
                'summary' => $operationId,
            ];
        }
        $doc = ['paths' => $paths];

        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);

        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->andReturn($doc);
        $this->app->instance(Generator::class, $generator);
    }

    private function clearOperationCatalog(): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    /**
     * Real, unscoped shape (research.md D4): a list containing rows owned
     * by two different users, and a single-object endpoint that returns
     * whatever id is asked for regardless of who owns it -- exactly what
     * ContactController::index()/show() do today.
     */
    private function fakeContactsHttp(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/contacts')) {
                return Http::response([
                    ['id' => self::USER_A_CONTACT_ID, 'user_id' => $this->userA->id, 'name' => "A's contact"],
                    ['id' => self::USER_B_CONTACT_ID, 'user_id' => $this->userB->id, 'name' => "B's contact"],
                ], 200);
            }

            if (str_contains($path, '/contacts/')) {
                return Http::response([
                    'id' => self::USER_B_CONTACT_ID,
                    'user_id' => $this->userB->id,
                    'name' => "B's contact",
                    'balance' => 1000,
                ], 200);
            }

            if (str_ends_with($path, '/gtd/actions')) {
                return Http::response([
                    ['id' => 'action-1', 'title' => 'Buy milk'],
                    ['id' => 'action-2', 'title' => 'Call mom'],
                ], 200);
            }

            return Http::response(['error' => 'unmapped test route: '.$path], 500);
        });
    }

    private function service(array $responses): AgentLoopService
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools, $options = []) use (&$responses) {
            return array_shift($responses);
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        $executor = new McpToolExecutor(
            app(McpToolRegistry::class),
            null,
            fn ($user) => 'test-token',
        );

        return new AgentLoopService(
            app(McpToolRegistry::class),
            $executor,
            app(OperationCache::class),
            $registry,
            presetRegistry: app(StructuredOutputPresetRegistry::class),
        );
    }

    private function plainReply(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content, 'tool_calls' => []]]]];
    }

    private function toolCall(string $name, array $arguments, string $id): array
    {
        return [
            'id' => $id,
            'type' => 'function',
            'function' => ['name' => $name, 'arguments' => json_encode($arguments)],
        ];
    }

    private function toolCallReply(array $calls): array
    {
        return ['choices' => [['message' => ['content' => '', 'tool_calls' => $calls]]]];
    }

    private function agent(User $user): Agent
    {
        return app(DataAgentProvisioner::class)->ensureForUser($user->id);
    }

    private function makeConversation(User $user, Agent $agent): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => 'Access-scoping conversation',
            'agent_id' => $agent->id,
            'agent_version_id' => $agent->current_version_id,
        ]);
    }

    private function toolResultContent(Conversation $conversation): string
    {
        $toolMessage = Message::where('conversation_id', $conversation->id)
            ->whereNotNull('tool_data')
            ->orderBy('id')
            ->first();
        $this->assertNotNull($toolMessage, 'fixture sanity: the run must have actually attempted execute_operation');

        return (string) ($toolMessage->tool_data['tool_results'][0]['content'] ?? '');
    }

    // ---------------------------------------------------------------
    // Contacts-shaped list: a second user's row must be dropped
    // ---------------------------------------------------------------

    #[Test]
    public function a_second_users_row_is_dropped_from_an_unscoped_contacts_list_result(): void
    {
        $this->fakeContactsHttp();

        $conversation = $this->makeConversation($this->userA, $this->agent($this->userA));

        $service = $this->service([
            $this->toolCallReply([$this->toolCall('execute_operation', [
                'operationId' => 'contacts.index',
                'parameters' => [],
            ], 'call_list')]),
            $this->plainReply('You have 1 contact.'),
        ]);

        $result = $service->run($conversation->fresh(), 'How many contacts do I have?');
        $this->assertSame('completed', $result['status']);

        $content = $this->toolResultContent($conversation);
        $decoded = json_decode($content, true);

        $this->assertIsArray($decoded);
        $ids = array_column($decoded, 'id');
        $this->assertContains(self::USER_A_CONTACT_ID, $ids, 'the requesting user\'s own row must still be present');
        $this->assertNotContains(
            self::USER_B_CONTACT_ID,
            $ids,
            'a foreign-owned row must never reach the model -- OwnerScopedResultFilter must be wired into executeApiCall()',
        );
    }

    // ---------------------------------------------------------------
    // Contacts-shaped single object: a foreign owner's object must be
    // replaced with the generic not-found shape, never a differently
    // worded ownership message
    // ---------------------------------------------------------------

    #[Test]
    public function a_foreign_owned_single_contact_is_replaced_with_a_generic_not_found_shape(): void
    {
        $this->fakeContactsHttp();

        $conversation = $this->makeConversation($this->userA, $this->agent($this->userA));

        $service = $this->service([
            $this->toolCallReply([$this->toolCall('execute_operation', [
                'operationId' => 'contacts.show',
                'parameters' => ['path' => ['id' => self::USER_B_CONTACT_ID]],
            ], 'call_show')]),
            $this->plainReply('I could not find that contact.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Show me contact '.self::USER_B_CONTACT_ID);
        $this->assertSame('completed', $result['status']);

        $content = $this->toolResultContent($conversation);
        $decoded = json_decode($content, true);

        $this->assertSame(
            ['error' => 'Not found.'],
            $decoded,
            'a foreign-owned single object must be replaced with the generic not-found shape -- OwnerScopedResultFilter must be wired into executeApiCall()',
        );
        $this->assertStringNotContainsString(
            "B's contact",
            $content,
            'no foreign-owned field may leak into the raw result the model sees',
        );
    }

    // ---------------------------------------------------------------
    // Two different users asking the identical question against the same
    // unscoped underlying source: each must see only their own row, and
    // the two answers are allowed to differ.
    // ---------------------------------------------------------------

    #[Test]
    public function two_different_users_asking_the_same_question_each_see_only_their_own_data(): void
    {
        $this->fakeContactsHttp();

        $conversationA = $this->makeConversation($this->userA, $this->agent($this->userA));
        $serviceA = $this->service([
            $this->toolCallReply([$this->toolCall('execute_operation', [
                'operationId' => 'contacts.index',
                'parameters' => [],
            ], 'call_list_a')]),
            $this->plainReply('You have 1 contact.'),
        ]);
        $resultA = $serviceA->run($conversationA->fresh(), 'How many contacts do I have?');
        $this->assertSame('completed', $resultA['status']);
        $idsA = array_column(json_decode($this->toolResultContent($conversationA), true), 'id');

        $conversationB = $this->makeConversation($this->userB, $this->agent($this->userB));
        $serviceB = $this->service([
            $this->toolCallReply([$this->toolCall('execute_operation', [
                'operationId' => 'contacts.index',
                'parameters' => [],
            ], 'call_list_b')]),
            $this->plainReply('You have 1 contact.'),
        ]);
        $resultB = $serviceB->run($conversationB->fresh(), 'How many contacts do I have?');
        $this->assertSame('completed', $resultB['status']);
        $idsB = array_column(json_decode($this->toolResultContent($conversationB), true), 'id');

        $this->assertSame([self::USER_A_CONTACT_ID], $idsA, "user A's own row must be the only row in user A's result");
        $this->assertSame([self::USER_B_CONTACT_ID], $idsB, "user B's own row must be the only row in user B's result");
        $this->assertNotSame($idsA, $idsB, 'the two users asking the identical question may legitimately get different, correctly scoped answers');
        $this->assertEmpty(
            array_intersect($idsA, $idsB),
            'neither user\'s filtered result may contain a row belonging to the other user',
        );
    }

    // ---------------------------------------------------------------
    // A foreign-owned single object must give no hint that it exists at
    // all -- not a count, not an id, not a differently-worded refusal.
    // ---------------------------------------------------------------

    #[Test]
    public function a_foreign_owned_single_contact_result_gives_no_hint_that_the_excluded_data_exists(): void
    {
        $this->fakeContactsHttp();

        $conversation = $this->makeConversation($this->userA, $this->agent($this->userA));

        $service = $this->service([
            $this->toolCallReply([$this->toolCall('execute_operation', [
                'operationId' => 'contacts.show',
                'parameters' => ['path' => ['id' => self::USER_B_CONTACT_ID]],
            ], 'call_show')]),
            $this->plainReply('I could not find that contact.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Show me contact '.self::USER_B_CONTACT_ID);
        $this->assertSame('completed', $result['status']);

        $content = $this->toolResultContent($conversation);
        $decoded = json_decode($content, true);

        // The response must read no differently than if the excluded row
        // were entirely absent -- exactly the generic shape, nothing more.
        $this->assertSame(['error' => 'Not found.'], $decoded);
        $this->assertCount(1, $decoded, 'no extra key may accompany the generic error, since any extra key could hint at what was excluded');
        $this->assertArrayNotHasKey('id', $decoded);
        $this->assertArrayNotHasKey('count', $decoded);
        $this->assertArrayNotHasKey('meta', $decoded);

        foreach ([self::USER_B_CONTACT_ID, "B's contact", (string) $this->userB->id, '1000'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $content,
                'no fragment of the excluded contact\'s identity or content may leak into the raw result the model sees',
            );
        }
    }

    // ---------------------------------------------------------------
    // gtd/lists-shaped: no user_id anywhere -> full passthrough,
    // unaffected by this feature
    // ---------------------------------------------------------------

    #[Test]
    public function a_schemaless_node_shared_source_passes_through_completely_unfiltered(): void
    {
        $this->fakeContactsHttp();

        $conversation = $this->makeConversation($this->userA, $this->agent($this->userA));

        $service = $this->service([
            $this->toolCallReply([$this->toolCall('execute_operation', [
                'operationId' => 'gtd.actions.index',
                'parameters' => [],
            ], 'call_gtd')]),
            $this->plainReply('You have 2 actions.'),
        ]);

        $result = $service->run($conversation->fresh(), 'What are my actions?');
        $this->assertSame('completed', $result['status']);

        $content = $this->toolResultContent($conversation);
        $decoded = json_decode($content, true);

        $this->assertSame([
            ['id' => 'action-1', 'title' => 'Buy milk'],
            ['id' => 'action-2', 'title' => 'Call mom'],
        ], $decoded, 'a schema-less, node-shared source must pass through completely unfiltered, both rows present');
    }
}
