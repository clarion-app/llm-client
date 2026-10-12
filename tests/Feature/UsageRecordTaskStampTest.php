<?php

namespace Tests\Feature;

use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A usage record carries the task it was spent on.
 *
 * Every model call records exactly one usage row through
 * MetricsRecorder::recordUsage(), wherever in the task's tree of helpers it
 * ran. The row learns its task the way it already learns its run: from the
 * ambient Context, stamped by the model's own creating listener, with no
 * query of its own. A task's spend is then simply the sum of its rows.
 */
class UsageRecordTaskStampTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::forget('task_budget_id');
        Context::forget('run_id');
        DB::table('usage_records')->delete();

        parent::tearDown();
    }

    private function record(string $attemptGroupId): void
    {
        app(MetricsRecorder::class)->recordUsage(
            conversationId: (string) Str::uuid(),
            userId: (string) Str::uuid(),
            attemptGroupId: $attemptGroupId,
            providerUsage: ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
            inputText: 'input',
            outputText: 'output',
        );
    }

    #[Test]
    public function usage_recorded_during_a_task_is_stamped_with_the_task(): void
    {
        $task = (string) Str::uuid();
        $run = (string) Str::uuid();
        Context::add('task_budget_id', $task);
        Context::add('run_id', $run);

        $group = (string) Str::uuid();
        $this->record($group);

        $row = UsageRecord::where('attempt_group_id', $group)->first();
        $this->assertNotNull($row, 'recordUsage() wrote its row');
        $this->assertSame($task, $row->task_budget_id);
        $this->assertSame($run, $row->run_id, 'the run stamp is unchanged');
    }

    #[Test]
    public function an_explicit_task_is_never_overwritten(): void
    {
        Context::add('task_budget_id', (string) Str::uuid());
        $explicit = (string) Str::uuid();

        $record = new UsageRecord([
            'conversation_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'attempt_group_id' => (string) Str::uuid(),
            'task_budget_id' => $explicit,
        ]);
        $record->save();

        $this->assertSame($explicit, $record->fresh()->task_budget_id);
    }

    #[Test]
    public function with_no_task_in_context_the_stamp_stays_null(): void
    {
        Context::forget('task_budget_id');

        $group = (string) Str::uuid();
        $this->record($group);

        $row = UsageRecord::where('attempt_group_id', $group)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->task_budget_id);
    }

    #[Test]
    public function the_stamp_issues_no_query_of_its_own(): void
    {
        $count = function (?string $task): int {
            $task === null ? Context::forget('task_budget_id') : Context::add('task_budget_id', $task);

            $queries = 0;
            DB::listen(function () use (&$queries) {
                $queries++;
            });

            $before = $queries;
            UsageRecord::create([
                'conversation_id' => (string) Str::uuid(),
                'user_id' => (string) Str::uuid(),
                'attempt_group_id' => (string) Str::uuid(),
            ]);

            return $queries - $before;
        };

        $without = $count(null);
        $with = $count((string) Str::uuid());

        $this->assertSame($without, $with, 'stamping from Context adds no query beyond the insert');
        $this->assertSame(1, UsageRecord::whereNotNull('task_budget_id')->count(), 'and the stamp was applied');
    }
}
