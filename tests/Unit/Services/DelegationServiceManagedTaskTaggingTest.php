<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\DelegationService;
use ClarionApp\LlmClient\Services\ManagerService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 103-manager-agent, Phase 9 (US7), tasks.md T064.
 *
 * Unit tests for `DelegationService::resolveAndValidate()`/
 * `createDelegationRow()`'s not-yet-built `managed_task_id` INHERITANCE
 * (Grounding note item 2, research.md D2/D10): a delegation whose
 * `$parentConversation` is itself an existing delegation's own
 * `helper_conversation_id` must inherit that enclosing delegation's
 * `managed_task_id` -- the same `Delegation::where('helper_conversation_id',
 * ...)->latest('started_at')->first()` lookup `resolveAndValidate()` already
 * uses for `depth` -- propagated unconditionally, for every delegation
 * everywhere, never only for a direct `assign_part` call. `part_id` is
 * NEVER inherited.
 *
 * Also re-exercises Phase 3 (T022)'s own direct stamp end-to-end, from the
 * `assign_part` tool call down through `ManagerService::assignPart()`'s own
 * `delegate()` call, and confirms an entirely ordinary delegation (outside
 * any managed task) still carries both columns `null`, exactly as every
 * 098-101 delegation already does.
 *
 * Mirrors DelegationServiceTest.php's/ManagerServiceAssignPartTest.php's own
 * scaffolding (seedOperationCatalog, the three auxiliary tables
 * buildMessagesPayload()/applyContextWindowTrim() touch regardless,
 * serviceWithScriptedProvider()) since every case here drives a real
 * DelegationService::delegate() call (mocked AgentLoopService::run(), never
 * a live provider).
 *
 * Written before resolveAndValidate()/createDelegationRow() inherit
 * managed_task_id at all -- the nested-inheritance case (T067's own target)
 * is expected to FAIL red until it lands; the other two cases already pass
 * against the Phase 3 code (T022's own direct stamp, and the pre-existing
 * "both null outside a managed task" behavior), included here for the same
 * end-to-end re-exercising reason the task description states.
 */
class DelegationServiceManagedTaskTaggingTest extends TestCase
{
    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog();

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
                $table->index('user_id');
            });
        }

        if (!Schema::hasTable('episodic_memories')) {
            Schema::create('episodic_memories', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->uuid('conversation_id');
                $table->text('summary');
                $table->json('topics');
                $table->boolean('protected')->default(false);
                $table->unsignedInteger('word_count');
                $table->unsignedInteger('summary_word_count');
                $table->json('embedding')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('condensation_states')) {
            Schema::create('condensation_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            });
        }
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_delegations')->delete();
        DB::table('messages')->delete();
        if (Schema::hasTable('mcp_sessions')) {
            DB::table('mcp_sessions')->delete();
        }
        if (Schema::hasTable('agent_run_actions')) {
            DB::table('agent_run_actions')->delete();
        }
        if (Schema::hasTable('agent_run_steps')) {
            DB::table('agent_run_steps')->delete();
        }
        if (Schema::hasTable('agent_runs')) {
            DB::table('agent_runs')->delete();
        }
        DB::table('managed_task_parts')->delete();
        DB::table('managed_tasks')->delete();
        DB::table('agent_helper_assignments')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixture helpers (DelegationServiceTest.php/ManagerServiceAssignPartTest.php precedent)
    // -----------------------------------------------------------------

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

    private function makeAgent(string $name): Agent
    {
        return app(AgentService::class)->create($this->user->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    private function makeConversation(?Agent $agent): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => 'Already titled',
            'agent_id' => $agent?->id,
            'agent_version_id' => $agent?->current_version_id,
        ]);
    }

    private function serviceWithScriptedProvider(array $responses): AgentLoopService
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools, $options = []) use (&$responses) {
            return array_shift($responses);
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        return new AgentLoopService(
            app(McpToolRegistry::class),
            app(McpToolExecutor::class),
            app(OperationCache::class),
            $registry,
            presetRegistry: app(\ClarionApp\LlmClient\Services\StructuredOutputPresetRegistry::class),
            runTraceRecorder: config('llm-client.run_trace.enabled', false) ? app(RunTraceRecorder::class) : null,
        );
    }

    private function plainReply(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content, 'tool_calls' => []]]]];
    }

    private function successResultReply(): array
    {
        return $this->plainReply(json_encode([
            'status' => 'success',
            'summary' => 'Helper completed the task.',
            'output' => [],
            'undone' => '',
        ], JSON_FORCE_OBJECT));
    }

    /** A canned "completed" nested run() return, bypassing the real agent loop entirely. */
    private function completedRunReturn(): array
    {
        return [
            'status' => 'completed',
            'content' => 'Helper reply.',
            'validated' => [
                'status' => 'success',
                'summary' => 'Helper reply.',
                'output' => [],
                'undone' => '',
            ],
            'message_id' => null,
        ];
    }

    // =================================================================
    // Direct stamp, re-exercised end-to-end via ManagerService::assignPart()
    // =================================================================

    #[Test]
    public function a_delegation_created_via_assign_parts_own_delegate_call_carries_the_given_managed_task_and_part_id(): void
    {
        $manager = $this->makeAgent('manager-direct-stamp');
        $helper = $this->makeAgent('helper-direct-stamp');
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helper->id);

        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A one-part task.');
        [$part] = app(ManagerService::class)->planParts($task, ['The only part.']);

        $this->app->instance(AgentLoopService::class, $this->serviceWithScriptedProvider([
            $this->successResultReply(),
        ]));

        $result = app(ManagerService::class)->assignPart($task, $part, $helper->id, 'Do the part.', null);
        $this->assertArrayNotHasKey('error', $result, 'fixture sanity: the assignment itself must be admitted');

        $delegation = Delegation::find($result['delegation_id']);
        $this->assertNotNull($delegation);
        $this->assertSame($task->id, $delegation->managed_task_id, 'a delegation made via assign_part must carry the task\'s own managed_task_id');
        $this->assertSame($part->id, $delegation->part_id, 'a delegation made via assign_part must carry the part\'s own part_id');
    }

    // =================================================================
    // Nested inheritance (T067's own target, research.md D2/D10, Grounding
    // note item 2)
    // =================================================================

    #[Test]
    public function a_nested_delegation_from_a_helpers_own_conversation_inherits_the_enclosing_delegations_managed_task_id_but_never_its_part_id(): void
    {
        $manager = $this->makeAgent('manager-nested');
        $helperOne = $this->makeAgent('helper-one-nested');
        $helperTwo = $this->makeAgent('helper-two-nested');
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helperOne->id);
        // helperOne has its OWN assigned helper -- the ordinary
        // delegate_to_helper path a helper's own conversation may use on
        // its own initiative, entirely independent of assign_part.
        app(AgentHelperService::class)->assign($this->user->id, $helperOne->id, $helperTwo->id);

        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A task whose part\'s helper delegates further.');
        [$part] = app(ManagerService::class)->planParts($task, ['The only part.']);

        // The direct assign_part delegation for this part, seeded exactly
        // as ManagerService::assignPart() itself would have written it --
        // this IS the "enclosing delegation" the nested call below must
        // look up via its own helper_conversation_id.
        $helperOneConversation = $this->makeConversation($helperOne);
        $directDelegation = Delegation::create([
            'parent_conversation_id' => $task->conversation_id,
            'parent_agent_id' => $manager->id,
            'helper_agent_id' => $helperOne->id,
            'helper_conversation_id' => $helperOneConversation->id,
            'owner_user_id' => $this->user->id,
            'task' => 'Do the only part.',
            'depth' => 1,
            'status' => 'in_progress',
            'started_at' => now(),
            'managed_task_id' => $task->id,
            'part_id' => $part->id,
        ]);

        // helperOne's OWN conversation now makes an ORDINARY
        // delegate_to_helper call to helperTwo -- managed_task_id/part_id
        // both explicitly null/omitted, exactly as AgentLoopService's own
        // handleDelegateToHelper() always calls delegate().
        $this->app->instance(AgentLoopService::class, $this->serviceWithScriptedProvider([
            $this->successResultReply(),
        ]));

        $result = app(DelegationService::class)->delegate($helperOneConversation, $helperTwo->id, 'A sub-task helperOne delegates on its own.', null);
        $this->assertArrayNotHasKey('error', $result, 'fixture sanity: the nested delegation itself must be admitted');

        $nested = Delegation::where('parent_conversation_id', $helperOneConversation->id)->first();
        $this->assertNotNull($nested);
        $this->assertNotSame($directDelegation->id, $nested->id, 'fixture sanity: a genuinely new, second Delegation row');

        $this->assertSame(
            $task->id,
            $nested->managed_task_id,
            'a nested delegation made from a helper\'s own conversation must inherit the enclosing delegation\'s managed_task_id, the same way depth already propagates',
        );
        $this->assertNull(
            $nested->part_id,
            'part_id must NEVER be inherited -- it stays null for a nested delegation, set only by a direct assign_part call',
        );
    }

    #[Test]
    public function nested_inheritance_reaches_two_levels_deep(): void
    {
        $manager = $this->makeAgent('manager-two-deep');
        $helperOne = $this->makeAgent('helper-one-two-deep');
        $helperTwo = $this->makeAgent('helper-two-two-deep');
        $helperThree = $this->makeAgent('helper-three-two-deep');
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helperOne->id);
        app(AgentHelperService::class)->assign($this->user->id, $helperOne->id, $helperTwo->id);
        app(AgentHelperService::class)->assign($this->user->id, $helperTwo->id, $helperThree->id);

        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A task two nested hops deep.');
        [$part] = app(ManagerService::class)->planParts($task, ['The only part.']);

        $helperOneConversation = $this->makeConversation($helperOne);
        Delegation::create([
            'parent_conversation_id' => $task->conversation_id,
            'parent_agent_id' => $manager->id,
            'helper_agent_id' => $helperOne->id,
            'helper_conversation_id' => $helperOneConversation->id,
            'owner_user_id' => $this->user->id,
            'task' => 'Do the only part.',
            'depth' => 1,
            'status' => 'in_progress',
            'started_at' => now(),
            'managed_task_id' => $task->id,
            'part_id' => $part->id,
        ]);

        // First hop: helperOne -> helperTwo, ordinary delegate_to_helper.
        $this->app->instance(AgentLoopService::class, $this->serviceWithScriptedProvider([
            $this->successResultReply(),
        ]));
        app(DelegationService::class)->delegate($helperOneConversation, $helperTwo->id, 'helperOne delegates to helperTwo.', null);

        $firstHop = Delegation::where('parent_conversation_id', $helperOneConversation->id)->first();
        $this->assertNotNull($firstHop);
        $this->assertSame($task->id, $firstHop->managed_task_id, 'fixture sanity: the first hop must itself have inherited managed_task_id');
        $helperTwoConversation = Conversation::find($firstHop->helper_conversation_id);
        $this->assertNotNull($helperTwoConversation);

        // Second hop: helperTwo -> helperThree, another ordinary
        // delegate_to_helper call, made from helperTwo's own conversation
        // (itself the helper_conversation_id of the FIRST hop, not the
        // original direct assignment).
        $this->app->instance(AgentLoopService::class, $this->serviceWithScriptedProvider([
            $this->successResultReply(),
        ]));
        app(DelegationService::class)->delegate($helperTwoConversation, $helperThree->id, 'helperTwo delegates to helperThree.', null);

        $secondHop = Delegation::where('parent_conversation_id', $helperTwoConversation->id)->first();
        $this->assertNotNull($secondHop);
        $this->assertSame($task->id, $secondHop->managed_task_id, 'inheritance must reach two nested hops deep, not just one');
        $this->assertNull($secondHop->part_id);
    }

    // =================================================================
    // Entirely outside any managed task -- both columns stay null
    // =================================================================

    #[Test]
    public function a_delegation_made_entirely_outside_any_managed_task_has_both_columns_null(): void
    {
        $parent = $this->makeAgent('parent-agent-no-managed-task');
        $helper = $this->makeAgent('helper-agent-no-managed-task');
        app(AgentHelperService::class)->assign($this->user->id, $parent->id, $helper->id);

        $conversation = $this->makeConversation($parent);

        $this->app->instance(AgentLoopService::class, $this->serviceWithScriptedProvider([
            $this->successResultReply(),
        ]));

        $result = app(DelegationService::class)->delegate($conversation, $helper->id, 'An ordinary delegation.', null);
        $this->assertArrayNotHasKey('error', $result, 'fixture sanity');

        $row = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->managed_task_id, 'a delegation made entirely outside any managed task must have a null managed_task_id, exactly as every 098-101 delegation already does');
        $this->assertNull($row->part_id);
    }
}
