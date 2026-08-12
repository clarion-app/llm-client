<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Exceptions\BudgetExceededException;
use ClarionApp\LlmClient\Exceptions\RateLimitExceededException;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\RoleResolver;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Illuminate\Http\Request;
use Auth;
use Illuminate\Support\Facades\Log;

class AgentController extends Controller
{
    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string',
            'conversation_id' => 'nullable|string|exists:conversations,id',
            'channel' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/'],
        ]);

        $user = Auth::user();
        $channel = $validated['channel'] ?? 'web';

        // If a specific conversation_id is provided, use it
        if (!empty($validated['conversation_id'])) {
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

            if ($conversation->is_processing) {
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
            $agentLoopService = app(AgentLoopService::class);
            $result = $agentLoopService->run($conversation, $validated['message']);
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

        return response()->json($response, $statusCode);
    }
}
