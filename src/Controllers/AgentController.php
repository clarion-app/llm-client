<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Exceptions\BudgetExceededException;
use ClarionApp\LlmClient\Exceptions\RateLimitExceededException;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\QuestionAnswerIntake;
use ClarionApp\LlmClient\Services\QuestionResumer;
use ClarionApp\LlmClient\Services\RoleResolver;
use ClarionApp\LlmClient\ValueObjects\IntakeResult;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\ResumeCaller;
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
        ]);

        $user = Auth::user();
        $channel = $validated['channel'] ?? 'web';
        $message = (string) ($validated['message'] ?? '');

        // A reply without a conversation id answers the user's most recently
        // asked pending question on this channel, however long ago it was
        // asked and whatever the conversation's processing flag says.
        $questionConversation = empty($validated['conversation_id'])
            ? $this->conversationAwaitingAnswer((string) $user->id, $channel)
            : null;

        $answered = null;

        if ($questionConversation !== null) {
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
            [$answered, $refusal] = $this->acceptAnswer($conversation, (string) $user->id, $message);
            if ($refusal !== null) {
                return $refusal;
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
            // Lookup recent conversation by user + channel + inactivity threshold
            $thresholdHours = config('llm-client.conversation.inactivity_threshold_hours', 4);
            $conversation = Conversation::where('user_id', $user->id)
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
                $result = $agentLoopService->run($conversation, $message);
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
            // A conversation work ceiling correctly stopping a response is
            // not a server failure — it is a policy-correct outcome that
            // deserves its own, more precise status than the sibling
            // max_iterations outcome currently, imprecisely, falls into.
            'stopped' => 200,
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

        return response()->json($response, $statusCode);
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

        if ($intake->kind === IntakeResult::ANSWERED) {
            return [$intake->question, null];
        }

        return [null, null];
    }
}
