<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Carbon\CarbonImmutable;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Services\RunTraceQuery;
use ClarionApp\LlmClient\ValueObjects\TraceExportConfig;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A run waiting on its user's answer is shown as waiting for the user.
 *
 * The run summary (`GET agent-runs`, `GET agent-runs/{runId}`, and the
 * `RunUpdated` broadcast payload built from the same projection) carries a
 * derived `awaiting_user` flag: true exactly while a pending question exists
 * for the run, false otherwise. The run itself stays `in_progress` while it
 * waits — not failed, stalled or abandoned. The list endpoint computes the
 * flag for a whole page at once rather than with a lookup per row.
 */
class RunSummaryAwaitingUserTest extends TestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['llm-client.run_trace.enabled' => true]);
        config(['llm-client.run_trace.abandonment_minutes' => 60]);
        config(['llm-client.agent_loop.confirmation_timeout' => 300]);

        $this->owner = User::factory()->create();
    }

    protected function tearDown(): void
    {
        TraceExportConfig::reset();

        foreach (['agent_questions', 'agent_run_export_queue', 'agent_run_messages', 'agent_run_actions', 'agent_run_steps', 'agent_runs'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /** An in-progress run of the owner whose one step opened $minutesAgo minutes ago and is still open. */
    private function waitingRun(int $minutesAgo = 5): string
    {
        $runId = (string) Str::uuid();
        $startedAt = CarbonImmutable::now()->subMinutes($minutesAgo)->format('Y-m-d H:i:s.u');

        DB::table('agent_runs')->insert([
            'id' => $runId,
            'kind' => 'interactive',
            'user_id' => $this->owner->id,
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

    /** A question for $runId in $state, asked $minutesAgo minutes ago. */
    private function question(?string $runId, string $state, int $minutesAgo = 5): string
    {
        $id = (string) Str::uuid();
        $conversationId = (string) Str::uuid();
        $askedAt = CarbonImmutable::now()->subMinutes($minutesAgo);
        $pending = $state === 'pending';

        DB::table('agent_questions')->insert([
            'id' => $id,
            'conversation_id' => $conversationId,
            'user_id' => $this->owner->id,
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
            'expires_at' => $askedAt->addDay(),
            'resolved_at' => $pending ? null : CarbonImmutable::now()->subMinute(),
            'created_at' => $askedAt,
            'updated_at' => $askedAt,
        ]);

        return $id;
    }

    private function show(string $runId): array
    {
        return $this->actingAs($this->owner, 'api')
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}")
            ->assertStatus(200)
            ->json();
    }

    /** @return array<string, array<string, mixed>> the listed run summaries, keyed by run id */
    private function listed(): array
    {
        $rows = $this->actingAs($this->owner, 'api')
            ->getJson('/api/clarion-app/llm-client/agent-runs')
            ->assertStatus(200)
            ->json('data');

        return collect($rows)->keyBy('id')->all();
    }

    private function assertFlag(bool $expected, array $summary, string $message): void
    {
        $this->assertArrayHasKey('awaiting_user', $summary, 'the run summary carries awaiting_user');
        $this->assertSame($expected, $summary['awaiting_user'], $message);
    }

    // ---------------------------------------------------------------
    // The flag
    // ---------------------------------------------------------------

    #[Test]
    public function a_run_with_a_pending_question_is_awaiting_its_user(): void
    {
        $runId = $this->waitingRun();
        $this->question($runId, 'pending');

        $summary = $this->show($runId);
        $this->assertFlag(true, $summary, 'GET agent-runs/{id} shows the run waiting for its user');
        $this->assertSame('in_progress', $summary['end_state'], 'a waiting run is still in progress');

        $this->assertFlag(true, $this->listed()[$runId], 'GET agent-runs shows the run waiting for its user');
        $this->assertFlag(true, app(RunTraceQuery::class)->runSummaryById($runId), 'the live-update payload agrees with the endpoints');
    }

    #[Test]
    public function a_run_without_a_question_is_not_awaiting_its_user(): void
    {
        $runId = $this->waitingRun();

        $this->assertFlag(false, $this->show($runId), 'no question, nothing to wait for');
        $this->assertFlag(false, $this->listed()[$runId], 'no question, nothing to wait for');
        $this->assertFlag(false, app(RunTraceQuery::class)->runSummaryById($runId), 'the live-update payload agrees with the endpoints');
    }

    /** @return array<string, array{string}> */
    public static function resolvedStates(): array
    {
        return [
            'answered' => ['answered'],
            'expired' => ['expired'],
            'withdrawn' => ['withdrawn'],
            'undeliverable' => ['undeliverable'],
        ];
    }

    #[Test]
    #[DataProvider('resolvedStates')]
    public function a_run_whose_question_is_resolved_is_not_awaiting_its_user(string $state): void
    {
        $runId = $this->waitingRun();
        $this->question($runId, $state);

        $this->assertFlag(false, $this->show($runId), "a {$state} question no longer waits on the user");
        $this->assertFlag(false, $this->listed()[$runId], "a {$state} question no longer waits on the user");
    }

    #[Test]
    public function the_flag_follows_the_question_from_pending_to_resolved(): void
    {
        $runId = $this->waitingRun();
        $questionId = $this->question($runId, 'pending');
        $this->assertFlag(true, $this->show($runId), 'precondition: waiting while pending');

        DB::table('agent_questions')->where('id', $questionId)->update([
            'state' => 'answered',
            'pending_conversation_id' => null,
            'resolved_at' => CarbonImmutable::now(),
        ]);

        $this->assertFlag(false, $this->show($runId), 'no longer waiting once answered');
    }

    #[Test]
    public function a_pending_question_marks_only_its_own_run(): void
    {
        $waiting = $this->waitingRun();
        $answered = $this->waitingRun();
        $idle = $this->waitingRun();
        $this->question($waiting, 'pending');
        $this->question($answered, 'answered');
        // A pending question recorded without a run marks nothing.
        $this->question(null, 'pending');

        $listed = $this->listed();

        $this->assertFlag(true, $listed[$waiting], 'the waiting run');
        $this->assertFlag(false, $listed[$answered], 'a run whose question was answered');
        $this->assertFlag(false, $listed[$idle], 'a run that never asked');
    }

    // ---------------------------------------------------------------
    // Waiting is not abandonment
    // ---------------------------------------------------------------

    #[Test]
    public function a_run_waiting_well_past_the_abandonment_threshold_is_still_shown_in_progress_and_waiting(): void
    {
        $runId = $this->waitingRun(minutesAgo: 120);
        $this->question($runId, 'pending', minutesAgo: 120);

        $this->assertSame(0, Artisan::call('llm-client:resolve-abandoned-runs'));

        $summary = $this->show($runId);
        $this->assertSame('in_progress', $summary['end_state'], 'waiting for the user is not abandonment');
        $this->assertNull($summary['end_reason']);
        $this->assertFlag(true, $summary, 'the run is shown as waiting for its user');
    }

    // ---------------------------------------------------------------
    // The list computes the flag for the whole page at once
    // ---------------------------------------------------------------

    /** Queries reading agent_questions seen while counting is on; null when off. */
    private ?int $questionQueries = null;

    /** Number of queries reading agent_questions while listing the owner's runs. */
    private function questionQueriesWhileListing(): int
    {
        $this->questionQueries = 0;
        $this->listed();
        $count = $this->questionQueries;
        $this->questionQueries = null;

        return $count;
    }

    #[Test]
    public function the_list_does_not_look_up_questions_per_row(): void
    {
        DB::listen(function (QueryExecuted $query) {
            if ($this->questionQueries !== null && str_contains($query->sql, 'agent_questions')) {
                $this->questionQueries++;
            }
        });

        $first = $this->waitingRun();
        $this->question($first, 'pending');
        $oneRun = $this->questionQueriesWhileListing();

        for ($i = 0; $i < 5; $i++) {
            $runId = $this->waitingRun();
            $this->question($runId, $i % 2 === 0 ? 'pending' : 'answered');
        }
        $sixRuns = $this->questionQueriesWhileListing();

        $this->assertLessThanOrEqual(2, $oneRun, 'one batched question lookup per flag per page');
        $this->assertSame($oneRun, $sixRuns, 'the number of question lookups does not grow with the number of runs listed');

        $listed = $this->listed();
        $this->assertCount(6, $listed);
        $this->assertSame(4, collect($listed)->where('awaiting_user', true)->count(), 'every listed run carries its own flag');
    }
}
