<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\Models\McpClientTool;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The fall-through, not early-return, guarantee AgentLoopService::
 * handleExecuteOperation() must give an external tool call: every safety
 * check a built-in operation already passes through before it can run --
 * the bound agent-version's own tools.allow narrowing, and the live
 * delegation-chain narrowing -- must apply to a synthetic external-tool
 * operationId exactly the same way, never bypassed the way the existing
 * CapabilityOffering shortcut bypasses them via its own isolated early
 * return (that shortcut is fine for CapabilityOffering only because
 * DelegationService::invokeAsCapability() re-implements an equivalent
 * eligibility gate of its own -- there is no separate gate like that for
 * an external tool).
 *
 * Both cases below deliberately configure the installation denylist to
 * contain nothing that would match this feature's own synthesized
 * "/mcp-client/{server}/{tool}" path, so any rejection observed can only
 * come from the narrowing block under test, never the denylist.
 */
class ExternalToolPermissionNarrowingTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog([
            'some.unrelated.operation' => ['path' => '/api/some-unrelated', 'method' => 'get', 'summary' => 'Unrelated built-in operation'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_delegations')->delete();
        DB::table('mcp_client_tools')->delete();
        DB::table('mcp_client_servers')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    /**
     * Seeds both of ApiManager's live-catalog seams (established
     * convention -- e.g. ConversationRecordsBoundAgentVersionJourneyTest,
     * SubagentToolRestrictionRuntimeJourneyTest), required before any
     * valid AgentDefinitionParser::parse() call.
     */
    private function seedOperationCatalog(array $operations = []): void
    {
        $paths = [];
        foreach ($operations as $operationId => $entry) {
            $paths[$entry['path']][$entry['method']] = [
                'operationId' => $operationId,
                'summary' => $entry['summary'],
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

    private function makeAgentPermitting(string $name, array $operationIds): Agent
    {
        $allowLines = implode("\n", array_map(fn (string $id) => "    - {$id}", $operationIds));

        $yaml = <<<YAML
name: {$name}
instructions: I am {$name}.
tools:
  allow:
{$allowLines}
YAML;

        return app(AgentService::class)->create($this->user->id, $yaml);
    }

    private function makeExternalTool(string $toolName = 'delete-everything'): McpClientTool
    {
        $server = McpClientServer::create([
            'name' => 'Narrowing Test Server',
            'transport' => 'streamable_http',
            'url' => 'https://example.test/mcp',
            'user_id' => $this->user->id,
        ]);

        return McpClientTool::create([
            'server_id' => $server->id,
            'synthetic_operation_id' => "mcp:{$server->id}:{$toolName}",
            'name' => $toolName,
            'description' => 'A destructive external action.',
            'input_schema' => ['type' => 'object', 'properties' => []],
            'last_seen_at' => now(),
        ]);
    }

    #[Test]
    public function a_bound_agent_versions_own_tools_allow_excluding_the_external_tool_rejects_it_exactly_like_an_excluded_built_in_operation_would(): void
    {
        $tool = $this->makeExternalTool();

        $agent = $this->makeAgentPermitting('narrowing-agent', ['some.unrelated.operation']);

        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'agent_id' => $agent->id,
            'agent_version_id' => $agent->current_version_id,
        ]);

        $result = json_decode(
            app(AgentLoopService::class)->executeMetaTool(
                'execute_operation',
                ['operationId' => $tool->synthetic_operation_id, 'parameters' => []],
                $conversation,
            ),
            true,
        );

        $this->assertSame(
            'Operation not permitted by the agent version this conversation is bound to.',
            $result['error'] ?? null,
            'the exact same rejection message a built-in operation excluded from tools.allow already gets -- proving the bound-agent-version narrowing block was genuinely reached for a synthetic external-tool operationId, not skipped by an isolated early-return the way the existing capability-offering shortcut is',
        );
    }

    #[Test]
    public function a_delegation_chain_ancestors_current_restriction_also_rejects_an_external_tool_call_even_when_the_acting_conversation_has_no_bound_agent_version_of_its_own(): void
    {
        $tool = $this->makeExternalTool('read-everything');

        $parentAgent = $this->makeAgentPermitting('narrowing-parent', ['some.unrelated.operation']);

        // The acting conversation itself is NOT bound to any agent
        // version (agent_id/agent_version_id both null) -- so the FIRST
        // narrowing block (boundDefinition !== null) is a structural
        // no-op here, isolating this assertion to the delegation-chain
        // check alone (effectiveBoundResolver->check()), never the
        // bound-agent-version block the previous test already proved.
        $helperConversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
        ]);

        Delegation::create([
            'parent_conversation_id' => (string) Str::uuid(),
            'parent_agent_id' => $parentAgent->id,
            'helper_agent_id' => (string) Str::uuid(),
            'helper_conversation_id' => $helperConversation->id,
            'owner_user_id' => $this->user->id,
            'task' => 'A permission-narrowing fixture delegation.',
            'depth' => 1,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        $result = json_decode(
            app(AgentLoopService::class)->executeMetaTool(
                'execute_operation',
                ['operationId' => $tool->synthetic_operation_id, 'parameters' => []],
                $helperConversation,
            ),
            true,
        );

        $this->assertArrayHasKey('error', (array) $result);
        $this->assertStringContainsString(
            'ancestor agent',
            $result['error'] ?? '',
            'the rejection must come from the live delegation-chain check (effectiveBoundResolver->check()) -- distinguishable from the bound-agent-version rejection the previous test proved, since this conversation has no bound agent version of its own for that earlier block to act on at all',
        );
    }

    #[Test]
    public function widening_the_bound_agent_versions_tools_allow_to_name_the_external_tool_directly_lets_it_reach_the_confirmation_step(): void
    {
        $tool = $this->makeExternalTool('read-status');

        $agent = $this->makeAgentPermitting('widening-agent', [$tool->synthetic_operation_id]);

        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'agent_id' => $agent->id,
            'agent_version_id' => $agent->current_version_id,
        ]);

        $result = json_decode(
            app(AgentLoopService::class)->executeMetaTool(
                'execute_operation',
                ['operationId' => $tool->synthetic_operation_id, 'parameters' => []],
                $conversation,
            ),
            true,
        );

        $this->assertTrue(
            (bool) ($result['__requires_confirmation'] ?? false),
            'naming the external tool\'s own synthetic operationId directly in tools.allow must let it reach McpClientCallValidator\'s unconditional confirm step, proving the narrowing block above can widen as well as narrow, not just reject unconditionally regardless of tools.allow content; got: ' . json_encode($result),
        );
    }

    #[Test]
    public function a_wildcard_pattern_scoped_to_one_servers_tools_permits_every_tool_that_server_offers(): void
    {
        $tool = $this->makeExternalTool('write-status');
        $wildcard = "mcp:{$tool->server_id}:*";

        $agent = $this->makeAgentPermitting('wildcard-agent', [$wildcard]);

        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'agent_id' => $agent->id,
            'agent_version_id' => $agent->current_version_id,
        ]);

        $result = json_decode(
            app(AgentLoopService::class)->executeMetaTool(
                'execute_operation',
                ['operationId' => $tool->synthetic_operation_id, 'parameters' => []],
                $conversation,
            ),
            true,
        );

        $this->assertTrue(
            (bool) ($result['__requires_confirmation'] ?? false),
            'a wildcard pattern scoped to this tool\'s own server must permit it, exactly like a built-in wildcard permits every operation under it; got: ' . json_encode($result),
        );
    }
}
