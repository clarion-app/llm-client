<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\RunTraceQuery;
use ClarionApp\LlmClient\ValueObjects\RunRelation;
use Illuminate\Support\Facades\DB;

/**
 * SC-004 / FR-020 driven through the real delivery paths.
 *
 * "100% of completed interactive runs are resolvable from their assistant reply
 * message, and 100% are resolvable from the user message that triggered them."
 *
 * Seeding the two associations with `RunTraceRecorder::linkMessage()` and then
 * resolving them proves only that `agent_run_messages` can be read back. It cannot
 * fail if `AgentLoopService` stops writing the trigger, or if the streaming path
 * loses the trigger message id on its way through `start()` — which is the whole
 * substance of FR-020. These tests therefore drive `run()` and the streamed loop
 * and let the production code write the associations.
 */
class RunTraceAssociationJourneyTest extends AssembledSystemTestCase
{
    public function test_sync_response_resolves_from_both_trigger_and_reply(): void
    {
        $this->scenario = 'sync_response_resolves_from_both';
        $this->entryPath = 'sync';

        $fixture = $this->fixture()->build();
        $this->app['config']->set('llm-client.run_trace.enabled', true);

        $this->script()->finalAnswer('Here is your answer.');

        $result = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'A question that needs answering.',
        );

        $this->assertSame('completed', $result['status']);

        $userId = (string) $fixture->conversation->user_id;
        $query = $this->app->make(RunTraceQuery::class);

        $run = DB::table('agent_runs')
            ->where('conversation_id', $fixture->conversation->id)
            ->first();
        $this->assertNotNull($run);

        // The messages as the loop actually created them.
        $triggerMessage = Message::where('conversation_id', $fixture->conversation->id)
            ->where('role', 'user')
            ->orderBy('created_at')
            ->first();
        $this->assertNotNull($triggerMessage, 'The loop created the triggering user message');

        $replyMessage = Message::find($result['message_id']);
        $this->assertNotNull($replyMessage, 'The loop created the assistant reply');

        $fromTrigger = $query->findRunForMessage($userId, $triggerMessage->id);
        $this->assertNotNull($fromTrigger, 'The triggering user message resolves to the run');
        $this->assertEquals($run->id, $fromTrigger->id);

        $fromReply = $query->findRunForMessage($userId, $replyMessage->id);
        $this->assertNotNull($fromReply, 'The assistant reply resolves to the run');
        $this->assertEquals($run->id, $fromReply->id);

        // Each association carries the relation that distinguishes the two lookups.
        $this->assertNotNull(
            $query->findRunForMessage($userId, $triggerMessage->id, RunRelation::Trigger),
        );
        $this->assertNotNull(
            $query->findRunForMessage($userId, $replyMessage->id, RunRelation::Reply),
        );
    }

    public function test_streamed_response_resolves_from_both_trigger_and_reply(): void
    {
        $this->scenario = 'streamed_response_resolves_from_both';
        $this->entryPath = 'stream';

        $fixture = $this->fixture()->build();
        $this->app['config']->set('llm-client.run_trace.enabled', true);

        $this->script()->finalAnswer('Here is your streamed answer.');

        // MessageController::store() creates the user message, then hands its id
        // to start(). Reproduced here rather than guessed, because "the latest
        // user message" is exactly the ambiguity FR-019 makes unsafe.
        $triggerMessage = Message::create([
            'conversation_id' => $fixture->conversation->id,
            'role' => 'user',
            'user' => 'User',
            'content' => 'A question that needs a streamed answer.',
            'responseTime' => 0,
        ]);

        $this->app->make(AgentLoopService::class)->start(
            $fixture->conversation,
            1,
            null,
            $triggerMessage->id,
        );

        $stream = $this->stream();
        $stream->extractDispatchedJobs();
        $stream->emit($this->buildSseChunks($this->script()->serve()));
        $stream->finish();

        $userId = (string) $fixture->conversation->user_id;
        $query = $this->app->make(RunTraceQuery::class);

        $run = DB::table('agent_runs')
            ->where('conversation_id', $fixture->conversation->id)
            ->first();
        $this->assertNotNull($run);

        $replyMessage = Message::where('conversation_id', $fixture->conversation->id)
            ->where('role', 'assistant')
            ->orderByDesc('created_at')
            ->first();
        $this->assertNotNull($replyMessage);

        $fromTrigger = $query->findRunForMessage($userId, $triggerMessage->id);
        $this->assertNotNull(
            $fromTrigger,
            'The triggering user message resolves to the run on the streamed path too (FR-008)',
        );
        $this->assertEquals($run->id, $fromTrigger->id);

        $fromReply = $query->findRunForMessage($userId, $replyMessage->id);
        $this->assertNotNull($fromReply, 'The streamed reply resolves to the run');
        $this->assertEquals($run->id, $fromReply->id);

        $this->assertEquals(
            $fromTrigger->id,
            $fromReply->id,
            'Both messages resolve to the same run',
        );
    }
}
