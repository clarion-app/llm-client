<?php

namespace Tests\Integration\Resume;

use ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob;
use ClarionApp\LlmClient\Jobs\RunDelegationBatchMemberJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\DelegationService;
use ClarionApp\LlmClient\Services\RunResumer;
use Tests\Support\InjectedFault;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\HelperResumeJourneys;
use Tests\Integration\Harness\ProviderRetryJourneys;
use Tests\Integration\Harness\TaskBudgetHelperJourneys;

/**
 * A helper's provider failure is its delegator's wait too.
 *
 * A helper is a nested run: its caller (the parent's tool call, or a batch's
 * join) is blocked on its result, so a transient provider failure is retried
 * in place — a sleep through Laravel's Sleep seam (faked here), then the
 * same call again — and never deferred to a delayed job, which nobody could
 * wait on. While it sleeps its lease is extended past the sleep, so a
 * parent polling a batch does not mistake the sleeping helper for a dead one
 * and take it over.
 *
 * A helper whose worker died is the awaiting owner's to resume, in the
 * helper's own run and without repeating what it already did: the parent's
 * resume for a solo helper, the polling parent (or, with the parent dead
 * too, the parent's resume) for the members of a batch.
 */
class ResumeDelegationJourneyTest extends AssembledSystemTestCase
{
    use HelperResumeJourneys;
    use ProviderRetryJourneys;
    use TaskBudgetHelperJourneys;

    private Agent $root;

    private Agent $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHelperJourney();
        $this->armOutage();
        $this->entryPath = 'sync';
        $this->fixtureWithModel();
        $this->root = $this->agent('root-agent');
        $this->helper = $this->agent('solo-helper');
        $this->assignHelper($this->root, $this->helper);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        Sleep::fake(false);
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // M17 — a helper rides out a blip without leaving its caller
    // ---------------------------------------------------------------

    #[Test]
    public function helper_provider_blip_inline(): void
    {
        $this->scenario = 'resume_delegation_helper_blip_inline';
        $conversation = $this->rootConversation('sync', $this->root);

        // The root delegates; the helper's first model call fails once and
        // is retried; the root then answers.
        $this->script()->toolRequests([$this->delegateCall('call_solo', $this->helper, 'Say hello.')]);
        $this->scriptHelperAnswer('Helper said hello.');
        $this->script()->finalAnswer('The helper said hello.');
        $this->outage->failRequests([2]);

        // The helper's lease, read at the moment its retry sleeps.
        $leaseWhileSleeping = null;
        $sleeps = 0;
        $start = now();
        Sleep::fake();
        Sleep::whenFakingSleep(function () use (&$leaseWhileSleeping, &$sleeps) {
            $sleeps++;
            $helperConversation = Conversation::where('channel', 'agent-delegation')->first();
            $row = $helperConversation === null ? null : DB::table('agent_runs')->where('conversation_id', $helperConversation->id)->first();
            $leaseWhileSleeping = $row?->lease_expires_at;
        });

        $response = $this->send('sync', $conversation, 'Ask the helper to say hello.');

        // The helper retried in place: slept once, for the first delay.
        $this->assertSame(1, $this->outage->failures, 'the provider refused the helper\'s call once');
        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 10, 1);
        $this->assertSame(1, $sleeps);

        // The parent got the helper's result and finished its own task.
        $response->assertStatus(200);
        // (The reply opens with the standing disclosure that it included delegated work.)
        $this->assertStringEndsWith('The helper said hello.', (string) $response->json('content'));
        $result = $this->toolResultFor($conversation, 'call_solo');
        $this->assertNotNull($result, 'the parent received the helper\'s result');
        $this->assertStringContainsString('Helper said hello.', json_encode($result));

        // Nothing was deferred: no delayed job, no waiting run, and the
        // helper's event says the retry was in place.
        Queue::assertNotPushed(ResumeInterruptedRunJob::class);
        $helperConversation = Conversation::where('channel', 'agent-delegation')->firstOrFail();
        $helperRun = DB::table('agent_runs')->where('conversation_id', $helperConversation->id)->first();
        $this->assertNotNull($helperRun, 'the helper has its own run');
        $this->assertNotSame('waiting_provider_retry', $helperRun->resume_state);
        $this->assertNull($helperRun->next_attempt_at);
        $events = $this->resumeEventsOf($helperRun->id, 'provider_retry_scheduled');
        $this->assertCount(1, $events, 'the helper\'s retry is on its record');
        $this->assertFalse($events[0]['payload']['deferred'], 'the retry was in place, not deferred');
        $this->assertSame('unavailable', $events[0]['payload']['reason']);
        $this->assertSame('completed', $helperRun->end_state);

        // The parent's own run saw no failure at all.
        $this->assertSame(0, count(DB::table('agent_run_resume_events')->where('run_id', $this->runRowFor($conversation)->id)->get()));
        $this->assertSame('completed', $this->runRowFor($conversation)->end_state);

        // Before it slept, the helper's lease was extended to cover the
        // sleep and its own base lease, so a polling parent would not take
        // it over.
        $this->assertNotNull($leaseWhileSleeping, 'the helper held a lease while it slept');
        $needed = $start->copy()->addSeconds(10 + (int) config('llm-client.resume.helper_lease_seconds', 30));
        $this->assertGreaterThanOrEqual(
            $needed->getTimestamp(),
            Carbon::parse((string) $leaseWhileSleeping, 'UTC')->getTimestamp(),
            'lease_expires_at >= now + delay + helper_lease_seconds',
        );
    }

    // ---------------------------------------------------------------
    // Driving and killing
    // ---------------------------------------------------------------

    private function rootRun(Conversation $conversation, string $message, bool $propagateKill = true): void
    {
        try {
            $this->app->make(AgentLoopService::class)->run($conversation, $message);
        } catch (InjectedFault $e) {
            if ($propagateKill) {
                throw $e;
            }
        } catch (\Throwable) {
            // The work that ran on past a simulated kill ends however it ends;
            // the tables are put back as the kill left them.
        }
    }

    /** Three agents the root delegates a batch to, each served by its task. */
    private function threeMembers(): array
    {
        $members = [];
        foreach (['a' => 'Part A.', 'b' => 'Part B.', 'c' => 'Part C.'] as $key => $task) {
            $agent = $this->agent("member-{$key}");
            $this->assignHelper($this->root, $agent);
            $this->serveHelperTask($task, strtoupper($key).' note', strtoupper($key).' done.');
            $members[$key] = ['agent' => $agent, 'task' => $task, 'call' => "call_{$key}"];
        }

        return $members;
    }

    private function batchOf(array $members): array
    {
        return array_map(fn (array $m) => $this->delegateCall($m['call'], $m['agent'], $m['task']), array_values($members));
    }

    private function delegationFor(Conversation $parent, Agent $helper): Delegation
    {
        return Delegation::where('parent_conversation_id', $parent->id)->where('helper_agent_id', $helper->id)->firstOrFail();
    }

    /** The one resume job the sweep hands the run, worked. */
    private function sweepAndResume(Conversation $conversation): void
    {
        $runId = $this->latestRun($conversation)->id;
        $jobs = $this->sweep();
        $this->assertCount(1, $jobs, 'the sweep hands exactly one run to a job: the awaiting owner, never its helpers');
        $this->assertSame($runId, $jobs[0]->runId, 'it is the parent\'s run');
        $this->app->forgetScopedInstances();
        $jobs[0]->handle(app(RunResumer::class));
    }

    // ---------------------------------------------------------------
    // US5 AC1 — a solo helper killed with its parent is resumed by the parent
    // ---------------------------------------------------------------

    #[Test]
    public function solo_nested_helper_resumed_by_parent(): void
    {
        $this->scenario = 'resume_delegation_solo_nested';
        $this->bindInjector();
        $conversation = $this->rootConversation('sync', $this->root);
        $this->serveHelperTask('Write the note.', 'Helper note', 'Helper wrote the note.');
        $this->script()->toolRequests([$this->delegateCall('call_solo', $this->helper, 'Write the note.')]);
        // Consumed by the work that runs on past the kill in-process.
        $this->script()->finalAnswer('(work past the kill)');

        // The helper dies right after its first tool's result was recorded;
        // the parent dies with it (the tables are put back as the kill left them).
        $this->killTreeAt(fn () => $this->rootRun($conversation, 'Ask the helper to write the note.'), 'after_tool_recorded', 1);

        $this->assertSame(['Helper note'], $this->storedNotes(), 'precondition: the helper\'s effect fired once');
        $delegation = $this->delegationFor($conversation, $this->helper);
        $this->assertSame('in_progress', $delegation->status, 'precondition: the helper had not finished');
        $helperRuns = $this->runsOfConversation($delegation->helper_conversation_id);
        $this->assertCount(1, $helperRuns);
        $this->assertSame('in_progress', $helperRuns[0]->end_state);
        $rootRunId = $this->latestRun($conversation)->id;
        $this->discardUnconsumedScript();
        $this->script()->finalAnswer('The helper wrote the note.');
        $requestsBefore = $this->agentRequests();

        $this->sweepAndResume($conversation);

        // The parent resumed in its own run, and the helper in its own.
        $root = $this->latestRun($conversation);
        $this->assertSame($rootRunId, $root->id);
        $this->assertSame('completed', $root->end_state);
        $this->assertSame(1, Delegation::where('parent_conversation_id', $conversation->id)->count(), 'no second delegation was made');
        $this->assertCount(1, $this->runsOfConversation($delegation->helper_conversation_id), 'the helper kept its run');
        $this->assertSame($helperRuns[0]->id, $this->runsOfConversation($delegation->helper_conversation_id)[0]->id);
        $this->assertSame('completed', $this->runsOfConversation($delegation->helper_conversation_id)[0]->end_state);

        // Nothing the helper had done was repeated, and it asked the model only for its answer.
        $this->assertSame(['Helper note'], $this->storedNotes(), 'the recorded effect is not repeated');
        $this->assertSame($requestsBefore + 2, $this->agentRequests(), 'one model call for the helper\'s answer, one for the parent\'s');

        // The delegation row ends exactly as a delegation that was never interrupted does.
        $row = $delegation->fresh();
        $this->assertSame('completed', $row->status);
        $this->assertSame('success', $row->result_status);
        $this->assertNull($row->result_reason);
        $this->assertSame('Helper wrote the note.', $row->result_summary);
        $this->assertNotNull($row->completed_at);
        $this->assertSame($helperRuns[0]->id, $row->helper_run_id, 'the delegation names the helper\'s run');

        // The parent received it, once.
        $this->assertSame(1, $this->resultCountFor($conversation, 'call_solo'));
        $result = $this->toolResultFor($conversation, 'call_solo');
        $this->assertSame('Helper wrote the note.', $result['summary'] ?? null);
        $this->assertSame($delegation->id, $result['delegation_id'] ?? null);
        $this->assertSame([], $this->sweep(), 'a later sweep finds nothing more to resume');
    }

    // ---------------------------------------------------------------
    // M22 — a helper that finished is reused, not run again
    // ---------------------------------------------------------------

    #[Test]
    public function finished_helper_reused_by_resumed_parent(): void
    {
        $this->scenario = 'resume_delegation_finished_helper_reused';
        $this->bindInjector();
        $conversation = $this->rootConversation('sync', $this->root);
        $this->serveHelperTask('Write the note.', 'Helper note', 'Helper wrote the note.');
        $this->script()->toolRequests([$this->delegateCall('call_solo', $this->helper, 'Write the note.')]);

        // The helper finishes; the parent dies after the delegation's effect
        // fired but before its result was recorded (the helper's own tool is
        // effect 1, the parent's delegate call effect 2).
        $this->killTreeAt(fn () => $this->rootRun($conversation, 'Ask the helper to write the note.'), 'after_tool_effect', 2);

        $delegation = $this->delegationFor($conversation, $this->helper);
        $this->assertSame('completed', $delegation->status, 'precondition: the helper finished');
        $this->assertSame('call_solo', $delegation->parent_tool_call_id, 'precondition: the delegation names the parent\'s call');
        $helperRunId = $this->runsOfConversation($delegation->helper_conversation_id)[0]->id;
        $this->assertSame(['Helper note'], $this->storedNotes());
        $this->assertSame(0, $this->resultCountFor($conversation, 'call_solo'), 'precondition: the parent never recorded the result');
        $this->discardUnconsumedScript();
        $this->script()->finalAnswer('The helper wrote the note.');
        $requestsBefore = $this->agentRequests();

        $this->sweepAndResume($conversation);

        // The result comes from the delegation's own row: the helper was not run again.
        $this->assertSame($requestsBefore + 1, $this->agentRequests(), 'only the parent\'s final answer asked the model');
        $this->assertSame(['Helper note'], $this->storedNotes());
        $this->assertSame(1, Delegation::where('parent_conversation_id', $conversation->id)->count());
        $this->assertCount(1, $this->runsOfConversation($delegation->helper_conversation_id));
        $this->assertSame($helperRunId, $this->runsOfConversation($delegation->helper_conversation_id)[0]->id);

        $this->assertSame(1, $this->resultCountFor($conversation, 'call_solo'));
        $result = $this->toolResultFor($conversation, 'call_solo');
        $this->assertSame($delegation->id, $result['delegation_id'] ?? null, 'the result is the one delegation\'s');
        $this->assertSame('Helper wrote the note.', $result['summary'] ?? null);
        $this->assertSame('success', $result['status'] ?? null);
        $this->assertSame('completed', $this->latestRun($conversation)->end_state);
    }

    // ---------------------------------------------------------------
    // M21 — a member whose worker died is resumed while its parent waits
    // ---------------------------------------------------------------

    #[Test]
    public function batch_member_resumed_while_parent_waits(): void
    {
        $this->scenario = 'resume_delegation_batch_member_while_parent_waits';
        $this->expectInterruptedRun = true;
        $this->bindInjector();
        // Members are taken by "workers" (this test), not run inline.
        Queue::fake([ResumeInterruptedRunJob::class, RunDelegationBatchMemberJob::class]);
        $conversation = $this->rootConversation('sync', $this->root);
        $members = $this->threeMembers();
        $this->script()->toolRequests($this->batchOf($members));
        $this->script()->finalAnswer('All three parts are done.');

        $started = null;
        $this->atEachJoinPoll(function (int $poll) use (&$started, $members, $conversation) {
            $jobs = fn (string $key) => $this->memberJobsFor($this->delegationFor($conversation, $members[$key]['agent'])->id);
            if ($poll === 1) {
                $started = now()->copy();
                // Member b's worker dies after its first tool result was recorded;
                // members a and c are worked normally.
                $this->killTreeAt(fn () => $this->workMember($jobs('b')[0]), 'after_tool_recorded', 1);
                $this->workMember($jobs('a')[0]);
                $this->workMember($jobs('c')[0]);
                $this->assertSame(['B note', 'A note', 'C note'], $this->storedNotes());
                $this->assertSame('in_progress', $this->delegationFor($conversation, $members['b']['agent'])->status, 'precondition: b is stalled');
            } elseif ($poll === 2) {
                // b's helper lease runs out (a worker that is gone renews nothing).
                $helperConversationId = $this->delegationFor($conversation, $members['b']['agent'])->helper_conversation_id;
                $lease = $this->runsOfConversation($helperConversationId)[0]->lease_expires_at;
                $this->assertNotNull($lease, 'precondition: b\'s helper run holds a lease');
                \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse((string) $lease, 'UTC')->addSecond());
            } elseif ($poll === 3) {
                // Past the deadline the parent first gave b: only a deadline
                // extended for the resume keeps b from being timed out now.
                $beyond = $started->copy()->addSeconds((int) config('llm-client.delegation.max_seconds', 120) + DelegationService::JOIN_WAIT_GRACE_SECONDS + 10);
                if ($beyond->greaterThan(now())) {
                    \Illuminate\Support\Carbon::setTestNow($beyond);
                }
            } elseif ($poll === 4) {
                $resumeJobs = $jobs('b');
                $this->assertCount(2, $resumeJobs, 'b was dispatched once more, for its resume');
                $this->workMember($resumeJobs[1]);
            }
        });

        $result = $this->app->make(AgentLoopService::class)->run($conversation, 'Do the three parts.');
        $this->assertNoListenerFailure();

        // Exactly one resume job was dispatched for b, none for a or c.
        $b = $this->delegationFor($conversation, $members['b']['agent']);
        $this->assertCount(2, $this->memberJobsFor($b->id), 'one original job and one resume job for b');
        $this->assertCount(1, $this->memberJobsFor($this->delegationFor($conversation, $members['a']['agent'])->id));
        $this->assertCount(1, $this->memberJobsFor($this->delegationFor($conversation, $members['c']['agent'])->id));

        // b resumed in its own run; nobody was run twice.
        $this->assertSame('completed', $result['status'] ?? null);
        $this->assertCount(3, $this->delegationsOf($conversation), 'no new delegation rows');
        foreach ($members as $key => $member) {
            $row = $this->delegationFor($conversation, $member['agent']);
            $this->assertSame('completed', $row->status, "member {$key} completed (b was not timed out)");
            $this->assertSame(strtoupper($key).' done.', $row->result_summary);
            $this->assertCount(1, $this->runsOfConversation($row->helper_conversation_id), "member {$key} has the one run");
            $this->assertSame('completed', $this->runsOfConversation($row->helper_conversation_id)[0]->end_state);
            $this->assertSame(1, $this->resultCountFor($conversation, $member['call']), "the parent received member {$key}'s result once");
            $this->assertSame(strtoupper($key).' done.', $this->toolResultFor($conversation, $member['call'])['summary'] ?? null);
        }
        $notes = $this->storedNotes();
        sort($notes);
        $this->assertSame(['A note', 'B note', 'C note'], $notes, 'each member\'s effect fired once: the others were not re-run');
        $this->assertSame('completed', $this->latestRun($conversation)->end_state);
    }

    // ---------------------------------------------------------------
    // The parent died too: its resume re-joins the batch
    // ---------------------------------------------------------------

    #[Test]
    public function parent_died_too_rejoins(): void
    {
        $this->scenario = 'resume_delegation_parent_died_too';
        $this->expectInterruptedRun = true;
        $this->bindInjector();
        Queue::fake([ResumeInterruptedRunJob::class, RunDelegationBatchMemberJob::class]);
        $conversation = $this->rootConversation('sync', $this->root);
        $members = $this->threeMembers();
        $this->script()->toolRequests($this->batchOf($members));
        $this->script()->finalAnswer('(work past the kill)');

        $tree = null;
        $this->atEachJoinPoll(function (int $poll) use (&$tree, $members, $conversation) {
            $jobs = fn (string $key) => $this->memberJobsFor($this->delegationFor($conversation, $members[$key]['agent'])->id);
            if ($poll === 1) {
                // a finishes, b's worker dies mid-member, c's job is never taken.
                $this->killTreeAt(fn () => $this->workMember($jobs('b')[0]), 'after_tool_recorded', 1);
                $this->workMember($jobs('a')[0]);
            } elseif ($poll === 2) {
                // Then the parent dies too.
                $tree = $this->snapshotTree();
                throw new InjectedFault('the parent died while waiting');
            }
        });

        $this->rootRun($conversation, 'Do the three parts.', false);
        $this->restoreTree($tree);
        $this->expectInterruptedRun = true;

        $rows = [];
        foreach ($members as $key => $member) {
            $rows[$key] = $this->delegationFor($conversation, $member['agent']);
        }
        $this->assertSame(['completed', 'in_progress', 'queued'], [$rows['a']->status, $rows['b']->status, $rows['c']->status], 'precondition: terminal, stalled, never started');
        $this->assertSame(['B note', 'A note'], $this->storedNotes());
        $this->discardUnconsumedScript();
        $this->script()->finalAnswer('All three parts are done.');
        // The parent's resume runs the members inline from here on.
        Queue::fake([ResumeInterruptedRunJob::class]);
        config(['queue.default' => 'sync']);

        $this->sweepAndResume($conversation);

        $this->assertSame(3, Delegation::where('parent_conversation_id', $conversation->id)->count(), 'no new delegation row');
        foreach ($members as $key => $member) {
            $row = $this->delegationFor($conversation, $member['agent']);
            $this->assertSame($rows[$key]->id, $row->id);
            $this->assertSame('completed', $row->status, "member {$key} completed");
            $this->assertSame(strtoupper($key).' done.', $row->result_summary);
            $this->assertCount(1, $this->runsOfConversation($row->helper_conversation_id), "member {$key} kept its one run");
            $this->assertSame(1, $this->resultCountFor($conversation, $member['call']), "member {$key}'s result reached the parent once");
        }
        $notes = $this->storedNotes();
        sort($notes);
        $this->assertSame(['A note', 'B note', 'C note'], $notes, 'a terminal member was reused, the stalled one resumed, the unstarted one run: each effect once');
        $this->assertSame('completed', $this->latestRun($conversation)->end_state);
    }

    // ---------------------------------------------------------------
    // Each result reaches the parent exactly once
    // ---------------------------------------------------------------

    #[Test]
    public function each_result_once(): void
    {
        $this->scenario = 'resume_delegation_each_result_once';
        $this->bindInjector();
        $conversation = $this->rootConversation('sync', $this->root);
        $members = $this->threeMembers();
        $this->script()->toolRequests($this->batchOf($members));

        // All three members finish inline; the parent dies right after
        // recording the first member's result (the members' own tools are
        // records 1-3, the batch's first call record 4).
        $this->killTreeAt(fn () => $this->rootRun($conversation, 'Do the three parts.'), 'after_tool_recorded', 4);

        $this->assertCount(3, $this->delegationsOf($conversation));
        foreach ($this->delegationsOf($conversation) as $row) {
            $this->assertSame('completed', $row->status, 'precondition: every member finished');
        }
        $notesBefore = $this->storedNotes();
        $this->assertCount(3, $notesBefore);
        $this->discardUnconsumedScript();
        $this->script()->finalAnswer('All three parts are done.');
        $requestsBefore = $this->agentRequests();

        $this->sweepAndResume($conversation);

        foreach ($members as $key => $member) {
            $this->assertSame(1, $this->resultCountFor($conversation, $member['call']), "member {$key}'s result is in the parent's record exactly once");
            $this->assertSame(strtoupper($key).' done.', $this->toolResultFor($conversation, $member['call'])['summary'] ?? null);
        }
        $this->assertSame($notesBefore, $this->storedNotes(), 'no member was run again');
        $this->assertCount(3, $this->delegationsOf($conversation));
        $this->assertSame($requestsBefore + 1, $this->agentRequests(), 'only the parent\'s final answer asked the model');
        $this->assertSame('completed', $this->latestRun($conversation)->end_state);
    }
}
