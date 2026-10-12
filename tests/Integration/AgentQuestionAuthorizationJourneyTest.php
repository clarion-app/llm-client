<?php

namespace Tests\Integration;

use ClarionApp\Backend\Models\User;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Contracts\InteractiveQuestionAnswers;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\InteractiveAnswerService;
use ClarionApp\LlmClient\Services\QuestionAnswerIntake;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\IntakeResult;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswer;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerStatus;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\RecordingChannelDelivery;

/**
 * Only the user who was asked can answer a question or see it in the run
 * record.
 *
 * User A's agent asks a question in A's Telegram conversation. User B — an
 * authenticated, unrelated user who has somehow learned every id involved
 * (A's conversation, run, step, `ask_user` action and question) — tries to
 * answer it through both reply endpoints and to read it through the run
 * endpoints. Every attempt is refused with the shape those endpoints already
 * use for a stranger, and A's question stays pending, unanswered and
 * undelivered-to-anyone-else. The one endpoint that takes a question id —
 * answering it from the conversation screen's form — checks the owner and
 * answers B exactly as it answers an id that does not exist, so the question
 * id alone grants nothing — nor does any other id B holds put in its place.
 * The interactive-answer contract the messaging channel calls tells B
 * nothing either: no state, and a refusal identical to an absent question's.
 * An administrator allowed to read A's conversation reads its messages but
 * is never shown the form. B's own messages, blank or ordinary, never touch
 * A's question.
 *
 * Real wiring throughout, with the model scripted at the HTTP boundary.
 */
class AgentQuestionAuthorizationJourneyTest extends AssembledSystemTestCase
{
    private const API = '/api/clarion-app/llm-client';

    private const UNIFORM_RUN_404 = ['error' => 'Run not found', 'code' => 'run_not_found'];

    private RecordingChannelDelivery $telegram;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entryPath = 'sync';
        config(['llm-client.run_trace.enabled' => true]);

        $this->telegram = new RecordingChannelDelivery('telegram');
        $this->app->make(ConversationChannelRegistry::class)->register($this->telegram);

        $this->userB = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Someone Else',
            'email' => 'someone-else@example.com',
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);
    }

    /** User A's agent asks the three-choice question in A's Telegram conversation. */
    private function userAIsAsked(): AgentQuestion
    {
        $fixture = $this->fixture()->build();
        $fixture->conversation->update(['channel' => 'telegram']);
        $conversation = $fixture->conversation->fresh();

        $this->actingAs($fixture->user, 'api')->postJson(self::API.'/agent', [
            'message' => 'Help me tidy up the project.',
            'channel' => 'telegram',
            'conversation_id' => $conversation->id,
        ])->assertStatus(202)->assertJson(['status' => 'awaiting_user']);

        $question = AgentQuestion::where('conversation_id', $conversation->id)->firstOrFail();
        $this->assertSame(QuestionState::Pending, $question->state, 'precondition: A\'s question waits on A');
        $this->assertNotNull($question->run_id, 'precondition: the question belongs to a recorded run');
        $this->assertNotNull($question->step_id);
        $this->assertNotNull($question->action_id);

        return $question;
    }

    /** A's question is exactly as it was asked: pending, unanswered, holding its slot. */
    private function assertStillPending(AgentQuestion $question, string $context): void
    {
        $fresh = AgentQuestion::findOrFail($question->id);

        $this->assertSame(QuestionState::Pending, $fresh->state, "{$context}: A's question is still pending");
        $this->assertSame($question->conversation_id, $fresh->pending_conversation_id, "{$context}: A's question still holds its slot");
        $this->assertNull($fresh->resolved_at, "{$context}: not resolved");
        $this->assertNull($fresh->answer_raw, "{$context}: not answered");
        $this->assertNull($fresh->answered_by, "{$context}: nobody answered");
        $this->assertNull($fresh->resumed_at, "{$context}: the run was not resumed");
        $this->assertSame('in_progress', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'), "{$context}: A's run still waits");
        $this->assertSame('awaiting_confirmation', DB::table('agent_run_actions')->where('id', $question->action_id)->value('outcome'), "{$context}: the ask still waits");
    }

    /** A's conversation holds no message from B. */
    private function assertNothingStoredFor(AgentQuestion $question, int $messagesBefore): void
    {
        $this->assertSame(
            $messagesBefore,
            Message::where('conversation_id', $question->conversation_id)->count(),
            'nothing B sent was stored in A\'s conversation',
        );
    }

    #[Test]
    public function another_user_cannot_answer_the_question_through_either_reply_endpoint(): void
    {
        $this->scenario = 'question_authorization_answer';
        $this->script()
            ->toolRequest('ask_user', ['question' => AgentQuestionWebJourneyTest::QUESTION, 'choices' => AgentQuestionWebJourneyTest::CHOICES]);

        $question = $this->userAIsAsked();
        $messagesBefore = Message::where('conversation_id', $question->conversation_id)->count();
        $modelRequestsBefore = count($this->transport()->capturedChatPayloads());
        $asB = $this->actingAs($this->userB, 'api');

        // The web reply endpoint, A's conversation id: the existing 403.
        $asB->postJson(self::API.'/message', ['conversation_id' => $question->conversation_id, 'content' => '2'])
            ->assertStatus(403);
        $this->assertStillPending($question, 'B replied through POST message');

        // A blank reply is never answer_required: it is the ordinary blank-
        // message validation error, exactly what a conversation with no
        // pending question returns, so it reveals nothing about A's question.
        $asB->postJson(self::API.'/message', ['conversation_id' => $question->conversation_id, 'content' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('content')
            ->assertJsonMissing(['code' => 'answer_required']);
        $this->assertStillPending($question, 'B sent a blank reply through POST message');

        // The agent endpoint, A's conversation id: the existing 403 shape.
        $asB->postJson(self::API.'/agent', ['message' => '2', 'channel' => 'telegram', 'conversation_id' => $question->conversation_id])
            ->assertStatus(403)
            ->assertExactJson(['error' => 'Forbidden', 'code' => 'forbidden']);
        $this->assertStillPending($question, 'B replied through POST agent');

        $asB->postJson(self::API.'/agent', ['message' => '', 'channel' => 'telegram', 'conversation_id' => $question->conversation_id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message')
            ->assertJsonMissing(['code' => 'answer_required']);
        $this->assertStillPending($question, 'B sent a blank reply through POST agent');

        // The question id in place of a conversation id is not a conversation.
        $asB->postJson(self::API.'/message', ['conversation_id' => $question->id, 'content' => '2'])->assertStatus(422);
        $asB->postJson(self::API.'/agent', ['message' => '2', 'channel' => 'telegram', 'conversation_id' => $question->id])->assertStatus(422);
        $this->assertStillPending($question, 'B passed the question id as a conversation id');

        // Even handed A's conversation directly, intake never takes B's reply as A's answer.
        $intake = $this->app->make(QuestionAnswerIntake::class)
            ->accept(Conversation::findOrFail($question->conversation_id), (string) $this->userB->id, '2');
        $this->assertSame(IntakeResult::NOT_AN_ANSWER, $intake->kind, 'intake refuses a reply from anyone but the user who was asked');
        $this->assertStillPending($question, 'B\'s reply reached intake');

        $this->assertNothingStoredFor($question, $messagesBefore);
        $this->assertSame($modelRequestsBefore, count($this->transport()->capturedChatPayloads()), 'A\'s agent was never resumed');
        $this->assertCount(1, $this->telegram->deliveries, 'only A\'s question was ever delivered');

        // A can still answer it.
        $this->assertNotNull(AgentQuestion::where('pending_conversation_id', $question->conversation_id)->first());
    }

    #[Test]
    public function another_user_cannot_see_the_question_in_the_run_record(): void
    {
        $this->scenario = 'question_authorization_run_record';
        $this->script()
            ->toolRequest('ask_user', ['question' => AgentQuestionWebJourneyTest::QUESTION, 'choices' => AgentQuestionWebJourneyTest::CHOICES]);

        $question = $this->userAIsAsked();
        $runId = $question->run_id;
        $asB = $this->actingAs($this->userB, 'api');

        $asB->getJson(self::API."/agent-runs/{$runId}")->assertStatus(404)->assertExactJson(self::UNIFORM_RUN_404);
        $asB->getJson(self::API."/agent-runs/{$runId}/steps")->assertStatus(404)->assertExactJson(self::UNIFORM_RUN_404);
        $asB->getJson(self::API."/agent-runs/{$runId}/steps/{$question->step_id}/actions")->assertStatus(404)->assertExactJson(self::UNIFORM_RUN_404);
        $asB->getJson(self::API."/agent-runs/{$runId}/actions/{$question->action_id}/children")->assertStatus(404)->assertExactJson(self::UNIFORM_RUN_404);
        $asB->getJson(self::API."/agent-runs/{$runId}/actions/{$question->action_id}")->assertStatus(404)->assertExactJson(self::UNIFORM_RUN_404);

        // B's own run list never includes A's run.
        $listed = $asB->getJson(self::API.'/agent-runs')->assertStatus(200)->json('data');
        $this->assertNotContains($runId, array_column($listed, 'id'), 'A\'s waiting run is not in B\'s list');

        // Nor can B read the question through A's conversation.
        $asB->getJson(self::API."/conversation/{$question->conversation_id}/message")->assertStatus(403);

        // The owner sees all of it — the refusals above are about who asks, not what exists.
        $asA = $this->actingAs($this->fixture()->user, 'api');
        $asA->getJson(self::API."/agent-runs/{$runId}")->assertStatus(200);
        $asA->getJson(self::API."/agent-runs/{$runId}/actions/{$question->action_id}")->assertStatus(200)->assertJson(['target' => 'ask_user']);

        $this->assertStillPending($question, 'B read the run record');
    }

    #[Test]
    public function the_only_endpoint_taking_a_question_id_is_the_owner_checked_answer_route(): void
    {
        $this->scenario = 'question_authorization_routes';
        $this->script()
            ->toolRequest('ask_user', ['question' => AgentQuestionWebJourneyTest::QUESTION, 'choices' => AgentQuestionWebJourneyTest::CHOICES]);

        $llmClientRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/clarion-app/llm-client/'));
        $this->assertNotEmpty($llmClientRoutes->all(), 'precondition: the package routes are registered');

        $offending = $llmClientRoutes
            ->filter(function (RoutingRoute $route) {
                foreach ($route->parameterNames() as $name) {
                    if (stripos($name, 'question') !== false) {
                        return true;
                    }
                }

                return stripos($route->uri(), 'question') !== false;
            })
            ->map(fn (RoutingRoute $route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame(
            ['POST api/clarion-app/llm-client/question/{id}/answer'],
            $offending,
            'apart from the form\'s answer route, a question is only ever reached through its conversation or run, both owner-checked',
        );

        // That route refuses B exactly as it refuses an id that does not
        // exist, and A's question is untouched.
        $question = $this->userAIsAsked();
        $asB = $this->actingAs($this->userB, 'api');

        $foreign = $asB->postJson(self::API."/question/{$question->id}/answer", ['selected' => [2]]);
        $absent = $asB->postJson(self::API.'/question/'.Str::uuid().'/answer', ['selected' => [2]]);

        $foreign->assertStatus(404);
        $absent->assertStatus(404);
        $this->assertSame('{}', $foreign->getContent());
        $this->assertSame($absent->getContent(), $foreign->getContent());

        $this->assertStillPending($question, 'B answered through the form route');
    }

    #[Test]
    public function another_users_own_messages_never_touch_the_question(): void
    {
        $this->scenario = 'question_authorization_own_messages';
        $this->script()
            ->toolRequest('ask_user', ['question' => AgentQuestionWebJourneyTest::QUESTION, 'choices' => AgentQuestionWebJourneyTest::CHOICES])
            ->finalAnswer('Hello, B.');

        $question = $this->userAIsAsked();
        $messagesBefore = Message::where('conversation_id', $question->conversation_id)->count();
        $this->app->make(RoleAssignmentService::class)
            ->set(ModelRole::Inference, $this->userB->id, $this->fixture()->server->id, 'gpt-4');
        // B's own recent Telegram conversation, which B's messages continue.
        $conversationB = Conversation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->userB->id,
            'server_id' => $this->fixture()->server->id,
            'model' => 'gpt-4',
            'title' => 'B\'s Telegram conversation',
            'channel' => 'telegram',
        ]);
        $asB = $this->actingAs($this->userB, 'api');

        // B, who has no pending question, sends a blank Telegram message: the
        // ordinary validation error, never A's answer_required.
        $blank = $asB->postJson(self::API.'/agent', ['message' => '', 'channel' => 'telegram']);
        $blank->assertStatus(422);
        $this->assertNotSame('answer_required', $blank->json('code'));
        $blank->assertJsonValidationErrors('message');
        $this->assertStillPending($question, 'B sent a blank message of their own');

        // B sends "2" on the same channel with no conversation id: B's own
        // conversation handles it, A's question is not selected.
        $ordinary = $asB->postJson(self::API.'/agent', ['message' => '2', 'channel' => 'telegram']);
        $this->assertSame(200, $ordinary->status(), (string) $ordinary->getContent());
        $ordinary->assertJson(['status' => 'completed', 'content' => 'Hello, B.']);
        $this->assertSame($conversationB->id, $ordinary->json('conversation_id'), 'B\'s message went to B\'s own conversation');
        $this->assertStillPending($question, 'B sent an ordinary message of their own');
        $this->assertNothingStoredFor($question, $messagesBefore);
        $this->assertSame([], array_values(array_filter(
            $this->telegram->deliveries,
            fn (array $d) => $d['conversation_id'] === $ordinary->json('conversation_id'),
        )), 'no question is pushed to B');
    }

    /** Everything about a response a caller could tell apart, except the time it was sent. */
    private function observable(TestResponse $response): array
    {
        $headers = $response->headers->all();
        unset($headers['date']);

        return ['status' => $response->getStatusCode(), 'body' => $response->getContent(), 'headers' => $headers];
    }

    #[Test]
    public function another_user_holding_every_identifier_can_neither_see_nor_answer_through_any_interactive_route(): void
    {
        $this->scenario = 'question_authorization_interactive';
        $this->script()
            ->toolRequest('ask_user', ['question' => AgentQuestionWebJourneyTest::QUESTION, 'choices' => AgentQuestionWebJourneyTest::CHOICES]);
        // Anything that did resume A's run would be queued here, not run unseen.
        Queue::fake([SendHttpStreamRequest::class, ResumeAfterQuestionJob::class]);

        $question = $this->userAIsAsked();
        $userA = $this->fixture()->user;
        $messagesBefore = Message::where('conversation_id', $question->conversation_id)->count();
        $modelRequestsBefore = count($this->transport()->capturedChatPayloads());
        $this->assertNotNull($question->display_message_id, 'precondition: the question has a display message');

        $asA = $this->actingAs($userA, 'api');
        $ownerList = $asA->getJson(self::API."/conversation/{$question->conversation_id}/message")->assertStatus(200)->json();
        $ownerView = collect($ownerList)->firstWhere('id', $question->display_message_id)['question'] ?? null;
        $this->assertSame('pending', $ownerView['state'] ?? null, 'precondition: the owner is shown the form');

        // --- The web form's answer route ---------------------------------
        $asB = $this->actingAs($this->userB, 'api');
        $absentId = (string) Str::uuid();
        $answers = [['selected' => [2]], ['selected' => [1]], ['text' => 'Frontend, please']];

        foreach ($answers as $body) {
            $label = json_encode($body);
            $absent = $asB->postJson(self::API."/question/{$absentId}/answer", $body);
            $absent->assertStatus(404);
            $this->assertSame('{}', $absent->getContent(), "{$label}: an absent id is answered with an empty 404");

            // B holds the question id, and tries every other id involved as one too.
            $held = [
                'question id' => $question->id,
                'conversation id' => $question->conversation_id,
                'display message id' => $question->display_message_id,
                'run id' => $question->run_id,
                'ask action id' => $question->action_id,
            ];
            foreach ($held as $what => $id) {
                $this->assertSame(
                    $this->observable($absent),
                    $this->observable($asB->postJson(self::API."/question/{$id}/answer", $body)),
                    "{$label} with A's {$what}: indistinguishable from an id that does not exist",
                );
            }
            $this->assertStillPending($question, "B submitted {$label} to the form route");
        }

        // --- Viewing the conversation -------------------------------------
        // An ordinary user is refused A's conversation, as before.
        $asB->getJson(self::API."/conversation/{$question->conversation_id}/message")->assertStatus(403);

        // An administrator allowed to read other users' conversations reads
        // the same messages A does, but never the form: the question view is
        // the owner's alone.
        $adminId = (string) $this->userB->id;
        Gate::define('list users', fn ($user) => (string) $user->id === $adminId);
        $adminList = $this->actingAs($this->userB, 'api')
            ->getJson(self::API."/conversation/{$question->conversation_id}/message")
            ->assertStatus(200)
            ->json();
        $this->assertSame(array_column($ownerList, 'id'), array_column($adminList, 'id'), 'precondition: the administrator reads the same messages');
        foreach ($adminList as $message) {
            $this->assertArrayNotHasKey('question', $message, 'no message carries a question view for anyone but the owner');
        }
        $this->assertStillPending($question, 'B read A\'s conversation');

        // --- The interactive-answer contract the messaging channel calls ---
        $contract = $this->app->make(InteractiveQuestionAnswers::class);
        $userB = (string) $this->userB->id;

        $this->assertSame(QuestionState::Pending, $contract->stateFor($question->id, (string) $userA->id), 'precondition: the owner is told the state');
        $this->assertNull($contract->stateFor($question->id, $userB), 'B is not told the question\'s state');
        $this->assertNull($contract->stateFor($absentId, $userB));

        $absentOutcome = $contract->submit($absentId, $userB, InteractiveAnswer::selection([2]), AnswerMethod::MessagingOption);
        $this->assertSame(InteractiveAnswerStatus::NotFound, $absentOutcome->status);

        foreach ([InteractiveAnswer::selection([2]), InteractiveAnswer::selection([3]), InteractiveAnswer::freeText('Frontend, please')] as $answer) {
            $label = $answer->toReplyText();

            $tapped = $contract->submit($question->id, $userB, $answer, AnswerMethod::MessagingOption);
            $this->assertSame(InteractiveAnswerStatus::NotFound, $tapped->status, "B's tap {$label}: not found");
            $this->assertEquals($absentOutcome, $tapped, "B's tap {$label}: indistinguishable from an absent question");

            $recorded = $this->app->make(InteractiveAnswerService::class)->record($question->id, $userB, $answer, AnswerMethod::WebForm);
            $this->assertEquals($absentOutcome, $recorded, "B's recorded answer {$label}: indistinguishable from an absent question");

            $this->assertStillPending($question, "B answered {$label} through the contract");
        }

        // --- Nothing anywhere moved ----------------------------------------
        Queue::assertNotPushed(ResumeAfterQuestionJob::class);
        $this->assertNothingStoredFor($question, $messagesBefore);
        $this->assertSame($modelRequestsBefore, count($this->transport()->capturedChatPayloads()), 'A\'s agent was never resumed');
        $this->assertCount(1, $this->telegram->deliveries, 'only A\'s question was ever delivered');

        // A's form is exactly as it was.
        $ownerListAfter = $this->actingAs($userA, 'api')->getJson(self::API."/conversation/{$question->conversation_id}/message")->assertStatus(200)->json();
        $this->assertSame($ownerView, collect($ownerListAfter)->firstWhere('id', $question->display_message_id)['question'] ?? null, 'A is still shown the same pending form');
    }
}
