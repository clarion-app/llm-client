<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use Illuminate\Http\Request;
use Auth;
use Illuminate\Support\Facades\Log;

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
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'title' => 'nullable|string',
            'model' => 'required|string',
            'server_id' => 'required|string',
        ]);

        $validatedData['user_id'] = Auth::id();
        $validatedData['character'] = "Clarion";
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
     * Display the specified resource.
     */
    public function show($id)
    {
        $conversation = Conversation::find($id);
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
        $request->validate([
            'title' => 'required|string',
            'model' => 'required|string',
            'server_id' => 'required|string'
        ]);

        $conversation = Conversation::find($id);
        $conversation->update($request->all());

        return response()->json($conversation, 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $conversation = Conversation::find($id);
        $conversation->delete();

        return response()->noContent();
    }

    public function generateTitle($id)
    {
        $conversation = Conversation::find($id);
        $request = new \ClarionApp\LlmClient\OpenAIGenerateConversationTitleRequest($conversation);
        $request->sendGenerateConversationTitle();
    }
}
