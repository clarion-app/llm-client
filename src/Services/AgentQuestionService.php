<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\DeliveryResult;
use ClarionApp\LlmClient\ValueObjects\OpenedQuestion;
use ClarionApp\LlmClient\ValueObjects\OpenQuestionRequest;
use ClarionApp\LlmClient\ValueObjects\ParsedAnswer;
use ClarionApp\LlmClient\ValueObjects\PauseKind;
use ClarionApp\LlmClient\ValueObjects\PausedTurnContext;
use ClarionApp\LlmClient\ValueObjects\QuestionRefusal;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\ResumeCaller;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Every write to `agent_questions` goes through this service.
 *
 * Each way a question can leave `pending` — answered, expired, withdrawn,
 * undeliverable — is the single compare-and-set in transition(), so racing
 * resolutions always produce exactly one winner, and only the winner
 * delivers a notice or resumes the run.
 */
class AgentQuestionService
{
    /** `tool_data.kind` of the message showing a question in its conversation. */
    public const KIND_QUESTION = 'agent_question';

    /** `tool_data.kind` of a notice about a question resolved without an answer. */
    public const KIND_NOTICE = 'agent_question_notice';

    /** `tool_data.kind` of the user's message that answered a question. */
    public const KIND_ANSWER = 'agent_question_answer';

    /** Every message kind that exists only for the user, never for the model. */
    public const CONVERSATION_ONLY_KINDS = [self::KIND_QUESTION, self::KIND_NOTICE, self::KIND_ANSWER];

    /**
     * Turn a validated `ask_user` call into a pending question.
     *
     * The question row (which takes the conversation's single pending slot),
     * the display message, the paused turn, the conversation no longer
     * marked processing, and the `ask_user` action suspended as waiting on a
     * person are written in one transaction, so there is never a pending
     * question without a resumable pause or the reverse. If the slot is
     * already taken nothing is written and the ask is refused.
     *
     * Delivery to the user's channel is an external side effect, so it
     * happens only after the commit. A failed delivery never throws: it is
     * reported on the returned OpenedQuestion, the question is resolved as
     * undeliverable, and the run is resumed with that outcome (inline on the
     * synchronous path, whose result is returned as `resumeResult`; a
     * failure of that continuation propagates like any synchronous run's).
     */
    public function open(OpenQuestionRequest $request): OpenedQuestion|QuestionRefusal
    {
        $conversation = $request->conversation;

        // The text that accompanied the ask is shown above the question only
        // on the sync path; on the stream path it was already streamed to the
        // user as the paused message itself.
        $preamble = $request->resumeMode === OpenQuestionRequest::RESUME_SYNC ? $request->preamble : null;

        try {
            [$question, $display] = DB::transaction(function () use ($request, $conversation, $preamble) {
                $now = now();
                $expiryMinutes = max(1, (int) config('llm-client.agent_questions.expiry_minutes', 1440));

                // Inserted first: the UNIQUE slot latch refuses a second
                // pending question before anything else is written.
                $question = AgentQuestion::create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $conversation->user_id,
                    'pending_conversation_id' => $conversation->id,
                    'run_id' => $request->runId,
                    'step_id' => $request->stepId,
                    'action_id' => $request->actionId,
                    'paused_message_id' => $request->pausedMessage->id,
                    'tool_call_id' => $request->toolCallId,
                    'channel' => (string) ($conversation->channel ?? 'web'),
                    'resume_mode' => $request->resumeMode,
                    'question' => $request->request->question,
                    'choices' => $request->request->choices,
                    'allow_multiple' => $request->request->allowMultiple,
                    'state' => QuestionState::Pending,
                    'asked_at' => $now,
                    'expires_at' => $now->copy()->addMinutes($expiryMinutes),
                ]);

                $display = Message::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'user' => $conversation->character,
                    'content' => app(QuestionRenderer::class)->render($question, $preamble),
                    'responseTime' => 0,
                    'tool_data' => [
                        'kind' => self::KIND_QUESTION,
                        'question_id' => $question->id,
                        'state' => QuestionState::Pending->value,
                    ],
                ]);

                $question->display_message_id = $display->id;
                $question->save();

                app(PausedTurnWriter::class)->pause(
                    $request->pausedMessage,
                    PauseKind::Question,
                    ['question_id' => $question->id],
                    new PausedTurnContext(
                        pausedToolCallId: $request->toolCallId,
                        pendingToolResults: $request->pendingToolResults,
                        iteration: $request->iteration,
                        runId: $request->runId,
                        stepId: $request->stepId,
                        actionId: $request->actionId,
                    ),
                );

                // Waiting on the user is not processing: the next message in
                // this conversation must reach the answer path.
                Conversation::find($conversation->id)?->update(['is_processing' => false]);
                $conversation->setAttribute('is_processing', false);

                app(RunTraceRecorder::class)->closeAction($request->actionId, ActionOutcome::AwaitingConfirmation);

                return [$question, $display];
            });
        } catch (UniqueConstraintViolationException) {
            return QuestionRefusal::alreadyPending();
        }

        $delivery = $this->deliver($conversation, (string) $display->content);

        if ($delivery->delivered) {
            $deliveredAt = now();
            DB::table('agent_questions')->where('id', $question->id)->update(['delivered_at' => $deliveredAt]);
            $question->delivered_at = $deliveredAt;
        } else {
            Log::warning('AgentQuestionService: question could not be delivered', [
                'question_id' => $question->id,
                'conversation_id' => $conversation->id,
                'channel' => $question->channel,
                'reason' => $delivery->failureReason,
            ]);

            // A question the user never saw cannot be answered, so the run
            // must not wait for one: resolve it as undeliverable and resume
            // the agent with that outcome.
            if ($this->markUndeliverable($question, (string) $delivery->failureReason)) {
                $question->refresh();

                return new OpenedQuestion($question, $display, $delivery, $this->resumeUndeliverable($question));
            }

            $question->refresh();
        }

        return new OpenedQuestion($question, $display, $delivery);
    }

    /**
     * Resume a run whose question could not be delivered, on the path it
     * paused on: inline for the synchronous path (the pause site returns the
     * continuation's result to its caller), through a queued job for the
     * streamed path.
     *
     * A failure of the inline continuation propagates to the synchronous
     * caller, after the resumer has already reset the conversation and
     * closed the run. A job that cannot be queued is left to the re-drive
     * sweep, which picks up every resolved question that never resumed.
     *
     * @return array<string, mixed>|null
     */
    private function resumeUndeliverable(AgentQuestion $question): ?array
    {
        if ($question->resume_mode === OpenQuestionRequest::RESUME_SYNC) {
            return app(QuestionResumer::class)->resume($question->id, ResumeCaller::HttpRequest);
        }

        try {
            ResumeAfterQuestionJob::dispatch($question->id);
        } catch (\Throwable $e) {
            Log::warning('AgentQuestionService: the resume of an undeliverable question could not be queued', [
                'question_id' => $question->id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Resolve a pending question whose answerable period has passed.
     *
     * Only the winner (true) writes the expiry notice into the conversation
     * and delivers it; resuming the run is the caller's job. A question
     * already resolved by anything else loses (false) and nothing changes.
     */
    public function expire(AgentQuestion $question): bool
    {
        return $this->resolveWithNotice($question, QuestionState::Expired, []);
    }

    /**
     * Resolve a pending question that could not be delivered to its user,
     * recording why.
     *
     * The winner (true) writes a notice and attempts to deliver it once; a
     * failure of that follow-up delivery is only logged, since the channel
     * has just failed and the agent is told regardless. Resuming the run is
     * the caller's job.
     */
    public function markUndeliverable(AgentQuestion $question, string $reason): bool
    {
        return $this->resolveWithNotice($question, QuestionState::Undeliverable, [
            'delivery_failure_reason' => $reason,
        ]);
    }

    /**
     * Resolve a pending question without an answer and tell the user.
     *
     * The notice message is written in the winning transition's transaction,
     * so a notice exists exactly when the resolution happened. No notice is
     * written or delivered when the conversation no longer exists.
     *
     * @param  array<string, mixed>  $fields
     */
    private function resolveWithNotice(AgentQuestion $question, QuestionState $to, array $fields): bool
    {
        $result = DB::transaction(function () use ($question, $to, $fields) {
            if (!$this->transition($question->id, $to, $fields, true)) {
                return null;
            }

            $resolved = AgentQuestion::findOrFail($question->id);
            $conversation = Conversation::find($resolved->conversation_id);

            if ($conversation === null) {
                return [$resolved, null, null];
            }

            $notice = Message::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'user' => $conversation->character,
                'content' => app(QuestionRenderer::class)->renderNotice($resolved),
                'responseTime' => 0,
                'tool_data' => [
                    'kind' => self::KIND_NOTICE,
                    'question_id' => $resolved->id,
                    'state' => $to->value,
                ],
            ]);

            return [$resolved, $conversation, $notice];
        });

        if ($result === null) {
            return false;
        }

        [$resolved, $conversation, $notice] = $result;

        if ($conversation !== null && $notice !== null) {
            $delivery = $this->deliver($conversation, (string) $notice->content);

            if (!$delivery->delivered) {
                Log::warning('AgentQuestionService: question notice could not be delivered', [
                    'question_id' => $resolved->id,
                    'conversation_id' => $conversation->id,
                    'channel' => $resolved->channel,
                    'state' => $to->value,
                    'reason' => $delivery->failureReason,
                ]);
            }
        }

        return true;
    }

    /**
     * Record the owner's reply as the answer to a pending question.
     *
     * The answer and the user message carrying it are written in the
     * winning transition's transaction, so `answer_message_id` never names
     * a message that does not exist. Returns null when the question was
     * already resolved by something else; nothing is written then.
     */
    public function answer(AgentQuestion $question, ParsedAnswer $answer, string $userId, string $rawContent): ?Message
    {
        if ((string) $question->user_id !== $userId) {
            throw new \LogicException('Only the user who was asked may answer a question.');
        }

        return DB::transaction(function () use ($question, $answer, $userId, $rawContent) {
            $won = $this->transition($question->id, QuestionState::Answered, [
                'answer_raw' => $rawContent,
                'answer_selected' => json_encode($answer->selected),
                'answered_by' => $userId,
            ], true);

            if (!$won) {
                return null;
            }

            $message = Message::create([
                'conversation_id' => $question->conversation_id,
                'role' => 'user',
                'user' => User::find($userId)?->name,
                'content' => $rawContent,
                'tool_data' => [
                    'kind' => self::KIND_ANSWER,
                    'question_id' => $question->id,
                ],
            ]);

            DB::table('agent_questions')->where('id', $question->id)->update(['answer_message_id' => $message->id]);

            return $message;
        });
    }

    /**
     * Hand text to the conversation's channel adapter. An adapter that
     * throws, or a channel with no adapter, is a failed delivery; this
     * never throws.
     */
    public function deliver(Conversation $conversation, string $text): DeliveryResult
    {
        $channel = (string) ($conversation->channel ?? 'web');
        $adapter = app(ConversationChannelRegistry::class)->for($channel);

        if ($adapter === null) {
            return DeliveryResult::failed("no delivery is registered for the '{$channel}' channel");
        }

        try {
            return $adapter->deliver($conversation, $text);
        } catch (\Throwable $e) {
            return DeliveryResult::failed(get_class($e).': '.$e->getMessage());
        }
    }

    /**
     * Move a pending question to a terminal state, if it is still pending.
     *
     * The winning caller (returns true) sets the state and `resolved_at`,
     * releases the one-pending-per-conversation latch and writes $fields
     * (raw column values). When $setProcessing is true the conversation is
     * marked processing in the same commit, so a second message arriving
     * between this resolution and the resume can never start a concurrent
     * run. A losing caller (returns false) changes nothing.
     *
     * @param  array<string, mixed>  $fields
     */
    private function transition(string $id, QuestionState $to, array $fields, bool $setProcessing): bool
    {
        if ($to === QuestionState::Pending) {
            throw new \InvalidArgumentException('A question can only transition to a terminal state.');
        }

        return DB::transaction(function () use ($id, $to, $fields, $setProcessing) {
            $now = now();

            $affected = DB::table('agent_questions')
                ->where('id', $id)
                ->where('state', QuestionState::Pending->value)
                ->update(array_merge($fields, [
                    'state' => $to->value,
                    'resolved_at' => $now,
                    'pending_conversation_id' => null,
                    'updated_at' => $now,
                ]));

            if ($affected !== 1) {
                return false;
            }

            if ($setProcessing) {
                $conversationId = DB::table('agent_questions')->where('id', $id)->value('conversation_id');
                Conversation::find($conversationId)?->update(['is_processing' => true]);
            }

            return true;
        });
    }
}
