<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\Server;
use Illuminate\Http\Request;

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
        ]);

        $server = Server::create($validatedData);
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

    public function destroy(Server $server)
    {
        $server->delete();
        return response()->json([], 204);
    }
}
