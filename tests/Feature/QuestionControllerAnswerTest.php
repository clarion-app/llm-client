<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\RateLimit;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\AnswerParser;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\RateLimitGate;
use ClarionApp\LlmClient\Services\RateLimitService;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\AskUserRequest;
use ClarionApp\LlmClient\ValueObjects\OpenQuestionRequest;
use ClarionApp\LlmClient\ValueObjects\ParsedAnswer;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\RateLimitScope;
use ClarionApp\LlmClient\ValueObjects\UnansweredPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\QuestionChannels\QuestionEndpointHarness;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\TestCase;

/**
 * `POST question/{id}/answer` — the web form's submission.
 *
 * The body names a selection or free text, never both. Shape is checked
 * first; then the question is looked up by id and owner, and a question
 * that does not exist and one that belongs to someone else are answered
 * with the same bare 404. The rate limit and spending ceiling admit the
 * request before anything is recorded, so a refusal leaves the question
 * waiting. An applied answer resumes the paused run the way a typed reply
 * from the conversation screen does: inline for a streamed question, and
 * with the reply pushed to the conversation's own channel for a
 * synchronous one. A question that is no longer pending is reported with
 * its state, and nothing is written, started or resumed.
 *
 * A question that proceeded on its stated assumption at its deadline takes
 * no late answer: the stale form is told, in the question's view, what was
 * assumed. A question whose work stopped at its deadline still takes one,
 * and it resumes the work.
 */
class QuestionControllerAnswerTest extends TestCase
{
    use QuestionEndpointHarness;

    private User $user;

    private User $stranger;

    private RecordingChannelDelivery $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->setUpQuestionEndpoints([$this->user, $this->stranger]);

        Queue::fake();
        Event::fake([NewConversationMessageEvent::class]);

        // `web` keeps the adapter the package registers itself.
        $this->telegram = new RecordingChannelDelivery('telegram');
        app(ConversationChannelRegistry::class)->register($this->telegram);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        foreach (['rate_limits', 'cost_summaries', 'spending_ceilings'] as $table) {
            DB::table($table)->delete();
        }

        $this->tearDownQuestionEndpoints();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    private function endpoint(string $questionId): string
    {
        return "/api/clarion-app/llm-client/question/{$questionId}/answer";
    }

    private function submit(string $questionId, array $body, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->user, 'api')->postJson($this->endpoint($questionId), $body);
    }

    private function pending(array $choices = ['Backend', 'Frontend', 'Docs'], bool $allowMultiple = false, string $resumeMode = OpenQuestionRequest::RESUME_STREAM, string $channel = 'web'): AgentQuestion
    {
        return $this->pendingQuestionIn($this->questionConversation($this->user, $channel), $resumeMode, $choices, $allowMultiple);
    }

    private function resolved(QuestionState $state): AgentQuestion
    {
        $question = $this->pending();
        if ($state === QuestionState::Expired) {
            // A question asked before unanswered policies existed: it expires as it always did.
            DB::table('agent_questions')->where('id', $question->id)->update(['unanswered_policy' => null, 'effective_wait_minutes' => null]);
            $question->refresh();
        }
        $service = app(AgentQuestionService::class);

        $won = match ($state) {
            QuestionState::Answered => $service->answer($question, app(AnswerParser::class)->parse('1', $question), (string) $this->user->id, '1') !== null,
            QuestionState::Expired => $service->expire($question),
            QuestionState::Withdrawn => $service->withdrawForConversation(Conversation::findOrFail($question->conversation_id)),
            QuestionState::Undeliverable => $service->markUndeliverable($question, 'chat not found'),
            QuestionState::Pending => throw new \LogicException('not a resolution'),
        };
        $this->assertTrue($won, "precondition: the question became {$state->value}");

        return $question->fresh();
    }

    /** @return array<string, mixed> */
    private function row(string $questionId): array
    {
        return (array) DB::table('agent_questions')->where('id', $questionId)->first();
    }

    /** @return array{messages: int, runs: int, resumes: int, streams: int} */
    private function footprint(): array
    {
        return [
            'messages' => DB::table('messages')->count(),
            'runs' => DB::table('agent_runs')->count(),
            'resumes' => Queue::pushed(ResumeAfterQuestionJob::class)->count(),
            'streams' => Queue::pushed(SendHttpStreamRequest::class)->count(),
        ];
    }

    private function assertStillPendingAndUntouched(AgentQuestion $question, array $rowBefore, array $footprintBefore): void
    {
        $this->assertSame($rowBefore, $this->row($question->id), 'the question row is untouched');
        $this->assertSame(QuestionState::Pending, $question->fresh()->state, 'the question keeps waiting');
        $this->assertSame($footprintBefore, $this->footprint(), 'no message, run or resume');
        $this->assertSame(0, $this->scriptedChatCalls, 'no model request');
    }

    // ---------------------------------------------------------------
    // 201 — the answer is applied
    // ---------------------------------------------------------------

    #[Test]
    public function a_single_selection_answers_the_question_and_returns_its_view_and_message(): void
    {
        $question = $this->pending();

        $response = $this->submit($question->id, ['selected' => [2]]);

        $response->assertStatus(201);
        $this->assertSame('answered', $response->json('status'));
        $this->assertSame($question->id, $response->json('question.id'));
        $this->assertSame('answered', $response->json('question.state'));
        $this->assertSame([
            'is_selection' => true,
            'selected' => [['number' => 2, 'text' => 'Frontend']],
            'raw_reply' => '2',
            'method' => 'web_form',
        ], $response->json('question.answer'));
        $this->assertSame('2', $response->json('message.content'));
        $this->assertSame('agent_question_answer', $response->json('message.tool_data.kind'));
        $this->assertSame($question->id, $response->json('message.tool_data.question_id'));

        $question->refresh();
        $this->assertSame(QuestionState::Answered, $question->state);
        $this->assertSame('2', $question->answer_raw);
        $this->assertSame([['number' => 2, 'text' => 'Frontend']], $question->answer_selected);
        $this->assertSame(AnswerMethod::WebForm, $question->answer_method);
        $this->assertSame((string) $this->user->id, (string) $question->answered_by);
        $this->assertSame($response->json('message.id'), $question->answer_message_id);
    }

    #[Test]
    public function a_multiple_selection_is_recorded_as_the_canonical_reply(): void
    {
        $question = $this->pending(allowMultiple: true);

        $response = $this->submit($question->id, ['selected' => [3, 1]]);

        $response->assertStatus(201);
        $this->assertSame('1, 3', $response->json('message.content'));
        $this->assertSame([['number' => 1, 'text' => 'Backend'], ['number' => 3, 'text' => 'Docs']], $response->json('question.answer.selected'));

        $question->refresh();
        $this->assertSame('1, 3', $question->answer_raw);
        $this->assertSame([['number' => 1, 'text' => 'Backend'], ['number' => 3, 'text' => 'Docs']], $question->answer_selected);
        $this->assertSame(AnswerMethod::WebForm, $question->answer_method);
    }

    #[Test]
    public function free_text_is_recorded_verbatim(): void
    {
        $question = $this->pending();
        $text = 'none of these — use a fourth approach';

        $response = $this->submit($question->id, ['text' => $text]);

        $response->assertStatus(201);
        $this->assertSame([
            'is_selection' => false,
            'selected' => [],
            'raw_reply' => $text,
            'method' => 'web_form',
        ], $response->json('question.answer'));
        $this->assertSame($text, $response->json('message.content'));
        $this->assertSame($text, $question->fresh()->answer_raw);
    }

    #[Test]
    public function free_text_answers_a_question_without_choices(): void
    {
        $question = $this->pending(choices: []);

        $this->submit($question->id, ['text' => 'Tuesday at ten'])->assertStatus(201);

        $this->assertSame(QuestionState::Answered, $question->fresh()->state);
        $this->assertSame('Tuesday at ten', $question->fresh()->answer_raw);
    }

    // ---------------------------------------------------------------
    // 422 — answer_required
    // ---------------------------------------------------------------

    public static function emptyAnswers(): array
    {
        return [
            'empty selection' => [['selected' => []]],
            'empty text' => [['text' => '']],
            'whitespace text' => [['text' => "   \n "]],
        ];
    }

    #[Test]
    #[DataProvider('emptyAnswers')]
    public function an_empty_answer_is_refused_with_answer_required_and_the_question_keeps_waiting(array $body): void
    {
        $question = $this->pending();
        $rowBefore = $this->row($question->id);
        $footprintBefore = $this->footprint();

        $response = $this->submit($question->id, $body);

        $response->assertStatus(422);
        $this->assertSame([
            'code' => 'answer_required',
            'message' => 'Choose an option or type an answer.',
        ], $response->json());
        $this->assertStillPendingAndUntouched($question, $rowBefore, $footprintBefore);
    }

    // ---------------------------------------------------------------
    // 422 — invalid_answer
    // ---------------------------------------------------------------

    public static function malformedBodies(): array
    {
        return [
            'both keys' => [['selected' => [2], 'text' => 'Frontend']],
            'neither key' => [[]],
            'selected is not a list' => [['selected' => 2]],
            'non-integer entry' => [['selected' => ['two']]],
            'fractional entry' => [['selected' => [1.5]]],
            'null entry' => [['selected' => [null]]],
            'text is not a string' => [['text' => 5]],
            // The answer is stored verbatim in a TEXT column (65,535 bytes on
            // MySQL); a longer one is refused here rather than failing the
            // write. 32,768 two-byte characters are 65,536 bytes.
            'text longer than an answer can hold' => [['text' => str_repeat('é', 32768)]],
        ];
    }

    #[Test]
    #[DataProvider('malformedBodies')]
    public function a_malformed_body_is_refused_with_invalid_answer(array $body): void
    {
        $question = $this->pending();
        $rowBefore = $this->row($question->id);
        $footprintBefore = $this->footprint();

        $response = $this->submit($question->id, $body);

        $response->assertStatus(422);
        $this->assertSame('invalid_answer', $response->json('code'));
        $this->assertIsString($response->json('message'));
        $this->assertNotSame('', $response->json('message'));
        $this->assertStillPendingAndUntouched($question, $rowBefore, $footprintBefore);
    }

    public static function invalidSelections(): array
    {
        return [
            'out of range high' => [['Backend', 'Frontend', 'Docs'], false, [4]],
            'out of range zero' => [['Backend', 'Frontend', 'Docs'], false, [0]],
            'duplicate numbers' => [['Backend', 'Frontend', 'Docs'], true, [2, 2]],
            'two numbers on single-select' => [['Backend', 'Frontend', 'Docs'], false, [1, 3]],
            'selection on a question without choices' => [[], false, [1]],
        ];
    }

    #[Test]
    #[DataProvider('invalidSelections')]
    public function a_selection_the_question_cannot_take_is_refused_with_invalid_answer(array $choices, bool $allowMultiple, array $selected): void
    {
        $question = $this->pending($choices, $allowMultiple);
        $rowBefore = $this->row($question->id);
        $footprintBefore = $this->footprint();

        $response = $this->submit($question->id, ['selected' => $selected]);

        $response->assertStatus(422);
        $this->assertSame('invalid_answer', $response->json('code'));
        $this->assertIsString($response->json('message'));
        $this->assertStillPendingAndUntouched($question, $rowBefore, $footprintBefore);
    }

    // ---------------------------------------------------------------
    // 409 — no longer pending
    // ---------------------------------------------------------------

    public static function terminalStates(): array
    {
        return [
            'answered' => [QuestionState::Answered],
            'expired' => [QuestionState::Expired],
            'withdrawn' => [QuestionState::Withdrawn],
            'undeliverable' => [QuestionState::Undeliverable],
        ];
    }

    #[Test]
    #[DataProvider('terminalStates')]
    public function a_question_no_longer_pending_is_reported_with_its_state_and_nothing_happens(QuestionState $state): void
    {
        $question = $this->resolved($state);
        $rowBefore = $this->row($question->id);
        $footprintBefore = $this->footprint();
        $chatCallsBefore = $this->scriptedChatCalls;

        $response = $this->submit($question->id, ['selected' => [2]]);

        $response->assertStatus(409);
        $this->assertSame('question_not_pending', $response->json('code'));
        $this->assertSame($state->value, $response->json('state'));
        $this->assertSame($question->id, $response->json('question.id'));
        $this->assertSame($state->value, $response->json('question.state'));

        $this->assertSame($rowBefore, $this->row($question->id), 'the question row is untouched');
        $this->assertSame($footprintBefore, $this->footprint(), 'no new message, no run, no resume');
        $this->assertSame($chatCallsBefore, $this->scriptedChatCalls, 'no model request');
    }

    // ---------------------------------------------------------------
    // 404 — absent and foreign alike
    // ---------------------------------------------------------------

    #[Test]
    public function absent_and_foreign_are_indistinguishable(): void
    {
        $question = $this->pending();
        $rowBefore = $this->row($question->id);
        $footprintBefore = $this->footprint();

        $absent = $this->submit((string) Str::uuid(), ['selected' => [2]], $this->stranger);
        $foreign = $this->submit($question->id, ['selected' => [2]], $this->stranger);

        $absent->assertStatus(404);
        $foreign->assertStatus(404);
        $this->assertSame([], $foreign->json());
        $this->assertSame($absent->getContent(), $foreign->getContent(), 'byte-identical bodies');

        $headers = function (TestResponse $response): array {
            $all = $response->headers->all();
            unset($all['date']);
            ksort($all);

            return $all;
        };
        $this->assertSame($headers($absent), $headers($foreign), 'identical headers');

        $this->assertStillPendingAndUntouched($question, $rowBefore, $footprintBefore);
    }

    #[Test]
    public function a_foreign_question_is_not_found_even_for_a_requester_who_would_be_refused_admission(): void
    {
        // The owner check comes before admission: were it after, someone
        // out of allowance would be told 429 for an existing question but
        // 404 for an absent one, revealing which ids exist.
        $question = $this->pending();

        app(RateLimitService::class)->upsert(
            RateLimitScope::UserDefault,
            RateLimit::INSTALLATION_SCOPE_ID,
            ['max_requests' => 1, 'window_seconds' => 60],
        );
        app(RateLimitGate::class)->admit((string) $this->stranger->id, BudgetWorkKind::Interactive, (string) Str::uuid());
        $this->app->forgetScopedInstances();

        $rowBefore = $this->row($question->id);
        $footprintBefore = $this->footprint();

        $absent = $this->submit((string) Str::uuid(), ['selected' => [2]], $this->stranger);
        $foreign = $this->submit($question->id, ['selected' => [2]], $this->stranger);

        $absent->assertStatus(404);
        $foreign->assertStatus(404);
        $this->assertSame($absent->getContent(), $foreign->getContent(), 'byte-identical bodies');
        $this->assertStillPendingAndUntouched($question, $rowBefore, $footprintBefore);
    }

    #[Test]
    public function an_unauthenticated_request_is_refused(): void
    {
        $question = $this->pending();
        $rowBefore = $this->row($question->id);
        $footprintBefore = $this->footprint();

        $this->postJson($this->endpoint($question->id), ['selected' => [2]])->assertStatus(401);

        $this->assertStillPendingAndUntouched($question, $rowBefore, $footprintBefore);
    }

    // ---------------------------------------------------------------
    // Admission runs before recording
    // ---------------------------------------------------------------

    #[Test]
    public function a_rate_limit_refusal_leaves_the_question_pending(): void
    {
        $question = $this->pending();

        app(RateLimitService::class)->upsert(
            RateLimitScope::UserDefault,
            RateLimit::INSTALLATION_SCOPE_ID,
            ['max_requests' => 1, 'window_seconds' => 60],
        );
        // The allowance is spent by an earlier request in the window.
        app(RateLimitGate::class)->admit((string) $this->user->id, BudgetWorkKind::Interactive, $question->conversation_id);
        $this->app->forgetScopedInstances();

        $rowBefore = $this->row($question->id);
        $footprintBefore = $this->footprint();

        $this->submit($question->id, ['selected' => [2]])->assertStatus(429);

        $this->assertStillPendingAndUntouched($question, $rowBefore, $footprintBefore);
    }

    #[Test]
    public function a_spending_ceiling_refusal_leaves_the_question_pending(): void
    {
        Carbon::setTestNow(Carbon::now()->startOfMinute());
        $question = $this->pending();

        app(SpendingCeilingService::class)->upsert(
            BudgetScope::UserDefault,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            ['amount' => '25.00', 'period_type' => 'month', 'enforcement_mode' => 'stop'],
        );
        DB::table('cost_summaries')->insert([
            'id' => (string) Str::uuid(),
            'entity_type' => CostSummary::ENTITY_USER,
            'entity_id' => $this->user->id,
            'user_id' => $this->user->id,
            'period_date' => Carbon::now()->toDateString(),
            'request_count' => 1,
            'priced_cost_total' => '30.0000000000',
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => Carbon::now(),
        ]);

        $rowBefore = $this->row($question->id);
        $footprintBefore = $this->footprint();

        $runsBefore = DB::table('agent_runs')->pluck('id')->all();

        $this->submit($question->id, ['selected' => [2]])->assertStatus(402);

        // A spending refusal is recorded as its own refused run (the
        // operator's record of the stop, as on every entry path); nothing
        // else may change.
        $newRuns = DB::table('agent_runs')->whereNotIn('id', $runsBefore)->get();
        foreach ($newRuns as $run) {
            $this->assertSame('stopped_early', $run->end_state, 'the only new run is the refusal record');
        }
        $footprintBefore['runs'] += $newRuns->count();
        $this->assertStillPendingAndUntouched($question, $rowBefore, $footprintBefore);
    }

    // ---------------------------------------------------------------
    // Resuming the paused run
    // ---------------------------------------------------------------

    #[Test]
    public function a_streamed_question_resumes_inline_over_the_conversations_broadcasts(): void
    {
        $question = $this->pending();
        Queue::assertNotPushed(SendHttpStreamRequest::class);

        $this->submit($question->id, ['selected' => [2]])->assertStatus(201);

        $question->refresh();
        $this->assertNotNull($question->resumed_at, 'the paused turn was consumed by this request');
        Queue::assertPushed(SendHttpStreamRequest::class, 1);
        Queue::assertNotPushed(ResumeAfterQuestionJob::class);

        $results = Message::findOrFail($question->paused_message_id)->tool_data['tool_results'] ?? null;
        $this->assertIsArray($results);
        $this->assertSame('call_ask', $results[0]['tool_call_id'] ?? null);
        $outcome = json_decode((string) $results[0]['content'], true);
        $this->assertSame('answered', $outcome['outcome'] ?? null);
        $this->assertSame([['number' => 2, 'text' => 'Frontend']], $outcome['answer']['selected'] ?? null);
    }

    #[Test]
    public function a_synchronous_question_answered_here_pushes_the_reply_to_its_channel(): void
    {
        $question = $this->pending(resumeMode: OpenQuestionRequest::RESUME_SYNC, channel: 'telegram');
        $this->telegram->deliveries = [];
        $this->scriptedReplies = [$this->scriptedTextReply('Backend first, then.')];

        $this->submit($question->id, ['selected' => [1]])->assertStatus(201);

        $question->refresh();
        $this->assertSame(QuestionState::Answered, $question->state);
        $this->assertNotNull($question->resumed_at);
        $this->assertSame(1, $this->scriptedChatCalls, 'the paused run continued');
        $this->assertContains(
            'Backend first, then.',
            $this->telegram->textsFor($question->conversation_id),
            'the continuation\'s reply reaches the chat the conversation lives on',
        );
        Queue::assertNotPushed(SendHttpStreamRequest::class);
    }

    // ---------------------------------------------------------------
    // After the deadline
    // ---------------------------------------------------------------

    #[Test]
    public function a_stale_form_on_a_question_that_proceeded_on_its_assumption_is_told_what_was_assumed_and_nothing_happens(): void
    {
        $conversation = $this->questionConversation($this->user, 'web');
        $question = AgentQuestion::findOrFail($this->openQuestion($conversation, request: new AskUserRequest(
            'Which part should I change first?',
            ['Backend', 'Frontend', 'Docs'],
            false,
            policy: UnansweredPolicy::ProceedOnAssumption,
            assumedAnswer: new ParsedAnswer('1', true, [['number' => 1, 'text' => 'Backend']]),
            requestedWaitMinutes: 60,
        ))->question->id);
        $this->assertSame(QuestionState::ExpiredAssumed, app(AgentQuestionService::class)->expireWithPolicy($question), 'precondition: proceeded on its assumption');
        $rowBefore = $this->row($question->id);
        $footprintBefore = $this->footprint();
        $chatCallsBefore = $this->scriptedChatCalls;
        $deliveriesBefore = $this->telegram->deliveries;

        $response = $this->submit($question->id, ['selected' => [2]]);

        $response->assertStatus(409);
        $this->assertSame('question_not_pending', $response->json('code'));
        $this->assertSame('expired_assumed', $response->json('state'));
        $this->assertSame($question->id, $response->json('question.id'));
        $this->assertSame('expired_assumed', $response->json('question.state'));
        $this->assertFalse($response->json('question.answerable'));
        $this->assertSame(
            'Expired — no answer arrived in time; the agent continued assuming: 1. Backend',
            $response->json('question.outcome_text'),
            'the stale form is told what was assumed',
        );

        $this->assertSame($rowBefore, $this->row($question->id), 'the question row and its assumption are untouched');
        $this->assertSame($footprintBefore, $this->footprint(), 'no message, no run, no resume');
        $this->assertSame($chatCallsBefore, $this->scriptedChatCalls, 'no model request');
        $this->assertSame($deliveriesBefore, $this->telegram->deliveries, 'nothing is delivered');
    }

    #[Test]
    public function a_form_answer_to_a_question_whose_work_stopped_answers_it_late_and_resumes_the_work(): void
    {
        $question = $this->pending();
        DB::table('agent_questions')->where('id', $question->id)->update([
            'state' => QuestionState::Stopped->value,
            'stopped_at' => now()->subMinutes(5)->format('Y-m-d H:i:s'),
        ]);
        $stoppedAt = $this->row($question->id)['stopped_at'];
        Queue::assertNotPushed(SendHttpStreamRequest::class);

        $response = $this->submit($question->id, ['selected' => [2]]);

        $response->assertStatus(201);
        $this->assertSame('answered', $response->json('status'));
        $this->assertSame('answered', $response->json('question.state'));
        $this->assertTrue($response->json('question.answered_late'));
        $this->assertSame('2', $response->json('message.content'));

        $question->refresh();
        $this->assertSame(QuestionState::Answered, $question->state);
        $this->assertSame($stoppedAt, $this->row($question->id)['stopped_at'], 'when work stopped is kept');
        $this->assertNotNull($question->resumed_at, 'the stopped work resumed in this request');
        Queue::assertPushed(SendHttpStreamRequest::class, 1);
        Queue::assertNotPushed(ResumeAfterQuestionJob::class);

        $results = Message::findOrFail($question->paused_message_id)->tool_data['tool_results'] ?? null;
        $this->assertIsArray($results);
        $outcome = json_decode((string) $results[0]['content'], true);
        $this->assertSame('answered_late', $outcome['outcome'] ?? null);
        $this->assertSame([['number' => 2, 'text' => 'Frontend']], $outcome['answer']['selected'] ?? null);
    }
}
