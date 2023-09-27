<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\ServerGroup;
use Illuminate\Http\Request;

class ServerGroupController extends Controller
{
    public function index()
    {
        $serverGroups = ServerGroup::all();
        return response()->json($serverGroups, 200);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $serverGroup = ServerGroup::create($validatedData);
        return response()->json($serverGroup, 201);
    }

    public function show(ServerGroup $serverGroup)
    {
        return response()->json($serverGroup, 200);
    }

    public function update(Request $request, ServerGroup $serverGroup)
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $serverGroup->update($validatedData);
        return response()->json($serverGroup, 200);
    }

    public function destroy(ServerGroup $serverGroup)
    {
        $serverGroup->delete();
        return response()->json([], 204);
    }
}
