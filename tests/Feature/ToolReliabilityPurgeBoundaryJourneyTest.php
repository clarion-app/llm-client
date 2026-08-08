<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\ValueObjects\ToolFailureCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * FR-006/SC-006 purge-safety proof: a tool_reliability_summaries row,
 * already materialized at write time (research.md D1), must remain fully
 * readable and byte-for-byte unchanged after the tool_invocation_records
 * rows it was built from are removed -- simulating a future retention purge.
 * No purge command exists yet for tool_invocation_records (research.md D11),
 * so this test simulates one by deleting the detail rows directly, exactly
 * as quickstart.md item 11 describes.
 *
 * Every summary row here is produced by the real
 * MetricsRecorder::recordToolInvocation() write path -- the whole point is
 * proving the read afterward never depends on the detail rows still
 * existing, which is only a meaningful claim if the summary was built the
 * same way a real deployment would build it.
 */
class ToolReliabilityPurgeBoundaryJourneyTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::table('tool_reliability_summaries')->delete();
        DB::table('tool_invocation_records')->delete();
        DB::table('users')->delete();
        parent::tearDown();
    }

    private function endpoint(string $path): string
    {
        return '/api/clarion-app/llm-client/tool-reliability/'.$path;
    }

    private function recordAt(Carbon $when, string $toolName, string $userId, bool $success, ?ToolFailureCategory $category = null): void
    {
        Carbon::setTestNow($when);
        try {
            (new MetricsRecorder())->recordToolInvocation(
                conversationId: (string) Str::uuid(),
                userId: $userId,
                attemptGroupId: (string) Str::uuid(),
                toolName: $toolName,
                success: $success,
                failureCategory: $category,
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * FR-006/SC-006, quickstart.md item 11: with a summary already
     * materialized for a period, directly deleting the underlying
     * tool_invocation_records rows for that period must leave the summary
     * read completely unchanged -- same counts, same breakdown, same
     * low_sample/no_activity flags.
     */
    #[Test]
    public function a_summary_read_is_byte_for_byte_unchanged_after_its_underlying_detail_rows_are_purged(): void
    {
        $user = User::factory()->create();
        $day = Carbon::parse('2026-02-10 09:00:00', 'UTC');

        for ($i = 0; $i < 12; $i++) {
            $this->recordAt($day, 'purgeable_tool', $user->id, true);
        }
        $this->recordAt($day, 'purgeable_tool', $user->id, false, ToolFailureCategory::Timeout);
        $this->recordAt($day, 'purgeable_tool', $user->id, false, ToolFailureCategory::InvalidInput);
        $this->recordAt($day, 'purgeable_tool', $user->id, false, ToolFailureCategory::InvalidInput);

        $url = $this->endpoint('tools/purgeable_tool?period=day&date=2026-02-10');

        $beforePurge = $this->actingAs($user)->getJson($url);
        $beforePurge->assertStatus(200);
        $beforeBody = $beforePurge->json();

        $this->assertSame(15, $beforeBody['invocation_count']);
        $this->assertSame(12, $beforeBody['success_count']);
        $this->assertSame(3, $beforeBody['failure_count']);
        $this->assertSame(1, $beforeBody['failure_breakdown']['timeout']);
        $this->assertSame(2, $beforeBody['failure_breakdown']['invalid_input']);

        $this->assertGreaterThan(0, DB::table('tool_invocation_records')->where('tool_name', 'purgeable_tool')->count(), 'sanity check: the detail rows exist before the simulated purge');

        // Simulate a future retention purge: delete every detail row for
        // this tool, leaving only the already-materialized summary row.
        DB::table('tool_invocation_records')->where('tool_name', 'purgeable_tool')->delete();
        $this->assertSame(0, DB::table('tool_invocation_records')->where('tool_name', 'purgeable_tool')->count());
        $this->assertGreaterThan(0, DB::table('tool_reliability_summaries')->where('tool_name', 'purgeable_tool')->count(), 'the summary row itself must survive the detail-row deletion');

        $afterPurge = $this->actingAs($user)->getJson($url);
        $afterPurge->assertStatus(200);

        $this->assertSame($beforeBody, $afterPurge->json(), 'the summary read must be byte-for-byte unchanged after the underlying detail rows are purged (FR-006/SC-006)');
    }

    /**
     * A period spanning a purge boundary -- some underlying detail rows for
     * the period already deleted, some still intact -- must still read
     * correctly, sourced entirely from whatever was durably captured into
     * the summary before the (partial) purge, per spec.md's own edge case.
     */
    #[Test]
    public function a_period_spanning_a_partial_purge_boundary_still_reads_correctly(): void
    {
        $user = User::factory()->create();
        $mondayOfWeek = Carbon::parse('2026-02-09 08:00:00', 'UTC'); // a Monday
        $laterInSameWeek = Carbon::parse('2026-02-12 08:00:00', 'UTC'); // Thursday, same ISO week

        // Monday: 5 successes, 1 failure -- these detail rows will be
        // purged, simulating an older slice of the week already cleaned up.
        for ($i = 0; $i < 5; $i++) {
            $this->recordAt($mondayOfWeek, 'boundary_tool', $user->id, true);
        }
        $this->recordAt($mondayOfWeek, 'boundary_tool', $user->id, false, ToolFailureCategory::ServerError);

        // Thursday: 4 successes, 2 failures -- these detail rows remain
        // intact, simulating the not-yet-purged slice of the same week.
        for ($i = 0; $i < 4; $i++) {
            $this->recordAt($laterInSameWeek, 'boundary_tool', $user->id, true);
        }
        $this->recordAt($laterInSameWeek, 'boundary_tool', $user->id, false, ToolFailureCategory::AuthenticationFailure);
        $this->recordAt($laterInSameWeek, 'boundary_tool', $user->id, false, ToolFailureCategory::AuthenticationFailure);

        $url = $this->endpoint('tools/boundary_tool?period=week&date=2026-02-11');

        $beforePartialPurge = $this->actingAs($user)->getJson($url);
        $beforePartialPurge->assertStatus(200);
        $this->assertSame(12, $beforePartialPurge->json('invocation_count'));
        $this->assertSame(9, $beforePartialPurge->json('success_count'));
        $this->assertSame(3, $beforePartialPurge->json('failure_count'));

        // Purge only Monday's detail rows -- Thursday's remain intact.
        DB::table('tool_invocation_records')
            ->where('tool_name', 'boundary_tool')
            ->whereBetween('created_at', ['2026-02-09 00:00:00', '2026-02-09 23:59:59.999999'])
            ->delete();

        $remainingDetailRows = DB::table('tool_invocation_records')->where('tool_name', 'boundary_tool')->count();
        $this->assertSame(6, $remainingDetailRows, "Thursday's 6 detail rows must remain, only Monday's 6 purged");

        $afterPartialPurge = $this->actingAs($user)->getJson($url);
        $afterPartialPurge->assertStatus(200);

        $this->assertSame(
            $beforePartialPurge->json(),
            $afterPartialPurge->json(),
            'a period spanning a purge boundary must still report the full, correct week total -- sourced from the durable summary, not the now-partial detail rows'
        );
    }
}
