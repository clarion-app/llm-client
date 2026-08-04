<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Jobs\RefreshServerModelsJob;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\ServerStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        // Dispatch model refresh job.
        $triggeredBy = Auth::id();
        RefreshServerModelsJob::dispatch($server->id, $triggeredBy);

        return response()->json($server, 201);
    }

    public function show(Server $server)
    {
        return response()->json($server, 200);
    }

    public function update(Request $request, $id)
    {
        $server = Server::findOrFail($id);
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'server_url' => 'required|string|max:255',
            'token' => 'nullable|string|max:255',
        ]);

        $server->update($validatedData);

        // Dispatch model refresh job.
        $triggeredBy = Auth::id();
        RefreshServerModelsJob::dispatch($server->id, $triggeredBy);

        return response()->json($server, 200);
    }

    public function destroy($id)
    {
        $server = Server::findOrFail($id);
        $server->delete();
        return response()->json([], 204);
    }
}
