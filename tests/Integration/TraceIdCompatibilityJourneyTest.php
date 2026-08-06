<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\ToolInvocationRecord;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Services\RunTraceQuery;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * US5 (Phase 6) — the correctness backstop for pre-existing data (FR-016,
 * FR-017, FR-018). No new production code is exercised here: the nullable
 * `run_id` column and RunTraceQuery's existing null-handling (established in
 * Phase 2/3) are expected to already satisfy every scenario below.
 *
 * "Pre-feature" rows are simulated by creating Message/ToolInvocationRecord/
 * UsageRecord rows with no open run on Context — the same `creating` listener
 * that stamps run_id from Context (Phase 3) leaves it null when Context holds
 * nothing, which is exactly the state every row written before this feature
 * shipped is in. No backfill, no fabricated identifier (FR-017).
 */
class TraceIdCompatibilityJourneyTest extends AssembledSystemTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.run_trace.enabled', true);
    }

    /**
     * US5 scenario 1 (FR-016): a mixed set of tagged and untagged rows both
     * display correctly through ordinary, non-run_id-filtered listings, with
     * no error and no fabricated identifier on the untagged rows.
     *
     * US5 scenario 2 (FR-016): run_id-filtered RunTraceQuery methods return
     * only the tagged subset from that same mixed set, and the untagged rows
     * remain visible through the general listing, unaffected.
     */
    public function test_mixed_tagged_and_untagged_rows_coexist_in_listings_and_filtered_queries(): void
    {
        $fixture = $this->fixture()->build();
        $recorder = $this->app->make(RunTraceRecorder::class);
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) $fixture->user->id;

        // Precondition: no run is open, so Context holds nothing — mirrors a
        // deployment that predates this feature entirely.
        $this->assertNull(Context::get('run_id'));

        // --- "pre-feature" rows: created with no open run, run_id stays null.
        $untaggedMessage = Message::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $fixture->conversation->id,
            'role' => 'assistant',
            'content' => 'A reply written before trace-id propagation shipped.',
            'sequence_number' => 100,
        ]);
        $this->assertNull($untaggedMessage->run_id, 'Precondition: no open run means no run_id is stamped');

        $untaggedInvocation = ToolInvocationRecord::create([
            'conversation_id' => $fixture->conversation->id,
            'user_id' => $userId,
            'attempt_group_id' => (string) Str::uuid(),
            'tool_name' => 'legacy_tool',
            'outcome' => 'success',
        ]);
        $this->assertNull($untaggedInvocation->run_id);

        $untaggedUsage = UsageRecord::create([
            'conversation_id' => $fixture->conversation->id,
            'user_id' => $userId,
            'attempt_group_id' => (string) Str::uuid(),
            'input_tokens' => 10,
            'output_tokens' => 5,
            'total_tokens' => 15,
            'model' => 'legacy-model',
            'provider_type' => 'openai',
        ]);
        $this->assertNull($untaggedUsage->run_id);

        // --- "post-feature" rows: created inside an open run, via the normal
        // Context-stamping path (RunTraceRecorder::openRun() + the models'
        // own `creating` listeners — no direct run_id assignment).
        $runId = $recorder->openRun(RunKind::Interactive, $userId, $fixture->conversation->id);
        $this->assertNotNull($runId);
        $this->assertSame($runId, Context::get('run_id'), 'openRun() stamps Context, which the models read');

        $taggedMessage = Message::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $fixture->conversation->id,
            'role' => 'assistant',
            'content' => 'A reply produced by a run under this feature.',
            'sequence_number' => 101,
        ]);
        $this->assertSame($runId, $taggedMessage->run_id);

        $taggedInvocation = ToolInvocationRecord::create([
            'conversation_id' => $fixture->conversation->id,
            'user_id' => $userId,
            'attempt_group_id' => (string) Str::uuid(),
            'tool_name' => 'current_tool',
            'outcome' => 'success',
        ]);
        $this->assertSame($runId, $taggedInvocation->run_id);

        $taggedUsage = UsageRecord::create([
            'conversation_id' => $fixture->conversation->id,
            'user_id' => $userId,
            'attempt_group_id' => (string) Str::uuid(),
            'input_tokens' => 20,
            'output_tokens' => 8,
            'total_tokens' => 28,
            'model' => 'current-model',
            'provider_type' => 'openai',
        ]);
        $this->assertSame($runId, $taggedUsage->run_id);

        $recorder->closeRun($runId, RunEndState::Completed);
        $this->assertNull(Context::get('run_id'), 'closeRun() clears Context so nothing after it is mis-tagged');

        // === Scenario 1: ordinary, non-run_id-filtered listings show both
        // the tagged and untagged rows, with no error.
        $allMessages = Message::where('conversation_id', $fixture->conversation->id)->get();
        $messageIds = $allMessages->pluck('id')->all();
        $this->assertContains($untaggedMessage->id, $messageIds, 'Untagged (pre-feature) message must still appear in the general listing');
        $this->assertContains($taggedMessage->id, $messageIds, 'Tagged message must also appear in the general listing');
        $this->assertNull(
            $allMessages->firstWhere('id', $untaggedMessage->id)->run_id,
            'The untagged message keeps a genuine null, not a fabricated identifier (FR-017)'
        );

        $allInvocations = ToolInvocationRecord::where('conversation_id', $fixture->conversation->id)->get();
        $invocationIds = $allInvocations->pluck('id')->all();
        $this->assertContains($untaggedInvocation->id, $invocationIds);
        $this->assertContains($taggedInvocation->id, $invocationIds);
        $this->assertNull($allInvocations->firstWhere('id', $untaggedInvocation->id)->run_id);

        $allUsage = UsageRecord::where('conversation_id', $fixture->conversation->id)->get();
        $usageIds = $allUsage->pluck('id')->all();
        $this->assertContains($untaggedUsage->id, $usageIds);
        $this->assertContains($taggedUsage->id, $usageIds);
        $this->assertNull($allUsage->firstWhere('id', $untaggedUsage->id)->run_id);

        // === Scenario 2: run_id-filtered RunTraceQuery methods return ONLY
        // the tagged subset — the untagged rows are excluded, not errored on.
        $filteredMessages = $query->messagesForRun($userId, $runId);
        $this->assertNotNull($filteredMessages);
        $filteredMessageIds = array_map(fn ($m) => $m->id, $filteredMessages);
        $this->assertContains($taggedMessage->id, $filteredMessageIds);
        $this->assertNotContains($untaggedMessage->id, $filteredMessageIds, 'A null-run_id row must never satisfy a run_id filter');

        $filteredInvocations = $query->toolInvocationsForRun($userId, $runId);
        $this->assertNotNull($filteredInvocations);
        $filteredInvocationIds = array_map(fn ($t) => $t->id, $filteredInvocations);
        $this->assertContains($taggedInvocation->id, $filteredInvocationIds);
        $this->assertNotContains($untaggedInvocation->id, $filteredInvocationIds);

        $filteredUsage = $query->usageRecordsForRun($userId, $runId);
        $this->assertNotNull($filteredUsage);
        $filteredUsageIds = array_map(fn ($u) => $u->id, $filteredUsage);
        $this->assertContains($taggedUsage->id, $filteredUsageIds);
        $this->assertNotContains($untaggedUsage->id, $filteredUsageIds);

        // The untagged rows remain visible through the general listing,
        // unaffected by the existence of the filtered query above.
        $this->assertContains(
            $untaggedMessage->id,
            Message::where('conversation_id', $fixture->conversation->id)->get()->pluck('id')->all()
        );
    }

    /**
     * US5 scenario 3 (FR-018): a run_id naming a run that has since been
     * purged under spec 062's retention policy resolves via
     * RunTraceQuery::findRun() to null — identical to a record with no
     * identifier at all — rather than an error. Matches the "simulated
     * purge" pattern already used by RunTraceQueryRunRecordsTest (T011):
     * delete the agent_runs row directly, leaving the orphaned run_id
     * behind on the record.
     */
    public function test_run_id_naming_a_purged_run_resolves_to_null_not_an_error(): void
    {
        $fixture = $this->fixture()->build();
        $recorder = $this->app->make(RunTraceRecorder::class);
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) $fixture->user->id;

        $runId = $recorder->openRun(RunKind::Interactive, $userId, $fixture->conversation->id);
        $this->assertNotNull($runId);

        $orphanedMessage = Message::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $fixture->conversation->id,
            'role' => 'assistant',
            'content' => 'A reply whose run will be purged out from under it.',
            'sequence_number' => 200,
        ]);
        $this->assertSame($runId, $orphanedMessage->run_id);

        $recorder->closeRun($runId, RunEndState::Completed);

        // Simulate spec 062's retention purge: the agent_runs row is gone,
        // the record's run_id column is untouched (no FK, no cascade —
        // data-model.md §4 / research.md D4).
        DB::table('agent_runs')->where('id', $runId)->delete();

        $resolved = $query->findRun($userId, $runId);
        $this->assertNull(
            $resolved,
            'A run_id naming a since-purged run resolves to null via findRun(), not an error (FR-018)'
        );

        // Identical treatment to a record carrying no identifier at all.
        $noIdentifierResolution = $query->findRun($userId, (string) Str::uuid());
        $this->assertNull($noIdentifierResolution);
        $this->assertSame($resolved, $noIdentifierResolution, 'Purged-run and no-identifier resolutions are indistinguishable (FR-018)');

        // The record itself remains otherwise intact and usable — still
        // readable through the ordinary listing, still carrying its
        // (now-dangling) run_id, with no error.
        $stillReadable = Message::where('conversation_id', $fixture->conversation->id)
            ->where('id', $orphanedMessage->id)
            ->first();
        $this->assertNotNull($stillReadable);
        $this->assertSame($runId, $stillReadable->run_id, "The record's stored run_id is untouched by the purge — only resolving it changes");

        // Every run_id-filtered lookup for the purged run also resolves to
        // null, exactly as it would for a run that never existed (FR-018).
        $this->assertNull($query->messagesForRun($userId, $runId));
        $this->assertNull($query->toolInvocationsForRun($userId, $runId));
        $this->assertNull($query->usageRecordsForRun($userId, $runId));
    }
}
