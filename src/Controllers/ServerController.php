<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\Server;
use Illuminate\Http\Request;
use ClarionApp\LlmClient\OpenAIModelsRequest;
use Illuminate\Support\Facades\Log;

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
            'name' => 'required|string|max:255',
            'server_url' => 'required|string|max:255',
            'token' => 'nullable|string|max:255',
        ]);

        $server = Server::create($validatedData);

	    $modelRequest = new OpenAIModelsRequest();
        $modelRequest->getLanguageModels($server->id);

        return response()->json($server, 201);
    }

    public function show(Server $server)
    {
        return response()->json($server, 200);
    }

    public function update(Request $request, $id)
    {
        $server = Server::find($id);
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'server_url' => 'required|string|max:255',
            'token' => 'nullable|string|max:255',
        ]);

        $server->update($validatedData);
        $modelRequest = new OpenAIModelsRequest();
        $modelRequest->getLanguageModels($server->id);
        return response()->json($server, 200);
    }

    public function destroy($id)
    {
        $server = Server::find($id);
        $server->delete();
        return response()->json([], 204);
    }
}
