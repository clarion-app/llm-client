<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\Models\McpClientTool;
use ClarionApp\LlmClient\Services\AgentLoopService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A denylisted external tool must be refused outright -- never a
 * confirmation prompt, on top of a confirmation prompt, or in place of
 * one -- proving McpClientCallValidator checks the exact same
 * config('llm-client.api_denylist') array a built-in route is checked
 * against (see McpClientCallValidatorTest for the validator's own
 * isolated unit proof; this file proves the same guarantee reaches
 * production behavior through AgentLoopService::handleExecuteOperation(),
 * across more than one server offering the denylisted name).
 */
class ExternalToolDenylistTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog();
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('mcp_client_tools')->delete();
        DB::table('mcp_client_servers')->delete();
        DB::table('conversations')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function seedOperationCatalog(): void
    {
        $doc = ['paths' => []];

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

    private function makeExternalTool(string $serverName, string $toolName): McpClientTool
    {
        $server = McpClientServer::create([
            'name' => $serverName,
            'transport' => 'streamable_http',
            'url' => 'https://example.test/mcp',
            'user_id' => $this->user->id,
        ]);

        return McpClientTool::create([
            'server_id' => $server->id,
            'synthetic_operation_id' => "mcp:{$server->id}:{$toolName}",
            'name' => $toolName,
            'description' => 'An external tool.',
            'input_schema' => ['type' => 'object', 'properties' => []],
            'last_seen_at' => now(),
        ]);
    }

    /**
     * Mirrors makeExternalTool() exactly, except the synthetic_operation_id
     * is derived from the row's own locally-generated id (the
     * "mcp:{server_id}:{tool_id}" form McpClientToolDiscoveryService now
     * derives) rather than from the tool's name -- for proving a denylist
     * rule can be anchored to the durable form directly, without a real
     * discover() run.
     */
    private function makeExternalToolWithDurableId(string $serverName, string $toolName): McpClientTool
    {
        $server = McpClientServer::create([
            'name' => $serverName,
            'transport' => 'streamable_http',
            'url' => 'https://example.test/mcp',
            'user_id' => $this->user->id,
        ]);

        $id = (string) Str::uuid();

        return McpClientTool::create([
            'id' => $id,
            'server_id' => $server->id,
            'synthetic_operation_id' => "mcp:{$server->id}:{$id}",
            'name' => $toolName,
            'description' => 'An external tool.',
            'input_schema' => ['type' => 'object', 'properties' => []],
            'last_seen_at' => now(),
        ]);
    }

    private function invoke(McpClientTool $tool, Conversation $conversation): array
    {
        return json_decode(
            app(AgentLoopService::class)->executeMetaTool(
                'execute_operation',
                ['operationId' => $tool->synthetic_operation_id, 'parameters' => []],
                $conversation,
            ),
            true,
        );
    }

    #[Test]
    public function a_denylisted_tool_name_pattern_rejects_the_call_outright_with_no_confirmation_ever_raised(): void
    {
        config(['llm-client.api_denylist' => ['/mcp-client/*/delete_everything']]);

        $tool = $this->makeExternalTool('Denylist Test Server', 'delete_everything');
        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);

        $result = $this->invoke($tool, $conversation);

        $this->assertArrayHasKey('error', (array) $result, 'got: '.json_encode($result));
        $this->assertFalse(
            (bool) ($result['__requires_confirmation'] ?? false),
            'a denylisted external tool must never raise a confirmation prompt -- it is refused outright, not paused for a decision',
        );
        $this->assertArrayNotHasKey('confirmation_type', (array) $result);
    }

    #[Test]
    public function a_denylist_entry_written_against_a_tools_durable_synthetic_operation_id_rejects_the_call_outright(): void
    {
        $tool = $this->makeExternalToolWithDurableId('Durable Denylist Server', 'delete_everything');

        config(['llm-client.api_denylist' => [$tool->synthetic_operation_id]]);

        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);

        $result = $this->invoke($tool, $conversation);

        $this->assertArrayHasKey('error', (array) $result, 'got: '.json_encode($result));
        $this->assertFalse(
            (bool) ($result['__requires_confirmation'] ?? false),
            'a denylist entry written against a tool\'s durable synthetic_operation_id must reject outright, exactly as the legacy name-based-pattern form already does above',
        );
        $this->assertArrayNotHasKey('confirmation_type', (array) $result);
    }

    #[Test]
    public function the_same_denylist_pattern_rejects_the_tool_regardless_of_which_server_offers_it(): void
    {
        config(['llm-client.api_denylist' => ['/mcp-client/*/delete_everything']]);

        $toolFromServerA = $this->makeExternalTool('Server A', 'delete_everything');
        $toolFromServerB = $this->makeExternalTool('Server B', 'delete_everything');
        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);

        foreach ([$toolFromServerA, $toolFromServerB] as $tool) {
            $result = $this->invoke($tool, $conversation);

            $this->assertArrayHasKey(
                'error',
                (array) $result,
                "every server offering \"delete_everything\" must be rejected by the same pattern, not only the first one encountered; got: ".json_encode($result),
            );
            $this->assertFalse((bool) ($result['__requires_confirmation'] ?? false));
        }
    }

    #[Test]
    public function a_server_scoped_denylist_pattern_rejects_only_that_servers_own_tools(): void
    {
        $rejectedTool = $this->makeExternalTool('Scoped Server', 'delete_everything');
        $unaffectedTool = $this->makeExternalTool('Other Server', 'delete_everything');

        config(['llm-client.api_denylist' => ["/mcp-client/{$rejectedTool->server_id}/*"]]);

        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);

        $rejected = $this->invoke($rejectedTool, $conversation);
        $this->assertArrayHasKey('error', (array) $rejected, 'got: '.json_encode($rejected));

        $unaffected = $this->invoke($unaffectedTool, $conversation);
        $this->assertTrue(
            (bool) ($unaffected['__requires_confirmation'] ?? false),
            "a server-scoped denylist pattern must not reject a different server's identically-named tool; got: ".json_encode($unaffected),
        );
    }

    #[Test]
    public function a_denylist_entry_written_for_a_built_in_route_has_no_bearing_on_an_unrelated_external_tool(): void
    {
        // Proves the same config('llm-client.api_denylist') array is
        // genuinely read, not a parallel one -- an entry shaped for a
        // built-in route's own path space must not accidentally reject
        // (or accidentally spare) an external tool it was never meant to
        // match.
        config(['llm-client.api_denylist' => ['/api/clarion-app/llm-client/*']]);

        $tool = $this->makeExternalTool('Unrelated Server', 'get_status');
        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);

        $result = $this->invoke($tool, $conversation);

        $this->assertTrue((bool) ($result['__requires_confirmation'] ?? false), 'got: '.json_encode($result));
        $this->assertSame('external_tool', $result['confirmation_type'] ?? null);
    }
}
