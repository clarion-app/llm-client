<?php

namespace Tests\Unit\Resume;

use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Services\ResumePlanner;
use ClarionApp\LlmClient\Services\RunCheckpoint;
use ClarionApp\LlmClient\ValueObjects\CallDispositionKind;
use ClarionApp\LlmClient\ValueObjects\PlanKind;
use ClarionApp\LlmClient\ValueObjects\ResumeDriver;
use ClarionApp\LlmClient\ValueObjects\ResumeMode;
use ClarionApp\LlmClient\ValueObjects\ToolEffect;
use ClarionApp\LlmClient\ValueObjects\Unresumable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ResumePlanner — the plan a resume applies (checkpoint-ledger contract §4,
 * research D3, D8, D14).
 *
 * The planner reads only durable rows; every row here is built through the
 * real `RunCheckpoint` writes (C0–C5), so the plan is decided from exactly
 * what a kill leaves behind. The plan kinds (D8), the D3 disposition table,
 * the deadline shift and the D14 coherence refusals are each pinned.
 */
class ResumePlannerTest extends TestCase
{
    private string $userId = '11111111-1111-1111-1111-111111111111';

    // ---------------------------------------------------------------
    // Row fixtures (the RunCheckpointTest pattern)
    // ---------------------------------------------------------------

    /** Insert a minimal in-progress run row. */
    private function makeRun(string $id, array $overrides = []): void
    {
        DB::table('agent_runs')->insert(array_merge([
            'id' => $id,
            'kind' => 'interactive',
            'user_id' => $this->userId,
            'started_at' => now()->format('Y-m-d H:i:s.u'),
            'end_state' => 'in_progress',
        ], $overrides));
    }

    /** Insert a minimal in-progress step row. */
    private function makeStep(string $id, string $runId, int $position, array $overrides = []): void
    {
        DB::table('agent_run_steps')->insert(array_merge([
            'id' => $id,
            'run_id' => $runId,
            'position' => $position,
            'started_at' => now()->format('Y-m-d H:i:s.u'),
            'end_state' => 'in_progress',
        ], $overrides));
    }

    /** Close a step the way closeStep() does outside the checkpoint writer. */
    private function closeStep(string $stepId): void
    {
        DB::table('agent_run_steps')->where('id', $stepId)->update([
            'end_state' => 'completed',
            'ended_at' => now()->format('Y-m-d H:i:s.u'),
        ]);
    }

    private function checkpoint(): RunCheckpoint
    {
        return app(RunCheckpoint::class);
    }

    private function planner(): ResumePlanner
    {
        return app(ResumePlanner::class);
    }

    /** Plan the run, asserting it is coherent (a plan, not an Unresumable). */
    private function planOf(string $runId): object
    {
        $plan = $this->planner()->plan(AgentRun::findOrFail($runId));
        $this->assertNotInstanceOf(Unresumable::class, $plan, 'the run is coherent: the planner plans it');

        return $plan;
    }

    /** Assert the planner refuses the run with a checkpoint_incoherent code. */
    private function assertUnresumable(string $runId, string $context): Unresumable
    {
        $verdict = $this->planner()->plan(AgentRun::findOrFail($runId));
        $this->assertInstanceOf(Unresumable::class, $verdict, $context);
        $this->assertStringStartsWith('checkpoint_incoherent:', $verdict->reasonCode, $context);

        return $verdict;
    }

    /** The two tool calls a model turn makes, in the shape recordModelResponse takes. */
    private function twoCalls(): array
    {
        return [
            ['id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'execute_operation', 'arguments' => json_encode(['operationId' => 'storeNote'])]],
            ['id' => 'call_b', 'type' => 'function', 'function' => ['name' => 'list_applications', 'arguments' => '[]']],
        ];
    }

    /** One tool call, the shape recordModelResponse takes. */
    private function oneCall(): array
    {
        return [
            ['id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'execute_operation', 'arguments' => json_encode(['operationId' => 'storeNote'])]],
        ];
    }

    /** The C5 message write: the step's assistant row with its tool results. */
    private function writeStepMessage(string $messageId, string $conversationId, array $toolResults): void
    {
        DB::table('messages')->insert([
            'id' => $messageId,
            'role' => 'assistant',
            'content' => 'Let me check.',
            'tool_data' => json_encode([
                'tool_calls' => array_map(
                    fn (array $call) => ['id' => $call['id'], 'function' => $call['function']],
                    $this->oneCall(),
                ),
                'tool_results' => $toolResults,
                'iteration' => 1,
            ]),
            'conversation_id' => $conversationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Plan kinds (D8)
    // ---------------------------------------------------------------

    #[Test]
    public function no_open_step_plans_next_iteration(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $this->checkpoint()->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $this->checkpoint()->openStep('run-1', 'step-1', 7);
        $this->checkpoint()->recordModelResponse('step-1', 'Working on it.', $this->oneCall());
        $this->checkpoint()->markStarted('step-1', 'call_a', ToolEffect::EffectFree, 'action-1', 30);
        $this->checkpoint()->markCompleted('step-1', 'call_a', ['tool_call_id' => 'call_a', 'content' => 'done']);
        $this->checkpoint()->commitStep('run-1', 'step-1', 'msg-1');
        $this->closeStep('step-1');

        $plan = $this->planOf('run-1');

        $this->assertSame(PlanKind::NextIteration, $plan->kind, 'no open step: the next fresh iteration');
        $this->assertSame(8, $plan->iteration, 'iteration = last committed + 1');
        $this->assertNull($plan->openStepId, 'there is no open step');
    }

    #[Test]
    public function open_step_with_open_state_plans_repeat_model_call(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $this->checkpoint()->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $this->checkpoint()->openStep('run-1', 'step-1', 3);

        $plan = $this->planOf('run-1');

        $this->assertSame(PlanKind::RepeatModelCall, $plan->kind, 'the model call was lost: repeat it in the same step');
        $this->assertSame('step-1', $plan->openStepId);
        $this->assertNull($plan->partialMessageId, 'no partial row exists on the sync path');
    }

    #[Test]
    public function open_step_with_message_id_plans_repeat_model_call_with_partial(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $this->checkpoint()->openRun('run-1', ResumeMode::Stream, ResumeDriver::Root, []);
        $this->checkpoint()->openStep('run-1', 'step-1', 3);
        $this->checkpoint()->recordPartialMessage('step-1', 'msg-partial');

        $plan = $this->planOf('run-1');

        $this->assertSame(PlanKind::RepeatModelCall, $plan->kind);
        $this->assertSame('msg-partial', $plan->partialMessageId, 'the stream partial row is reused by the repeated call');
    }

    #[Test]
    public function open_step_with_model_recorded_plans_finish_tool_batch(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $this->checkpoint()->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $this->checkpoint()->openStep('run-1', 'step-1', 2);
        $this->checkpoint()->recordModelResponse('step-1', 'Let me check both.', $this->twoCalls());

        $plan = $this->planOf('run-1');

        $this->assertSame(PlanKind::FinishToolBatch, $plan->kind, 'the model response is recorded: finish the batch');
        $this->assertSame('step-1', $plan->openStepId);
        $this->assertSame('Let me check both.', $plan->assistant['content'] ?? null, 'the assistant is the checkpointed model response');
        $this->assertCount(2, $plan->assistant['tool_calls'] ?? [], 'every checkpointed call is in the assistant');
        $this->assertSame(
            ['call_a', 'call_b'],
            array_map(fn (array $call) => $call['id'] ?? null, $plan->assistant['tool_calls']),
            'the tool calls keep their ids',
        );
    }

    #[Test]
    public function message_holds_all_results_but_step_not_closed_plans_close_committed_step(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $this->checkpoint()->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $this->checkpoint()->openStep('run-1', 'step-1', 2);
        $this->checkpoint()->recordModelResponse('step-1', 'Let me check.', $this->oneCall());
        $this->checkpoint()->markStarted('step-1', 'call_a', ToolEffect::EffectFree, 'action-1', 30);
        $this->checkpoint()->markCompleted('step-1', 'call_a', ['tool_call_id' => 'call_a', 'content' => 'done']);
        // C5: the results move to the message and are stripped from the checkpoint.
        $this->checkpoint()->commitStep('run-1', 'step-1', 'msg-1');
        $this->writeStepMessage('msg-1', 'conv-1', [['tool_call_id' => 'call_a', 'content' => 'done']]);
        // The kill lands before closeStep: the step is still in_progress.

        $plan = $this->planOf('run-1');

        $this->assertSame(PlanKind::CloseCommittedStep, $plan->kind, 'the message holds every result: close the step and continue');
        $this->assertSame('step-1', $plan->openStepId);
        $this->assertSame(
            CallDispositionKind::Reuse,
            $plan->dispositions['call_a']->kind,
            'the completed call is reused from the message',
        );
        $this->assertSame('done', $plan->dispositions['call_a']->result['content'] ?? null, 'the reused result is the message entry');
    }

    // ---------------------------------------------------------------
    // Dispositions (the D3 table)
    // ---------------------------------------------------------------

    #[Test]
    public function completed_call_is_reused_with_its_result(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $this->checkpoint()->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $this->checkpoint()->openStep('run-1', 'step-1', 2);
        $this->checkpoint()->recordModelResponse('step-1', 'Let me check.', $this->oneCall());
        $this->checkpoint()->markStarted('step-1', 'call_a', ToolEffect::EffectFree, 'action-1', 30);
        $this->checkpoint()->markCompleted('step-1', 'call_a', ['tool_call_id' => 'call_a', 'content' => 'done']);

        $plan = $this->planOf('run-1');

        $this->assertSame(CallDispositionKind::Reuse, $plan->dispositions['call_a']->kind, 'completed: reuse');
        $this->assertSame('done', $plan->dispositions['call_a']->result['content'] ?? null, 'the checkpoint result is reused');
    }

    #[Test]
    public function not_started_call_is_executed(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $this->checkpoint()->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $this->checkpoint()->openStep('run-1', 'step-1', 2);
        $this->checkpoint()->recordModelResponse('step-1', 'Let me check.', $this->oneCall());

        $plan = $this->planOf('run-1');

        $this->assertSame(CallDispositionKind::Execute, $plan->dispositions['call_a']->kind, 'not_started: execute fresh');
    }

    #[Test]
    public function started_effect_free_call_is_rerun(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $this->checkpoint()->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $this->checkpoint()->openStep('run-1', 'step-1', 2);
        $this->checkpoint()->recordModelResponse('step-1', 'Let me check.', $this->oneCall());
        $this->checkpoint()->markStarted('step-1', 'call_a', ToolEffect::EffectFree, 'action-1', 30);

        $plan = $this->planOf('run-1');

        $this->assertSame(CallDispositionKind::Rerun, $plan->dispositions['call_a']->kind, 'started effect-free: re-run once');
    }

    #[Test]
    public function started_effectful_call_is_outcome_unknown(): void
    {
        // quickstart M4 turns this row red.
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $this->checkpoint()->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $this->checkpoint()->openStep('run-1', 'step-1', 2);
        $this->checkpoint()->recordModelResponse('step-1', 'Let me check.', $this->oneCall());
        $this->checkpoint()->markStarted('step-1', 'call_a', ToolEffect::Effectful, 'action-1', 30);

        $plan = $this->planOf('run-1');

        $this->assertSame(CallDispositionKind::OutcomeUnknown, $plan->dispositions['call_a']->kind, 'started effectful: the outcome is unknown');
    }

    #[Test]
    public function a_result_present_only_in_the_step_message_is_reused(): void
    {
        // The step's message holds call_a's result (an answer consumed into
        // it, or a result written before C4) while the checkpoint still says
        // started: the message wins, so the call is reused, never repeated
        // or reported unknown.
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $this->checkpoint()->openRun('run-1', ResumeMode::Stream, ResumeDriver::Root, []);
        $this->checkpoint()->openStep('run-1', 'step-1', 2);
        $this->checkpoint()->recordModelResponse('step-1', 'Let me check both.', $this->twoCalls());
        $this->checkpoint()->markStarted('step-1', 'call_a', ToolEffect::Effectful, 'action-1', 30);
        $this->checkpoint()->recordPartialMessage('step-1', 'msg-1');
        DB::table('messages')->insert([
            'id' => 'msg-1',
            'role' => 'assistant',
            'content' => 'Let me check both.',
            'tool_data' => json_encode([
                'tool_calls' => array_map(fn (array $call) => ['id' => $call['id'], 'function' => $call['function']], $this->twoCalls()),
                'tool_results' => [['tool_call_id' => 'call_a', 'content' => 'from the message']],
                'iteration' => 2,
            ]),
            'conversation_id' => 'conv-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $plan = $this->planOf('run-1');

        $this->assertSame(PlanKind::FinishToolBatch, $plan->kind, 'call_b still has to run');
        $this->assertSame(CallDispositionKind::Reuse, $plan->dispositions['call_a']->kind, 'a result only in the message: reuse');
        $this->assertSame('from the message', $plan->dispositions['call_a']->result['content'] ?? null, 'the message entry is the reused result');
        $this->assertSame(CallDispositionKind::Execute, $plan->dispositions['call_b']->kind);
    }

    #[Test]
    public function started_durable_call_is_resolved_from_its_record(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $this->checkpoint()->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $this->checkpoint()->openStep('run-1', 'step-1', 2);
        $this->checkpoint()->recordModelResponse('step-1', 'Let me check.', $this->oneCall());
        $this->checkpoint()->markStarted('step-1', 'call_a', ToolEffect::Durable, 'action-1', 30);

        $plan = $this->planOf('run-1');

        $this->assertSame(CallDispositionKind::ResolveDurable, $plan->dispositions['call_a']->kind, 'started durable: resolve from its own record');
    }

    // ---------------------------------------------------------------
    // options — the deadline shift
    // ---------------------------------------------------------------

    #[Test]
    public function options_carry_deadline_at_shifted_by_the_gap(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $this->checkpoint()->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, [
            'deadline_at' => now()->addSeconds(120)->toIso8601String(),
        ]);
        $this->checkpoint()->openStep('run-1', 'step-1', 2);
        $this->checkpoint()->recordModelResponse('step-1', 'Working.', $this->oneCall());
        $this->checkpoint()->markStarted('step-1', 'call_a', ToolEffect::EffectFree, 'action-1', 30);
        $this->checkpoint()->markCompleted('step-1', 'call_a', ['tool_call_id' => 'call_a', 'content' => 'done']);
        $this->checkpoint()->commitStep('run-1', 'step-1', 'msg-1');
        $this->closeStep('step-1');

        // The run sat: its last progress was 100 s ago.
        DB::table('agent_runs')->where('id', 'run-1')->update([
            'last_progress_at' => now()->subSeconds(100)->format('Y-m-d H:i:s.u'),
        ]);

        $plan = $this->planOf('run-1');

        $this->assertArrayHasKey('deadline_at', $plan->options, 'the deadline option survives the resume');
        $shifted = Carbon::parse($plan->options['deadline_at']);
        $expected = Carbon::now()->addSeconds(220); // 120 s remaining + 100 s of gap
        $this->assertLessThanOrEqual(
            60,
            abs($shifted->diffInSeconds($expected)),
            'deadline_at is shifted by now − last_progress_at',
        );
    }

    // ---------------------------------------------------------------
    // Coherence (D14) — each refusal is checkpoint_incoherent:<rule>
    // ---------------------------------------------------------------

    #[Test]
    public function undecodable_checkpoint_is_unresumable(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $this->checkpoint()->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $this->checkpoint()->openStep('run-1', 'step-1', 2);
        DB::table('agent_run_steps')->where('id', 'step-1')->update(['checkpoint' => 'not-json']);

        $this->assertUnresumable('run-1', 'an undecodable checkpoint is refused');
    }

    #[Test]
    public function checkpoint_with_unsupported_version_is_unresumable(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $this->checkpoint()->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $this->checkpoint()->openStep('run-1', 'step-1', 2);
        DB::table('agent_run_steps')->where('id', 'step-1')->update([
            'checkpoint' => json_encode(['v' => 2, 'model' => null, 'calls' => []]),
        ]);

        $this->assertUnresumable('run-1', 'a checkpoint with v ≠ 1 is refused');
    }

    #[Test]
    public function tool_call_without_an_id_is_unresumable(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $this->checkpoint()->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $this->checkpoint()->openStep('run-1', 'step-1', 2);
        DB::table('agent_run_steps')->where('id', 'step-1')->update([
            'checkpoint' => json_encode([
                'v' => 1,
                'model' => ['content' => 'Let me check.'],
                'calls' => [['name' => 'a call with no id']],
            ]),
        ]);

        $this->assertUnresumable('run-1', 'a tool call without an id is refused');
    }

    #[Test]
    public function duplicate_tool_call_id_is_unresumable(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $this->checkpoint()->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $this->checkpoint()->openStep('run-1', 'step-1', 2);
        $this->checkpoint()->recordModelResponse('step-1', 'Let me check.', $this->oneCall());
        $this->checkpoint()->commitStep('run-1', 'step-1', 'msg-1');
        // The step's message names the same call id twice.
        DB::table('messages')->insert([
            'id' => 'msg-1',
            'role' => 'assistant',
            'content' => 'Let me check.',
            'tool_data' => json_encode([
                'tool_calls' => [
                    ['id' => 'call_a', 'function' => ['name' => 'execute_operation', 'arguments' => '{}']],
                    ['id' => 'call_a', 'function' => ['name' => 'execute_operation', 'arguments' => '{}']],
                ],
                'tool_results' => [],
                'iteration' => 2,
            ]),
            'conversation_id' => 'conv-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertUnresumable('run-1', 'a duplicated tool call id is refused');
    }

    #[Test]
    public function message_results_for_unknown_ids_are_unresumable(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $this->checkpoint()->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $this->checkpoint()->openStep('run-1', 'step-1', 2);
        $this->checkpoint()->recordModelResponse('step-1', 'Let me check.', $this->oneCall());
        $this->checkpoint()->commitStep('run-1', 'step-1', 'msg-1');
        // The step's message holds a result for a call the model never made.
        DB::table('messages')->insert([
            'id' => 'msg-1',
            'role' => 'assistant',
            'content' => 'Let me check.',
            'tool_data' => json_encode([
                'tool_calls' => [['id' => 'call_a', 'function' => ['name' => 'execute_operation', 'arguments' => '{}']]],
                'tool_results' => [['tool_call_id' => 'call_ghost', 'content' => 'a result for a call that was never made']],
                'iteration' => 2,
            ]),
            'conversation_id' => 'conv-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertUnresumable('run-1', 'message results for unknown ids are refused');
    }

    #[Test]
    public function two_open_steps_are_unresumable(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $this->makeStep('step-2', 'run-1', 2);
        $this->checkpoint()->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $this->checkpoint()->openStep('run-1', 'step-1', 1);
        $this->checkpoint()->openStep('run-1', 'step-2', 2);

        $this->assertUnresumable('run-1', 'more than one open step is refused');
    }

    #[Test]
    public function a_gap_in_step_positions_is_unresumable(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $this->makeStep('step-3', 'run-1', 3); // position 2 was never written
        $this->checkpoint()->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $this->checkpoint()->openStep('run-1', 'step-1', 1);
        $this->checkpoint()->recordModelResponse('step-1', 'Working.', $this->oneCall());
        $this->checkpoint()->markStarted('step-1', 'call_a', ToolEffect::EffectFree, 'action-1', 30);
        $this->checkpoint()->markCompleted('step-1', 'call_a', ['tool_call_id' => 'call_a', 'content' => 'done']);
        $this->checkpoint()->commitStep('run-1', 'step-1', 'msg-1');
        $this->closeStep('step-1');
        $this->checkpoint()->openStep('run-1', 'step-3', 3);

        $this->assertUnresumable('run-1', 'a gap in the step positions is refused');
    }

    #[Test]
    public function missing_resume_options_are_unresumable(): void
    {
        $this->makeRun('run-1');
        $this->makeStep('step-1', 'run-1', 1);
        $this->checkpoint()->openRun('run-1', ResumeMode::Sync, ResumeDriver::Root, []);
        $this->checkpoint()->openStep('run-1', 'step-1', 2);
        DB::table('agent_runs')->where('id', 'run-1')->update(['resume_options' => null]);

        $this->assertUnresumable('run-1', 'a checkpoint_version = 1 run without resume_options is refused');
    }
}
