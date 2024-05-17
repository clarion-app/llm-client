<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ServerGroup;
use ClarionApp\LlmClient\Models\Server;
use Illuminate\Http\Request;
use Auth;
use ClarionApp\HttpQueue\HttpRequest;
use ClarionApp\HttpQueue\SendHttpRequest;
use Illuminate\Support\Facades\Log;
use ClarionApp\LlmClient\OpenAIConversationRequest;
use ClarionApp\LlmClient\OpenAIConversationStreamRequest;

class MessageController extends Controller
{
    public function index($conversation_id)
    {
        //TODO: Implement Spatie permissions
        $conversation = Conversation::find($conversation_id);
        if($conversation->user_id != Auth::id())
        {
            if(!Auth::user()->can('list users')) return response()->json([], 403);
        }

        $messages = Message::where('conversation_id', $conversation_id)->orderBy('created_at')->get();
        foreach($messages as &$message) $message->streaming = false;
        return response()->json($messages, 200);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'content' => 'required|string',
            'conversation_id' => 'required|exists:conversations,id'
        ]);

        $conversation = Conversation::find($validatedData['conversation_id']);
        if($conversation->user_id != Auth::id())
        {
            return response()->json([], 403);
        }

        $validatedData['role'] = "user";
        $validatedData['user'] = Auth::user()->name;

        $message = Message::create($validatedData);

        $r = new OpenAIConversationStreamRequest($conversation);
        $r->sendConversation();

        return response()->json($message, 201);
    }

    public function show(Message $message)
    {
        return response()->json($message, 200);
    }

    public function update(Request $request, Message $message)
    {
        $validatedData = $request->validate([
            'content' => 'required|string',
            'role' => 'required|enum:assistant,user,system',
            'user' => 'required|string',
            'responseTime' => 'nullable|integer',
            'conversation_id' => 'required|exists:conversations,id'
        ]);

        $conversation = Conversation::find($message->conversation_id);
        if($conversation->user_id != Auth::id())
        {
            return response()->json([], 403);
        }

        $message->update($validatedData);
        return response()->json($message, 200);
    }

    public function destroy($id)
    {
        $message = Message::with('conversation')->find($id);

        if($message->conversation->user_id != Auth::id())
        {
            return response()->json([], 403);
        }

        $message->delete();
        return response()->json([], 204);
    }
}
