<?php

namespace Tests\Integration\Harness;

use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Services\RunResumer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProviderOutage;

/**
 * Shared driving for the provider-failure journeys, on top of the
 * task-budget journeys' conversations, scripted steps and `send()`.
 *
 * The model provider can be made to fail ($this->outage), and the delayed
 * work a failure leaves behind is run the way a queue worker would run it:
 * a stream step job as soon as it is pushed, a resume job at its due time
 * (the fake clock is moved there), each in a fresh container scope. The
 * clock is frozen, so a wait costs no real time.
 *
 * For use in an AssembledSystemTestCase.
 */
trait ProviderRetryJourneys
{
    use TaskBudgetJourneys;

    protected ProviderOutage $outage;

    /** @var array<int, true> jobs already worked, by object id */
    protected array $workedJobs = [];

    protected function setUpRetryJourney(): void
    {
        $this->setUpTaskBudgetJourney();
        $this->armOutage();
    }

    /**
     * Make the provider failable and the retry's delayed jobs observable.
     * (A journey that already set itself up, helpers and managed tasks among
     * them, calls this instead of setUpRetryJourney().)
     */
    protected function armOutage(): void
    {
        config(['llm-client.resume.enabled' => true]);
        Queue::fake([AgentStreamStepJob::class, ResumeAfterQuestionJob::class, ResumeInterruptedRunJob::class, \ClarionApp\LlmClient\Jobs\RunManagedTaskStepJob::class]);

        $this->outage = new ProviderOutage();
        $this->outage->attach($this->transport()->handlerStack());
    }

    /** Prices the fixture's model so that every response with usage [10, 10] costs 0.04. */
    protected function priceTheModel(): void
    {
        ModelPrice::create([
            'provider_type' => 'openai',
            'model' => 'gpt-4',
            'reused_input_rate' => '0.00000000',
            'fresh_input_rate' => '0.00000000',
            'output_rate' => '4000.00000000',
            'effective_from' => Carbon::now()->subDay(),
            'effective_until' => null,
        ]);
    }

    /** Serve the streaming path from the outage's handler: the scripted turns, as SSE chunk lists. */
    protected function streamTurns(array $turns): void
    {
        $this->app->instance('llm-client.http_handler', $this->outage->streamHandler($turns, $this->transport()->handlerStack()));
    }

    /** The instant a queued job is due (now, when it is not delayed). */
    protected function dueOf(object $job): Carbon
    {
        $delay = $job->delay ?? null;
        if ($delay === null) {
            return now();
        }

        return $delay instanceof \DateTimeInterface ? Carbon::instance($delay) : now()->addSeconds((int) $delay);
    }

    /** @return list<object> queued jobs of the two kinds not yet worked, in the order a worker would take them */
    protected function pendingJobs(): array
    {
        $pending = [];
        foreach (Queue::pushed(AgentStreamStepJob::class)->all() as $job) {
            if (!isset($this->workedJobs[spl_object_id($job)])) {
                $pending[] = $job;
            }
        }
        $resumes = [];
        foreach (Queue::pushed(ResumeInterruptedRunJob::class)->all() as $job) {
            if (!isset($this->workedJobs[spl_object_id($job)])) {
                $resumes[] = $job;
            }
        }
        usort($resumes, fn ($a, $b) => $this->dueOf($a)->getTimestamp() <=> $this->dueOf($b)->getTimestamp());

        return array_merge($pending, $resumes);
    }

    /** Work the next job a worker would take. Returns its class, or null when nothing is queued. */
    protected function workNextJob(): ?string
    {
        $pending = $this->pendingJobs();
        if ($pending === []) {
            return null;
        }
        $job = $pending[0];
        $this->workedJobs[spl_object_id($job)] = true;
        $this->app->forgetScopedInstances();

        if ($job instanceof ResumeInterruptedRunJob) {
            $due = $this->dueOf($job);
            if ($due->greaterThan(now())) {
                Carbon::setTestNow($due);
            }
            $job->handle(app(RunResumer::class));

            return ResumeInterruptedRunJob::class;
        }

        $job->handle();

        return AgentStreamStepJob::class;
    }

    /** Work jobs until the run of $conversation ends (or $limit jobs were worked). Returns the number worked. */
    protected function workUntilRunEnds(Conversation $conversation, int $limit = 30): int
    {
        $worked = 0;
        while ($worked < $limit && $this->runRowFor($conversation)->end_state === 'in_progress') {
            if ($this->workNextJob() === null) {
                break;
            }
            $worked++;
        }

        return $worked;
    }

    /** The conversation's first run. */
    protected function runRowFor(Conversation $conversation): object
    {
        $row = DB::table('agent_runs')->where('conversation_id', $conversation->id)->orderBy('started_at')->first();
        $this->assertNotNull($row, 'the conversation has a run');

        return $row;
    }

    /** @return list<array{kind: string, attempt: ?int, payload: array}> */
    protected function resumeEventsOf(string $runId, ?string $kind = null): array
    {
        $query = DB::table('agent_run_resume_events')->where('run_id', $runId)->orderBy('created_at');
        if ($kind !== null) {
            $query->where('kind', $kind);
        }

        return $query->get()->map(fn ($e) => ['kind' => $e->kind, 'attempt' => $e->attempt, 'payload' => json_decode((string) $e->payload, true)])->all();
    }

    /** @return list<Message> the conversation's task_resume_notice messages of one kind */
    protected function retryNotices(Conversation $conversation, string $notice): array
    {
        return Message::where('conversation_id', $conversation->id)->get()
            ->filter(fn (Message $m) => ($m->tool_data['kind'] ?? null) === 'task_resume_notice' && ($m->tool_data['notice'] ?? null) === $notice)
            ->values()
            ->all();
    }

    /** The assistant replies that are neither step messages nor notices. */
    protected function finalReplies(Conversation $conversation): array
    {
        return Message::where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->get()
            ->filter(fn (Message $m) => empty($m->tool_data['tool_calls']) && ($m->tool_data['kind'] ?? null) === null)
            ->values()
            ->all();
    }

    /** Text that gives away a failure to someone who was told nothing went wrong. */
    protected function assertNoFailureWords(string $text, string $context): void
    {
        foreach (['fail', 'error', 'provider', 'unavailable', 'rate limit', 'sk-test'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $text, "{$context}: must not mention \"{$word}\"");
        }
        $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $text, "{$context}: no id");
        $this->assertDoesNotMatchRegularExpression('/(?<!\d)[1-5]\d{2}(?!\d)/', $text, "{$context}: no status code");
    }
}
