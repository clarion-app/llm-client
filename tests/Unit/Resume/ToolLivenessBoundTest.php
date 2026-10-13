<?php

namespace Tests\Unit\Resume;

use ClarionApp\LlmClient\Models\CapabilityOffering;
use ClarionApp\LlmClient\Services\RunCheckpoint;
use ClarionApp\LlmClient\Services\RunLease;
use ClarionApp\LlmClient\Services\ToolLivenessBound;
use ClarionApp\LlmClient\ValueObjects\ResumeDriver;
use ClarionApp\LlmClient\ValueObjects\ResumeMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The ToolLivenessBound (checkpoint-ledger contract §4): how long a run's
 * lease must be extended before a slow action, so a live but slow run is
 * never mistaken for dead. Each action type is bounded by its own timeout
 * (a command, a test, an mcp call, a delegation, a capability call), an
 * unknown action by the detection window, and the lease to set is
 * `max(base, bound + lease_grace_seconds)` — the base being the detection
 * window for a root run and `helper_lease_seconds` for a helper run. A
 * model call is bounded by the provider's timeout, and the whole thing is
 * capped by the run's own deadline when it has one.
 */
class ToolLivenessBoundTest extends TestCase
{
    private function bound(): ToolLivenessBound
    {
        return app(ToolLivenessBound::class);
    }

    private function makeRun(string $id, array $overrides = []): void
    {
        DB::table('agent_runs')->insert(array_merge([
            'id' => $id,
            'kind' => 'interactive',
            'user_id' => '11111111-1111-1111-1111-111111111111',
            'started_at' => now()->format('Y-m-d H:i:s.u'),
            'end_state' => 'in_progress',
        ], $overrides));
    }

    private function runRow(string $id): object
    {
        $row = DB::table('agent_runs')->where('id', $id)->first();
        $this->assertNotNull($row, "run {$id} must exist");

        return $row;
    }

    /** The coding-workspace operation ids, from the loop's own constants. */
    private function commandOp(): string
    {
        return \ClarionApp\LlmClient\Services\AgentLoopService::CODING_WORKSPACE_RUN_COMMAND_OPERATION_ID;
    }

    private function testOp(): string
    {
        return 'clarionApp.llmClient.codingWorkspace.runTests';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // The bound for each action type
    // ---------------------------------------------------------------

    #[Test]
    public function a_command_tool_is_bounded_by_command_timeout(): void
    {
        $this->assertSame(
            (int) config('llm-client.coding_agent.command_timeout_seconds'),
            $this->bound()->for('execute_operation', ['operationId' => $this->commandOp()]),
            'a command tool is bounded by coding_agent.command_timeout_seconds'
        );
    }

    #[Test]
    public function a_test_tool_is_bounded_by_test_timeout(): void
    {
        $this->assertSame(
            (int) config('llm-client.coding_agent.test_timeout_seconds'),
            $this->bound()->for('execute_operation', ['operationId' => $this->testOp()]),
            'a test tool is bounded by coding_agent.test_timeout_seconds'
        );
    }

    #[Test]
    public function an_mcp_tool_is_bounded_by_call_timeout(): void
    {
        $serverId = (string) Str::uuid();
        $this->assertSame(
            (int) config('llm-client.mcp_client.call_timeout_seconds'),
            $this->bound()->for('execute_operation', ['operationId' => "mcp:{$serverId}:some_tool"]),
            'an mcp: tool is bounded by mcp_client.call_timeout_seconds'
        );
    }

    #[Test]
    public function delegate_to_helper_is_bounded_by_max_seconds(): void
    {
        $this->assertSame(
            (int) config('llm-client.delegation.max_seconds'),
            $this->bound()->for('delegate_to_helper', ['helper_agent_id' => (string) Str::uuid(), 'task' => 'x']),
            'delegate_to_helper is bounded by delegation.max_seconds'
        );
    }

    #[Test]
    public function a_capability_call_is_bounded_by_capability_max_seconds(): void
    {
        $offering = CapabilityOffering::create([
            'id' => (string) Str::uuid(),
            'offered_agent_id' => (string) Str::uuid(),
            'caller_agent_id' => (string) Str::uuid(),
            'owner_user_id' => '11111111-1111-1111-1111-111111111111',
            'capability_name' => 'summarise',
            'capability_description' => 'Summarises a document.',
            'input_description' => 'The document text.',
        ]);

        $this->assertSame(
            (int) config('llm-client.capability_agent.max_seconds'),
            $this->bound()->for('execute_operation', ['operationId' => $offering->id]),
            'a capability call is bounded by capability_agent.max_seconds'
        );
    }

    #[Test]
    public function an_unknown_tool_is_bounded_by_the_detection_window(): void
    {
        $this->assertSame(
            (int) config('llm-client.resume.detection_window_seconds'),
            $this->bound()->for('some_unknown_tool', []),
            'an unknown tool is bounded by the detection window'
        );
        $this->assertSame(
            (int) config('llm-client.resume.detection_window_seconds'),
            $this->bound()->for('execute_operation', ['operationId' => 'nonexistent.operation']),
            'an unresolvable operation id is bounded by the detection window'
        );
    }

    // ---------------------------------------------------------------
    // The lease to set is max(base, bound + lease_grace_seconds)
    // ---------------------------------------------------------------

    #[Test]
    public function the_lease_is_at_least_the_window(): void
    {
        // With the default config every bound + grace is below the window,
        // so the lease is the window itself.
        $this->assertSame(
            (int) config('llm-client.resume.detection_window_seconds'),
            $this->bound()->leaseSeconds('delegate_to_helper', ['helper_agent_id' => (string) Str::uuid(), 'task' => 'x'], ResumeDriver::Root),
            'with the default config the lease is the detection window'
        );
    }

    #[Test]
    public function the_lease_is_max_of_window_and_bound_plus_grace(): void
    {
        // A command bound large enough that bound + grace exceeds the
        // window, so the max must pick the larger of the two.
        config(['llm-client.coding_agent.command_timeout_seconds' => 400]);
        $window = (int) config('llm-client.resume.detection_window_seconds');
        $grace = (int) config('llm-client.resume.lease_grace_seconds');

        $this->assertSame(
            max($window, 400 + $grace),
            $this->bound()->leaseSeconds('execute_operation', ['operationId' => $this->commandOp()], ResumeDriver::Root),
            'the lease is max(window, bound + grace)'
        );
    }

    #[Test]
    public function the_helper_base_is_helper_lease_seconds(): void
    {
        // The contrast that pins the base: for the same tool, a helper run
        // leases bound + grace (180 s, below the window) while a root run
        // leases the window (300 s). A helper base of the window would make
        // both 300; only a base of helper_lease_seconds gives 180.
        $tool = 'delegate_to_helper';
        $args = ['helper_agent_id' => (string) Str::uuid(), 'task' => 'x'];
        $grace = (int) config('llm-client.resume.lease_grace_seconds');
        $delegationMax = (int) config('llm-client.delegation.max_seconds');
        $helperBase = (int) config('llm-client.resume.helper_lease_seconds');
        $window = (int) config('llm-client.resume.detection_window_seconds');

        $this->assertSame(
            max($helperBase, $delegationMax + $grace),
            $this->bound()->leaseSeconds($tool, $args, ResumeDriver::BatchMember),
            'a helper run leases max(helper_lease_seconds, bound + grace)'
        );
        $this->assertSame(
            max($window, $delegationMax + $grace),
            $this->bound()->leaseSeconds($tool, $args, ResumeDriver::Root),
            'a root run leases max(window, bound + grace)'
        );
        $this->assertLessThan(
            max($window, $delegationMax + $grace),
            max($helperBase, $delegationMax + $grace),
            'the helper lease is below the window, proving the base is helper_lease_seconds'
        );
    }

    // ---------------------------------------------------------------
    // A model call is bounded by the provider timeout, capped by the
    // deadline
    // ---------------------------------------------------------------

    private function setProviderTimeout(int $seconds): void
    {
        config([
            'llm-client.providers.openai.timeout' => $seconds,
            'llm-client.providers.anthropic.timeout' => $seconds,
            'llm-client.providers.llama_cpp.timeout' => $seconds,
        ]);
    }

    #[Test]
    public function model_call_lease_is_max_of_base_and_provider_timeout_plus_grace(): void
    {
        // A provider timeout large enough that timeout + grace exceeds the
        // window, so the max must pick the provider side.
        $this->setProviderTimeout(400);
        $window = (int) config('llm-client.resume.detection_window_seconds');
        $grace = (int) config('llm-client.resume.lease_grace_seconds');

        $this->assertSame(
            max($window, 400 + $grace),
            $this->bound()->modelCallLeaseSeconds(ResumeDriver::Root),
            'the model call lease is max(window, provider timeout + grace)'
        );
    }

    #[Test]
    public function model_call_lease_is_bounded_by_the_deadline(): void
    {
        $this->setProviderTimeout(400); // uncapped lease would be 460 s
        $deadline = Carbon::now()->addSeconds(100);

        $leaseSeconds = $this->bound()->modelCallLeaseSeconds(ResumeDriver::Root, $deadline);

        $this->assertGreaterThan(0, $leaseSeconds, 'the lease is still positive');
        $this->assertTrue(
            Carbon::now()->addSeconds($leaseSeconds)->lessThanOrEqualTo($deadline),
            "now + {$leaseSeconds}s must not exceed the deadline ({$deadline->toDateTimeString()})"
        );
    }

    #[Test]
    public function model_call_lease_is_not_capped_by_a_distant_deadline(): void
    {
        $this->setProviderTimeout(400);
        $window = (int) config('llm-client.resume.detection_window_seconds');
        $grace = (int) config('llm-client.resume.lease_grace_seconds');
        $deadline = Carbon::now()->addSeconds(1000);

        $this->assertSame(
            max($window, 400 + $grace),
            $this->bound()->modelCallLeaseSeconds(ResumeDriver::Root, $deadline),
            'a deadline far in the future does not cap the lease'
        );
    }

    // ---------------------------------------------------------------
    // extendForModelCall on a helper run extends the 30 s base lease over
    // the model call
    // ---------------------------------------------------------------

    #[Test]
    public function extendForModelCall_on_a_helper_run_extends_to_the_model_call_lease(): void
    {
        // A helper run whose lease is at its 30 s base: the model-call
        // extension must carry it over to the model call lease (300 s), or
        // the parent's join wait would take a live member over.
        $runId = (string) Str::uuid();
        $this->makeRun($runId, [
            'resume_state' => 'working',
            'resume_driver' => ResumeDriver::BatchMember->value,
            'lease_holder' => app(RunLease::class)->nonce(),
            'lease_expires_at' => now()->addSeconds(30)->format('Y-m-d H:i:s.u'),
        ]);

        app(RunCheckpoint::class)->extendForModelCall($runId, ResumeDriver::BatchMember);

        $leaseExpires = Carbon::parse((string) $this->runRow($runId)->lease_expires_at);
        $floor = Carbon::now()->addSeconds(300)->subSeconds(5);
        $this->assertTrue(
            $leaseExpires->greaterThanOrEqualTo($floor),
            "lease_expires_at ({$this->runRow($runId)->lease_expires_at}) is extended to ~now + 300 s"
        );
    }
}
