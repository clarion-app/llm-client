<?php

namespace Tests\Feature\Resume;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\RunUpdated;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsRunProgress;
use Tests\TestCase;

/**
 * The `resume` block of the run summary: the state,
 * how often the task was resumed, when it was interrupted, when its next
 * provider attempt is due, and whether it was cancelled. Every description of
 * a run carries it (the list, a single run, the live push), it is null for a
 * run that never adopted the feature, and a list looks it up in one batch.
 */
class RunSummaryResumeBlockTest extends TestCase
{
    use SeedsRunProgress;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:41.120000', 'UTC'));
        config(['llm-client.run_trace.enabled' => true]);
        $this->owner = User::factory()->create();
    }

    protected function tearDown(): void
    {
        $this->clearProgressTables();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function summaryOf(string $runId): array
    {
        return $this->actingAs($this->owner, 'api')->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}")->assertStatus(200)->json();
    }

    /** @return array<string, array> the rows of the list, by run id */
    private function listed(string $query = ''): array
    {
        $rows = $this->actingAs($this->owner, 'api')->getJson("/api/clarion-app/llm-client/agent-runs{$query}")->assertStatus(200)->json('data');

        return collect($rows)->keyBy('id')->all();
    }

    #[Test]
    #[DataProvider('liveStates')]
    public function a_live_run_carries_its_resume_facts(string $resumeState): void
    {
        $runId = $this->seedRun((string) $this->owner->id, [
            'resume_state' => $resumeState,
            'resume_count' => 2,
            'interrupted_at' => $this->stamp('2026-10-12 10:12:00'),
            'next_attempt_at' => $resumeState === 'waiting_provider_retry' ? $this->stamp('2026-10-12 10:31:10') : null,
        ]);

        $resume = $this->summaryOf($runId)['resume'];

        $this->assertSame(['state', 'resume_count', 'interrupted_at', 'next_attempt_at', 'cancelled'], array_keys($resume));
        $this->assertSame($resumeState, $resume['state']);
        $this->assertSame(2, $resume['resume_count']);
        $this->assertSame('2026-10-12T10:12:00.000000Z', $resume['interrupted_at']);
        $this->assertSame($resumeState === 'waiting_provider_retry' ? '2026-10-12T10:31:10.000000Z' : null, $resume['next_attempt_at']);
        $this->assertFalse($resume['cancelled']);
    }

    public static function liveStates(): array
    {
        return [
            'working' => ['working'],
            'interrupted' => ['interrupted'],
            'resuming' => ['resuming'],
            'waiting_provider_retry' => ['waiting_provider_retry'],
        ];
    }

    #[Test]
    public function a_cancelled_run_says_so(): void
    {
        $byColumn = $this->seedRun((string) $this->owner->id, ['end_state' => 'stopped_early', 'end_reason' => 'cancelled', 'resume_state' => null, 'cancelled_at' => $this->stamp('2026-10-12 10:20:00')]);
        $byEvent = $this->seedRun((string) $this->owner->id, ['end_state' => 'stopped_early', 'end_reason' => 'The conversation was ended.', 'resume_state' => null]);
        $this->seedEvent($byEvent, 'resume_refused_cancelled', ['cause' => 'conversation_ended']);
        $running = $this->seedRun((string) $this->owner->id);

        $this->assertTrue($this->summaryOf($byColumn)['resume']['cancelled']);
        $this->assertTrue($this->summaryOf($byEvent)['resume']['cancelled'], 'a refused resume names the cancellation');
        $this->assertFalse($this->summaryOf($running)['resume']['cancelled']);
    }

    #[Test]
    public function a_run_that_never_adopted_the_feature_has_no_block(): void
    {
        $legacy = $this->seedRun((string) $this->owner->id, ['checkpoint_version' => null, 'resume_mode' => null, 'resume_driver' => null, 'resume_state' => null]);

        $summary = $this->summaryOf($legacy);

        $this->assertArrayHasKey('resume', $summary, 'the key is always there');
        $this->assertNull($summary['resume']);
    }

    #[Test]
    public function the_list_and_the_single_run_describe_the_block_alike(): void
    {
        $runId = $this->seedRun((string) $this->owner->id, ['resume_state' => 'interrupted', 'resume_count' => 1, 'interrupted_at' => $this->stamp('2026-10-12 10:12:00')]);
        $legacy = $this->seedRun((string) $this->owner->id, ['checkpoint_version' => null, 'resume_state' => null]);

        $rows = $this->listed();

        $this->assertSame($this->summaryOf($runId)['resume'], $rows[$runId]['resume']);
        $this->assertNull($rows[$legacy]['resume']);
    }

    #[Test]
    public function the_live_push_carries_the_same_block(): void
    {
        $runId = $this->seedRun((string) $this->owner->id, ['resume_state' => 'resuming', 'resume_count' => 1, 'interrupted_at' => $this->stamp('2026-10-12 10:12:00')]);

        $payload = (new RunUpdated($runId))->broadcastWith();

        $this->assertSame($this->summaryOf($runId)['resume'], $payload['resume']);
        $this->assertSame('resuming', $payload['resume']['state']);
    }

    #[Test]
    public function a_list_of_runs_adds_one_query_for_the_block_however_many_runs_it_holds(): void
    {
        $ids = [];
        foreach (range(1, 20) as $n) {
            $ids[] = $this->seedRun((string) $this->owner->id, [
                'started_at' => $this->stamp('2026-10-12 08:00:00'),
                'resume_state' => ['working', 'interrupted', 'resuming', 'waiting_provider_retry'][$n % 4],
                'resume_count' => $n % 3,
            ]);
        }
        $cancelled = $this->seedRun((string) $this->owner->id, ['end_state' => 'stopped_early', 'end_reason' => 'ended', 'resume_state' => null]);
        $this->seedEvent($cancelled, 'resume_refused_cancelled', ['cause' => 'conversation_deleted']);

        $queries = [];
        $counting = false;
        DB::listen(function (QueryExecuted $query) use (&$queries, &$counting) {
            if ($counting) {
                $queries[] = $query->sql;
            }
        });
        $list = function (int $perPage) use (&$queries, &$counting): array {
            $queries = [];
            $counting = true;
            $rows = $this->listed("?per_page={$perPage}");
            $counting = false;

            return [$queries, $rows];
        };
        $mentioning = fn (array $sqls, string $table) => count(array_filter($sqls, fn (string $sql) => str_contains($sql, $table)));

        [$oneQueries, $one] = $list(1);
        [$allQueries, $all] = $list(100);

        $this->assertCount(1, $one, 'precondition');
        $this->assertCount(21, $all, 'precondition');
        $this->assertSame(1, $mentioning($allQueries, 'agent_run_resume_events'), 'one query looks up the cancellations of all 21 runs');
        $this->assertSame(1, $mentioning($oneQueries, 'agent_run_resume_events'), 'and one for a single run');
        $this->assertSame(count($oneQueries), count($allQueries), 'the number of queries does not grow with the runs listed');
        $this->assertTrue($all[$cancelled]['resume']['cancelled']);
        foreach ($ids as $id) {
            $this->assertIsArray($all[$id]['resume'], "run {$id}");
        }
    }
}
