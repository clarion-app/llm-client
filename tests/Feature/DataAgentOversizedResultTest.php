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
use ClarionApp\LlmClient\Services\ToolResultCondenser;
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
 * US5 (P2, D2/D6, FR-013/FR-014, quickstart.md walkthrough step 8,
 * mutation-checklist row 7): a query whose full result would be
 * excessively large is bounded -- not dumped in full -- when reached
 * through the data agent's own real tools.allow: [GET] permission path
 * (AgentLoopService::run() -> executeApiCall() -> ToolResultCondenser),
 * with every sampled numeric value in the bounded output exact; a normal,
 * small-result question through the same path produces no bounding
 * artifact.
 */
class DataAgentOversizedResultTest extends TestCase
{
    private User $user;

    private Server $server;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('llm-client.tool_result_condensation', [
            'enabled' => true,
            'threshold_tokens' => 100,
            'max_condensed_tokens' => 500,
            'sample_items' => 5,
            'summarization_timeout_seconds' => 5,
            'cache_ttl_minutes' => 240,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.confirm_methods', []);
        $this->app['config']->set('llm-client.api_denylist', []);

        $this->user = User::factory()->create();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);
        app(RoleAssignmentService::class)->set(ModelRole::Inference, $this->user->id, $this->server->id, 'test-model');

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
            'reports.index' => ['path' => '/reports', 'method' => 'get'],
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
     * A large, per-row-numeric result (40 rows) far over the low test
     * threshold, plus a small, ordinary-sized result on a second
     * operation -- the "normal question" control case.
     */
    private function fakeReportsHttp(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/reports')) {
                $rows = [];
                for ($i = 0; $i < 40; $i++) {
                    $rows[] = [
                        'id' => "row-{$i}",
                        'amount' => 1000 + $i,
                        'description' => 'General ledger entry number '.$i.' with descriptive padding text to grow the payload well past the condensation threshold.',
                    ];
                }

                return Http::response($rows, 200);
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
            toolResultCondenser: new ToolResultCondenser(),
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

    private function agent(): Agent
    {
        return app(DataAgentProvisioner::class)->ensureForUser($this->user->id);
    }

    private function makeConversation(Agent $agent): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => 'Oversized-result conversation',
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
    // A large result is bounded through the data agent's own real path.
    // ---------------------------------------------------------------

    #[Test]
    public function an_oversized_result_is_bounded_not_dumped_in_full_through_the_data_agents_own_path(): void
    {
        $this->fakeReportsHttp();

        $conversation = $this->makeConversation($this->agent());

        $service = $this->service([
            $this->toolCallReply([$this->toolCall('execute_operation', [
                'operationId' => 'reports.index',
                'parameters' => [],
            ], 'call_reports')]),
            $this->plainReply('Here is a summary of your report totals.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Summarize all my ledger entries.');
        $this->assertSame('completed', $result['status']);

        $content = $this->toolResultContent($conversation);
        $decoded = json_decode($content, true);

        $this->assertIsArray($decoded, 'the bounded result must still be valid JSON');
        $this->assertArrayHasKey('_meta', $decoded, 'a large result reached through the data agent must be bounded (sample + _meta), never the full raw dump');
        $this->assertSame(40, $decoded['_meta']['total_count']);
        $this->assertSame(5, $decoded['_meta']['sample_count']);
        $this->assertArrayHasKey('_truncated', $decoded);
        $this->assertLessThan(40, count(array_filter(array_keys($decoded), 'is_int')), 'far fewer than all 40 rows must be present verbatim');

        // Every sampled row's numeric field must remain exact -- ties
        // together D2 (reached via the real permission path)/D6 (structured
        // reduction preserves scalars)/D9.3.
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(1000 + $i, $decoded[$i]['amount'], "sampled row {$i}'s amount must survive bounding exactly");
        }
    }

    // ---------------------------------------------------------------
    // A normal, small-result question produces no bounding artifact.
    // ---------------------------------------------------------------

    #[Test]
    public function a_normal_small_result_question_produces_no_bounding_artifact(): void
    {
        $this->fakeReportsHttp();

        $conversation = $this->makeConversation($this->agent());

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
        ], $decoded, 'a small, ordinary-sized result must pass through with no bounding artifact at all');
        $this->assertArrayNotHasKey('_meta', $decoded);
        $this->assertArrayNotHasKey('_truncated', $decoded);
    }
}
