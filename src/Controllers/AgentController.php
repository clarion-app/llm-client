<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\UserSetting;
use ClarionApp\LlmClient\Services\AgentLoopService;
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
                // Resolve server/model defaults
                $serverId = null;
                $modelName = null;

                $userSetting = UserSetting::where('user_id', $user->id)->first();
                if ($userSetting) {
                    $serverId = $userSetting->server_id;
                    $modelName = $userSetting->model;
                }

                if (!$serverId || !$modelName) {
                    $model = LanguageModel::whereHas('server')->first();
                    if (!$model) {
                        return response()->json([
                            'error' => 'No server or model available',
                            'code' => 'no_server',
                        ], 422);
                    }
                    $serverId = $serverId ?: $model->server_id;
                    $modelName = $modelName ?: $model->name;
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
            default => 500,
        };

        $response = [
            'conversation_id' => $conversation->id,
            'message_id' => $result['message_id'] ?? null,
            'content' => $result['content'] ?? '',
            'status' => $result['status'] ?? 'error',
        ];

        if (isset($result['confirmation'])) {
            $response['confirmation'] = $result['confirmation'];
        }

        return response()->json($response, $statusCode);
    }
}
