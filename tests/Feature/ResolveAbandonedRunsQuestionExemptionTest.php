<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\LlmClient\ValueObjects\TraceExportConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A run waiting on its user's answer is not abandoned.
 *
 * `llm-client:resolve-abandoned-runs` sweeps runs whose last activity is
 * older than the abandonment threshold. A run paused on a pending question
 * looks exactly like that — its step stays open for as long as the user
 * takes to answer — so it is exempt while its question can still be
 * answered. Once the question's answerable period has passed the exemption
 * lapses, even if the expiry sweep has not run, so a stalled expiry sweep
 * can never pin a run open forever.
 */
class ResolveAbandonedRunsQuestionExemptionTest extends TestCase
{
    private const SWEEP = 'llm-client:resolve-abandoned-runs';

    protected function setUp(): void
    {
        parent::setUp();

        config(['llm-client.run_trace.enabled' => true]);
        config(['llm-client.run_trace.abandonment_minutes' => 60]);
        config(['llm-client.agent_loop.confirmation_timeout' => 300]);
    }

    protected function tearDown(): void
    {
        TraceExportConfig::reset();

        foreach (['agent_questions', 'agent_run_export_queue', 'agent_run_messages', 'agent_run_actions', 'agent_run_steps', 'agent_runs'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    /**
     * An in-progress run whose only step opened two hours ago and never
     * closed — well past the 60-minute abandonment threshold.
     */
    private function staleRun(): string
    {
        $runId = (string) Str::uuid();
        $startedAt = CarbonImmutable::now()->subMinutes(120)->format('Y-m-d H:i:s.u');

        DB::table('agent_runs')->insert([
            'id' => $runId,
            'kind' => 'interactive',
            'user_id' => (string) Str::uuid(),
            'conversation_id' => (string) Str::uuid(),
            'end_state' => 'in_progress',
            'started_at' => $startedAt,
            'step_count' => 0,
            'created_at' => $startedAt,
        ]);
        DB::table('agent_run_steps')->insert([
            'id' => (string) Str::uuid(),
            'run_id' => $runId,
            'position' => 1,
            'end_state' => 'in_progress',
            'started_at' => $startedAt,
            'attempt_count' => 1,
        ]);

        return $runId;
    }

    /** A question row for $runId, asked two hours ago. */
    private function question(?string $runId, string $state, CarbonImmutable $expiresAt): string
    {
        $id = (string) Str::uuid();
        $conversationId = (string) Str::uuid();
        $askedAt = CarbonImmutable::now()->subMinutes(120);
        $pending = $state === 'pending';

        DB::table('agent_questions')->insert([
            'id' => $id,
            'conversation_id' => $conversationId,
            'user_id' => (string) Str::uuid(),
            'pending_conversation_id' => $pending ? $conversationId : null,
            'run_id' => $runId,
            'step_id' => null,
            'action_id' => null,
            'paused_message_id' => (string) Str::uuid(),
            'display_message_id' => null,
            'tool_call_id' => 'call_ask',
            'channel' => 'web',
            'resume_mode' => 'stream',
            'question' => 'Which part should I change first?',
            'choices' => json_encode(['Backend', 'Frontend', 'Docs']),
            'allow_multiple' => false,
            'state' => $state,
            'asked_at' => $askedAt,
            'expires_at' => $expiresAt,
            'resolved_at' => $pending ? null : CarbonImmutable::now()->subMinutes(5),
            // A resolved question's run has resumed (and then gone quiet); one
            // still waiting for its resume is about to continue instead.
            'resumed_at' => $pending ? null : CarbonImmutable::now()->subMinutes(5),
            'created_at' => $askedAt,
            'updated_at' => $askedAt,
        ]);

        return $id;
    }

    private function endState(string $runId): string
    {
        return (string) DB::table('agent_runs')->where('id', $runId)->value('end_state');
    }

    private function sweep(): void
    {
        $this->assertSame(0, Artisan::call(self::SWEEP));
    }

    #[Test]
    public function a_run_waiting_on_a_pending_question_is_not_abandoned(): void
    {
        $waiting = $this->staleRun();
        $this->question($waiting, 'pending', CarbonImmutable::now()->addHours(22));

        $this->sweep();

        $this->assertSame('in_progress', $this->endState($waiting), 'the run is waiting for its user, not abandoned');
        $this->assertSame(
            'in_progress',
            DB::table('agent_run_steps')->where('run_id', $waiting)->value('end_state'),
            'its open step is left open too',
        );
    }

    #[Test]
    public function the_exemption_lapses_once_the_question_can_no_longer_be_answered(): void
    {
        // Past its expiry and the short grace after it, but the expiry sweep
        // has not run: still pending.
        $runId = $this->staleRun();
        $this->question($runId, 'pending', CarbonImmutable::now()->subMinutes(11));

        $this->sweep();

        $this->assertSame('abandoned', $this->endState($runId));
    }

    #[Test]
    public function the_exemption_lapses_with_the_question_without_waiting_for_the_sweep(): void
    {
        $runId = $this->staleRun();
        $this->question($runId, 'pending', CarbonImmutable::now()->addMinutes(30));

        $this->sweep();
        $this->assertSame('in_progress', $this->endState($runId), 'precondition: exempt while answerable');

        // Past its expiry and the short grace after it.
        $this->travel(41)->minutes();
        $this->sweep();

        $this->assertSame('abandoned', $this->endState($runId), 'no longer exempt once the question is past its expiry');
    }

    #[Test]
    public function a_resolved_question_does_not_exempt_its_run(): void
    {
        $runs = [];
        foreach (['answered', 'expired', 'withdrawn', 'undeliverable'] as $state) {
            $runs[$state] = $this->staleRun();
            $this->question($runs[$state], $state, CarbonImmutable::now()->addHours(22));
        }

        $this->sweep();

        foreach ($runs as $state => $runId) {
            $this->assertSame('abandoned', $this->endState($runId), "a run whose question is {$state} is not waiting on anyone");
        }
    }

    #[Test]
    public function a_pending_question_exempts_only_its_own_run(): void
    {
        $waiting = $this->staleRun();
        $other = $this->staleRun();
        $this->question($waiting, 'pending', CarbonImmutable::now()->addHours(22));
        // A pending question recorded without a run must not exempt anything.
        $this->question(null, 'pending', CarbonImmutable::now()->addHours(22));

        $this->sweep();

        $this->assertSame('in_progress', $this->endState($waiting));
        $this->assertSame('abandoned', $this->endState($other), 'another stale run is swept as before');
    }
}
