<?php

namespace Tests\Support;

use ClarionApp\LlmClient\Models\AgentRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Row fixtures for the task progress view and the endpoints that carry it:
 * runs, steps with their checkpoints, delegations, questions, task budgets,
 * sequence runs and managed tasks, written straight into the hand-declared
 * test schema so each case states exactly the rows it needs.
 */
trait SeedsRunProgress
{
    private function stamp(string $when): string
    {
        return \Illuminate\Support\Carbon::parse($when, 'UTC')->format('Y-m-d H:i:s.u');
    }

    /** An in-progress run, working, started at 10:00:00 on 2026-10-12. */
    private function seedRun(string $userId, array $overrides = []): string
    {
        $id = $overrides['id'] ?? (string) Str::uuid();
        DB::table('agent_runs')->insert(array_merge([
            'id' => $id,
            'kind' => 'interactive',
            'user_id' => $userId,
            'conversation_id' => (string) Str::uuid(),
            'started_at' => $this->stamp('2026-10-12 10:00:00'),
            'end_state' => 'in_progress',
            'checkpoint_version' => 1,
            'resume_mode' => 'sync',
            'resume_driver' => 'root',
            'resume_state' => 'working',
            'last_progress_at' => $this->stamp('2026-10-12 10:30:41.12'),
        ], $overrides));

        return $id;
    }

    /**
     * A step. `$checkpointState` null leaves a legacy step with no checkpoint.
     *
     * @param  array<string, array<string, mixed>>  $calls  call id => checkpoint call entry
     */
    private function seedStep(string $runId, int $position, string $endState = 'completed', ?string $checkpointState = 'committed', array $calls = [], array $overrides = []): string
    {
        $id = $overrides['id'] ?? (string) Str::uuid();
        $checkpoint = $checkpointState === null ? null : json_encode(['v' => 1, 'iteration' => $position, 'model' => null, 'calls' => $calls]);
        DB::table('agent_run_steps')->insert(array_merge([
            'id' => $id,
            'run_id' => $runId,
            'position' => $position,
            'started_at' => $this->stamp('2026-10-12 10:00:00'),
            'ended_at' => $endState === 'in_progress' ? null : $this->stamp('2026-10-12 10:05:00'),
            'end_state' => $endState,
            'iteration' => $checkpointState === null ? null : $position,
            'checkpoint_state' => $checkpointState,
            'checkpoint' => $checkpoint,
        ], $overrides));

        return $id;
    }

    /** One checkpoint call entry. */
    private function checkpointCall(string $state, string $label = 'ledger.write', string $tool = 'execute_operation'): array
    {
        return ['index' => 0, 'tool' => $tool, 'label' => $label, 'state' => $state, 'effect' => 'effectful'];
    }

    private function seedEvent(string $runId, string $kind, array $payload = [], ?int $position = null, ?int $attempt = null, ?string $at = null): string
    {
        $id = (string) Str::uuid();
        DB::table('agent_run_resume_events')->insert([
            'id' => $id,
            'run_id' => $runId,
            'kind' => $kind,
            'step_position' => $position,
            'attempt' => $attempt,
            'payload' => json_encode($payload),
            'created_at' => $this->stamp($at ?? '2026-10-12 10:10:00'),
        ]);

        return $id;
    }

    /** A delegation of the run, in a batch. */
    private function seedDelegation(string $runId, string $userId, string $status, string $batchId, array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::table('agent_delegations')->insert(array_merge([
            'id' => $id,
            'parent_conversation_id' => (string) Str::uuid(),
            'helper_agent_id' => (string) Str::uuid(),
            'helper_conversation_id' => (string) Str::uuid(),
            'owner_user_id' => $userId,
            'task' => 'a task',
            'depth' => 1,
            'status' => $status,
            'batch_id' => $batchId,
            'parent_run_id' => $runId,
            'started_at' => $this->stamp('2026-10-12 10:20:00'),
        ], $overrides));

        return $id;
    }

    private function seedQuestion(string $runId, string $userId, string $state, array $overrides = []): string
    {
        $id = (string) Str::uuid();
        $conversationId = (string) Str::uuid();
        DB::table('agent_questions')->insert(array_merge([
            'id' => $id,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'pending_conversation_id' => $conversationId,
            'run_id' => $runId,
            'paused_message_id' => (string) Str::uuid(),
            'tool_call_id' => 'call_q',
            'channel' => 'web',
            'resume_mode' => 'sync',
            'question' => 'Which file?',
            'choices' => json_encode([]),
            'state' => $state,
            'asked_at' => $this->stamp('2026-10-12 10:15:00'),
            'expires_at' => $this->stamp('2026-10-13 10:15:00'),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    /** A task budget standing for the run. */
    private function seedTask(string $runId, string $userId, string $state = 'running', int $stepsUsed = 38, int $maxSteps = 200, array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::table('task_budgets')->insert(array_merge([
            'id' => $id,
            'user_id' => $userId,
            'root_conversation_id' => (string) Str::uuid(),
            'root_run_id' => $runId,
            'scope' => 'request',
            'state' => $state,
            'max_steps' => $maxSteps,
            'max_steps_origin' => 'default',
            'original_max_steps' => $maxSteps,
            'max_spend' => '25.0000000000',
            'max_spend_origin' => 'default',
            'original_max_spend' => '25.0000000000',
            'steps_used' => $stepsUsed,
            'approach_threshold' => '0.8000',
            'created_at' => $this->stamp('2026-10-12 10:00:00'),
            'updated_at' => $this->stamp('2026-10-12 10:00:00'),
        ], $overrides));

        return $id;
    }

    /** A sequence run of five stages with `$completed` of them done, on the run's conversation. */
    private function seedSequence(AgentRun|string $run, string $userId, int $completed, int $total = 5): void
    {
        $conversationId = $run instanceof AgentRun ? $run->conversation_id : DB::table('agent_runs')->where('id', $run)->value('conversation_id');
        $definitionId = (string) Str::uuid();
        DB::table('stage_sequence_definitions')->insert([
            'id' => $definitionId,
            'owner_user_id' => $userId,
            'coordinator_agent_id' => (string) Str::uuid(),
            'name' => 'Pipeline',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $stageIds = [];
        for ($position = 1; $position <= $total; $position++) {
            $stageIds[$position] = (string) Str::uuid();
            DB::table('stages')->insert([
                'id' => $stageIds[$position],
                'sequence_definition_id' => $definitionId,
                'position' => $position,
                'name' => "Stage {$position}",
                'helper_agent_id' => (string) Str::uuid(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $sequenceRunId = (string) Str::uuid();
        DB::table('sequence_runs')->insert([
            'id' => $sequenceRunId,
            'sequence_definition_id' => $definitionId,
            'owner_user_id' => $userId,
            'conversation_id' => $conversationId,
            'status' => 'in_progress',
            'current_stage_position' => $completed + 1,
            'last_progress_at' => $this->stamp('2026-10-12 10:30:00'),
            'started_at' => $this->stamp('2026-10-12 10:00:00'),
        ]);
        foreach ($stageIds as $position => $stageId) {
            if ($position > $completed + 1) {
                continue;
            }
            DB::table('stage_results')->insert([
                'id' => (string) Str::uuid(),
                'sequence_run_id' => $sequenceRunId,
                'stage_id' => $stageId,
                'status' => $position <= $completed ? 'completed' : 'running',
            ]);
        }
    }

    /** A managed task on the run's conversation: `$accepted` parts accepted out of `$total`. */
    private function seedManagedTask(string $runId, string $userId, int $accepted, int $total): void
    {
        $conversationId = DB::table('agent_runs')->where('id', $runId)->value('conversation_id');
        $taskId = (string) Str::uuid();
        DB::table('managed_tasks')->insert([
            'id' => $taskId,
            'conversation_id' => $conversationId,
            'owner_user_id' => $userId,
            'original_request' => 'Do the thing',
            'status' => 'in_progress',
            'round_ceiling' => 10,
            'rounds_used' => 2,
            'max_seconds' => 3600,
            'last_progress_at' => $this->stamp('2026-10-12 10:30:00'),
            'started_at' => $this->stamp('2026-10-12 10:00:00'),
        ]);
        for ($sequence = 1; $sequence <= $total; $sequence++) {
            DB::table('managed_task_parts')->insert([
                'id' => (string) Str::uuid(),
                'managed_task_id' => $taskId,
                'sequence' => $sequence,
                'description' => "Part {$sequence}",
                'state' => $sequence <= $accepted ? 'accepted' : ($sequence === $accepted + 1 ? 'out_for_assignment' : 'not_yet_assigned'),
                'created_at' => $this->stamp('2026-10-12 10:00:00'),
                'updated_at' => $this->stamp('2026-10-12 10:00:00'),
            ]);
        }
    }

    /** @return list<string> every table the fixtures write, for tearDown */
    private function progressTables(): array
    {
        return [
            'agent_run_resume_events', 'agent_run_actions', 'agent_run_steps', 'agent_runs', 'agent_delegations',
            'agent_questions', 'task_budget_events', 'task_budgets', 'stage_results', 'sequence_runs', 'stages',
            'stage_sequence_definitions', 'managed_task_parts', 'managed_tasks', 'conversations', 'users',
        ];
    }

    private function clearProgressTables(): void
    {
        foreach ($this->progressTables() as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
    }
}
