<?php

namespace Tests\Unit;

use ClarionApp\LlmClient\Models\UsageRecord;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * contracts/trace-id-propagation.md §3 (M1-M4), for UsageRecord.
 * The sole write site (MetricsRecorder::recordUsage()) requires no code
 * change — the listener stamps the row regardless of who calls
 * UsageRecord::create() (FR-006).
 */
class UsageRecordRunIdStampingTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::forget('run_id');
        DB::table('usage_records')->delete();

        parent::tearDown();
    }

    protected function baseAttributes(): array
    {
        return [
            'conversation_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'attempt_group_id' => (string) Str::uuid(),
            'input_tokens' => 10,
            'output_tokens' => 5,
            'total_tokens' => 15,
        ];
    }

    /** @test */
    public function creating_listener_stamps_run_id_from_context(): void
    {
        $runId = (string) Str::uuid();
        Context::add('run_id', $runId);

        $record = UsageRecord::create($this->baseAttributes());

        $this->assertSame($runId, $record->run_id);
    }

    /** @test */
    public function explicit_run_id_is_never_overwritten(): void
    {
        Context::add('run_id', (string) Str::uuid());
        $explicitRunId = (string) Str::uuid();

        $record = new UsageRecord($this->baseAttributes());
        $record->run_id = $explicitRunId;
        $record->save();

        $this->assertSame(
            $explicitRunId,
            $record->run_id,
            'M1: a caller-set run_id is never overwritten by the ambient Context value'
        );
    }

    /** @test */
    public function run_id_stays_null_when_context_empty(): void
    {
        Context::forget('run_id');

        $record = UsageRecord::create($this->baseAttributes());

        $this->assertNull(
            $record->run_id,
            'M2: no run open means no fallback identifier is fabricated'
        );
    }

    /** @test */
    public function creating_listener_issues_no_extra_query(): void
    {
        Context::forget('run_id');
        DB::enableQueryLog();
        UsageRecord::create($this->baseAttributes());
        $withoutContext = count(DB::getQueryLog());
        DB::flushQueryLog();

        Context::add('run_id', (string) Str::uuid());
        UsageRecord::create($this->baseAttributes());
        $withContext = count(DB::getQueryLog());
        DB::disableQueryLog();
        Context::forget('run_id');

        $this->assertSame(
            $withoutContext,
            $withContext,
            'M3: stamping run_id from Context must not add a query beyond the existing insert'
        );
    }
}
