<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\UserSetting;
use Illuminate\Http\Request;
use Auth;
use Illuminate\Support\Facades\Log;
use ClarionApp\LlmClient\Requests\ChooseApiApplicationsRequest;
use ClarionApp\LlmClient\Responses\HandleGenerateApiCallsResponse;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;

class ConversationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $userId = Auth::id();
        $searchTerm = $request->query('search');
        $query = Conversation::where('user_id', $userId);

        if(strlen($searchTerm))
        {
            $query->whereHas('messages', function ($query) use ($searchTerm) {
                $query->where('content', 'like', "%{$searchTerm}%");
            })->with(['messages' => function ($query) use ($searchTerm) {
                $query->where('content', 'like', "%{$searchTerm}%");
            }]);
        }
        else
        {
            $query->with('latest_message');
        }

        $conversations = $query->orderBy('created_at', 'DESC')->get();
        return response()->json($conversations, 200);
    }

    public function userConversations($user_id)
    {
        if(!Auth::user()->can("list user conversations"))
        {
            return response()->json(["message"=>"No permission."], 403);
        }

        $conversations = Conversation::where('user_id', $user_id)->orderBy('created_at', 'DESC')->get();
        return response()->json($conversations, 200);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created conversation in storage.
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'title' => 'nullable|string',
            'model' => 'nullable|string',
            'server_id' => 'nullable|string',
        ]);

        $validatedData['user_id'] = Auth::id();
        $validatedData['character'] = "Clarion";

        // Use validated server_id/model if provided, otherwise fall back to UserSetting, then first available
        $serverId = $validatedData['server_id'] ?? null;
        $modelName = $validatedData['model'] ?? null;

        if (!$serverId || !$modelName) {
            $userSetting = UserSetting::where('user_id', Auth::id())->first();
            if ($userSetting) {
                $serverId = $serverId ?: $userSetting->server_id;
                $modelName = $modelName ?: $userSetting->model;
            }
        }

        if (!$serverId || !$modelName) {
            $model = LanguageModel::whereHas('server')->first();
            if (!$model) {
                return response()->json(['message' => 'No server or model available. Please configure a server first.'], 422);
            }
            $serverId = $serverId ?: $model->server_id;
            $modelName = $modelName ?: $model->name;
        }

        $validatedData['server_id'] = $serverId;
        $validatedData['model'] = $modelName;

        $conversation = Conversation::create($validatedData);
        
        Message::create([
            'conversation_id'=>$conversation->id,
            'role'=>"Assistant",
            'user'=>"Clarion",
            'content'=>"How can I help you today?",
            'responseTime'=>0
        ]);

        return response()->json($conversation, 201);
    }

    /**
     * Store a newly created command conversation in storage.
     */
    public function storeCommand(Request $request)
    {
        $validatedData = $request->validate([
            'command' => 'string',
        ]);

        $req = new ChooseApiApplicationsRequest($validatedData['command']);
        $req->sendChooseApplications();

        return response()->json($req->conversation, 201);
    }

    /**
     * Display the specified conversation.
     */
    public function show($id)
    {
        $conversation = Conversation::findOrFail($id);

        if ($conversation->user_id !== Auth::id()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $conversation->messages = Message::where('conversation_id', $id)->orderBy('created_at', 'ASC')->get();
        return $conversation;
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Conversation $conversation)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $validatedData = $request->validate([
            'title' => 'required|string',
            'model' => 'required|string',
            'server_id' => 'required|string'
        ]);

        $conversation = Conversation::findOrFail($id);

        if ($conversation->user_id !== Auth::id()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $conversation->update($validatedData);

        return response()->json($conversation, 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $conversation = Conversation::findOrFail($id);

        if ($conversation->user_id !== Auth::id()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $conversation->delete();

        return response()->noContent();
    }

    public function generateTitle($id)
    {
        $conversation = Conversation::findOrFail($id);

        if ($conversation->user_id !== Auth::id()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request = new \ClarionApp\LlmClient\OpenAIGenerateConversationTitleRequest($conversation);
        $request->sendGenerateConversationTitle();
    }

    public function confirmApiCall(Request $request, $id)
    {
        $conversation = Conversation::findOrFail($id);

        if ($conversation->user_id !== Auth::id()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'approved' => 'required|boolean',
            'message_id' => 'required|string',
        ]);

        $message = Message::where('id', $validated['message_id'])
            ->where('conversation_id', $conversation->id)
            ->first();

        if (!$message) {
            return response()->json(['message' => 'Pending call not found'], 404);
        }

        $pendingData = json_decode($message->content, true);
        if (!$pendingData || !isset($pendingData['__pending_api_call'])) {
            return response()->json(['message' => 'No pending API call for this message'], 422);
        }

        if ($validated['approved']) {
            // Execute the stored call
            $result = (object) $pendingData;
            $handler = new HandleGenerateApiCallsResponse();

            // We need to set the conversation on the handler via reflection since it's protected
            $reflection = new \ReflectionClass($handler);
            $prop = $reflection->getProperty('conversation');
            $prop->setAccessible(true);
            $prop->setValue($handler, $conversation);

            $handler->executeApiCall($result, 0);

            // Mark message as executed
            $pendingData['__executed'] = true;
            $message->content = json_encode($pendingData);
            $message->save();

            return response()->json(['message' => 'API call executed'], 200);
        } else {
            // Mark as cancelled
            $pendingData['__cancelled'] = true;
            $message->content = json_encode($pendingData);
            $message->save();

            $cancelMsg = Message::create([
                'conversation_id' => $conversation->id,
                'role' => 'system',
                'user' => 'System',
                'content' => 'User denied the API call: ' . $pendingData['method'] . ' ' . $pendingData['path'],
                'responseTime' => 0,
            ]);
            event(new NewConversationMessageEvent($conversation->id, $cancelMsg->id));
            event(new FinishOpenAIConversationResponseEvent($conversation->id, $cancelMsg->content));

            return response()->json(['message' => 'API call cancelled'], 200);
        }
    }
}
