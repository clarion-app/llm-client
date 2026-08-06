<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\ToolInvocationRecord;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\Services\RunTraceQuery;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * US1/US2 driven through the real delivery paths (Phase 3 Independent Test).
 *
 * Seeding run_id directly on hand-inserted rows proves only that the column
 * exists and RunTraceQuery can filter on it (RunTraceQueryRunRecordsTest
 * already covers that in isolation). It cannot fail if the `creating`
 * listeners are never wired up, or if AgentLoopService/MetricsRecorder stop
 * writing inside the run's Context scope — which is the whole substance of
 * FR-002/FR-003/FR-004/FR-006. These tests therefore drive AgentLoopService::run()
 * and RunTraceRecorder::traceSystemRun() and let the production code write
 * (and stamp) everything itself.
 */
class TraceIdRecordAttributionJourneyTest extends AssembledSystemTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.run_trace.enabled', true);
    }

    /**
     * US1: every message, tool-invocation, and usage record a multi-round
     * (tool call + final answer) run produces carries that run's id — no more,
     * no fewer — verified both by direct attribute read and by the
     * RunTraceQuery round trip an operator would actually use.
     */
    public function test_every_record_produced_during_run_carries_the_run_id(): void
    {
        $this->scenario = 'every_record_produced_during_run_carries_the_run_id';
        $this->entryPath = 'sync';

        $fixture = $this->fixture()->build();

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
        $this->assertNotNull($run);

        $messages = Message::where('conversation_id', $fixture->conversation->id)->get();
        $this->assertGreaterThanOrEqual(2, $messages->count(), 'A tool round plus a final answer writes several messages');
        foreach ($messages as $message) {
            $this->assertSame(
                $run->id,
                $message->run_id,
                "Message {$message->id} (role {$message->role}) must carry the run id (US1)"
            );
        }

        $toolInvocations = ToolInvocationRecord::where('conversation_id', $fixture->conversation->id)->get();
        $this->assertGreaterThanOrEqual(1, $toolInvocations->count());
        foreach ($toolInvocations as $invocation) {
            $this->assertSame($run->id, $invocation->run_id, "Tool invocation {$invocation->id} must carry the run id (US1)");
        }

        $usageRecords = UsageRecord::where('conversation_id', $fixture->conversation->id)->get();
        $this->assertGreaterThanOrEqual(2, $usageRecords->count(), 'One usage record per LLM call: the tool round and the final answer');
        foreach ($usageRecords as $usage) {
            $this->assertSame($run->id, $usage->run_id, "Usage record {$usage->id} must carry the run id (US1)");
        }

        // The operator round trip: run id -> exactly what that run produced, no more, no fewer.
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) $fixture->conversation->user_id;

        $queriedMessages = $query->messagesForRun($userId, $run->id);
        $this->assertNotNull($queriedMessages);
        $this->assertEqualsCanonicalizing(
            $messages->pluck('id')->all(),
            array_map(fn ($m) => $m->id, $queriedMessages),
        );

        $queriedInvocations = $query->toolInvocationsForRun($userId, $run->id);
        $this->assertNotNull($queriedInvocations);
        $this->assertEqualsCanonicalizing(
            $toolInvocations->pluck('id')->all(),
            array_map(fn ($t) => $t->id, $queriedInvocations),
        );

        $queriedUsage = $query->usageRecordsForRun($userId, $run->id);
        $this->assertNotNull($queriedUsage);
        $this->assertEqualsCanonicalizing(
            $usageRecords->pluck('id')->all(),
            array_map(fn ($u) => $u->id, $queriedUsage),
        );
    }

    /**
     * US2 scenario 1: the reply message an operator was complained about
     * resolves, via its own run_id attribute, to the run that produced it.
     */
    public function test_reply_message_resolves_to_its_run_via_run_id(): void
    {
        $this->scenario = 'reply_message_resolves_to_its_run';
        $this->entryPath = 'sync';

        $fixture = $this->fixture()->build();

        $this->script()->finalAnswer('Here is your answer.');

        $result = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'A question that needs answering.',
        );

        $this->assertSame('completed', $result['status']);
        $this->assertNotNull($result['message_id']);

        $run = DB::table('agent_runs')
            ->where('conversation_id', $fixture->conversation->id)
            ->first();
        $this->assertNotNull($run);

        $replyMessage = Message::find($result['message_id']);
        $this->assertNotNull($replyMessage);
        $this->assertSame(
            $run->id,
            $replyMessage->run_id,
            'US2 scenario 1: the reply message resolves to its run via $message->run_id'
        );
    }

    /**
     * US2 scenario 2 (edge case): a run that produced no reply — the provider
     * genuinely returned nothing usable, so AgentLoopService::run() returns
     * status 'error' before writing any assistant message — still tags
     * whatever it did write (the triggering user message) with its run id.
     */
    public function test_run_with_no_reply_still_tags_whatever_it_wrote(): void
    {
        $this->scenario = 'run_with_no_reply_still_tags_whatever_it_wrote';
        $this->entryPath = 'sync';

        $fixture = $this->fixture()->build();

        // A response with no `choices` entry — the "No response from LLM"
        // branch, which terminates the round before any reply is written.
        $this->script()->steps[] = ['choices' => []];

        $result = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'A question that will not get an answer.',
        );

        $this->assertSame('error', $result['status']);
        $this->assertNull($result['message_id']);

        $run = DB::table('agent_runs')
            ->where('conversation_id', $fixture->conversation->id)
            ->first();
        $this->assertNotNull($run, 'The run itself was opened even though it produced no reply');

        $triggerMessage = Message::where('conversation_id', $fixture->conversation->id)
            ->where('role', 'user')
            ->first();
        $this->assertNotNull($triggerMessage);
        $this->assertSame(
            $run->id,
            $triggerMessage->run_id,
            'US2 scenario 2: the triggering message still carries the run id even though the run produced no reply'
        );

        $replyMessage = Message::where('conversation_id', $fixture->conversation->id)
            ->where('role', 'assistant')
            ->first();
        $this->assertNull($replyMessage, 'Precondition: this scenario genuinely produced no reply');
    }

    /**
     * US1 scenario 4 / US2 scenario 3: two runs in the same conversation
     * resolve to two disjoint record sets and never cross-attribute.
     */
    public function test_two_runs_in_one_conversation_never_cross_attribute(): void
    {
        $this->scenario = 'two_runs_in_one_conversation_never_cross_attribute';
        $this->entryPath = 'sync';

        $fixture = $this->fixture()->build();

        $this->script()->finalAnswer('First round answer.');
        $firstResult = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'First question',
        );
        $this->assertSame('completed', $firstResult['status']);

        $this->script()->finalAnswer('Second round answer.');
        $secondResult = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'Second question',
        );
        $this->assertSame('completed', $secondResult['status']);

        $runs = DB::table('agent_runs')
            ->where('conversation_id', $fixture->conversation->id)
            ->orderBy('started_at')
            ->get();
        $this->assertCount(2, $runs);
        $firstRun = $runs[0];
        $secondRun = $runs[1];
        $this->assertNotSame($firstRun->id, $secondRun->id);

        $firstReply = Message::find($firstResult['message_id']);
        $secondReply = Message::find($secondResult['message_id']);
        $this->assertSame($firstRun->id, $firstReply->run_id);
        $this->assertSame($secondRun->id, $secondReply->run_id);
        $this->assertNotSame($firstReply->run_id, $secondReply->run_id);

        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) $fixture->conversation->user_id;

        $firstRunMessages = $query->messagesForRun($userId, $firstRun->id);
        $secondRunMessages = $query->messagesForRun($userId, $secondRun->id);
        $this->assertNotNull($firstRunMessages);
        $this->assertNotNull($secondRunMessages);

        $firstIds = array_map(fn ($m) => $m->id, $firstRunMessages);
        $secondIds = array_map(fn ($m) => $m->id, $secondRunMessages);

        $this->assertEmpty(
            array_intersect($firstIds, $secondIds),
            'Two runs in one conversation must never share a message (US1 scenario 4 / US2 scenario 3)'
        );
        $this->assertContains($firstReply->id, $firstIds);
        $this->assertContains($secondReply->id, $secondIds);
        $this->assertNotContains($secondReply->id, $firstIds);
        $this->assertNotContains($firstReply->id, $secondIds);
    }

    /**
     * FR-005 / SC-001: a system-initiated run (RunTraceRecorder::traceSystemRun(),
     * no conversation) tags its own tool-invocation and usage records with its
     * identifier exactly as an interactive run does.
     */
    public function test_system_initiated_run_tags_its_own_tool_and_usage_records(): void
    {
        $this->scenario = 'system_initiated_run_tags_its_own_records';
        $this->entryPath = 'sync';

        $fixture = $this->fixture()->build();

        $recorder = $this->app->make(RunTraceRecorder::class);
        $metrics = $this->app->make(MetricsRecorder::class);

        $recorder->traceSystemRun(
            'test_system_initiated_trace_id',
            $fixture->user->id,
            null, // no conversation — FR-005's "no user-facing conversation"
            function () use ($metrics, $fixture) {
                $metrics->recordUsage(
                    conversationId: (string) Str::uuid(),
                    userId: $fixture->user->id,
                    attemptGroupId: (string) Str::uuid(),
                    providerUsage: ['prompt_tokens' => 12, 'completion_tokens' => 4, 'total_tokens' => 16],
                    inputText: 'system prompt text',
                    outputText: 'system output text',
                    model: 'background-model',
                    providerType: 'openai',
                );

                $metrics->recordToolInvocation(
                    conversationId: (string) Str::uuid(),
                    userId: $fixture->user->id,
                    attemptGroupId: (string) Str::uuid(),
                    toolName: 'background_tool',
                    success: true,
                );

                return 'done';
            },
        );

        $run = DB::table('agent_runs')
            ->where('source', 'test_system_initiated_trace_id')
            ->where('user_id', $fixture->user->id)
            ->first();
        $this->assertNotNull($run);
        $this->assertNull($run->conversation_id, 'A system-initiated run has no conversation (FR-005)');

        $usageRecord = UsageRecord::where('user_id', $fixture->user->id)
            ->where('model', 'background-model')
            ->first();
        $this->assertNotNull($usageRecord);
        $this->assertSame(
            $run->id,
            $usageRecord->run_id,
            'A system-initiated run tags its own usage record exactly as an interactive run does (FR-005)'
        );

        $toolInvocation = ToolInvocationRecord::where('user_id', $fixture->user->id)
            ->where('tool_name', 'background_tool')
            ->first();
        $this->assertNotNull($toolInvocation);
        $this->assertSame(
            $run->id,
            $toolInvocation->run_id,
            'A system-initiated run tags its own tool-invocation record exactly as an interactive run does (FR-005)'
        );
    }
}
