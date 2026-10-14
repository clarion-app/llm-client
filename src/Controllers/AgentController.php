<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Exceptions\BudgetExceededException;
use ClarionApp\LlmClient\Exceptions\RateLimitExceededException;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\LateReplyNotifier;
use ClarionApp\LlmClient\Services\QuestionAnswerIntake;
use ClarionApp\LlmClient\Services\QuestionResumer;
use ClarionApp\LlmClient\Services\RoleResolver;
use ClarionApp\LlmClient\Services\TaskBudgetDirectiveParser;
use ClarionApp\LlmClient\Services\TaskBudgetService;
use ClarionApp\LlmClient\Services\TaskBudgetText;
use ClarionApp\LlmClient\ValueObjects\IntakeResult;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\ResumeCaller;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetState;
use Illuminate\Http\Request;
use Auth;
use Illuminate\Support\Facades\Log;

class AgentController extends Controller
{
    public function __invoke(Request $request)
    {
        // `message` may be blank only so a blank reply to a pending question
        // can be refused with answer_required; without a pending question a
        // blank message is the same validation error it always was.
        $validated = $request->validate([
            'message' => 'present|nullable|string',
            'conversation_id' => 'nullable|string|exists:conversations,id',
            'channel' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/'],
            'task_budget' => ['sometimes', 'nullable', 'array'],
        ] + TaskBudgetController::rules('task_budget.'));

        $user = Auth::user();
        $channel = $validated['channel'] ?? 'web';
        $message = (string) ($validated['message'] ?? '');
        $bodyBudget = $validated['task_budget'] ?? null;

        // A "/budget" line is read first — before the message can be taken
        // as an answer or refused while the conversation is busy: changing
        // or viewing the ceilings of a running or paused task is exactly
        // what a user does while it is busy or waiting on a ceiling prompt.
        $directive = (new TaskBudgetDirectiveParser())->parse($message);
        if ($directive['malformed'] || $directive['viewOnly'] || $directive['directiveOnly']) {
            return $this->handleDirective($directive, $bodyBudget, $validated['conversation_id'] ?? null, (string) $user->id, $channel);
        }
        if ($directive['stated'] !== null) {
            $message = $directive['remaining'];
        }
        $stated = TaskBudgetController::statedFrom($bodyBudget, $directive['stated']);

        // A reply without a conversation id answers the user's most recently
        // asked pending question on this channel, however long ago it was
        // asked and whatever the conversation's processing flag says.
        $questionConversation = empty($validated['conversation_id'])
            ? $this->conversationAwaitingAnswer((string) $user->id, $channel)
            : null;

        $answered = null;
        // The change made by ceilings stated with a reply to a waiting
        // question (a reply starts no new request of its own).
        $changedWithReply = null;

        if ($questionConversation !== null) {
            $changedWithReply = app(TaskBudgetService::class)->changeWithReply($questionConversation, $stated, (string) $user->id);
            [$answered, $refusal] = $this->acceptAnswer($questionConversation, (string) $user->id, $message);
            if ($refusal !== null) {
                return $refusal;
            }

            // Resolved by something else (expiry, withdrawal) a moment
            // earlier: the reply is an ordinary message, handled by the
            // ordinary lookup below rather than run in the conversation
            // whose resolution may already be resuming.
            if ($answered === null) {
                $questionConversation = null;
            }
        }

        // Only the requester's own pending question lets a blank message
        // through, so a blank message can never reveal whether someone
        // else's conversation is waiting on its user.
        if ($answered === null && trim($message) === '') {
            $hasPendingQuestion = !empty($validated['conversation_id'])
                && AgentQuestion::where('pending_conversation_id', $validated['conversation_id'])
                    ->where('user_id', $user->id)
                    ->exists();

            if (!$hasPendingQuestion) {
                $request->validate(['message' => 'required|string']);
            }
        }

        $result = null;

        if ($questionConversation !== null) {
            $conversation = $questionConversation;
        } elseif (!empty($validated['conversation_id'])) {
            $conversation = Conversation::find($validated['conversation_id']);

            if (!$conversation) {
                return response()->json([
                    'error' => 'Conversation not found',
                    'code' => 'conversation_not_found',
                ], 404);
            }

            if ($conversation->user_id !== $user->id) {
                return response()->json([
                    'error' => 'Forbidden',
                    'code' => 'forbidden',
                ], 403);
            }

            // Taken as an answer before the processing check: the reply
            // to a pending question is exactly what the run is waiting for.
            $changedWithReply = app(TaskBudgetService::class)->changeWithReply($conversation, $stated, (string) $user->id);
            [$answered, $refusal] = $this->acceptAnswer($conversation, (string) $user->id, $message);
            if ($refusal !== null) {
                return $refusal;
            }

            // A reply after the conversation's latest question expired on its
            // assumption is told so before anything else can refuse it, so a
            // conversation still busy with the run continuing on that
            // assumption still explains why the reply was not an answer.
            if (!$answered) {
                app(LateReplyNotifier::class)->noticeFor($conversation, (string) $user->id);
            }

            // Read fresh: a question resolved a moment ago (e.g. by expiry)
            // has already marked the conversation processing for its resumed
            // run, and this message must not start a second, concurrent one.
            if (!$answered && Conversation::whereKey($conversation->id)->value('is_processing')) {
                return response()->json([
                    'error' => 'Conversation is already processing',
                    'code' => 'processing',
                ], 409);
            }
        } else {
            // A reply after a question on this channel expired on its
            // assumption belongs with that question's conversation, however
            // long ago it was last active: the user is told there, once, that
            // the reply came too late — even when the conversation has since
            // ended (the idle timeout ends it soon after the run that
            // continued on the assumption finished, long before a user who
            // missed the deadline usually replies). The reply itself goes
            // into that conversation only while it is open and free; while
            // the continuing run still has it, or once it has ended, the
            // reply goes where an ordinary reply would, so no second run
            // starts beside the continuing one.
            $conversation = null;
            $assumedIn = $this->conversationOwedLateReplyNotice((string) $user->id, $channel);

            if ($assumedIn !== null) {
                app(LateReplyNotifier::class)->noticeFor($assumedIn, (string) $user->id);

                $current = Conversation::whereKey($assumedIn->id)->first(['is_processing', 'ended_at']);
                if ($current !== null && !$current->is_processing && $current->ended_at === null) {
                    $conversation = $assumedIn;
                }
            }

            // Lookup recent conversation by user + channel + inactivity threshold
            $thresholdHours = config('llm-client.conversation.inactivity_threshold_hours', 4);
            $conversation ??= Conversation::where('user_id', $user->id)
                ->where('channel', $channel)
                ->where('updated_at', '>', now()->subHours($thresholdHours))
                ->where('is_processing', false)
                ->latest('updated_at')
                ->first();

            if (!$conversation) {
                // Resolve server/model via RoleResolver
                $serverId = null;
                $modelName = null;

                $resolution = app(RoleResolver::class)->resolve(ModelRole::Inference, $user->id);
                if ($resolution->hasEffectiveModel()) {
                    $serverId = $resolution->server->id;
                    $modelName = $resolution->model;
                }

                if (!$serverId || !$modelName) {
                    return response()->json([
                        'error' => 'No inference model is assigned',
                        'code' => 'no_server',
                    ], 422);
                }

                $conversation = Conversation::create([
                    'user_id' => $user->id,
                    'server_id' => $serverId,
                    'model' => $modelName,
                    'character' => 'Clarion',
                    'channel' => $channel,
                ]);
            }
        }

        try {
            if ($answered !== null) {
                // Intake recorded the answer; the run it paused continues
                // synchronously and its result is this request's response.
                $result = app(QuestionResumer::class)->resume($answered->id, ResumeCaller::HttpRequest)
                    ?? ['status' => 'stopped', 'content' => '', 'message_id' => null];
            } else {
                $agentLoopService = app(AgentLoopService::class);
                $result = $stated === null
                    ? $agentLoopService->run($conversation, $message)
                    : $agentLoopService->run($conversation, $message, ['task_budget' => $stated]);
            }
        } catch (BudgetExceededException $e) {
            // Structural, rather than an instanceof test inside the blanket
            // catch below, so the intent is visible at a glance: a ceiling
            // refusal is a decision with its own 402 body, not an unexplained
            // failure. Left to the catch below it would surface as exactly the
            // generic 500 this feature exists to replace.
            throw $e;
        } catch (RateLimitExceededException $e) {
            // Same reasoning, second refusal type: a rate-limit refusal is a
            // decision with its own 429 body, not an unexplained failure.
            throw $e;
        } catch (\Throwable $e) {
            Log::error('AgentController: agent loop error', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Agent loop error: ' . $e->getMessage(),
                'code' => 'internal_error',
            ], 500);
        }

        $statusCode = match ($result['status'] ?? 'error') {
            'completed' => 200,
            'confirmation_required' => 202,
            // The agent asked the user a question, already delivered through
            // the conversation's channel; the caller sends nothing.
            'awaiting_user' => 202,
            // The model provider is unavailable for the moment: the task is
            // still working, its retry is scheduled, and its reply will
            // arrive on the conversation's channel.
            'continuing' => 202,
            // A conversation work ceiling correctly stopping a response is
            // not a server failure — it is a policy-correct outcome that
            // deserves its own, more precise status than the sibling
            // max_iterations outcome currently, imprecisely, falls into.
            'stopped' => 200,
            'decision_required' => 200,
            'budget_updated' => 200,
            'directive_malformed' => 200,
            default => 500,
        };

        $response = [
            'conversation_id' => $conversation->id,
            'message_id' => $result['message_id'] ?? null,
            'content' => $result['content'] ?? '',
            'status' => $result['status'] ?? 'error',
        ];

        // The agent loop names *which* ceiling or condition ended a response
        // whenever one did. Dropping that key here would leave an HTTP caller
        // with the status alone, unable to tell a work-ceiling stop apart from
        // any other limit by anything other than prose — the exact ambiguity
        // the distinct code values exist to remove.
        if (isset($result['code'])) {
            $response['code'] = $result['code'];
        }

        if (isset($result['confirmation'])) {
            $response['confirmation'] = $result['confirmation'];
        }

        if (isset($result['question_id'])) {
            $response['question_id'] = $result['question_id'];
        }

        // A reply to a waiting question started no request: only a change
        // its stated ceilings actually made can have been reduced.
        $line = $answered !== null
            ? ($changedWithReply === null ? null : app(TaskBudgetService::class)->reducedLine($stated, $changedWithReply['reduced']))
            : app(TaskBudgetService::class)->reducedLine($stated);
        if ($line !== null) {
            $response['task_budget_notice'] = $line;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Answers a directive-only, bare or malformed "/budget" message without
     * running anything. Without a conversation id it targets the user's
     * most recently updated conversation on the channel whose task is
     * running or paused.
     */
    private function handleDirective(array $directive, ?array $bodyBudget, ?string $conversationId, string $userId, string $channel)
    {
        $text = new TaskBudgetText();
        if ($conversationId !== null) {
            $conversation = Conversation::find($conversationId);
            if (!$conversation) {
                return response()->json(['error' => 'Conversation not found', 'code' => 'conversation_not_found'], 404);
            }
            if ($conversation->user_id !== $userId) {
                return response()->json(['error' => 'Forbidden', 'code' => 'forbidden'], 403);
            }
        } else {
            $id = Conversation::query()
                ->join('task_budgets', 'task_budgets.id', '=', 'conversations.active_task_budget_id')
                ->where('conversations.user_id', $userId)
                ->where('conversations.channel', $channel)
                ->whereNull('conversations.deleted_at')
                ->whereIn('task_budgets.state', [TaskBudgetState::Running->value, TaskBudgetState::Paused->value])
                ->orderByDesc('conversations.updated_at')
                ->value('conversations.id');
            $conversation = $id === null ? null : Conversation::find($id);
        }
        if ($directive['malformed']) {
            return response()->json(['status' => 'directive_malformed', 'content' => $text->directiveMalformed(), 'conversation_id' => $conversation?->id], 200);
        }
        $tasks = app(TaskBudgetService::class);
        $task = $conversation === null ? null : $tasks->currentFor($conversation);
        if ($task === null || $task->state->isTerminal()) {
            return response()->json(['status' => 'budget_updated', 'content' => $text->noTaskInProgress(), 'conversation_id' => $conversation?->id], 200);
        }
        if ($directive['viewOnly']) {
            return response()->json(['status' => 'budget_updated', 'content' => $text->standing($tasks->standing($task)), 'conversation_id' => $conversation->id], 200);
        }
        // A body field sent beside the line wins key by key, as it does for
        // a request.
        $changed = $tasks->changeFromConversation($conversation, $task, TaskBudgetController::statedFrom($bodyBudget, $directive['stated']), $userId);
        if ($changed === null) {
            return response()->json(['status' => 'budget_updated', 'content' => $text->noTaskInProgress(), 'conversation_id' => $conversation->id], 200);
        }

        return response()->json(['status' => 'budget_updated', 'content' => $changed['content'], 'conversation_id' => $conversation->id], 200);
    }

    /**
     * The user's conversation on $channel holding the most recently asked
     * question still waiting for an answer — pending, or stopped at its
     * deadline and waiting for a late one — if any.
     */
    private function conversationAwaitingAnswer(string $userId, string $channel): ?Conversation
    {
        $conversationId = AgentQuestion::query()
            ->join('conversations', 'conversations.id', '=', 'agent_questions.pending_conversation_id')
            ->where('agent_questions.user_id', $userId)
            ->whereIn('agent_questions.state', [QuestionState::Pending->value, QuestionState::Stopped->value])
            ->where('conversations.user_id', $userId)
            ->where('conversations.channel', $channel)
            ->whereNull('conversations.deleted_at')
            ->orderByDesc('agent_questions.asked_at')
            ->value('agent_questions.pending_conversation_id');

        return $conversationId === null ? null : Conversation::find($conversationId);
    }

    /**
     * The user's conversation on $channel (not deleted; possibly ended)
     * whose most recently asked question expired on its assumption and has
     * not yet had its late-reply notice, if any — the most recently asked
     * such question wins.
     */
    private function conversationOwedLateReplyNotice(string $userId, string $channel): ?Conversation
    {
        $conversationId = AgentQuestion::query()
            ->join('conversations', 'conversations.id', '=', 'agent_questions.conversation_id')
            ->where('agent_questions.user_id', $userId)
            ->where('agent_questions.state', QuestionState::ExpiredAssumed->value)
            ->whereNull('agent_questions.late_reply_noticed_at')
            ->where('conversations.user_id', $userId)
            ->where('conversations.channel', $channel)
            ->whereNull('conversations.deleted_at')
            ->whereNotExists(function ($newer) {
                $newer->selectRaw('1')
                    ->from('agent_questions as newer')
                    ->whereColumn('newer.conversation_id', 'agent_questions.conversation_id')
                    ->whereColumn('newer.asked_at', '>', 'agent_questions.asked_at');
            })
            ->orderByDesc('agent_questions.asked_at')
            ->value('agent_questions.conversation_id');

        return $conversationId === null ? null : Conversation::find($conversationId);
    }

    /**
     * Offer the message to the conversation's pending question, if it has
     * one.
     *
     * @return array{0: ?AgentQuestion, 1: ?\Illuminate\Http\JsonResponse}
     *   the answered question, or the response refusing a blank reply
     */
    private function acceptAnswer(Conversation $conversation, string $userId, string $message): array
    {
        $intake = app(QuestionAnswerIntake::class)->accept($conversation, $userId, $message);

        if ($intake->kind === IntakeResult::ANSWER_REQUIRED) {
            return [null, response()->json([
                'code' => 'answer_required',
                'error' => 'An answer is required to reply to the pending question.',
            ], 422)];
        }

        if ($intake->kind === IntakeResult::DECISION_REQUIRED) {
            return [null, response()->json([
                'status' => 'decision_required',
                'content' => $intake->guidance,
                'conversation_id' => $conversation->id,
            ], 200)];
        }

        if ($intake->kind === IntakeResult::DECISION_RECORDED) {
            return [null, response()->json([
                'status' => 'awaiting_user',
                'content' => '',
                'question_id' => $intake->question?->id,
                'conversation_id' => $conversation->id,
            ], 202)];
        }

        if ($intake->kind === IntakeResult::ANSWERED) {
            return [$intake->question, null];
        }

        return [null, null];
    }
}
