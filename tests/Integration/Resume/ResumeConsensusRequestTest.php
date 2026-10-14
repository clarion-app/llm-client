<?php

namespace Tests\Integration\Resume;

use ClarionApp\LlmClient\Jobs\ResumeConsensusRequestJob;
use ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob;
use ClarionApp\LlmClient\Jobs\RunDelegationBatchMemberJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\ConsensusRequest;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\TaskBudget;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\HelperResumeJourneys;
use Tests\Integration\Harness\RequestLane;
use Tests\Support\InjectedFault;

/**
 * A multi-opinion request whose contributors were interrupted, with the
 * request's own process (and so its HTTP response) gone, is finished by a
 * resume: the sweep hands the request to one job, finished contributors
 * are reused, unfinished ones resumed, the answer is reconciled once and
 * posted to the conversation on its channel.
 */
class ResumeConsensusRequestTest extends AssembledSystemTestCase
{
    use HelperResumeJourneys;

    private const QUESTION = 'What is the answer?';

    private const ANSWER = 'The answer is 42.';

    private Agent $coordinator;

    /** @var array<string, Agent> */
    private array $contributors = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHelperJourney();
        $this->entryPath = 'sync';
        $this->fixtureWithModel();
        $this->coordinator = $this->agent('coordinator');
        foreach (['a', 'b', 'c'] as $key) {
            $this->contributors[$key] = $this->agent("contributor-{$key}");
            $this->assignHelper($this->coordinator, $this->contributors[$key]);
            $this->serveHelperTask(self::QUESTION, strtoupper($key).' note', self::ANSWER, "contributor-{$key}");
        }
        RoleAssignment::create([
            'role' => 'judge', 'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->fixture()->server->id, 'model' => 'gpt-4',
        ]);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    private function judgeRequests(): int
    {
        return collect($this->transport()->capturedPayloadsForLane(RequestLane::AgentTurn))
            ->filter(fn ($payload) => str_starts_with((string) ($payload->messages[0]['content'] ?? ''), 'You are reconciling several independent contributors'))
            ->count();
    }

    private function delegationOf(string $key): Delegation
    {
        return Delegation::where('helper_agent_id', $this->contributors[$key]->id)->firstOrFail();
    }

    /** The one consensus request, interrupted: a finished, b stalled mid-contribution, c never started; its process gone. */
    private function interruptedRequest(Conversation $conversation): ConsensusRequest
    {
        $this->expectInterruptedRun = true;
        $this->bindInjector();
        Queue::fake([ResumeInterruptedRunJob::class, RunDelegationBatchMemberJob::class]);
        $this->script()->finalAnswer(json_encode(['classification' => 'agreed', 'reconciled_answer' => self::ANSWER, 'positions' => []]));

        $tree = null;
        $this->atEachJoinPoll(function (int $poll) use (&$tree, $conversation) {
            $job = fn (string $key) => $this->memberJobsFor($this->delegationOf($key)->id)[0];
            if ($poll === 1) {
                // b's worker dies after its first tool result was recorded; a is worked normally; c's job is never taken.
                $this->killTreeAt(fn () => $this->workMember($job('b')), 'after_tool_recorded', 1);
                $this->workMember($job('a'));
            } elseif ($poll === 2) {
                // Then the process that was waiting for them (the request itself) dies.
                $tree = $this->snapshotTree();
                throw new InjectedFault('the request process died');
            }
        });

        // The framework's handler logs the death of the request's process.
        $this->ledger->expect('the request process died');
        try {
            $this->actingAs($this->fixture()->user, 'api')->postJson('/api/clarion-app/llm-client/consensus-requests', [
                'conversation_id' => $conversation->id,
                'question' => self::QUESTION,
            ]);
        } catch (\Throwable) {
            // The request's response is gone with its process.
        }
        $this->assertNotNull($tree, 'the request reached its wait');
        $this->restoreTree($tree);

        $request = ConsensusRequest::firstOrFail();
        $this->assertSame('in_progress', $request->status, 'precondition: the request never finished');
        $this->assertNull($request->answer_message_id);
        $this->assertSame(['completed', 'in_progress', 'queued'], [$this->delegationOf('a')->status, $this->delegationOf('b')->status, $this->delegationOf('c')->status], 'precondition: finished, stalled, never started');
        $this->assertSame(['B note', 'A note'], $this->storedNotes());
        $this->assertSame(0, $this->judgeRequests(), 'precondition: nothing was reconciled');

        return $request;
    }

    /** @return list<object> the consensus resume jobs the sweep pushed (the resume job class is faked) */
    private function sweepForRequests(): array
    {
        $this->sweepFaking([ResumeConsensusRequestJob::class]);

        return Queue::pushed(ResumeConsensusRequestJob::class)->values()->all();
    }

    #[Test]
    public function an_interrupted_request_is_finished_by_one_resume_job(): void
    {
        $this->scenario = 'resume_consensus_request';
        $conversation = $this->rootConversation('sync', $this->coordinator);
        $request = $this->interruptedRequest($conversation);
        $answersBefore = Message::where('conversation_id', $conversation->id)->where('content', self::ANSWER)->count();
        $delegations = Delegation::pluck('id')->sort()->values()->all();

        // The sweep hands the request to one job, and only one.
        $jobs = $this->sweepForRequests();
        $this->assertCount(1, $jobs, 'one resume job for the request');
        $this->assertContains($request->id, array_filter(get_object_vars($jobs[0]), 'is_string'), 'it names the request');

        // A minute later the sweep finds nothing more to do for it.
        \Illuminate\Support\Carbon::setTestNow(now()->addMinute());
        Queue::fake([ResumeInterruptedRunJob::class, ResumeConsensusRequestJob::class, RunDelegationBatchMemberJob::class]);
        $this->assertSame(0, Artisan::call('llm-client:resume-interrupted-runs'));
        Queue::assertNotPushed(ResumeConsensusRequestJob::class);

        // The job runs the contributors that had not finished, here inline.
        Queue::fake([ResumeInterruptedRunJob::class, ResumeConsensusRequestJob::class]);
        $this->app->forgetScopedInstances();
        $this->app->call([$jobs[0], 'handle']);

        // The finished contributor was reused, the stalled one resumed, the unstarted one run.
        $this->assertSame($delegations, Delegation::pluck('id')->sort()->values()->all(), 'no new delegation was made');
        foreach (['a', 'b', 'c'] as $key) {
            $row = $this->delegationOf($key);
            $this->assertSame('completed', $row->status, "contributor {$key} completed");
            $this->assertSame(self::ANSWER, $row->result_summary);
            $this->assertCount(1, $this->runsOfConversation($row->helper_conversation_id), "contributor {$key} kept its one run");
        }
        $notes = $this->storedNotes();
        sort($notes);
        $this->assertSame(['A note', 'B note', 'C note'], $notes, 'each contributor\'s effect fired once');

        // The request is reconciled once and its answer posted to the conversation and delivered on its channel.
        $request->refresh();
        $this->assertSame('completed', $request->status);
        $this->assertSame(3, (int) $request->successful_count);
        $this->assertSame(1, $this->judgeRequests(), 'the answers were reconciled once');
        $answer = Message::find($request->answer_message_id);
        $this->assertNotNull($answer, 'the answer is a message of the conversation');
        $this->assertSame($conversation->id, $answer->conversation_id);
        $this->assertSame('assistant', $answer->role);
        $this->assertSame(self::ANSWER, $answer->content);
        $this->assertSame($answersBefore + 1, Message::where('conversation_id', $conversation->id)->where('content', self::ANSWER)->count());
        $this->assertContains(self::ANSWER, $this->telegram->textsFor($conversation->id), 'and delivered on the conversation\'s channel');
        $this->assertSame('completed', TaskBudget::where('scope', TaskBudget::SCOPE_CONSENSUS)->firstOrFail()->state->value, 'the request\'s task ended with it');

        // A later sweep dispatches nothing, and a second run of the job changes nothing.
        \Illuminate\Support\Carbon::setTestNow(now()->addMinutes(10));
        Queue::fake([ResumeInterruptedRunJob::class, ResumeConsensusRequestJob::class]);
        $this->assertSame(0, Artisan::call('llm-client:resume-interrupted-runs'));
        Queue::assertNotPushed(ResumeConsensusRequestJob::class);
        $this->app->call([$jobs[0], 'handle']);
        $this->assertSame($answersBefore + 1, Message::where('conversation_id', $conversation->id)->where('content', self::ANSWER)->count(), 'the answer is posted once');
        $this->assertSame(1, $this->judgeRequests());
    }
}
