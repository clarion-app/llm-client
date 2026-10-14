<?php

namespace Tests\Feature\Resume;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Services\ResumeEventLog;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsRunProgress;
use Tests\TestCase;

/**
 * GET agent-runs/{id}/resume-events: the run's
 * resume and retry events, oldest first so the newest is last, fifty to a
 * page, each in the documented shape, never carrying an argument or provider
 * text, and answering an absent run and someone else's run with the same 404.
 */
class RunResumeEventsEndpointTest extends TestCase
{
    use SeedsRunProgress;

    private User $owner;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:41.120000', 'UTC'));
        config(['llm-client.run_trace.enabled' => true]);
        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
    }

    protected function tearDown(): void
    {
        $this->clearProgressTables();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function eventsOf(User $as, string $runId, string $query = '')
    {
        return $this->actingAs($as, 'api')->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/resume-events{$query}");
    }

    #[Test]
    public function an_event_comes_back_in_the_documented_shape(): void
    {
        $runId = $this->seedRun((string) $this->owner->id);
        $id = $this->seedEvent($runId, 'resume_started', ['from_step' => 3, 'iteration' => 3, 'mode' => 'sync', 'reused' => [['tool_call_id' => 'call_a', 'tool' => 'execute_operation', 'label' => 'Store a note']]], 3, 1, '2026-10-12 10:12:00');

        $response = $this->eventsOf($this->owner, $runId)->assertStatus(200);

        $this->assertSame(['data', 'meta'], array_keys($response->json()));
        $this->assertCount(1, $response->json('data'));
        $event = $response->json('data.0');
        $this->assertSame(['id', 'kind', 'step_position', 'attempt', 'payload', 'created_at'], array_keys($event));
        $this->assertSame($id, $event['id']);
        $this->assertSame('resume_started', $event['kind']);
        $this->assertSame(3, $event['step_position']);
        $this->assertSame(1, $event['attempt']);
        $this->assertSame('2026-10-12T10:12:00.000000Z', $event['created_at']);
        $this->assertSame('Store a note', $event['payload']['reused'][0]['label']);
    }

    #[Test]
    public function events_are_fifty_to_a_page_oldest_first_so_the_newest_is_last(): void
    {
        $runId = $this->seedRun((string) $this->owner->id);
        // Sixty events, one a minute, written out of order so that the
        // listing's own ordering is what puts them right.
        $order = range(1, 60);
        shuffle($order);
        foreach ($order as $n) {
            $this->seedEvent($runId, 'provider_retry_scheduled', ['reason' => 'timeout', 'status' => null, 'next_attempt_at' => null, 'deferred' => true], null, $n, Carbon::parse('2026-10-12 09:00:00', 'UTC')->addMinutes($n)->toDateTimeString());
        }

        $first = $this->eventsOf($this->owner, $runId)->assertStatus(200);
        $second = $this->eventsOf($this->owner, $runId, '?page=2')->assertStatus(200);

        $this->assertCount(50, $first->json('data'));
        $this->assertCount(10, $second->json('data'));
        $this->assertSame(50, $first->json('meta.per_page'));
        $this->assertSame(60, $first->json('meta.total'));
        $this->assertSame(1, $first->json('meta.current_page'));
        $this->assertSame(2, $second->json('meta.current_page'));
        $this->assertSame(range(1, 50), array_column($first->json('data'), 'attempt'), 'the first page runs oldest to newest');
        $this->assertSame(range(51, 60), array_column($second->json('data'), 'attempt'), 'the newest event is the last of the last page');
    }

    #[Test]
    public function a_run_with_no_events_lists_none(): void
    {
        $runId = $this->seedRun((string) $this->owner->id);

        $response = $this->eventsOf($this->owner, $runId)->assertStatus(200);

        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, $response->json('meta.total'));
    }

    #[Test]
    public function only_the_runs_own_events_are_listed(): void
    {
        $runId = $this->seedRun((string) $this->owner->id);
        $other = $this->seedRun((string) $this->owner->id);
        $this->seedEvent($runId, 'takeover', [], 2, 1);
        $this->seedEvent($other, 'takeover', [], 9, 1);

        $data = $this->eventsOf($this->owner, $runId)->assertStatus(200)->json('data');

        $this->assertCount(1, $data);
        $this->assertSame(2, $data[0]['step_position']);
    }

    #[Test]
    public function a_payload_carries_no_arguments_and_no_provider_text(): void
    {
        $runId = $this->seedRun((string) $this->owner->id);
        // Written through the event log, the only writer, with the keys it
        // is handed at its worst.
        app(ResumeEventLog::class)->record($runId, 'provider_retry_scheduled', null, 2, [
            'reason' => 'rate_limited',
            'status' => 429,
            'next_attempt_at' => '2026-10-12T10:40:00Z',
            'deferred' => true,
            'provider_error' => 'Client error: POST https://api.example.test/bot123:SECRET/chat resulted in 429',
        ]);
        app(ResumeEventLog::class)->record($runId, 'resume_started', 3, 1, [
            'from_step' => 3,
            'iteration' => 3,
            'mode' => 'sync',
            'reused' => [],
            'rerun' => [['tool_call_id' => 'call_b', 'tool' => 'execute_operation', 'label' => 'List notes', 'arguments' => '{"password":"hunter2"}']],
            'outcome_unknown' => [],
            'model_call_repeated' => false,
        ]);

        $body = (string) $this->eventsOf($this->owner, $runId)->assertStatus(200)->getContent();

        $this->assertStringNotContainsString('hunter2', $body, 'no tool-call argument');
        $this->assertStringNotContainsString('SECRET', $body, 'no provider error text');
        $this->assertStringNotContainsString('Client error', $body);
        $this->assertStringContainsString('List notes', $body, 'the label stays');
    }

    #[Test]
    public function an_absent_run_and_another_users_run_answer_the_same_404(): void
    {
        $foreign = $this->seedRun((string) $this->stranger->id);
        $this->seedEvent($foreign, 'takeover');
        $absent = '99999999-9999-9999-9999-999999999999';

        $foreignResponse = $this->eventsOf($this->owner, $foreign);
        $absentResponse = $this->eventsOf($this->owner, $absent);

        $foreignResponse->assertStatus(404);
        $absentResponse->assertStatus(404);
        $this->assertSame($absentResponse->getContent(), $foreignResponse->getContent());
        $this->assertSame(
            $this->actingAs($this->owner, 'api')->getJson("/api/clarion-app/llm-client/agent-runs/{$absent}")->getContent(),
            $absentResponse->getContent(),
            'the same not-found body every run endpoint gives',
        );
    }

    #[Test]
    public function an_unauthenticated_caller_is_refused(): void
    {
        $runId = $this->seedRun((string) $this->owner->id);

        $this->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/resume-events")->assertStatus(401);
    }
}
