<?php

namespace Tests\Integration;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\ToolInvocationRecord;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\RunTraceQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 7 (T035) — FR-020/SC-008. Possession of a run identifier must not by
 * itself grant access to what it points to.
 *
 * User A's run is driven for real through AgentLoopService::run() so its id
 * is a genuine, live identifier — not a guessed or freshly-minted UUID that
 * happens to collide with nothing. User B then learns that id (as if it had
 * leaked through a log line, a shared link, whatever channel) and attempts
 * every read surface RunTraceQuery exposes for a run: the pre-existing
 * findRun() (spec 062) plus the three Phase 3 additions. All four must
 * reject B and return null rather than user A's data (Q1/Q5 in
 * contracts/trace-id-propagation.md §4).
 *
 * A positive control against user A's own id proves the run genuinely
 * produced messages, tool invocations, and usage records — so B's rejection
 * is a real ownership check, not an accident of an empty run.
 */
class TraceIdAuthorizationJourneyTest extends AssembledSystemTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.run_trace.enabled', true);
    }

    public function test_user_b_holding_user_as_real_run_id_is_rejected_by_every_query_method(): void
    {
        $this->scenario = 'user_b_holding_user_as_real_run_id_is_rejected';
        $this->entryPath = 'sync';

        // --- User A drives a real, multi-round run: a tool call followed by
        // a final answer, so the run genuinely produces a message, a tool
        // invocation, and (two calls' worth of) usage records.
        $fixture = $this->fixture()->build();
        $userAId = (string) $fixture->conversation->user_id;

        $this->script()
            ->toolRequest('search_operations', ['query' => 'list contacts'])
            ->finalAnswer('I found some contacts for you.');

        $result = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'List my contacts',
        );
        $this->assertSame('completed', $result['status']);

        $run = DB::table('agent_runs')
            ->where('conversation_id', $fixture->conversation->id)
            ->first();
        $this->assertNotNull($run, 'Precondition: user A really has a completed run');
        $runId = $run->id;
        $this->assertSame($userAId, $run->user_id, 'Precondition: the run really belongs to user A');

        // Precondition: the run really produced records to leak, so B's
        // rejection below proves an ownership check fired rather than the
        // run simply having nothing in it.
        $this->assertGreaterThanOrEqual(
            2,
            Message::where('run_id', $runId)->count(),
            'Precondition: the run wrote at least a tool round and a final answer'
        );
        $this->assertGreaterThanOrEqual(
            1,
            ToolInvocationRecord::where('run_id', $runId)->count(),
            'Precondition: the run wrote at least one tool invocation'
        );
        $this->assertGreaterThanOrEqual(
            2,
            UsageRecord::where('run_id', $runId)->count(),
            'Precondition: the run wrote at least two usage records (tool round + final answer)'
        );

        $query = $this->app->make(RunTraceQuery::class);

        // Positive control: user A, the genuine owner, can read all four.
        $this->assertNotNull($query->findRun($userAId, $runId));
        $this->assertNotNull($query->messagesForRun($userAId, $runId));
        $this->assertNotEmpty($query->messagesForRun($userAId, $runId));
        $this->assertNotNull($query->toolInvocationsForRun($userAId, $runId));
        $this->assertNotEmpty($query->toolInvocationsForRun($userAId, $runId));
        $this->assertNotNull($query->usageRecordsForRun($userAId, $runId));
        $this->assertNotEmpty($query->usageRecordsForRun($userAId, $runId));

        // --- User B: a wholly unrelated account that never touched this
        // conversation, run, or any of its records — it only knows the real
        // run id, e.g. because it leaked.
        $userB = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'User B',
            'email' => 'user-b@example.com',
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);
        $userBId = (string) $userB->id;
        $this->assertNotSame($userAId, $userBId, 'Precondition: user B is a genuinely different account');

        // FR-020/SC-008: possession of the identifier alone must not unlock
        // any of the four read surfaces for user B.
        $this->assertNull(
            $query->findRun($userBId, $runId),
            'findRun must reject a caller who does not own the run (FR-020)'
        );
        $this->assertNull(
            $query->messagesForRun($userBId, $runId),
            'messagesForRun must reject a caller who does not own the run (FR-020)'
        );
        $this->assertNull(
            $query->toolInvocationsForRun($userBId, $runId),
            'toolInvocationsForRun must reject a caller who does not own the run (FR-020)'
        );
        $this->assertNull(
            $query->usageRecordsForRun($userBId, $runId),
            'usageRecordsForRun must reject a caller who does not own the run (FR-020)'
        );
    }
}
