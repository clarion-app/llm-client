<?php

namespace Tests\Feature\Resume;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Services\RunCheckpoint;
use ClarionApp\LlmClient\Services\RunLease;
use ClarionApp\LlmClient\ValueObjects\ResumeDriver;
use ClarionApp\LlmClient\ValueObjects\ResumeMode;
use ClarionApp\LlmClient\ValueObjects\ToolEffect;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsRunProgress;
use Tests\TestCase;

/**
 * GET agent-runs/{id}/progress: the owner reads the
 * derived progress view, an absent run and someone else's run answer the same
 * 404, an unauthenticated caller is refused, and the values follow a scripted
 * task through four moments: between steps, while a tool runs, while a retry
 * waits, and after a resume.
 */
class RunProgressEndpointTest extends TestCase
{
    use SeedsRunProgress;

    private User $owner;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:41.120000', 'UTC'));
        config(['llm-client.run_trace.enabled' => true, 'llm-client.resume.enabled' => true]);
        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
    }

    protected function tearDown(): void
    {
        $this->clearProgressTables();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function progressOf(User $as, string $runId)
    {
        return $this->actingAs($as, 'api')->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/progress");
    }

    private function checkpoint(): RunCheckpoint
    {
        return app(RunCheckpoint::class);
    }

    /** Close a step the way the run record does once its checkpoint is committed. */
    private function completeStep(string $stepId): void
    {
        DB::table('agent_run_steps')->where('id', $stepId)->update(['end_state' => 'completed', 'ended_at' => $this->stamp('2026-10-12 10:20:00')]);
    }

    // ---------------------------------------------------------------
    // The owner's view
    // ---------------------------------------------------------------

    #[Test]
    public function the_owner_reads_the_progress_in_the_documented_shape(): void
    {
        $runId = $this->seedRun((string) $this->owner->id, ['resume_count' => 1]);
        $this->seedStep($runId, 1, 'completed', 'committed');
        $this->seedTask($runId, (string) $this->owner->id, 'running', 38, 200);

        $body = $this->progressOf($this->owner, $runId)->assertStatus(200)->json();

        $this->assertSame(
            ['run_id', 'state', 'activity', 'steps_completed', 'structure', 'remaining', 'allowance', 'started_at', 'elapsed_ms', 'last_progress_at', 'resume_count', 'interrupted_at', 'next_attempt_at'],
            array_keys($body),
        );
        $this->assertSame($runId, $body['run_id']);
        $this->assertSame('working', $body['state']);
        $this->assertSame(['kind' => 'between_steps'], $body['activity']);
        $this->assertSame(1, $body['steps_completed']);
        $this->assertNull($body['structure']);
        $this->assertSame('unknown', $body['remaining']);
        $this->assertSame(['steps_used' => 38, 'max_steps' => 200, 'label' => 'allowance used'], $body['allowance']);
        $this->assertSame('2026-10-12T10:00:00.000000Z', $body['started_at']);
        $this->assertSame(1841120, $body['elapsed_ms']);
        $this->assertSame(1, $body['resume_count']);
    }

    #[Test]
    public function an_absent_run_and_another_users_run_answer_the_same_404(): void
    {
        $foreign = $this->seedRun((string) $this->stranger->id);
        $absent = '99999999-9999-9999-9999-999999999999';

        $foreignResponse = $this->progressOf($this->owner, $foreign);
        $absentResponse = $this->progressOf($this->owner, $absent);

        $foreignResponse->assertStatus(404);
        $absentResponse->assertStatus(404);
        $this->assertSame($absentResponse->getContent(), $foreignResponse->getContent(), 'status and body alike, so the endpoint never says which runs exist');
        $this->assertSame(
            $this->actingAs($this->owner, 'api')->getJson("/api/clarion-app/llm-client/agent-runs/{$absent}")->getContent(),
            $absentResponse->getContent(),
            'the same not-found body every run endpoint gives',
        );
        $this->assertStringNotContainsString($foreign, (string) $foreignResponse->getContent(), 'the body never echoes an id');
    }

    #[Test]
    public function an_unauthenticated_caller_is_refused(): void
    {
        $runId = $this->seedRun((string) $this->owner->id);

        $this->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/progress")->assertStatus(401);
    }

    #[Test]
    public function the_stranger_reads_their_own_run_and_not_the_owners(): void
    {
        $owners = $this->seedRun((string) $this->owner->id);
        $theirs = $this->seedRun((string) $this->stranger->id);

        $this->progressOf($this->stranger, $theirs)->assertStatus(200)->assertJsonPath('run_id', $theirs);
        $this->progressOf($this->stranger, $owners)->assertStatus(404);
    }

    // ---------------------------------------------------------------
    // Four scripted moments of one task
    // ---------------------------------------------------------------

    #[Test]
    public function the_values_follow_a_scripted_task_through_four_moments(): void
    {
        $userId = (string) $this->owner->id;
        $runId = $this->seedRun($userId, ['checkpoint_version' => null, 'resume_state' => null, 'lease_holder' => null, 'last_progress_at' => null]);
        $this->checkpoint()->openRun($runId, ResumeMode::Sync, ResumeDriver::Root, []);

        // Step 1 runs a model turn with no tools and commits.
        $first = $this->seedStep($runId, 1, 'in_progress', null);
        $this->checkpoint()->openStep($runId, $first, 1);
        $this->checkpoint()->recordModelResponse($first, 'Checking.', []);
        $this->checkpoint()->commitStep($runId, $first, (string) \Illuminate\Support\Str::uuid());
        $this->completeStep($first);

        // 1. Between steps.
        $body = $this->progressOf($this->owner, $runId)->assertStatus(200)->json();
        $this->assertSame('working', $body['state']);
        $this->assertSame(['kind' => 'between_steps'], $body['activity']);
        $this->assertSame(1, $body['steps_completed']);

        // 2. During a tool call.
        $second = $this->seedStep($runId, 2, 'in_progress', null);
        $this->checkpoint()->openStep($runId, $second, 2);
        $call = ['id' => 'call_w', 'type' => 'function', 'function' => ['name' => 'ledger.write', 'arguments' => '{"entry":"secret-entry"}']];
        $this->checkpoint()->recordModelResponse($second, 'Writing.', [$call]);
        $this->checkpoint()->markStarted($second, 'call_w', ToolEffect::Effectful, (string) \Illuminate\Support\Str::uuid(), 60);

        $body = $this->progressOf($this->owner, $runId)->assertStatus(200)->json();
        $this->assertSame('working', $body['state']);
        $this->assertSame(['kind' => 'running_tool', 'label' => 'ledger.write'], $body['activity']);
        $this->assertSame(1, $body['steps_completed'], 'the running step is not counted');
        $this->assertStringNotContainsString('secret-entry', (string) json_encode($body));

        // 3. During a provider retry wait: the lease is released and the retry is due later.
        DB::table('agent_runs')->where('id', $runId)->update([
            'resume_state' => 'waiting_provider_retry',
            'lease_holder' => null,
            'next_attempt_at' => $this->stamp('2026-10-12 10:31:11'),
        ]);
        $body = $this->progressOf($this->owner, $runId)->assertStatus(200)->json();
        $this->assertSame('waiting_provider_retry', $body['state']);
        $this->assertSame('waiting_to_retry', $body['activity']['kind']);
        $this->assertSame('2026-10-12T10:31:11.000000Z', $body['next_attempt_at']);

        // 4. After a resume: the lease expired, was found, then taken again.
        DB::table('agent_runs')->where('id', $runId)->update([
            'resume_state' => 'working',
            'lease_holder' => (string) \Illuminate\Support\Str::uuid(),
            'next_attempt_at' => null,
            'lease_expires_at' => $this->stamp('2026-10-12 10:00:00'),
        ]);
        $dispatch = DB::table('agent_runs')->where('id', $runId)->value('dispatch_id');
        $lease = app(RunLease::class);
        $newDispatch = $lease->interruptExpired($runId, $dispatch, 'working');
        $this->assertNotNull($newDispatch, 'precondition: the expired lease was found');

        $body = $this->progressOf($this->owner, $runId)->assertStatus(200)->json();
        $this->assertSame('interrupted', $body['state']);
        $this->assertSame('2026-10-12T10:30:41.120000Z', $body['interrupted_at']);

        $this->assertTrue($lease->claim($runId, $newDispatch)->won, 'precondition: the resume took the run');
        DB::table('agent_runs')->where('id', $runId)->increment('resume_count');
        $this->assertSame('resuming', $this->progressOf($this->owner, $runId)->json('state'));

        $this->checkpoint()->markCompleted($second, 'call_w', ['tool_call_id' => 'call_w', 'content' => 'ok']);
        $body = $this->progressOf($this->owner, $runId)->assertStatus(200)->json();
        $this->assertSame('working', $body['state'], 'the first checkpoint write after the resume lands the run back in working');
        $this->assertSame(1, $body['resume_count']);
        $this->assertSame(1, $body['steps_completed']);
        $this->assertNotNull($body['last_progress_at']);
    }
}
