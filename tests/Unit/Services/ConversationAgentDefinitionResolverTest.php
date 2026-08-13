<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ConversationHandoff;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\ConversationAgentDefinitionResolver;
use ClarionApp\LlmClient\ValueObjects\AgentDefinition;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for ConversationAgentDefinitionResolver::forConversation()
 * (090-agent-version-binding, Phase 4/US2, contracts §2, data-model.md §1's
 * entity-relationship summary).
 *
 * Written before ConversationAgentDefinitionResolver exists — every test in
 * this file is expected to fail with a "Class ...ConversationAgentDefinitionResolver
 * not found" error until T025 creates it.
 */
class ConversationAgentDefinitionResolverTest extends TestCase
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

        DB::table('conversation_handoffs')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('language_models')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function resolver(): ConversationAgentDefinitionResolver
    {
        return new ConversationAgentDefinitionResolver(new AgentDefinitionParser());
    }

    /**
     * Seeds both of ApiManager's live-catalog seams — required before any
     * *valid* AgentDefinitionParser::parse() call, since parse()
     * unconditionally resolves the operation catalog once per call
     * (AgentServiceTest's own established convention, mirrored here from
     * AgentVersionResolverTest — this feature's own sibling resolver).
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

    // ---------------------------------------------------------------
    // null for an unbound conversation (the overwhelmingly common case)
    // ---------------------------------------------------------------

    #[Test]
    public function returns_null_for_an_unbound_conversation(): void
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'agent_id' => null,
            'agent_version_id' => null,
        ]);

        $definition = $this->resolver()->forConversation($conversation);

        $this->assertNull($definition);
    }

    // ---------------------------------------------------------------
    // Correct, fixed-version resolution for a bound conversation
    // ---------------------------------------------------------------

    #[Test]
    public function returns_the_bound_versions_definition_for_a_bound_conversation(): void
    {
        $agent = app(AgentService::class)->create(
            $this->user->id,
            "name: weather-agent\ninstructions: Always respond in English.",
        );

        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'agent_id' => $agent->id,
            'agent_version_id' => $agent->current_version_id,
        ]);

        $definition = $this->resolver()->forConversation($conversation);

        $this->assertInstanceOf(AgentDefinition::class, $definition);
        $this->assertSame('Always respond in English.', $definition->instructions);
        $this->assertSame('weather-agent', $definition->name);
    }

    // ---------------------------------------------------------------
    // Unaffected by later edits to the agent — research.md D1, the exact
    // bug this feature exists to prevent.
    // ---------------------------------------------------------------

    #[Test]
    public function remains_pinned_to_the_bound_version_after_the_agent_is_later_edited(): void
    {
        $agent = app(AgentService::class)->create(
            $this->user->id,
            "name: weather-agent\ninstructions: Always respond in English.",
        );
        $version1Id = $agent->current_version_id;

        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'agent_id' => $agent->id,
            'agent_version_id' => $version1Id,
        ]);

        // Produce version 2 with DIFFERENT instructions while the
        // conversation is already bound to version 1.
        app(AgentService::class)->update(
            $agent,
            $this->user->id,
            "name: weather-agent\ninstructions: Always respond in French.",
        );

        // A fresh resolver instance — no shared state carried over from
        // anything above.
        $definition = (new ConversationAgentDefinitionResolver(new AgentDefinitionParser()))
            ->forConversation($conversation->fresh());

        $this->assertSame(
            'Always respond in English.',
            $definition->instructions,
            'must resolve via agentVersion() (the fixed agent_version_id), never agent()->currentVersion',
        );
    }

    // ---------------------------------------------------------------
    // Defensive-only: the bound AgentVersion row itself no longer resolves
    // ---------------------------------------------------------------

    #[Test]
    public function returns_null_when_the_bound_version_row_no_longer_exists(): void
    {
        $agent = app(AgentService::class)->create(
            $this->user->id,
            "name: weather-agent\ninstructions: Always respond in English.",
        );
        $versionId = $agent->current_version_id;

        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'agent_id' => $agent->id,
            'agent_version_id' => $versionId,
        ]);

        // Defensive-only case: the bound AgentVersion row itself is gone.
        DB::table('agent_versions')->where('id', $versionId)->delete();

        $definition = $this->resolver()->forConversation($conversation->fresh());

        $this->assertNull($definition);
        $this->assertSame(
            $versionId,
            $conversation->fresh()->agent_version_id,
            'a resolution failure must never retroactively change agent_version_id (FR-003 is non-negotiable)',
        );
    }

    // ---------------------------------------------------------------
    // A bound version whose raw_definition no longer PARSES degrades to
    // null rather than throwing (contracts §2 "Non-guarantees," the
    // resolved A1/A2 finding from the analyze pass).
    // ---------------------------------------------------------------

    #[Test]
    public function degrades_to_null_rather_than_throwing_when_the_bound_versions_raw_definition_no_longer_resolves(): void
    {
        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Primary']);
        $model = LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'retiring-model', 'server_id' => $server->id]);

        $agent = app(AgentService::class)->create(
            $this->user->id,
            "name: weather-agent\nmodel: retiring-model",
        );
        $versionId = $agent->current_version_id;

        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'agent_id' => $agent->id,
            'agent_version_id' => $versionId,
        ]);

        // The bound version's named model no longer exists on this
        // installation — raw_definition can no longer resolve
        // (AgentDefinitionParser::parse() throws AgentDefinitionResolutionException).
        $model->delete();

        $definition = $this->resolver()->forConversation($conversation->fresh());

        $this->assertNull($definition, 'a parse/resolution failure must degrade to null, never throw — a hard failure here would break an in-progress turn entirely');
        $this->assertSame(
            $versionId,
            $conversation->fresh()->agent_version_id,
            'a resolution failure must never retroactively change agent_version_id',
        );
    }

    // =================================================================
    // effectiveDefinitionFor() — 093-agent-handoff
    //
    // Written before effectiveDefinitionFor() exists — every test in this
    // section is expected to fail with a "Call to undefined method
    // ConversationAgentDefinitionResolver::effectiveDefinitionFor()" error
    // until T015 adds it. forConversation() itself (tested above) is never
    // modified by this feature — data-model.md §3.
    // =================================================================

    #[Test]
    public function effective_definition_for_matches_for_conversation_when_there_is_no_handoff(): void
    {
        $agent = app(AgentService::class)->create(
            $this->user->id,
            "name: weather-agent\ninstructions: Always respond in English.",
        );

        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'agent_id' => $agent->id,
            'agent_version_id' => $agent->current_version_id,
        ]);

        $resolver = $this->resolver();

        $effective = $resolver->effectiveDefinitionFor($conversation);
        $original = $resolver->forConversation($conversation);

        $this->assertInstanceOf(AgentDefinition::class, $effective);
        $this->assertInstanceOf(AgentDefinition::class, $original);
        $this->assertSame(
            $original->instructions,
            $effective->instructions,
            'with no handoff rows, effectiveDefinitionFor() must return byte-identical instructions to forConversation()',
        );
        $this->assertSame(
            $original->toolsAllow,
            $effective->toolsAllow,
            'with no handoff rows, effectiveDefinitionFor() must return byte-identical permitted operations to forConversation()',
        );
        $this->assertSame($original->name, $effective->name);
    }

    #[Test]
    public function effective_definition_for_resolves_the_handoffs_target_while_for_conversation_still_resolves_the_original(): void
    {
        $agentA = app(AgentService::class)->create(
            $this->user->id,
            "name: agent-a\ninstructions: Always respond in English.",
        );
        $agentB = app(AgentService::class)->create(
            $this->user->id,
            "name: agent-b\ninstructions: Always respond in French.",
        );

        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'agent_id' => $agentA->id,
            'agent_version_id' => $agentA->current_version_id,
        ]);

        ConversationHandoff::create([
            'conversation_id' => $conversation->id,
            'position' => 1,
            'from_agent_id' => $agentA->id,
            'to_agent_id' => $agentB->id,
            'to_agent_version_id' => $agentB->current_version_id,
            'created_at' => now(),
        ]);

        $resolver = $this->resolver();

        $effective = $resolver->effectiveDefinitionFor($conversation);
        $original = $resolver->forConversation($conversation);

        $this->assertInstanceOf(AgentDefinition::class, $effective);
        $this->assertSame(
            'Always respond in French.',
            $effective->instructions,
            'effectiveDefinitionFor() must resolve the handoff row\'s to_agent_version_id (agent B), not the conversation\'s original binding',
        );

        $this->assertInstanceOf(AgentDefinition::class, $original);
        $this->assertSame(
            'Always respond in English.',
            $original->instructions,
            'forConversation(), called on the very same conversation object, must still resolve the ORIGINAL binding (agent A), provably unchanged',
        );
    }

    #[Test]
    public function effective_definition_for_resolves_the_latest_of_multiple_handoffs(): void
    {
        $agentA = app(AgentService::class)->create(
            $this->user->id,
            "name: agent-a\ninstructions: I am agent A.",
        );
        $agentB = app(AgentService::class)->create(
            $this->user->id,
            "name: agent-b\ninstructions: I am agent B.",
        );
        $agentC = app(AgentService::class)->create(
            $this->user->id,
            "name: agent-c\ninstructions: I am agent C.",
        );

        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'agent_id' => $agentA->id,
            'agent_version_id' => $agentA->current_version_id,
        ]);

        ConversationHandoff::create([
            'conversation_id' => $conversation->id,
            'position' => 1,
            'from_agent_id' => $agentA->id,
            'to_agent_id' => $agentB->id,
            'to_agent_version_id' => $agentB->current_version_id,
            'created_at' => now(),
        ]);
        ConversationHandoff::create([
            'conversation_id' => $conversation->id,
            'position' => 2,
            'from_agent_id' => $agentB->id,
            'to_agent_id' => $agentC->id,
            'to_agent_version_id' => $agentC->current_version_id,
            'created_at' => now(),
        ]);

        $definition = $this->resolver()->effectiveDefinitionFor($conversation);

        $this->assertInstanceOf(AgentDefinition::class, $definition);
        $this->assertSame(
            'I am agent C.',
            $definition->instructions,
            'effectiveDefinitionFor() must resolve the LATEST (highest position) handoff row, not an earlier one',
        );
    }

    #[Test]
    public function effective_definition_for_degrades_to_null_when_the_handoffs_target_version_no_longer_resolves(): void
    {
        $agentA = app(AgentService::class)->create(
            $this->user->id,
            "name: agent-a\ninstructions: I am agent A.",
        );
        $agentB = app(AgentService::class)->create(
            $this->user->id,
            "name: agent-b\ninstructions: I am agent B.",
        );
        $targetVersionId = $agentB->current_version_id;

        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'agent_id' => $agentA->id,
            'agent_version_id' => $agentA->current_version_id,
        ]);

        ConversationHandoff::create([
            'conversation_id' => $conversation->id,
            'position' => 1,
            'from_agent_id' => $agentA->id,
            'to_agent_id' => $agentB->id,
            'to_agent_version_id' => $targetVersionId,
            'created_at' => now(),
        ]);

        // Simulate a since-deleted/unresolvable target version — the same
        // defensive scenario forConversation() itself already degrades on.
        DB::table('agent_versions')->where('id', $targetVersionId)->delete();

        $definition = $this->resolver()->effectiveDefinitionFor($conversation->fresh());

        $this->assertNull(
            $definition,
            'a since-deleted/unresolvable handoff target version must degrade to null, matching forConversation()\'s own degrade-on-failure posture, never throwing',
        );
    }
}
