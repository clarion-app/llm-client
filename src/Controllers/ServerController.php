<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\Server;
use Illuminate\Http\Request;
use ClarionApp\LlmClient\OpenAIModelsRequest;

class ServerController extends Controller
{
    public function index()
    {
        $servers = Server::all();
        return response()->json($servers, 200);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'server_group_id' => 'required|string|max:36',
            'server_url' => 'required|string|max:255'
        ]);

        $server = Server::create($validatedData);

	$modelRequest = new OpenAIModelsRequest();
        $modelRequest->getLanguageModels($server->server_group_id);

        return response()->json($server, 201);
    }

    public function show(Server $server)
    {
        return response()->json($server, 200);
    }

    public function update(Request $request, Server $server)
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $server->update($validatedData);
        return response()->json($server, 200);
    }

    public function destroy($id)
    {
        $server = Server::find($id);
        $server->delete();
        return response()->json([], 204);
    }
}
