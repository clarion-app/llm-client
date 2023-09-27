<?php

namespace ClarionApp\LlmClient\Controllers;

use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Conversation;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function index()
    {
        $messages = Message::all();
        return response()->json($messages, 200);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'content' => 'required|string',
            'role' => 'required|enum:assistant,user,system',
            'user' => 'required|string',
            'responseTime' => 'nullable|integer',
            'conversation_id' => 'required|exists:conversations,id'
        ]);

        $message = Message::create($validatedData);
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

        $message->update($validatedData);
        return response()->json($message, 200);
    }

    public function destroy(Message $message)
    {
        $message->delete();
        return response()->json([], 204);
    }
}
