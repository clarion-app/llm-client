<?php

namespace Tests\Unit;

use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Models\AgentRunStep;
use ClarionApp\LlmClient\Services\RunTraceQuery;
use ClarionApp\LlmClient\ValueObjects\RunRelation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RunTraceQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Enable run tracing for tests.
        $this->app['config']->set('llm-client.run_trace.enabled', true);
    }

    protected function tearDown(): void
    {
        DB::table('agent_run_messages')->delete();
        DB::table('agent_run_steps')->delete();
        DB::table('agent_runs')->delete();

        parent::tearDown();
    }

    // Helper: insert a run directly.
    protected function insertRun(
        string $userId,
        ?string $conversationId = null,
        string $endState = 'in_progress',
    ): string {
        $runId = (string) \Illuminate\Support\Str::uuid();
        DB::table('agent_runs')->insert([
            'id' => $runId,
            'kind' => 'interactive',
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'source' => null,
            'end_state' => $endState,
            'end_reason' => null,
            'started_at' => now()->toDateTimeString(),
            'ended_at' => null,
            'duration_ms' => null,
            'step_count' => 0,
            'created_at' => now()->toDateTimeString(),
        ]);

        return $runId;
    }

    // Helper: insert a step directly.
    protected function insertStep(string $runId, int $position): string
    {
        $stepId = (string) \Illuminate\Support\Str::uuid();
        DB::table('agent_run_steps')->insert([
            'id' => $stepId,
            'run_id' => $runId,
            'position' => $position,
            'attempt_group_id' => null,
            'end_state' => 'in_progress',
            'end_reason' => null,
            'started_at' => now()->toDateTimeString(),
            'ended_at' => null,
            'duration_ms' => null,
            'wait_ms' => null,
        ]);

        return $stepId;
    }

    // Helper: insert a message link directly.
    protected function insertMessageLink(string $runId, string $messageId, string $relation): string
    {
        $linkId = (string) \Illuminate\Support\Str::uuid();
        DB::table('agent_run_messages')->insert([
            'id' => $linkId,
            'run_id' => $runId,
            'message_id' => $messageId,
            'relation' => $relation,
            'created_at' => now()->toDateTimeString(),
        ]);
        return $linkId;
    }

    // ========== findRun ==========

    /** @test */
    public function find_run_returns_run_for_owner(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) \Illuminate\Support\Str::uuid();
        $runId = $this->insertRun($userId);

        $run = $query->findRun($userId, $runId);

        $this->assertInstanceOf(AgentRun::class, $run);
        $this->assertEquals($runId, $run->id);
        $this->assertEquals($userId, $run->user_id);
    }

    /** @test */
    public function find_run_returns_null_for_wrong_user(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) \Illuminate\Support\Str::uuid();
        $runId = $this->insertRun($userId);

        $otherUserId = (string) \Illuminate\Support\Str::uuid();
        $run = $query->findRun($otherUserId, $runId);

        $this->assertNull($run);
    }

    /** @test */
    public function find_run_returns_null_for_non_existent_run(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) \Illuminate\Support\Str::uuid();

        $run = $query->findRun($userId, (string) \Illuminate\Support\Str::uuid());
        $this->assertNull($run);
    }

    // ========== stepsForRun ==========

    /** @test */
    public function steps_for_run_returns_ordered_steps(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) \Illuminate\Support\Str::uuid();
        $runId = $this->insertRun($userId);

        $this->insertStep($runId, 3);
        $this->insertStep($runId, 1);
        $this->insertStep($runId, 2);

        $steps = $query->stepsForRun($userId, $runId);

        $this->assertIsArray($steps);
        $this->assertCount(3, $steps);
        $this->assertEquals(1, $steps[0]->position);
        $this->assertEquals(2, $steps[1]->position);
        $this->assertEquals(3, $steps[2]->position);
    }

    /** @test */
    public function steps_for_run_returns_empty_array_for_zero_steps(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) \Illuminate\Support\Str::uuid();
        $runId = $this->insertRun($userId);

        $steps = $query->stepsForRun($userId, $runId);

        $this->assertIsArray($steps);
        $this->assertCount(0, $steps);
    }

    /** @test */
    public function steps_for_run_returns_null_for_wrong_user(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) \Illuminate\Support\Str::uuid();
        $runId = $this->insertRun($userId);
        $this->insertStep($runId, 1);

        $otherUserId = (string) \Illuminate\Support\Str::uuid();
        $steps = $query->stepsForRun($otherUserId, $runId);

        $this->assertNull($steps);
    }

    /** @test */
    public function steps_for_run_returns_null_for_non_existent_run(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) \Illuminate\Support\Str::uuid();

        $steps = $query->stepsForRun($userId, (string) \Illuminate\Support\Str::uuid());
        $this->assertNull($steps);
    }

    // ========== runsForConversation ==========

    /** @test */
    public function runs_for_conversation_returns_ordered_runs(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();

        $run1 = $this->insertRun($userId, $convId);
        usleep(10_000);
        $run2 = $this->insertRun($userId, $convId);

        $runs = $query->runsForConversation($userId, $convId);

        $this->assertCount(2, $runs);
        $this->assertInstanceOf(AgentRun::class, $runs[0]);
        $this->assertInstanceOf(AgentRun::class, $runs[1]);
        $this->assertEquals($run1, $runs[0]->id);
        $this->assertEquals($run2, $runs[1]->id);
    }

    /** @test */
    public function runs_for_conversation_filters_by_user(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) \Illuminate\Support\Str::uuid();
        $otherUserId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();

        $this->insertRun($userId, $convId);
        $this->insertRun($otherUserId, $convId);

        $runs = $query->runsForConversation($userId, $convId);

        $this->assertCount(1, $runs);
        $this->assertEquals($userId, $runs[0]->user_id);
    }

    /** @test */
    public function runs_for_conversation_includes_runs_without_reply_message(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();

        $this->insertRun($userId, $convId, 'completed');

        $runs = $query->runsForConversation($userId, $convId);

        $this->assertCount(1, $runs);
    }

    // ========== runsForUser ==========

    /** @test */
    public function runs_for_user_returns_descending_by_started_at(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) \Illuminate\Support\Str::uuid();

        $run1 = $this->insertRun($userId, 'conv-1');
        usleep(10_000);
        $run2 = $this->insertRun($userId, 'conv-2');

        $runs = $query->runsForUser($userId);

        $this->assertCount(2, $runs);
        // Most recent first.
        $this->assertEquals($run2, $runs[0]->id);
        $this->assertEquals($run1, $runs[1]->id);
    }

    /** @test */
    public function runs_for_user_limits_results(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) \Illuminate\Support\Str::uuid();

        for ($i = 0; $i < 20; $i++) {
            $this->insertRun($userId, "conv-$i");
        }

        $runs = $query->runsForUser($userId, 10);

        $this->assertCount(10, $runs);
    }

    // ========== findRunForMessage ==========

    /** @test */
    public function find_run_for_message_finds_linked_run(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) \Illuminate\Support\Str::uuid();
        $runId = $this->insertRun($userId);
        $msgId = (string) \Illuminate\Support\Str::uuid();

        $this->insertMessageLink($runId, $msgId, RunRelation::Trigger->value);

        $run = $query->findRunForMessage($userId, $msgId);

        $this->assertInstanceOf(AgentRun::class, $run);
        $this->assertEquals($runId, $run->id);
    }

    /** @test */
    public function find_run_for_message_returns_null_for_unlinked_message(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) \Illuminate\Support\Str::uuid();
        $this->insertRun($userId);

        $run = $query->findRunForMessage($userId, (string) \Illuminate\Support\Str::uuid());
        $this->assertNull($run);
    }

    /** @test */
    public function find_run_for_message_filters_by_user_ownership(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) \Illuminate\Support\Str::uuid();
        $runId = $this->insertRun($userId);
        $msgId = (string) \Illuminate\Support\Str::uuid();

        $this->insertMessageLink($runId, $msgId, RunRelation::Trigger->value);

        $otherUserId = (string) \Illuminate\Support\Str::uuid();
        $run = $query->findRunForMessage($otherUserId, $msgId);

        $this->assertNull($run);
    }
}
