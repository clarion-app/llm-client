<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\CeilingPromptRequest;
use ClarionApp\LlmClient\ValueObjects\DeliveryResult;
use ClarionApp\LlmClient\ValueObjects\OpenedQuestion;
use ClarionApp\LlmClient\ValueObjects\OpenQuestionRequest;
use ClarionApp\LlmClient\ValueObjects\ParsedAnswer;
use ClarionApp\LlmClient\ValueObjects\PauseKind;
use ClarionApp\LlmClient\ValueObjects\PausedTurnContext;
use ClarionApp\LlmClient\ValueObjects\QuestionRefusal;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\ResumeCaller;
use ClarionApp\LlmClient\ValueObjects\UnansweredPolicy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Every write to `agent_questions` goes through this service.
 *
 * Each way a question can leave a waiting state (`pending`, or `stopped`
 * for a late answer or a discard) is the single compare-and-set in
 * transition(), so racing resolutions always produce exactly one winner,
 * and only the winner delivers a notice or resumes the run.
 */
class AgentQuestionService
{
    /** `tool_data.kind` of the message showing a question in its conversation. */
    public const KIND_QUESTION = 'agent_question';

    /** `tool_data.kind` of a notice about a question resolved without an answer. */
    public const KIND_NOTICE = 'agent_question_notice';

    /** `tool_data.kind` of the user's message that answered a question. */
    public const KIND_ANSWER = 'agent_question_answer';

    /** `tool_data.kind` of a notice about a task's budget (approaching, changed). */
    public const KIND_TASK_BUDGET_NOTICE = 'task_budget_notice';

    /** `tool_data.kind` of a task-budget report (halted, accepted, stopped). */
    public const KIND_TASK_BUDGET_REPORT = 'task_budget_report';

    /** Every message kind that exists only for the user, never for the model. */
    public const CONVERSATION_ONLY_KINDS = [self::KIND_QUESTION, self::KIND_NOTICE, self::KIND_ANSWER, self::KIND_TASK_BUDGET_NOTICE, self::KIND_TASK_BUDGET_REPORT];

    /** Length of the `agent_questions.delivery_failure_reason` column. */
    private const MAX_FAILURE_REASON_CHARS = 512;

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
     * happens only after the commit, through QuestionPresenter: with the
     * choices as controls where the channel can show them, else as plain
     * text. A failed delivery never throws: it is
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
                $ask = $request->request;
                $assumed = $ask->assumedAnswer;

                // The waiting period the agent asked for, brought within the
                // installation's bounds. It and the policy are fixed here, at
                // ask time, and nothing changes them afterwards.
                $effectiveMinutes = app(QuestionWaitingPeriod::class)->effectiveMinutes($ask->requestedWaitMinutes);

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
                    'preamble' => $preamble,
                    'state' => QuestionState::Pending,
                    'asked_at' => $now,
                    'expires_at' => $now->copy()->addMinutes($effectiveMinutes),
                    // Written in this insert, so the display message rendered
                    // below from this in-memory model already carries them.
                    'unanswered_policy' => $ask->policy,
                    'consequential' => $ask->consequential,
                    'assumed_answer_raw' => $assumed?->rawReply,
                    'assumed_answer_selected' => $assumed?->selected,
                    'requested_wait_minutes' => $ask->requestedWaitMinutes,
                    'effective_wait_minutes' => $effectiveMinutes,
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

                app(RunTraceRecorder::class)->closeAction(
                    $request->actionId,
                    ActionOutcome::AwaitingConfirmation,
                    content: $question->runRecordSnapshot(),
                );

                return [$question, $display];
            });
        } catch (UniqueConstraintViolationException) {
            return QuestionRefusal::alreadyPending();
        }

        // The run is now waiting on its user.
        app(RunTraceRecorder::class)->announceRunChanged($question->run_id);

        $delivery = app(QuestionPresenter::class)->deliverQuestion($conversation, $question, (string) $display->content, $preamble);

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
     * Ask the user what to do with a task paused at its budget ceiling.
     *
     * The display message is written first and the question then points at
     * it as its paused message: there is no paused agent turn behind a
     * ceiling prompt. Both are written in one transaction, and the question
     * takes the conversation's single question slot, so if the slot is
     * already held nothing is written and the prompt is refused. Delivery
     * happens after the commit.
     */
    public function openCeilingPrompt(CeilingPromptRequest $request): OpenedQuestion|QuestionRefusal
    {
        $conversation = $request->conversation;
        $text = app(TaskBudgetText::class);
        $requested = config('llm-client.task_budget.prompt_wait_minutes');
        $requested = is_numeric($requested) ? (int) $requested : null;

        try {
            [$question, $display] = DB::transaction(function () use ($request, $conversation, $text, $requested) {
                $now = now();
                $effectiveMinutes = app(TaskBudgetLimits::class)->promptWaitMinutes();

                $display = Message::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'user' => $conversation->character,
                    'content' => '',
                    'responseTime' => 0,
                    'tool_data' => [
                        'kind' => self::KIND_QUESTION,
                        'question_id' => null,
                        'state' => QuestionState::Pending->value,
                        'origin' => AgentQuestion::ORIGIN_TASK_BUDGET,
                    ],
                ]);

                $question = AgentQuestion::create([
                    'origin' => AgentQuestion::ORIGIN_TASK_BUDGET,
                    'task_budget_id' => $request->task->id,
                    'conversation_id' => $conversation->id,
                    'user_id' => $conversation->user_id,
                    'pending_conversation_id' => $conversation->id,
                    'run_id' => $request->runId,
                    'step_id' => null,
                    'action_id' => null,
                    'paused_message_id' => $display->id,
                    'display_message_id' => $display->id,
                    'tool_call_id' => 'task_budget',
                    'channel' => (string) ($conversation->channel ?? 'web'),
                    'resume_mode' => $request->resumeMode,
                    'question' => $text->promptQuestion($request->standing),
                    'choices' => $text->choices($request->offeredSteps, $request->offeredSpend, $request->standing->currency),
                    'allow_multiple' => false,
                    'preamble' => $request->report,
                    'state' => QuestionState::Pending,
                    'asked_at' => $now,
                    'expires_at' => $now->copy()->addMinutes($effectiveMinutes),
                    'unanswered_policy' => \ClarionApp\LlmClient\ValueObjects\UnansweredPolicy::Stop,
                    'consequential' => true,
                    'requested_wait_minutes' => $requested,
                    'effective_wait_minutes' => $effectiveMinutes,
                ]);

                $toolData = $display->tool_data;
                $toolData['question_id'] = $question->id;
                $display->tool_data = $toolData;
                $display->content = app(QuestionRenderer::class)->render($question, $question->preamble);
                $display->save();

                Conversation::find($conversation->id)?->update(['is_processing' => false]);
                $conversation->setAttribute('is_processing', false);

                return [$question, $display];
            });
        } catch (UniqueConstraintViolationException) {
            return QuestionRefusal::alreadyPending();
        }

        app(TaskBudgetService::class)->appendEvent($request->task, 'prompt_raised', [
            'question_id' => $question->id,
            'choices' => $question->choices,
        ]);

        app(RunTraceRecorder::class)->announceRunChanged($question->run_id);

        $delivery = app(QuestionPresenter::class)->deliverQuestion($conversation, $question, (string) $display->content, $question->preamble);

        if ($delivery->delivered) {
            $deliveredAt = now();
            DB::table('agent_questions')->where('id', $question->id)->update(['delivered_at' => $deliveredAt]);
            $question->delivered_at = $deliveredAt;
        } else {
            Log::warning('AgentQuestionService: the task budget prompt could not be delivered', [
                'question_id' => $question->id,
                'conversation_id' => $conversation->id,
                'channel' => $question->channel,
                'reason' => $delivery->failureReason,
            ]);
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
     * Resolve a pending question whose answerable period has passed,
     * following the policy it was asked with.
     *
     * A question asked before unanswered policies existed expires as it
     * always did. A `proceed_on_assumption` question resolves the same way
     * but its run continues on the answer it said it would assume. A `stop`
     * question stops instead: it keeps its
     * conversation's question slot so a late reply still reaches it, stays
     * unresolved, leaves the conversation not processing, and its run waits
     * for a late answer or a discard.
     *
     * Only the winner writes the notice into the conversation and delivers
     * it; resuming the run (for a state that continues it) is the caller's
     * job. Returns the state won, or null when anything else resolved the
     * question first — nothing changes then.
     */
    public function expireWithPolicy(AgentQuestion $question): ?QuestionState
    {
        $policy = $question->unanswered_policy instanceof UnansweredPolicy
            ? $question->unanswered_policy
            : UnansweredPolicy::tryFrom((string) $question->unanswered_policy);

        if ($policy === null) {
            return $this->resolveWithNotice($question, [QuestionState::Pending], QuestionState::Expired, [], true)
                ? QuestionState::Expired
                : null;
        }

        if ($policy === UnansweredPolicy::ProceedOnAssumption) {
            // Resolved like any expiry: the slot is released and the
            // conversation marked processing, since the run continues on
            // the assumption the question stated.
            return $this->resolveWithNotice($question, [QuestionState::Pending], QuestionState::ExpiredAssumed, [], true)
                ? QuestionState::ExpiredAssumed
                : null;
        }

        // Written with its fraction of a second, which a bound date loses
        // on MySQL.
        $stoppedAt = now()->format('Y-m-d H:i:s.u');

        $won = $this->resolveWithNotice(
            $question,
            [QuestionState::Pending],
            QuestionState::Stopped,
            ['stopped_at' => $stoppedAt],
            setProcessing: false,
            releaseSlot: false,
            resolves: false,
        );

        if (!$won) {
            return null;
        }

        $this->recordStop($question);

        return QuestionState::Stopped;
    }

    /**
     * Show the stop in the run record: the waiting `ask_user` action keeps
     * waiting, its content now saying the work stopped, and the run's
     * watchers are told. The stop already stands, so nothing here may throw
     * out of the sweep.
     */
    private function recordStop(AgentQuestion $question): void
    {
        try {
            $fresh = $question->fresh() ?? $question;
            $recorder = app(RunTraceRecorder::class);
            $recorder->updateSuspendedActionContent($fresh->action_id, $fresh->runRecordSnapshot());
            $recorder->announceRunChanged($fresh->run_id);
        } catch (\Throwable $e) {
            Log::warning('AgentQuestionService: the stop could not be shown in the run record', [
                'question_id' => $question->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Expire a pending question by its policy (see expireWithPolicy()):
     * true when this call won.
     */
    public function expire(AgentQuestion $question): bool
    {
        return $this->expireWithPolicy($question) !== null;
    }

    /**
     * Withdraw the question waiting in $conversation, because its user
     * stopped the conversation's work (ended or deleted it).
     *
     * Only the winner (true) tells the user the question no longer needs a
     * reply and ends the waiting run: the paused call is answered with the
     * withdrawn outcome so the stored history stays valid, and the run
     * closes without another model call. The conversation is not marked
     * processing, since nothing continues. With no pending question, or one
     * resolved by anything else first, nothing changes (false) — an answer
     * accepted a moment earlier stands and its run continues.
     */
    public function withdrawForConversation(Conversation $conversation): bool
    {
        // Loaded as a model, as every other resolver loads it, before its
        // compare-and-set.
        $question = AgentQuestion::where('pending_conversation_id', $conversation->id)->first();

        if ($question === null) {
            return false;
        }

        if (!$this->resolveWithNotice($question, [QuestionState::Pending, QuestionState::Stopped], QuestionState::Withdrawn, [], false)) {
            return false;
        }

        try {
            app(QuestionResumer::class)->resume($question->id, ResumeCaller::HttpRequest);
        } catch (\Throwable $e) {
            // The withdrawal stands; a resolved question that never resumed
            // is picked up by the re-drive sweep, which closes its run.
            Log::warning('AgentQuestionService: the withdrawn question\'s run could not be closed', [
                'question_id' => $question->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }

    /**
     * Resolve a pending question that could not be delivered to its user,
     * recording why.
     *
     * The winner (true) writes a notice and attempts to deliver it once; a
     * failure of that follow-up delivery is logged and recorded on the
     * notice, never retried, since the channel has just failed and the agent
     * is told regardless. Resuming the run is the caller's job.
     */
    public function markUndeliverable(AgentQuestion $question, string $reason): bool
    {
        // An adapter's reason is free text; it is kept within its column so
        // an overlong one can never make the resolution itself fail.
        $reason = self::recordableReason($reason);

        return $this->resolveWithNotice($question, [QuestionState::Pending], QuestionState::Undeliverable, [
            'delivery_failure_reason' => $reason,
        ], true);
    }

    /**
     * Move a question out of a waiting state without an answer and tell the
     * user.
     *
     * The notice message is written in the winning transition's transaction,
     * so a notice exists exactly when the transition happened. No notice is
     * written or delivered when the conversation no longer exists.
     *
     * $from lists the states the question may leave; $setProcessing is true
     * for every resolution that continues the run. A transition that keeps
     * the question waiting ($resolves false — the stop) also records its new
     * state on the message showing the question, in the same commit; a
     * resolution's is recorded when its run resumes.
     *
     * @param  list<QuestionState>  $from
     * @param  array<string, mixed>  $fields
     */
    private function resolveWithNotice(
        AgentQuestion $question,
        array $from,
        QuestionState $to,
        array $fields,
        bool $setProcessing,
        bool $releaseSlot = true,
        bool $resolves = true,
    ): bool {
        $result = DB::transaction(function () use ($question, $from, $to, $fields, $setProcessing, $releaseSlot, $resolves) {
            if (!$this->transition($question->id, $from, $to, $fields, $setProcessing, $releaseSlot, $resolves)) {
                return null;
            }

            $resolved = AgentQuestion::findOrFail($question->id);

            if (!$resolves) {
                $this->markDisplayMessage($resolved);
            }

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
            $this->deliverNotice($conversation, $notice, 'AgentQuestionService: question notice could not be delivered', [
                'question_id' => $resolved->id,
                'conversation_id' => $conversation->id,
                'channel' => $resolved->channel,
                'state' => $to->value,
            ]);
        }

        return true;
    }

    /** Record the question's current state on the message showing it. */
    private function markDisplayMessage(AgentQuestion $question): void
    {
        $display = $question->display_message_id === null ? null : Message::find($question->display_message_id);

        if ($display === null) {
            return;
        }

        $toolData = is_array($display->tool_data) ? $display->tool_data : [];
        $toolData['state'] = $question->state->value;
        $display->tool_data = $toolData;
        $display->save();
    }

    /**
     * Record the owner's reply as the answer to a pending question.
     *
     * The answer and the user message carrying it are written in the
     * winning transition's transaction, so `answer_message_id` never names
     * a message that does not exist. Returns null when the question was
     * already resolved by something else; nothing is written then.
     *
     * $method records how the user answered (typed, web form, tapped
     * option); it is written in the same update as the answer.
     */
    public function answer(
        AgentQuestion $question,
        ParsedAnswer $answer,
        string $userId,
        string $rawContent,
        AnswerMethod $method = AnswerMethod::TypedReply,
    ): ?Message {
        if ((string) $question->user_id !== $userId) {
            throw new \LogicException('Only the user who was asked may answer a question.');
        }

        return DB::transaction(function () use ($question, $answer, $userId, $rawContent, $method) {
            $won = $this->transition($question->id, [QuestionState::Pending, QuestionState::Stopped], QuestionState::Answered, [
                'answer_raw' => $rawContent,
                'answer_selected' => json_encode($answer->selected),
                'answer_method' => $method->value,
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
     * Claim the one late-reply notice a question that expired on its
     * assumption is owed. A compare-and-set: of any number of concurrent
     * callers exactly one gets true, and every later call gets false.
     */
    public function claimLateReplyNotice(string $id): bool
    {
        $now = now()->format('Y-m-d H:i:s.u');

        return DB::table('agent_questions')
            ->where('id', $id)
            ->where('state', QuestionState::ExpiredAssumed->value)
            ->whereNull('late_reply_noticed_at')
            ->update(['late_reply_noticed_at' => $now, 'updated_at' => $now]) === 1;
    }

    /**
     * Post the late-reply notice for $question in $conversation, if it is
     * still owed: the claim and the notice message are written in one
     * transaction, and the notice is delivered on the conversation's channel
     * once that commits. Returns the notice, or null when another reply
     * already claimed it. A failed delivery is logged; the notice stands.
     */
    public function postLateReplyNotice(AgentQuestion $question, Conversation $conversation): ?Message
    {
        $notice = DB::transaction(function () use ($question, $conversation) {
            if (!$this->claimLateReplyNotice($question->id)) {
                return null;
            }

            return Message::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'user' => $conversation->character,
                'content' => app(QuestionRenderer::class)->renderLateReplyNotice($question),
                'responseTime' => 0,
                'tool_data' => [
                    'kind' => self::KIND_NOTICE,
                    'question_id' => $question->id,
                    'state' => QuestionState::ExpiredAssumed->value,
                    'notice' => 'late_reply',
                ],
            ]);
        });

        if ($notice === null) {
            return null;
        }

        $this->deliverNotice($conversation, $notice, 'AgentQuestionService: late-reply notice could not be delivered', [
            'question_id' => $question->id,
            'conversation_id' => $conversation->id,
            'channel' => $conversation->channel,
        ]);

        return $notice;
    }

    /**
     * Deliver a notice already written to $conversation and record on it
     * whether it arrived: `delivered`, and for a failure a bounded
     * `delivery_failure_reason`. A failure is logged. The notice and the
     * resolution it describes stand either way, so nothing here throws.
     *
     * @param  array<string, mixed>  $logContext
     */
    private function deliverNotice(Conversation $conversation, Message $notice, string $failureLog, array $logContext): void
    {
        $delivery = $this->deliver($conversation, (string) $notice->content);
        $reason = $delivery->delivered ? null : self::recordableReason($delivery->failureReason ?? 'unknown reason');

        if (!$delivery->delivered) {
            Log::warning($failureLog, $logContext + ['reason' => $reason]);
        }

        try {
            $toolData = is_array($notice->tool_data) ? $notice->tool_data : [];
            $toolData['delivered'] = $delivery->delivered;
            if ($reason !== null) {
                $toolData['delivery_failure_reason'] = $reason;
            }
            $notice->tool_data = $toolData;
            $notice->save();
        } catch (\Throwable $e) {
            Log::warning('AgentQuestionService: the notice\'s delivery could not be recorded', $logContext + [
                'notice_id' => $notice->id,
                'error' => self::recordableReason($e->getMessage()),
            ]);
        }
    }

    /**
     * A delivery failure reason fit to store and log: any bot token an
     * adapter's error carried (in an API URL or on its own) is removed, and
     * the text is cut to the reason column's length.
     */
    private static function recordableReason(string $reason): string
    {
        $reason = (string) preg_replace('~/bot\d+:[A-Za-z0-9_-]+~', '/bot[bot token]', $reason);
        $reason = (string) preg_replace('~\b\d{5,}:[A-Za-z0-9_-]{20,}~', '[bot token]', $reason);

        if (mb_strlen($reason) > self::MAX_FAILURE_REASON_CHARS) {
            $reason = mb_substr($reason, 0, self::MAX_FAILURE_REASON_CHARS - 1).'…';
        }

        return $reason;
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
            // An exception's message can carry the request URL, and with it
            // a bot token.
            return DeliveryResult::failed(self::recordableReason(get_class($e).': '.$e->getMessage()));
        }
    }

    /**
     * Move a question out of a waiting state, if it is still in one of
     * the $from states.
     *
     * The winning caller (returns true) sets the state and writes $fields
     * (raw column values). When $resolves is true it also sets
     * `resolved_at`; when $releaseSlot is true it releases the
     * one-question-per-conversation latch. Every resolution does both; only
     * the stop, which leaves the question waiting for a late answer, does
     * neither. When $setProcessing is true the conversation is marked
     * processing in the same commit, so a second message arriving between
     * this resolution and the resume can never start a concurrent run. A
     * losing caller (returns false) changes nothing.
     *
     * The winner also schedules QuestionPresentationUpdater to run once the
     * outermost transaction commits, so every place the question is shown
     * learns of the change — and never of one that rolled back.
     *
     * @param  list<QuestionState>  $from
     * @param  array<string, mixed>  $fields
     */
    private function transition(
        string $id,
        array $from,
        QuestionState $to,
        array $fields,
        bool $setProcessing,
        bool $releaseSlot = true,
        bool $resolves = true,
    ): bool {
        if ($to === QuestionState::Pending) {
            throw new \InvalidArgumentException('A question can never transition back to pending.');
        }

        if ($from === []) {
            throw new \InvalidArgumentException('A transition needs at least one state to leave.');
        }

        return DB::transaction(function () use ($id, $from, $to, $fields, $setProcessing, $releaseSlot, $resolves) {
            $now = now();

            $changes = ['state' => $to->value, 'updated_at' => $now];
            if ($resolves) {
                $changes['resolved_at'] = $now;
            }
            if ($releaseSlot) {
                $changes['pending_conversation_id'] = null;
            }

            $affected = DB::table('agent_questions')
                ->where('id', $id)
                ->whereIn('state', array_map(fn (QuestionState $state) => $state->value, $from))
                ->update(array_merge($fields, $changes));

            if ($affected !== 1) {
                return false;
            }

            if ($setProcessing) {
                $conversationId = DB::table('agent_questions')->where('id', $id)->value('conversation_id');
                Conversation::find($conversationId)?->update(['is_processing' => true]);
            }

            DB::afterCommit(fn () => app(QuestionPresentationUpdater::class)->resolved($id));

            return true;
        });
    }
}
