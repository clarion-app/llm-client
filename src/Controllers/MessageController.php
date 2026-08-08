<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ServerGroup;
use Illuminate\Http\Request;
use Auth;
use ClarionApp\HttpQueue\HttpRequest;
use ClarionApp\HttpQueue\SendHttpRequest;
use Illuminate\Support\Facades\Log;
use ClarionApp\LlmClient\OpenAIConversationRequest;
use ClarionApp\LlmClient\OpenAIConversationStreamRequest;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\BudgetGate;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;

class MessageController extends Controller
{
    public function index($conversation_id)
    {
        //TODO: Implement Spatie permissions
        $conversation = Conversation::find($conversation_id);
        if(!$conversation) return response()->json([], 404);
        
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

        // Before Message::create(), not after. A refused request must leave the
        // stored history byte-identical, and the user's own turn is the half
        // that is easy to miss: nothing downstream can tell a stored message
        // for work that never happened apart from one that was answered —
        // condensation will summarise it, memory capture will remember it, and
        // the next turn's context will include it.
        //
        // This is an ordering requirement layered on the funnel, not a second
        // decision point: it calls the same BudgetGate, and the check inside
        // start() below remains as defence in depth, free because the gate
        // admits a scope once per request.
        app(BudgetGate::class)->admit(
            (string) Auth::id(),
            BudgetWorkKind::Interactive,
            $conversation->id,
        );

        $validatedData['role'] = "user";
        $validatedData['user'] = Auth::user()->name;

        $message = Message::create($validatedData);

        if (!$conversation->is_processing) {
            $agentLoopService = app(AgentLoopService::class);
            $agentLoopService->start($conversation, 1, null, $message->id);
        }

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
            'role' => 'required|in:assistant,user,system',
            'user' => 'required|string',
            'responseTime' => 'nullable|integer',
            'conversation_id' => 'required|exists:conversations,id'
        ]);

        $conversationId = $validatedData['conversation_id'];
        $conversation = Conversation::find($conversationId);
        if (!$conversation || $conversation->user_id != Auth::id())
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
