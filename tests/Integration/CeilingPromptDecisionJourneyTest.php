<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\InteractiveAnswerService;
use ClarionApp\LlmClient\Services\TaskBudgetText;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswer;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerOutcome;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerStatus;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\TaskBudgetJourneys;

/**
 * The user decides what happens at a task's ceiling — on the web and in
 * a chat, by typing or by tapping, and the decision is applied once.
 *
 * Every journey starts from a task paused at its three-step default ceiling
 * with the prompt waiting: a web conversation (the streamed path) decided
 * through the web form or a typed message, or a Telegram conversation (the
 * synchronous path) decided by a typed reply or a tap on one of the
 * prompt's buttons.
 *
 *  - Continue raises the ceiling by the original three steps and the same
 *    run carries on from where it paused: the model's next request sees
 *    every result already stored, and nothing done before is done again.
 *  - Accept closes the task with what has been done: the run completes,
 *    the report is shown once, and no further step is taken. The prompt
 *    takes no decision after that.
 *  - Stop parks the task: nothing runs and nothing is queued, the prompt
 *    keeps the conversation's question slot, and the user is told on the
 *    channel. A later continue, from any entry, still resumes it — once.
 *
 * A typed reply in the chat that is not a decision is answered with the
 * accepted replies, and starts nothing.
 *
 * The chat's acknowledgements are pinned as the messaging integration
 * builds them from the outcome: "Answer received: <the choice>" for a tap,
 * the prompt's own outcome line for a tap that comes after a decision.
 */
class CeilingPromptDecisionJourneyTest extends AssembledSystemTestCase
{
    use TaskBudgetJourneys;

    private const FINAL = 'All notes stored.';

    private TaskBudgetText $text;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTaskBudgetJourney();
        config(['llm-client.agent_loop.max_iterations' => 3]);
        $this->text = new TaskBudgetText();
    }

    protected function tearDown(): void
    {
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function journeys(): array
    {
        $cases = [];
        foreach (['web form', 'web typed', 'telegram typed', 'telegram tap'] as $entry) {
            foreach (['continue', 'accept', 'stop'] as $decision) {
                $cases["{$decision} via {$entry}"] = [$entry, $decision];
            }
        }

        return $cases;
    }

    private function pathOf(string $entry): string
    {
        return str_starts_with($entry, 'web') ? 'stream' : 'sync';
    }

    /** A task paused at its three-step default, its prompt waiting. */
    private function pausedAtTheCeiling(string $path): array
    {
        $conversation = $this->conversationFor($path);
        if ($path === 'sync') {
            $this->scriptSteps(1, 3);
            $this->send('sync', $conversation, 'Store notes until you are done.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        } else {
            $this->send('stream', $conversation, 'Store notes until you are done.')->assertStatus(201);
            $this->playSteps(1, 3);
        }

        $prompt = $this->promptOf($conversation);
        $this->assertNotNull($prompt);
        $this->assertSame(QuestionState::Pending, $prompt->state);
        $this->assertSame(3, $this->modelRequests($path));
        $this->assertCount(3, $this->noteWrites);
        $this->assertSame('Continue with more allowance (+3 steps)', $prompt->choiceList()[0], 'the offered allowance is the original ceiling');

        return [Conversation::findOrFail($conversation->id), $prompt, $this->latestRunIdOf($conversation)];
    }

    /** Decide through $entry. Before a continue on the synchronous path, its next model turn is scripted. */
    private function decide(string $entry, Conversation $conversation, AgentQuestion $prompt, string $decision): TestResponse|InteractiveAnswerOutcome
    {
        $number = ['continue' => 1, 'accept' => 2, 'stop' => 3][$decision];
        if ($decision === 'continue' && $this->pathOf($entry) === 'sync') {
            $this->script()->finalAnswer(self::FINAL);
        }
        $user = $this->fixture()->user;

        return match ($entry) {
            'web form' => $this->actingAs($user, 'api')->postJson("/api/clarion-app/llm-client/question/{$prompt->id}/answer", ['selected' => [$number]]),
            'web typed' => $this->actingAs($user, 'api')->postJson('/api/clarion-app/llm-client/message', ['conversation_id' => $conversation->id, 'content' => $decision]),
            'telegram typed' => $this->actingAs($user, 'api')->postJson('/api/clarion-app/llm-client/agent', ['message' => $decision, 'channel' => 'telegram']),
            'telegram tap' => $this->telegram->tap($prompt->id, (string) $user->id, [$number]),
        };
    }

    /**
     * The chat's acknowledgement of a tap, built from the outcome the way
     * the messaging integration builds it.
     */
    private function telegramAck(InteractiveAnswerOutcome $outcome, string $questionId): string
    {
        return match ($outcome->status) {
            InteractiveAnswerStatus::Answered => 'Answer received: '.$outcome->selectedSummary,
            InteractiveAnswerStatus::NotPending => app(InteractiveAnswerService::class)->describeState($questionId, (string) $this->fixture()->user->id) ?? '(fixed text for '.$outcome->state?->value.')',
            InteractiveAnswerStatus::InvalidAnswer => 'Unknown action',
            default => '(neutral)',
        };
    }

    /** Finish a continued run: on the streamed path its next turn is played here. */
    private function finishContinuation(string $path, int $slot): void
    {
        if ($path === 'stream') {
            $this->playTurn($slot, self::FINAL);
        }
    }

    /** @return list<string> the tool call ids answered in a captured request */
    private function answeredCallIds(CapturedPayload $payload): array
    {
        $messages = json_decode(json_encode($payload->messages), true);

        return array_values(array_filter(array_map(
            fn (array $m) => ($m['role'] ?? null) === 'tool' ? ($m['tool_call_id'] ?? null) : null,
            $messages,
        )));
    }

    private function lastAgentPayload(string $path): CapturedPayload
    {
        $payloads = $path === 'sync' ? $this->transport()->capturedChatPayloads() : $this->stream()->extractDispatchedJobs()->capturedPayloads();

        return $payloads[array_key_last($payloads)];
    }

    private function deliveredOn(string $path, Conversation $conversation): array
    {
        return $path === 'sync' ? $this->telegram->textsFor($conversation->id) : $this->web->textsFor($conversation->id);
    }

    #[Test]
    #[DataProvider('journeys')]
    public function the_decision_is_applied_once(string $entry, string $decision): void
    {
        $path = $this->pathOf($entry);
        $this->scenario = 'ceiling_prompt_decision_'.str_replace(' ', '_', $entry).'_'.$decision;
        $this->entryPath = $path;
        [$conversation, $prompt, $runId] = $this->pausedAtTheCeiling($path);
        $deliveredBefore = count($this->deliveredOn($path, $conversation));

        $result = $this->decide($entry, $conversation, $prompt, $decision);

        match ($decision) {
            'continue' => $this->assertContinued($entry, $path, $conversation, $prompt, $runId, $result, $deliveredBefore),
            'accept' => $this->assertAccepted($entry, $path, $conversation, $prompt, $runId, $result, $deliveredBefore),
            'stop' => $this->assertStoppedThenContinuedOnce($entry, $path, $conversation, $prompt, $runId, $result, $deliveredBefore),
        };
    }

    private function assertContinued(string $entry, string $path, Conversation $conversation, AgentQuestion $prompt, ?string $runId, mixed $result, int $deliveredBefore, int $slot = 3): void
    {
        $this->finishContinuation($path, $slot);

        $this->assertSame(QuestionState::Answered, $prompt->fresh()->state);
        $this->assertSame(4, $this->modelRequests($path), 'a fourth model request was made');
        $this->assertCount(3, $this->noteWrites, 'no earlier tool call was executed again');
        $this->assertSame(1, DB::table('agent_runs')->where('conversation_id', $conversation->id)->count(), 'the same run continued');
        $this->assertSame('completed', DB::table('agent_runs')->where('id', $runId)->value('end_state'));
        $task = $this->taskRowOf($conversation);
        $this->assertSame(6, (int) $task->max_steps, 'raised by the original three steps');
        $this->assertSame(4, (int) $task->steps_used);
        $this->assertSame('completed', $task->state);

        // The continuation's request carries every result stored before the
        // pause, and not the decision itself (it was for this task's budget,
        // not for the agent).
        $payload = $this->lastAgentPayload($path);
        foreach ([1, 2, 3] as $n) {
            $this->assertContains("call_note_{$n}", $this->answeredCallIds($payload));
            $this->assertContains("call_apps_{$n}", $this->answeredCallIds($payload));
        }
        $this->assertFalse($payload->containsText((string) $prompt->question), 'the prompt is not part of the agent\'s history');

        match ($entry) {
            'web form' => $result->assertStatus(201)->assertJson(['status' => 'answered', 'question' => ['state' => 'answered']]),
            'web typed' => $result->assertStatus(201),
            'telegram typed' => $result->assertStatus(200)->assertJson(['status' => 'completed', 'content' => self::FINAL]),
            'telegram tap' => (function () use ($result, $prompt, $conversation, $deliveredBefore) {
                $this->assertSame('Answer received: 1. Continue with more allowance (+3 steps)', $this->telegramAck($result, $prompt->id));
                $this->assertSame([self::FINAL], array_slice($this->telegram->textsFor($conversation->id), $deliveredBefore), 'the reply reaches the chat');
                // A tap on the old prompt now describes how it was decided.
                $again = $this->telegram->tap($prompt->id, (string) $this->fixture()->user->id, [1]);
                $this->assertSame($this->text->outcomeContinued(), $this->telegramAck($again, $prompt->id));
            })(),
        };
    }

    private function assertAccepted(string $entry, string $path, Conversation $conversation, AgentQuestion $prompt, ?string $runId, mixed $result, int $deliveredBefore): void
    {
        $task = $this->taskRowOf($conversation);
        $this->assertSame('concluded', $task->state);
        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertSame('completed', $run->end_state);
        $this->assertSame("Concluded at this task's budget ceiling (accepted)", $run->end_reason);
        $this->assertSame(3, $this->modelRequests($path), 'no further request');

        $report = Message::where('conversation_id', $conversation->id)->get()
            ->first(fn (Message $m) => ($m->tool_data['kind'] ?? null) === AgentQuestionService::KIND_TASK_BUDGET_REPORT);
        $this->assertNotNull($report, 'the report is in the conversation');
        $this->assertStringStartsWith("This task's budget ceiling was reached", $report->content);

        // Shown once: in the response a chat request is waiting on; in the
        // chat for a tap; as the stored message on the web.
        $delivered = array_slice($this->deliveredOn($path, $conversation), $deliveredBefore);
        match ($entry) {
            'web form' => (function () use ($result, $delivered) {
                $result->assertStatus(201)->assertJson(['status' => 'answered', 'question' => ['state' => 'answered']]);
                $this->assertSame([], $delivered);
            })(),
            'web typed' => (function () use ($result, $delivered) {
                $result->assertStatus(201);
                $this->assertSame([], $delivered);
            })(),
            'telegram typed' => (function () use ($result, $delivered, $report) {
                $result->assertStatus(200)->assertJson(['status' => 'completed', 'content' => $report->content]);
                $this->assertSame([], $delivered, 'the chat posts the response; nothing else is sent');
            })(),
            'telegram tap' => (function () use ($result, $delivered, $report, $prompt) {
                $this->assertSame('Answer received: 2. Accept what has been done', $this->telegramAck($result, $prompt->id));
                $this->assertSame([$report->content], $delivered);
                $again = $this->telegram->tap($prompt->id, (string) $this->fixture()->user->id, [1]);
                $this->assertSame($this->text->outcomeAccepted(), $this->telegramAck($again, $prompt->id));
            })(),
        };

        // A later continue is not taken: the prompt is no longer waiting.
        $later = app(InteractiveAnswerService::class)->record($prompt->id, (string) $this->fixture()->user->id, InteractiveAnswer::selection([1]), AnswerMethod::WebForm);
        $this->assertSame(InteractiveAnswerStatus::NotPending, $later->status);
        $this->assertSame('concluded', $this->taskRowOf($conversation)->state);
        $this->assertSame(3, $this->modelRequests($path));
    }

    private function assertStoppedThenContinuedOnce(string $entry, string $path, Conversation $conversation, AgentQuestion $prompt, ?string $runId, mixed $result, int $deliveredBefore): void
    {
        $stopped = $prompt->fresh();
        $this->assertSame(QuestionState::Stopped, $stopped->state);
        $this->assertSame($conversation->id, $stopped->pending_conversation_id, 'the question slot is kept');
        $this->assertSame('paused', $this->taskRowOf($conversation)->state);
        $this->assertSame(3, $this->modelRequests($path), 'nothing runs');
        Queue::assertNotPushed(ResumeAfterQuestionJob::class);
        $this->assertSame('in_progress', DB::table('agent_runs')->where('id', $runId)->value('end_state'), 'the run waits');
        $this->assertSame([$this->text->stoppedByChoice()], array_slice($this->deliveredOn($path, $conversation), $deliveredBefore), 'the user is told, once, on the channel');

        match ($entry) {
            'web form' => $result->assertStatus(201)->assertJson(['status' => 'answered', 'question' => ['state' => 'stopped', 'answerable' => true]]),
            'web typed' => $result->assertStatus(201),
            // Nothing for the chat to post: the notice was delivered already.
            'telegram typed' => $result->assertStatus(202)->assertJson(['status' => 'awaiting_user', 'content' => '', 'question_id' => $prompt->id]),
            'telegram tap' => $this->assertSame('Answer received: Stop', $this->telegramAck($result, $prompt->id)),
        };

        // Later — even much later — a continue from the same entry resumes the task, once.
        $this->travel(2)->days();
        $this->assertSame(0, Artisan::call('llm-client:resolve-expired-questions'));
        $this->assertSame(QuestionState::Stopped, $prompt->fresh()->state);
        $deliveredBefore = count($this->deliveredOn($path, $conversation));

        $later = $this->decide($entry, $conversation, $prompt, 'continue');

        $this->assertContinued($entry, $path, $conversation, $prompt, $runId, $later, $deliveredBefore);
        $this->assertSame(0, Artisan::call('llm-client:resolve-expired-questions'));
        $this->workQueuedResumes();
        $this->assertSame(4, $this->modelRequests($path), 'applied exactly once');
    }

    #[Test]
    public function a_typed_reply_in_the_chat_that_is_not_a_decision_gets_guidance_and_starts_nothing(): void
    {
        $this->scenario = 'ceiling_prompt_decision_telegram_invalid';
        $this->entryPath = 'sync';
        [$conversation, $prompt, $runId] = $this->pausedAtTheCeiling('sync');

        $response = $this->actingAs($this->fixture()->user, 'api')->postJson('/api/clarion-app/llm-client/agent', ['message' => 'maybe later', 'channel' => 'telegram']);

        $this->assertSame(1, DB::table('agent_runs')->count(), 'no run starts');
        $this->assertSame(3, $this->modelRequests('sync'), 'no model request');
        $response->assertStatus(200)->assertJson([
            'status' => 'decision_required',
            'content' => $this->text->decisionGuidance(TaskBudgetText::PROBLEM_UNRECOGNISED, ['reply' => 'maybe later']),
            'conversation_id' => $conversation->id,
        ]);
        $this->assertSame(QuestionState::Pending, $prompt->fresh()->state);
        $this->assertSame('paused', $this->taskRowOf($conversation)->state);
        $this->assertSame(0, Message::where('conversation_id', $conversation->id)->where('content', 'maybe later')->count(), 'the reply is not stored as a request');
    }
}
