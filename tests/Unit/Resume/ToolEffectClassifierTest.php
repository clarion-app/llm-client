<?php

namespace Tests\Unit\Resume;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CapabilityOffering;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\McpClientTool;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\ToolEffectClassifier;
use ClarionApp\LlmClient\ValueObjects\ToolEffect;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\OperationCatalogue;
use Tests\TestCase;

/**
 * The ToolEffectClassifier (checkpoint-ledger contract §3): at C3 every call
 * is classified effect_free, durable or effectful, first-match-wins, and a
 * lookup that fails classifies effectful (never throws, never effect_free).
 *
 * The classifier is resolved through the container so the test is agnostic
 * to its constructor shape; the one collaborator whose behaviour the test
 * pins (McpToolRegistry's _meta.method lookup) is bound explicitly.
 */
class ToolEffectClassifierTest extends TestCase
{
    private Conversation $conversation;

    /** A registry double whose findTool() answers $answer for $name (null otherwise). */
    private function bindRegistry(?array $answer, ?string $forName = null): void
    {
        $mock = Mockery::mock(McpToolRegistry::class);
        $mock->shouldReceive('findTool')->andReturnUsing(
            fn (string $name) => $forName !== null && $name === $forName ? $answer : null
        );
        $this->app->instance(McpToolRegistry::class, $mock);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // A lenient registry default: rule 5's lookup resolves to nothing, so
        // only the execute_operation rules (2-4) and the named built-ins (6-7)
        // can match. The two tests that pin rule 5 re-bind it explicitly.
        $this->bindRegistry(null);

        // Seed the operation catalogue so ApiManager::getOperationDetails()
        // resolves the method-bearing operations without touching the real
        // documentation service.
        (new OperationCatalogue())->seed([
            'openapi' => '3.0.0',
            'info' => ['title' => 'Classifier API', 'version' => '1.0.0'],
            'paths' => [
                '/get' => ['get' => ['operationId' => 'op.get', 'summary' => 'A read', 'parameters' => [], 'responses' => ['200' => ['description' => 'OK']]]],
                '/head' => ['head' => ['operationId' => 'op.head', 'summary' => 'A head', 'parameters' => [], 'responses' => ['200' => ['description' => 'OK']]]],
                '/put' => ['put' => ['operationId' => 'op.put', 'summary' => 'A replace', 'parameters' => [], 'responses' => ['200' => ['description' => 'OK']]]],
                '/post' => ['post' => ['operationId' => 'op.post', 'summary' => 'A create', 'parameters' => [], 'responses' => ['201' => ['description' => 'Created']]]],
                '/patch' => ['patch' => ['operationId' => 'op.patch', 'summary' => 'A partial update', 'parameters' => [], 'responses' => ['200' => ['description' => 'OK']]]],
                '/delete' => ['delete' => ['operationId' => 'op.delete', 'summary' => 'A delete', 'parameters' => [], 'responses' => ['200' => ['description' => 'OK']]]],
            ],
        ]);

        $user = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Classifier User',
            'email' => 'classifier@example.com',
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);
        $server = Server::create([
            'id' => (string) Str::uuid(),
            'name' => 'Classifier Server',
            'provider_type' => 'openai',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test-token',
        ]);
        $this->conversation = Conversation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'server_id' => $server->id,
            'model' => 'gpt-4',
            'title' => 'Classifier conversation',
        ]);
    }

    protected function tearDown(): void
    {
        (new OperationCatalogue())->reset();
        Mockery::close();
        parent::tearDown();
    }

    private function classifier(): ToolEffectClassifier
    {
        return app(ToolEffectClassifier::class);
    }

    private function op(string $operationId): array
    {
        return ['operationId' => $operationId, 'parameters' => []];
    }

    // ---------------------------------------------------------------
    // Rule 1 — the delegation and question tools are durable
    // ---------------------------------------------------------------

    #[Test]
    public function delegate_to_helper_is_durable(): void
    {
        $this->assertSame(ToolEffect::Durable, $this->classifier()->classify('delegate_to_helper', ['helper_agent_id' => (string) Str::uuid(), 'task' => 'x'], $this->conversation));
    }

    #[Test]
    public function assign_part_is_durable(): void
    {
        $this->assertSame(ToolEffect::Durable, $this->classifier()->classify('assign_part', ['part_id' => (string) Str::uuid()], $this->conversation));
    }

    #[Test]
    public function ask_user_is_durable(): void
    {
        $this->assertSame(ToolEffect::Durable, $this->classifier()->classify('ask_user', ['question' => 'Which one?'], $this->conversation));
    }

    // ---------------------------------------------------------------
    // Rule 2 — a capability-offering execute_operation is durable
    // ---------------------------------------------------------------

    #[Test]
    public function a_capability_offering_execute_operation_is_durable(): void
    {
        $offering = CapabilityOffering::create([
            'id' => (string) Str::uuid(),
            'offered_agent_id' => (string) Str::uuid(),
            'caller_agent_id' => (string) Str::uuid(),
            'owner_user_id' => (string) $this->conversation->user_id,
            'capability_name' => 'summarise',
            'capability_description' => 'Summarises a document.',
            'input_description' => 'The document text.',
        ]);

        $this->assertSame(
            ToolEffect::Durable,
            $this->classifier()->classify('execute_operation', $this->op($offering->id), $this->conversation),
            'an execute_operation whose operationId is a CapabilityOffering id is durable'
        );
    }

    // ---------------------------------------------------------------
    // Rule 3 — an mcp: execute_operation is effectful, even when its
    // cached readOnlyHint says otherwise (quickstart M3)
    // ---------------------------------------------------------------

    #[Test]
    public function external_hints_untrusted(): void
    {
        $serverId = (string) Str::uuid();
        McpClientTool::create([
            'server_id' => $serverId,
            'synthetic_operation_id' => "mcp:{$serverId}:read_thing",
            'name' => 'read_thing',
            'description' => 'An external read.',
            'input_schema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => true],
            'last_seen_at' => now(),
        ]);

        $this->assertSame(
            ToolEffect::Effectful,
            $this->classifier()->classify('execute_operation', $this->op("mcp:{$serverId}:read_thing"), $this->conversation),
            'an mcp: tool is effectful even when its readOnlyHint is true (the hint is untrusted)'
        );
    }

    // ---------------------------------------------------------------
    // Rule 4 — an execute_operation resolved by HTTP method
    // ---------------------------------------------------------------

    #[Test]
    public function a_get_operation_is_effect_free(): void
    {
        $this->assertSame(ToolEffect::EffectFree, $this->classifier()->classify('execute_operation', $this->op('op.get'), $this->conversation));
    }

    #[Test]
    public function a_head_operation_is_effect_free(): void
    {
        $this->assertSame(ToolEffect::EffectFree, $this->classifier()->classify('execute_operation', $this->op('op.head'), $this->conversation));
    }

    #[Test]
    public function a_put_operation_is_effectful_even_when_idempotent(): void
    {
        // PUT is idempotent (safe to repeat), but the classifier does not
        // trust the hint: any non-GET/HEAD method is effectful.
        $this->assertSame(ToolEffect::Effectful, $this->classifier()->classify('execute_operation', $this->op('op.put'), $this->conversation));
    }

    #[Test]
    public function a_post_operation_is_effectful(): void
    {
        $this->assertSame(ToolEffect::Effectful, $this->classifier()->classify('execute_operation', $this->op('op.post'), $this->conversation));
    }

    #[Test]
    public function a_patch_operation_is_effectful(): void
    {
        $this->assertSame(ToolEffect::Effectful, $this->classifier()->classify('execute_operation', $this->op('op.patch'), $this->conversation));
    }

    #[Test]
    public function a_delete_operation_is_effectful(): void
    {
        $this->assertSame(ToolEffect::Effectful, $this->classifier()->classify('execute_operation', $this->op('op.delete'), $this->conversation));
    }

    #[Test]
    public function an_unresolvable_operation_id_is_effectful(): void
    {
        $this->assertSame(
            ToolEffect::Effectful,
            $this->classifier()->classify('execute_operation', $this->op('nonexistent.operation'), $this->conversation),
            'an operationId that resolves to nothing is effectful'
        );
    }

    // ---------------------------------------------------------------
    // Rule 5 — a registry tool's _meta.method
    // ---------------------------------------------------------------

    #[Test]
    public function a_registry_tool_with_get_meta_is_effect_free(): void
    {
        $this->bindRegistry(
            ['name' => 'registry_read', '_meta' => ['operationId' => 'reg.read', 'method' => 'GET', 'path' => '/reg/read']],
            'registry_read'
        );

        $this->assertSame(ToolEffect::EffectFree, $this->classifier()->classify('registry_read', [], $this->conversation));
    }

    // ---------------------------------------------------------------
    // Rule 6 — the built-in read tools
    // ---------------------------------------------------------------

    #[Test]
    public function the_builtin_read_tools_are_effect_free(): void
    {
        foreach (['search_operations', 'list_applications', 'memory_read', 'memory_search'] as $name) {
            $this->assertSame(ToolEffect::EffectFree, $this->classifier()->classify($name, [], $this->conversation), "{$name} is effect free");
        }
    }

    // ---------------------------------------------------------------
    // Rule 7 — operator-declared effect-free tools
    // ---------------------------------------------------------------

    #[Test]
    public function a_name_listed_in_resume_effect_free_tools_is_effect_free(): void
    {
        config(['llm-client.resume.effect_free_tools' => ['my_custom_read']]);

        $this->assertSame(ToolEffect::EffectFree, $this->classifier()->classify('my_custom_read', [], $this->conversation));
    }

    // ---------------------------------------------------------------
    // Rule 8 — anything else is effectful
    // ---------------------------------------------------------------

    #[Test]
    public function an_unknown_name_is_effectful(): void
    {
        $this->assertSame(ToolEffect::Effectful, $this->classifier()->classify('some_unknown_tool', [], $this->conversation));
    }

    // ---------------------------------------------------------------
    // A lookup that throws classifies effectful (never propagates)
    // ---------------------------------------------------------------

    #[Test]
    public function a_lookup_that_throws_is_effectful(): void
    {
        // The registry lookup is the classifier's rule-5 read; make it throw
        // and assert the classifier degrades to effectful rather than
        // propagating. once() pins that the lookup really was attempted, so
        // the assertion cannot pass vacuously by skipping the lookup.
        $mock = Mockery::mock(McpToolRegistry::class);
        $mock->shouldReceive('findTool')->once()->andThrow(new \RuntimeException('catalogue unavailable'));
        $this->app->instance(McpToolRegistry::class, $mock);

        $this->assertSame(
            ToolEffect::Effectful,
            $this->classifier()->classify('some_unknown_tool', [], $this->conversation),
            'a throwing lookup classifies effectful'
        );
    }
}
