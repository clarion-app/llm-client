<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Conversation;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Conversation::all();
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
        $request->validate([
            'title' => 'required|string',
            'model' => 'required|string',
            'character' => 'required|string',
        ]);

        $conversation = Conversation::create($request->all());

        return response()->json($conversation, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Conversation $conversation)
    {
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
    public function update(Request $request, Conversation $conversation)
    {
        $request->validate([
            'title' => 'required|string',
            'model' => 'required|string',
            'character' => 'required|string',
        ]);

        $conversation->update($request->all());

        return response()->json($conversation, 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Conversation $conversation)
    {
        $conversation->delete();

        return response()->noContent();
    }
}
