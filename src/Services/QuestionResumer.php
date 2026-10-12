<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\BudgetExceededException;
use ClarionApp\LlmClient\Exceptions\RateLimitExceededException;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\OpenQuestionRequest;
use ClarionApp\LlmClient\ValueObjects\ParsedAnswer;
use ClarionApp\LlmClient\ValueObjects\QuestionResolution;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\ResumeCaller;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Continues the run a resolved question paused, exactly once.
 *
 * The claim (`resumed_at`), answering the paused `ask_user` call with the
 * resolution's outcome, marking the display message with the final state,
 * and resolving the `ask_user` action commit together. Whoever makes that
 * claim is the only caller that continues the run; every later call is a
 * no-op. The continuation itself happens after the commit, on the loop path
 * the run paused on.
 */
class QuestionResumer
{
    public const CONTEXT_DELETED_REASON = 'question context deleted';

    public const WITHDRAWN_REASON = 'question withdrawn';

    /**
     * @return array<string, mixed>|null  the synchronous continuation's
     *   result; null when there is none (a streamed continuation, a run
     *   that ends instead of continuing, or nothing left to resume)
     */
    public function resume(string $questionId, ResumeCaller $caller): ?array
    {
        $claim = DB::transaction(fn () => $this->claim($questionId));

        if ($claim === null) {
            return null;
        }

        /** @var AgentQuestion $question */
        [$question, $resolution, $conversation, $paused] = $claim;

        $recorder = app(RunTraceRecorder::class);

        // The run is no longer waiting on its user.
        $recorder->announceRunChanged($question->run_id);

        // The conversation or the paused turn is gone: nothing can continue.
        if ($conversation === null || $paused === null) {
            $conversation?->update(['is_processing' => false]);
            $recorder->closeStep($question->step_id, RunEndState::StoppedEarly, self::CONTEXT_DELETED_REASON, $this->waitMs($question, $paused));
            $recorder->closeRun($question->run_id, RunEndState::StoppedEarly, self::CONTEXT_DELETED_REASON);

            return null;
        }

        // A withdrawn question ends its run without another model call.
        if (!$question->state->continuesRun()) {
            $recorder->closeStep($question->step_id, RunEndState::StoppedEarly, self::WITHDRAWN_REASON, $this->waitMs($question, $paused));
            $recorder->closeRun($question->run_id, RunEndState::StoppedEarly, self::WITHDRAWN_REASON);

            return null;
        }

        try {
            if ($question->resume_mode === OpenQuestionRequest::RESUME_STREAM) {
                app(AgentLoopService::class)->resumeQuestion($conversation, $paused, $resolution);

                return null;
            }

            if ($question->resume_mode === OpenQuestionRequest::RESUME_SYNC) {
                $result = app(AgentLoopService::class)->resumeQuestionSync($conversation, $paused, $resolution);

                // Nobody is waiting on an HTTP response for a background
                // resume, so the reply goes to the user through their
                // channel. A follow-up question was already delivered.
                if ($caller === ResumeCaller::Background && ($result['status'] ?? null) !== 'awaiting_user') {
                    $this->deliverReply($conversation, $question, (string) ($result['content'] ?? ''));
                }

                return $result;
            }

            throw new \LogicException("Resuming a question paused on the '{$question->resume_mode}' path is not supported.");
        } catch (BudgetExceededException|RateLimitExceededException $e) {
            // Re-admission refused the continuation; the gate has already
            // left the conversation usable and recorded the refusal.
            throw $e;
        } catch (\Throwable $e) {
            // The winning resolution marked the conversation processing and
            // no sweep ever clears that flag, so a failed continuation must,
            // or the conversation would refuse every later message.
            Conversation::find($conversation->id)?->update(['is_processing' => false]);

            $reason = 'question resume failed: '.get_class($e);
            $recorder->closeStep($question->step_id, RunEndState::Failed, $reason);
            $recorder->closeRun($question->run_id, RunEndState::Failed, $reason);

            Log::error('QuestionResumer: the resumed run failed to continue', [
                'question_id' => $question->id,
                'conversation_id' => $conversation->id,
                'caller' => $caller->value,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Continue the run a question answered from the conversation screen
     * paused. A question paused on the streaming path continues over the
     * conversation's broadcasts; one paused on the synchronous path (a
     * conversation that lives on another channel, answered from the screen)
     * has nobody waiting on its reply, so the reply is pushed to the
     * conversation's own channel.
     *
     * @return array<string, mixed>|null  as resume()
     */
    public function resumeAfterAnswer(AgentQuestion $question): ?array
    {
        $caller = $question->resume_mode === OpenQuestionRequest::RESUME_SYNC
            ? ResumeCaller::Background
            : ResumeCaller::HttpRequest;

        return $this->resume($question->id, $caller);
    }

    /**
     * The human wait of the step a run that ends here (withdrawn, or its
     * context gone) spent paused on its question, so the run's recorded
     * human wait covers it exactly as it does for a run that continues.
     * Measured from the paused turn's millisecond `paused_at` stamp (the
     * question row's own timestamps are whole seconds, which would overstate
     * it); unknown when the paused turn is gone.
     */
    private function waitMs(AgentQuestion $question, ?Message $paused): ?int
    {
        $pausedAt = is_array($paused?->tool_data) ? ($paused->tool_data['paused_at'] ?? null) : null;

        if ($question->step_id === null || !is_string($pausedAt)) {
            return null;
        }

        // Never earlier than the step itself began: `paused_at` is stored to
        // the millisecond and the step's start to the microsecond, so the
        // wait can otherwise exceed the step it is part of by a rounding.
        $from = \Illuminate\Support\Carbon::parse($pausedAt);
        $stepStartedAt = DB::table('agent_run_steps')->where('id', $question->step_id)->value('started_at');
        if ($stepStartedAt !== null && \Illuminate\Support\Carbon::parse($stepStartedAt)->greaterThan($from)) {
            $from = \Illuminate\Support\Carbon::parse($stepStartedAt);
        }

        return max(0, (int) floor($from->diffInMilliseconds(now(), false)));
    }

    /** Push a resumed synchronous run's reply to the conversation's channel. */
    private function deliverReply(Conversation $conversation, AgentQuestion $question, string $content): void
    {
        if (trim($content) === '') {
            return;
        }

        $delivery = app(AgentQuestionService::class)->deliver($conversation, $content);

        if (!$delivery->delivered) {
            Log::warning('QuestionResumer: the resumed run\'s reply could not be delivered', [
                'question_id' => $question->id,
                'conversation_id' => $conversation->id,
                'channel' => $conversation->channel,
                'reason' => $delivery->failureReason,
            ]);
        }
    }

    /**
     * Take the one-time resume claim and, when the context still exists,
     * consume the paused turn. Runs inside the caller's transaction.
     *
     * @return array{0: AgentQuestion, 1: QuestionResolution, 2: ?Conversation, 3: ?Message}|null
     */
    private function claim(string $questionId): ?array
    {
        $now = now();

        $claimed = DB::table('agent_questions')
            ->where('id', $questionId)
            ->where('state', '<>', QuestionState::Pending->value)
            ->whereNull('resumed_at')
            ->update(['resumed_at' => $now, 'updated_at' => $now]);

        if ($claimed !== 1) {
            return null;
        }

        $question = AgentQuestion::findOrFail($questionId);
        $resolution = $this->resolutionFor($question);
        $recorder = app(RunTraceRecorder::class);

        // The action's content becomes the question's final record: how it
        // was resolved, when, after how long, and the answer if any.
        $snapshot = $question->runRecordSnapshot();

        $this->markDisplayMessage($question);

        $conversation = Conversation::find($question->conversation_id);
        $paused = Message::where('id', $question->paused_message_id)->lockForUpdate()->first();

        if ($conversation === null || $paused === null) {
            $recorder->closeAction($question->action_id, ActionOutcome::Failure, self::CONTEXT_DELETED_REASON, $snapshot);

            return [$question, $resolution, $conversation, $paused];
        }

        app(PausedTurnWriter::class)->consume($paused, $resolution->toToolResult($question));

        if ($question->state === QuestionState::Answered) {
            $recorder->closeAction($question->action_id, ActionOutcome::Success, null, $snapshot);
        } else {
            $recorder->closeAction($question->action_id, ActionOutcome::Failure, 'question '.$question->state->value, $snapshot);
        }

        return [$question, $resolution, $conversation, $paused];
    }

    private function resolutionFor(AgentQuestion $question): QuestionResolution
    {
        if ($question->state === QuestionState::Answered) {
            $selected = array_values($question->answer_selected ?? []);

            return new QuestionResolution(
                QuestionState::Answered,
                new ParsedAnswer((string) $question->answer_raw, $selected !== [], $selected),
            );
        }

        return new QuestionResolution($question->state, null, $question->delivery_failure_reason);
    }

    /** Record the question's final state on the message showing it. */
    private function markDisplayMessage(AgentQuestion $question): void
    {
        if ($question->display_message_id === null) {
            return;
        }

        $display = Message::find($question->display_message_id);
        if ($display === null) {
            return;
        }

        $toolData = is_array($display->tool_data) ? $display->tool_data : [];
        $toolData['state'] = $question->state->value;
        $display->tool_data = $toolData;
        $display->save();
    }
}
