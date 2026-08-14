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
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 099-result-aggregation, Phase 3 (US1 + US2), tasks.md T014.
 *
 * Unit tests for `DelegationService::delegate()`'s revised `'completed'`
 * branch (data-model.md §3, research.md D1/D3, contracts/
 * result-aggregation-meta-tool.md §2). `AgentLoopService::run()`'s return
 * array is mocked directly -- this package's own established mocking seam
 * for `DelegationService`, confirmed against `tests/Unit/Services/
 * DelegationServiceTest.php`, which binds a `Mockery::mock(AgentLoopService::class)`
 * via `$this->app->instance(AgentLoopService::class, $mock)` and drives
 * `delegate()` against its scripted return value, never a real LLM call.
 *
 * Written before Phase 3's implementation tasks (T016-T020) touch
 * `DelegationService::delegate()`'s `'completed'` branch at all -- every
 * assertion below is expected to FAIL red: today the `'completed'` branch
 * still reads `$rawResult['content']` (not `$rawResult['validated']`),
 * writes none of the six new `result_*` columns, and returns the old
 * `{status, helper, result}` shape rather than contracts §2's eight-key
 * `{delegation_id, helper, status, summary, output, undone, truncated,
 * reason}` shape.
 */
class DelegationServiceResultMappingTest extends TestCase
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
        if (\Illuminate\Support\Facades\Schema::hasTable('agent_run_actions')) {
            DB::table('agent_run_actions')->delete();
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('agent_run_steps')) {
            DB::table('agent_run_steps')->delete();
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('agent_runs')) {
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
    // Operation-catalog scaffolding (DelegationServiceTest's own
    // established precedent)
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
     * A parent/helper pair with an active AgentHelperAssignment, and the
     * parent's own conversation -- the minimum fixture every case below
     * needs before it can call delegate() at all.
     *
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

    /**
     * Mocks AgentLoopService::run() to return $rawResult and binds it into
     * the container, exactly as DelegationServiceTest's own scripted cases
     * do for a directly-controlled return array (rather than a scripted
     * LlmProvider driving a real run()).
     */
    private function mockRunReturning(array $rawResult): void
    {
        $mock = Mockery::mock(AgentLoopService::class);
        $mock->shouldReceive('run')->once()->andReturn($rawResult);
        $this->app->instance(AgentLoopService::class, $mock);
    }

    /**
     * 099-result-aggregation, Phase 4 (US5), tasks.md T023's own seam.
     */
    private function mockRunThrowing(\Throwable $e): void
    {
        $mock = Mockery::mock(AgentLoopService::class);
        $mock->shouldReceive('run')->once()->andThrow($e);
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
    // Assertion 1: the new $delegationOptions keys reach the nested
    // run() call (mutation-checklist row 1's own target)
    // =================================================================

    #[Test]
    public function delegate_passes_the_delegation_result_preset_and_retry_options_into_the_nested_run_call(): void
    {
        [$helper, $conversation] = $this->makeDelegationFixture('options');

        $capturedOptions = null;

        $mock = Mockery::mock(AgentLoopService::class);
        $mock->shouldReceive('run')
            ->once()
            ->andReturnUsing(function ($conversationArg, $message, array $options = []) use (&$capturedOptions) {
                $capturedOptions = $options;

                return [
                    'status' => 'completed',
                    'content' => 'irrelevant to this assertion',
                    'validated' => [
                        'status' => 'success',
                        'summary' => 'Done.',
                        'output' => [],
                        'undone' => '',
                    ],
                    'message_id' => null,
                ];
            });
        $this->app->instance(AgentLoopService::class, $mock);

        app(DelegationService::class)->delegate($conversation, $helper->id, 'Do the task.', null);

        $this->assertIsArray($capturedOptions, 'fixture sanity: the nested run() call must actually have been invoked');
        $this->assertSame(
            'delegation_result',
            $capturedOptions['preset'] ?? null,
            'delegate() must pass preset: delegation_result into the nested run() call\'s $options (research.md D1)',
        );
        $this->assertTrue(
            $capturedOptions['retry_on_validation_failure'] ?? null,
            'delegate() must pass retry_on_validation_failure: true into the nested run() call\'s $options',
        );
        $this->assertSame(
            config('llm-client.delegation.max_result_schema_retries', 2),
            $capturedOptions['max_schema_retries'] ?? null,
            'delegate() must pass max_schema_retries from config(llm-client.delegation.max_result_schema_retries) into the nested run() call\'s $options',
        );
    }

    // =================================================================
    // Assertions 2/5: validated status: success
    // =================================================================

    #[Test]
    public function a_validated_success_result_maps_to_result_status_success_with_an_explicit_empty_undone_and_no_truncation(): void
    {
        [$helper, $conversation] = $this->makeDelegationFixture('success');

        $output = ['line_items' => ['Widget A', 'Widget B'], 'total' => '1042.50'];

        $this->mockRunReturning([
            'status' => 'completed',
            'content' => json_encode($output),
            'validated' => [
                'status' => 'success',
                'summary' => 'Extracted all line items from the invoice.',
                'output' => $output,
                'undone' => '',
            ],
            'message_id' => null,
        ]);

        $result = app(DelegationService::class)->delegate($conversation, $helper->id, 'Extract line items.', 'Invoice #123.');

        $this->assertContractShape($result);
        $this->assertSame('success', $result['status'] ?? null);
        $this->assertSame('Extracted all line items from the invoice.', $result['summary'] ?? null);
        $this->assertSame($output, $result['output'] ?? null);
        $this->assertSame('', $result['undone'] ?? null, 'undone must be present and exactly "" on full success, never omitted (FR-004)');
        $this->assertSame(false, $result['truncated'] ?? null);
        $this->assertNull($result['reason'], 'reason must be null for a success outcome');

        $row = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($row, 'a completed delegation must still write a Delegation row');
        $this->assertSame('success', $row->result_status);
        $this->assertNull($row->result_reason);
        $this->assertSame('Extracted all line items from the invoice.', $row->result_summary);
        $this->assertSame($output, json_decode((string) $row->result_output, true));
        $this->assertSame('', $row->result_undone, 'result_undone must be exactly "" (not null) on full success');
        $this->assertFalse($row->result_truncated, 'result_truncated must be explicitly false, not merely absent');
    }

    // =================================================================
    // Assertions 3/5: validated status: partial (helper self-reported)
    // =================================================================

    #[Test]
    public function a_validated_partial_result_maps_to_result_status_partial_reason_helper_reported_with_distinct_summary_and_undone(): void
    {
        [$helper, $conversation] = $this->makeDelegationFixture('partial');

        $output = ['line_items' => ['Widget A', 'Widget B', 'Widget C']];

        $this->mockRunReturning([
            'status' => 'completed',
            'content' => json_encode($output),
            'validated' => [
                'status' => 'partial',
                'summary' => 'Extracted 3 of 5 line items before running out of time.',
                'output' => $output,
                'undone' => 'The remaining 2 line items still need extraction.',
            ],
            'message_id' => null,
        ]);

        $result = app(DelegationService::class)->delegate($conversation, $helper->id, 'Extract line items.', 'Invoice #123.');

        $this->assertContractShape($result);
        $this->assertSame('partial', $result['status'] ?? null);
        $this->assertSame('helper_reported', $result['reason'] ?? null, 'a helper\'s own self-reported partial gets reason: helper_reported (research.md D3), symmetric with self-reported failure');
        $this->assertNotSame($result['summary'] ?? null, $result['undone'] ?? null, 'summary and undone must be distinct fields (FR-003)');
        $this->assertNotSame('', $result['summary'] ?? null);
        $this->assertNotSame('', $result['undone'] ?? null);

        $row = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('partial', $row->result_status);
        $this->assertSame('helper_reported', $row->result_reason);
        $this->assertNotSame($row->result_summary, $row->result_undone);
        $this->assertNotEmpty($row->result_summary);
        $this->assertNotEmpty($row->result_undone);
    }

    // =================================================================
    // Assertions 4/5: validated status: failure (helper's own honest
    // report)
    // =================================================================

    #[Test]
    public function a_validated_failure_result_maps_to_result_status_failure_reason_helper_reported(): void
    {
        [$helper, $conversation] = $this->makeDelegationFixture('failure');

        $this->mockRunReturning([
            'status' => 'completed',
            'content' => json_encode(['partial_data' => 'should never surface']),
            'validated' => [
                'status' => 'failure',
                'summary' => 'Could not access the invoice attachment.',
                'output' => ['partial_data' => 'should never surface'],
                'undone' => 'Everything -- the task could not be started.',
            ],
            'message_id' => null,
        ]);

        $result = app(DelegationService::class)->delegate($conversation, $helper->id, 'Extract line items.', 'Invoice #123.');

        $this->assertContractShape($result);
        $this->assertSame('failure', $result['status'] ?? null);
        $this->assertSame('helper_reported', $result['reason'] ?? null);
        $this->assertNull($result['output'], 'a failure outcome\'s output must be null, never the helper\'s own (possibly misleading) partial content (FR-007)');

        $row = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('failure', $row->result_status);
        $this->assertSame('helper_reported', $row->result_reason);
        $this->assertNull($row->result_output, 'result_output must be null for any failure outcome, regardless of what the helper\'s own output object contained (FR-007)');
    }

    // =================================================================
    // Assertion 6: closeAction() gains a $content argument for the
    // completed-branch success case (FR-015/SC-008, mutation-checklist
    // row 11)
    // =================================================================

    #[Test]
    public function the_completed_branch_passes_the_full_six_field_result_to_close_action_as_content(): void
    {
        [$helper, $conversation] = $this->makeDelegationFixture('close-action');

        $output = ['line_items' => ['Widget A']];

        $this->mockRunReturning([
            'status' => 'completed',
            'content' => json_encode($output),
            'validated' => [
                'status' => 'success',
                'summary' => 'Extracted the one line item.',
                'output' => $output,
                'undone' => '',
            ],
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

        app(DelegationService::class)->delegate($conversation, $helper->id, 'Extract the line item.', 'Invoice #123.');

        $this->assertIsString($capturedContent, 'closeAction() must be called with a non-null $content argument for the completed-branch success case (FR-015/SC-008)');
        $decoded = json_decode((string) $capturedContent, true);
        $this->assertIsArray($decoded, 'closeAction()\'s $content argument must be valid JSON');
        $this->assertSame('success', $decoded['status'] ?? null);
        $this->assertSame('Extracted the one line item.', $decoded['summary'] ?? null);
        $this->assertSame($output, $decoded['output'] ?? null);
        $this->assertSame('', $decoded['undone'] ?? null);
        $this->assertSame(false, $decoded['truncated'] ?? null);
        $this->assertNull($decoded['reason']);
    }

    // =================================================================
    // 099-result-aggregation, Phase 4 (US5), tasks.md T024: across every
    // result_status: 'failure' reason now producible (helper_reported from
    // Phase 3, malformed_output/no_output/exception from Phase 4),
    // result_output must be exactly null -- never {}, never a decoded
    // value, never the string 'null' (FR-007, mutation-checklist row 3).
    // The helper_reported case should already pass from Phase 3; the three
    // Phase 4 reasons are expected to FAIL until T026/T027 land.
    // =================================================================

    #[Test]
    public function result_output_is_exactly_null_for_every_failure_reason(): void
    {
        // helper_reported (Phase 3 -- should already pass)
        [$helper, $conversation] = $this->makeDelegationFixture('failure-reason-helper-reported');
        $this->mockRunReturning([
            'status' => 'completed',
            'content' => json_encode(['partial_data' => 'should never surface']),
            'validated' => [
                'status' => 'failure',
                'summary' => 'Could not access the invoice attachment.',
                'output' => ['partial_data' => 'should never surface'],
                'undone' => 'Everything -- the task could not be started.',
            ],
            'message_id' => null,
        ]);
        $result = app(DelegationService::class)->delegate($conversation, $helper->id, 'Extract line items.', 'Invoice #123.');
        $this->assertSame('helper_reported', $result['reason'] ?? null, 'fixture sanity');
        $this->assertNull($result['output']);
        $row = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNull($row->result_output);
        $this->assertNotSame('null', $row->result_output);

        // malformed_output (Phase 4)
        [$helper, $conversation] = $this->makeDelegationFixture('failure-reason-malformed');
        $this->mockRunThrowing(new SchemaValidationError(
            'The response did not match the required schema.',
            [],
            'not valid json at all',
        ));
        $result = app(DelegationService::class)->delegate($conversation, $helper->id, 'Extract line items.', 'Invoice #123.');
        $this->assertSame('malformed_output', $result['reason'] ?? null, 'fixture sanity');
        $this->assertNull($result['output']);
        $row = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNull($row->result_output);
        $this->assertNotSame('null', $row->result_output);

        // no_output (Phase 4, via SchemaValidationError with empty raw content)
        [$helper, $conversation] = $this->makeDelegationFixture('failure-reason-no-output');
        $this->mockRunThrowing(new SchemaValidationError(
            'The response was empty.',
            [],
            '   ',
        ));
        $result = app(DelegationService::class)->delegate($conversation, $helper->id, 'Extract line items.', 'Invoice #123.');
        $this->assertSame('no_output', $result['reason'] ?? null, 'fixture sanity');
        $this->assertNull($result['output']);
        $row = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNull($row->result_output);
        $this->assertNotSame('null', $row->result_output);

        // exception (Phase 4, a generic \Throwable)
        [$helper, $conversation] = $this->makeDelegationFixture('failure-reason-exception');
        $this->mockRunThrowing(new \RuntimeException('boom'));
        $result = app(DelegationService::class)->delegate($conversation, $helper->id, 'Extract line items.', 'Invoice #123.');
        $this->assertSame('exception', $result['reason'] ?? null, 'fixture sanity');
        $this->assertNull($result['output']);
        $row = Delegation::where('parent_conversation_id', $conversation->id)->first();
        $this->assertNull($row->result_output);
        $this->assertNotSame('null', $row->result_output);
    }
}
