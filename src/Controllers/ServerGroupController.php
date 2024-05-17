<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\ServerGroup;
use ClarionApp\LlmClient\Models\Server;
use Illuminate\Http\Request;
use Auth;
use Illuminate\Support\Facades\Log;

class ServerGroupController extends Controller
{
    public function index()
    {
        $serverGroups = ServerGroup::where('user_id', Auth::id())->get();
        return response()->json($serverGroups, 200);
    }

    public function userServerGroups($user_id)
    {
        if(!Auth::user()->can("list user conversations"))
        {
            return response()->json(["message"=>"No permission."], 403);
        }

        $groups = ServerGroup::where('user_id', $user_id)->orderBy('created_at', 'DESC')->get();
        return response()->json($groups, 200);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'token' => 'nullable|string|max:255',
        ]);

        $validatedData['user_id'] = Auth::id();
	Log::info(print_r($validatedData, 1));
        $serverGroup = ServerGroup::create($validatedData);
        return response()->json($serverGroup, 201);
    }

    public function show($id)
    {
        $group = ServerGroup::with('servers')->find($id);
        $servers = Server::where('server_group_id', $id)->get();
        return response()->json($group, 200);
    }

    public function update(Request $request, $id)
    {
        $serverGroup = ServerGroup::find($id);
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'token' => 'nullable|string|max:255',
        ]);

        $serverGroup->update($validatedData);
        return response()->json($serverGroup, 200);
    }

    public function destroy($id)
    {
        $serverGroup = ServerGroup::find($id);
        $serverGroup->delete();
        return response()->json([], 204);
    }
}
