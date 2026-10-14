<?php

namespace Tests\Integration\Resume;

use ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
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
 */
class ResumeDelegationJourneyTest extends AssembledSystemTestCase
{
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
}
