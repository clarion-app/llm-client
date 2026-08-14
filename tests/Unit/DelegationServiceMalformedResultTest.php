<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Exceptions\SchemaValidationError;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\DelegationService;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 099-result-aggregation, Phase 4 (US5), tasks.md T023.
 *
 * Unit tests for `DelegationService::delegate()`'s `catch (\Throwable $e)`
 * block and its final "neither completed nor a recognized ceiling"
 * fallback branch (research.md D2/D3, Grounding note item 6, data-model.md
 * §1/§3, contracts/result-aggregation-meta-tool.md §2). `AgentLoopService::
 * run()` is mocked directly to either throw or return a non-completing
 * shape -- the same seam `DelegationServiceResultMappingTest.php` (T014)
 * establishes for this feature's own `DelegationService` unit tests.
 *
 * Written before Phase 4's implementation tasks (T026/T027) touch the
 * catch/fallback branches at all -- every assertion below is expected to
 * FAIL red: today none of result_status/result_reason/result_summary/
 * result_output/result_undone/result_truncated is written on any of these
 * paths, and closeAction() is called with no $content argument on any of
 * them either.
 */
class DelegationServiceMalformedResultTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_delegations')->delete();
        DB::table('messages')->delete();
        if (Schema::hasTable('agent_run_actions')) {
            DB::table('agent_run_actions')->delete();
        }
        if (Schema::hasTable('agent_run_steps')) {
            DB::table('agent_run_steps')->delete();
        }
        if (Schema::hasTable('agent_runs')) {
            DB::table('agent_runs')->delete();
        }
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
    // Operation-catalog scaffolding (DelegationServiceResultMappingTest's
    // own established precedent)
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

    // -----------------------------------------------------------------
    // Fixture helpers
    // -----------------------------------------------------------------

    private function makeAgent(string $name): Agent
    {
        return app(AgentService::class)->create($this->user->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    private function makeConversation(Agent $agent): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => 'Already titled',
            'agent_id' => $agent->id,
            'agent_version_id' => $agent->current_version_id,
        ]);
    }

    /**
     * @return array{0: Agent, 1: Conversation}
     */
    private function makeDelegationFixture(string $suffix): array
    {
        $parent = $this->makeAgent("parent-agent-{$suffix}");
        $helper = $this->makeAgent("helper-agent-{$suffix}");
        app(AgentHelperService::class)->assign($this->user->id, $parent->id, $helper->id);

        $conversation = $this->makeConversation($parent);

        return [$helper, $conversation];
    }

    private function mockRunThrowing(\Throwable $e): void
    {
        $mock = Mockery::mock(AgentLoopService::class);
        $mock->shouldReceive('run')->once()->andThrow($e);
        $this->app->instance(AgentLoopService::class, $mock);
    }

    private function mockRunReturning(array $rawResult): void
    {
        $mock = Mockery::mock(AgentLoopService::class);
        $mock->shouldReceive('run')->once()->andReturn($rawResult);
        $this->app->instance(AgentLoopService::class, $mock);
    }

    /**
     * The exact eight-key shape contracts/result-aggregation-meta-tool.md
     * §2 specifies for every completed/exhausted/failed delegation.
     */
    private function assertContractShape(array $result): void
    {
        $this->assertSame(
            ['delegation_id', 'helper', 'status', 'summary', 'output', 'undone', 'truncated', 'reason'],
            array_keys($result),
            'delegate_to_helper\'s tool result must match contracts §2\'s eight-key shape exactly',
        );
    }

    // =================================================================
    // Case 1: SchemaValidationError, non-empty raw content -> malformed_output
    // =================================================================

    #[Test]
    public function a_schema_validation_error_with_non_empty_raw_content_maps_to_malformed_output(): void
    {
        [$helper, $conversation] = $this->makeDelegationFixture('malformed');

        $this->mockRunThrowing(new SchemaValidationError(
            'The response did not match the required schema.',
            [],
            'Sure, here is my answer: the invoice has three line items.',
        ));

        $result = app(DelegationService::class)->delegate($conversation, $helper->id, 'Extract line items.', 'Invoice #123.');

        $this->assertContractShape($result);
        $this->assertSame('failure', $result['status'] ?? null);
        $this->assertSame('malformed_output', $result['reason'] ?? null);
        $this->assertNull($result['output'], 'a malformed-output failure must never carry a partial/misleading output object (FR-007)');
        $this->assertIsString($result['summary'] ?? null);
        $this->assertNotSame('', $result['summary'] ?? null, 'result_summary must be a non-empty fixed system-composed note');

        $row = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($row, 'a caught exception must still write a Delegation row');
        $this->assertSame('failed', $row->status, "098's own status column stays 'failed', unchanged by this feature");
        $this->assertSame('failure', $row->result_status);
        $this->assertSame('malformed_output', $row->result_reason);
        $this->assertIsString($row->result_summary);
        $this->assertNotSame('', $row->result_summary);
        $this->assertNull($row->result_output);
    }

    // =================================================================
    // Case 2: SchemaValidationError, empty/whitespace raw content -> no_output
    // =================================================================

    #[Test]
    public function a_schema_validation_error_with_empty_raw_content_maps_to_no_output(): void
    {
        [$helper, $conversation] = $this->makeDelegationFixture('empty-raw');

        $this->mockRunThrowing(new SchemaValidationError(
            'The response was empty.',
            [],
            "   \n  ",
        ));

        $result = app(DelegationService::class)->delegate($conversation, $helper->id, 'Extract line items.', 'Invoice #123.');

        $this->assertContractShape($result);
        $this->assertSame('failure', $result['status'] ?? null);
        $this->assertSame('no_output', $result['reason'] ?? null, "a whitespace-only raw content is the exact trim(...) === '' split research.md D2 specifies");
        $this->assertNull($result['output'], 'FR-007');
        $this->assertIsString($result['summary'] ?? null);
        $this->assertNotSame('', $result['summary'] ?? null);

        $row = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('failed', $row->status);
        $this->assertSame('failure', $row->result_status);
        $this->assertSame('no_output', $row->result_reason);
        $this->assertIsString($row->result_summary);
        $this->assertNotSame('', $row->result_summary);
        $this->assertNull($row->result_output);
    }

    // =================================================================
    // Case 3: a generic \Throwable (not SchemaValidationError) -> exception
    // =================================================================

    #[Test]
    public function a_generic_throwable_maps_to_exception(): void
    {
        [$helper, $conversation] = $this->makeDelegationFixture('generic-exception');

        $this->mockRunThrowing(new \RuntimeException('The provider connection was reset.'));

        $result = app(DelegationService::class)->delegate($conversation, $helper->id, 'Extract line items.', 'Invoice #123.');

        $this->assertContractShape($result);
        $this->assertSame('failure', $result['status'] ?? null);
        $this->assertSame('exception', $result['reason'] ?? null);
        $this->assertNull($result['output'], 'FR-007');
        $this->assertIsString($result['summary'] ?? null);
        $this->assertNotSame('', $result['summary'] ?? null);

        $row = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('failed', $row->status);
        $this->assertSame('failure', $row->result_status);
        $this->assertSame('exception', $row->result_reason);
        $this->assertIsString($row->result_summary);
        $this->assertNotSame('', $row->result_summary);
        $this->assertNull($row->result_output);
    }

    // =================================================================
    // Case 4: run() returns non-throwing, non-completed, non-ceiling-coded
    // ("No response from LLM", AgentLoopService.php's own early-return
    // shape, Grounding note item 6) -> no_output, via the FINAL FALLBACK
    // branch, NOT the SchemaValidationError catch
    // =================================================================

    #[Test]
    public function a_non_completing_non_ceiling_coded_run_return_maps_to_no_output_via_the_final_fallback(): void
    {
        [$helper, $conversation] = $this->makeDelegationFixture('final-fallback');

        $this->mockRunReturning([
            'status' => 'error',
            'content' => 'No response from LLM',
            'message_id' => null,
        ]);

        $result = app(DelegationService::class)->delegate($conversation, $helper->id, 'Extract line items.', 'Invoice #123.');

        $this->assertContractShape($result);
        $this->assertSame('failure', $result['status'] ?? null);
        $this->assertSame('no_output', $result['reason'] ?? null, "the provider-returned-no-choices-at-all case is DelegationService's own final fallback branch, not the SchemaValidationError catch (Grounding note item 6)");
        $this->assertNull($result['output'], 'FR-007');
        $this->assertIsString($result['summary'] ?? null);
        $this->assertNotSame('', $result['summary'] ?? null);

        $row = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('failed', $row->status);
        $this->assertSame('failure', $row->result_status);
        $this->assertSame('no_output', $row->result_reason);
        $this->assertIsString($row->result_summary);
        $this->assertNotSame('', $row->result_summary);
        $this->assertNull($row->result_output);
    }

    // =================================================================
    // closeAction() gains a $content argument on the catch/fallback paths
    // too (FR-015/SC-008, mutation-checklist row 11) -- covers T026's
    // SchemaValidationError branch and T027's final-fallback branch
    // =================================================================

    #[Test]
    public function the_schema_validation_error_branch_passes_the_full_six_field_result_to_close_action_as_content(): void
    {
        [$helper, $conversation] = $this->makeDelegationFixture('close-action-malformed');

        $this->mockRunThrowing(new SchemaValidationError(
            'The response did not match the required schema.',
            [],
            'not valid json at all',
        ));

        $capturedContent = 'not-yet-captured';

        $mockRecorder = Mockery::mock(RunTraceRecorder::class);
        $mockRecorder->shouldReceive('openAction')->once()->andReturn('fake-action-id');
        $mockRecorder->shouldReceive('closeAction')
            ->once()
            ->withArgs(function ($actionId, $outcome = null, $failureReason = null, $content = null) use (&$capturedContent) {
                $capturedContent = $content;

                return true;
            });
        $this->app->instance(RunTraceRecorder::class, $mockRecorder);

        app(DelegationService::class)->delegate($conversation, $helper->id, 'Extract line items.', 'Invoice #123.');

        $this->assertIsString($capturedContent, 'closeAction() must be called with a non-null $content argument for the SchemaValidationError branch (FR-015/SC-008)');
        $decoded = json_decode((string) $capturedContent, true);
        $this->assertIsArray($decoded, 'closeAction()\'s $content argument must be valid JSON');
        $this->assertSame('failure', $decoded['status'] ?? null);
        $this->assertSame('malformed_output', $decoded['reason'] ?? null);
        $this->assertArrayHasKey('output', $decoded, 'output key must be present (and null), not merely absent');
        $this->assertNull($decoded['output']);
    }

    #[Test]
    public function the_final_fallback_branch_passes_the_full_six_field_result_to_close_action_as_content(): void
    {
        [$helper, $conversation] = $this->makeDelegationFixture('close-action-fallback');

        $this->mockRunReturning([
            'status' => 'error',
            'content' => 'No response from LLM',
            'message_id' => null,
        ]);

        $capturedContent = 'not-yet-captured';

        $mockRecorder = Mockery::mock(RunTraceRecorder::class);
        $mockRecorder->shouldReceive('openAction')->once()->andReturn('fake-action-id');
        $mockRecorder->shouldReceive('closeAction')
            ->once()
            ->withArgs(function ($actionId, $outcome = null, $failureReason = null, $content = null) use (&$capturedContent) {
                $capturedContent = $content;

                return true;
            });
        $this->app->instance(RunTraceRecorder::class, $mockRecorder);

        app(DelegationService::class)->delegate($conversation, $helper->id, 'Extract line items.', 'Invoice #123.');

        $this->assertIsString($capturedContent, 'closeAction() must be called with a non-null $content argument for the final-fallback branch (FR-015/SC-008)');
        $decoded = json_decode((string) $capturedContent, true);
        $this->assertIsArray($decoded, 'closeAction()\'s $content argument must be valid JSON');
        $this->assertSame('failure', $decoded['status'] ?? null);
        $this->assertSame('no_output', $decoded['reason'] ?? null);
        $this->assertArrayHasKey('output', $decoded);
        $this->assertNull($decoded['output']);
    }
}
