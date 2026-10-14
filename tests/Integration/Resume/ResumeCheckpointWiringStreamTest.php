<?php

namespace Tests\Integration\Resume;

use ClarionApp\LlmClient\Models\Conversation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\TaskBudgetJourneys;

/**
 * The streaming path writes the checkpoint ledger (C0/C1/C2/C5) and hands the
 * execution lease off at every iteration boundary.
 *
 * A scripted two-turn stream (a tool call, then a reply) leaves the run
 * checkpointed (version 1, stream, root) with every step committed and its
 * message id. The step's message id is the partial assistant row as soon as
 * the first content delta arrives, and it is the same row after finish(). At
 * the hand-off to the next iteration the run has a new dispatch id and its
 * lease released; at close the lease columns are cleared.
 */
class ResumeCheckpointWiringStreamTest extends AssembledSystemTestCase
{
    use TaskBudgetJourneys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTaskBudgetJourney();
    }

    protected function tearDown(): void
    {
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    /** @return object the run row for the conversation's single run */
    private function runRowOf(Conversation $conversation): object
    {
        $row = DB::table('agent_runs')->where('conversation_id', $conversation->id)->orderByDesc('started_at')->first();
        $this->assertNotNull($row, 'the run exists');

        return $row;
    }

    /** @return object the run's step at $position (0-based) */
    private function stepOf(object $run, int $position): object
    {
        $step = DB::table('agent_run_steps')->where('run_id', $run->id)->where('position', $position)->first();
        $this->assertNotNull($step, "step {$position} exists");

        return $step;
    }

    #[Test]
    public function a_two_turn_stream_is_checkpointed_and_hands_the_lease_off(): void
    {
        $this->scenario = 'resume_wiring_stream_two_turn';
        $conversation = $this->conversationFor('stream');

        // Turn 1: a tool call. Turn 2: the reply.
        $this->script()->toolRequests($this->stepCalls(1))->usage(10, 10);
        $this->script()->finalAnswer('Done.')->usage(10, 10);

        $this->send('stream', $conversation, 'Store a note, then reply.')->assertStatus(201);

        // ---- Turn 1: the tool call -------------------------------------
        $stream = $this->stream();
        $stream->extractDispatchedJobs();
        $response = $this->script()->serve();
        $stream->emit($this->buildToolCallSseChunks($response));
        $stream->finish();

        // The hand-off to turn 2: a new dispatch id, the lease released.
        $run = $this->runRowOf($conversation);
        $dispatchAfterTurnOne = $run->dispatch_id;
        $this->assertNotNull($dispatchAfterTurnOne, 'the run has a dispatch id at the hand-off');
        $this->assertNull($run->lease_holder, 'the lease is released at the hand-off');

        // ---- Turn 2: the reply ----------------------------------------
        $stream->nextSlot();
        $stream->extractDispatchedJobs();
        $response2 = $this->script()->serve();
        $chunks = $this->buildSseChunks($response2);

        // The first content delta: the step's message id is the partial row.
        $stream->emit([$chunks[0]]);
        $messageIdAtFirstDelta = $this->stepOf($run, 1)->message_id;
        $this->assertNotNull($messageIdAtFirstDelta, 'the step names its partial row at the first delta');

        // The rest of the turn, then finish.
        $stream->emit(array_slice($chunks, 1));
        $stream->finish();
        $messageIdAfterFinish = $this->stepOf($run, 1)->message_id;
        $this->assertSame($messageIdAtFirstDelta, $messageIdAfterFinish, 'the step keeps the same row after finish');

        // ---- The run and its steps ------------------------------------
        $run = $this->runRowOf($conversation);
        $this->assertSame(1, (int) $run->checkpoint_version, 'the run is checkpointed');
        $this->assertSame('stream', $run->resume_mode);
        $this->assertSame('root', $run->resume_driver, 'a plain interactive run is driven by root');
        $this->assertNotNull($run->resume_options, 'the whitelisted options are stored');

        $steps = DB::table('agent_run_steps')->where('run_id', $run->id)->orderBy('position')->get();
        $this->assertCount(2, $steps, 'two steps, one per turn');
        foreach ($steps as $i => $step) {
            $this->assertSame($i + 1, (int) $step->iteration, "step {$i} carries its loop iteration");
            $this->assertSame('committed', $step->checkpoint_state, "step {$i} is committed");
            $this->assertNotNull($step->message_id, "step {$i} names its assistant message");
            $this->assertNotNull($step->checkpoint, "step {$i} has a checkpoint");
        }

        // The run closed: its lease is cleared.
        $this->assertNull($run->lease_holder, 'the lease is cleared at close');
        $this->assertNull($run->dispatch_id, 'the dispatch id is cleared at close');
        $this->assertNull($run->resume_state, 'the resume state is cleared at close');
    }
}
