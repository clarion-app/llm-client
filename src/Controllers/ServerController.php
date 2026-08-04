<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Jobs\RefreshServerModelsJob;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\ServerStatus;
use ClarionApp\LlmClient\ValueObjects\ServerAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ServerController extends Controller
{
    /**
     * Transform a Server model into the API response array.
     * Adds has_token (boolean) and ensures token is never exposed.
     */
    private function serverToArray(Server $server): array
    {
        $arr = $server->toArray();
        // Use the casted (decrypted) value to determine has_token.
        // getAttributes() returns the raw encrypted string which is always non-empty.
        $arr['has_token'] = !empty($server->token);
        // Ensure token is never in the response (belt-and-suspenders).
        unset($arr['token']);
        return $arr;
    }

    public function index()
    {
        $servers = Server::all()->map(fn ($s) => $this->serverToArray($s));
        return response()->json($servers, 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'server_url' => 'required|string|max:255',
            'token' => 'nullable|string|max:255',
            'provider_type' => [
                'sometimes',
                'string',
                Rule::in(array_map(fn ($pt) => $pt->value, ProviderType::cases())),
            ],
        ]);

        // Normalize server_url through ServerAddress before storage (FR-032).
        try {
            $address = ServerAddress::fromInput($validated['server_url']);
            $validated['server_url'] = (string) $address;
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'errors' => [
                    'server_url' => [
                        sprintf('Invalid server address "%s": %s',
                            $validated['server_url'],
                            $e->getMessage(),
                        ),
                    ],
                ],
            ], 422);
        }

        // Default provider_type to 'openai' if not provided.
        if (!isset($validated['provider_type'])) {
            $validated['provider_type'] = ProviderType::OpenAI->value;
        }

        $server = Server::create($validated);

        // Dispatch model refresh job with triggered_by = Auth::id().
        $triggeredBy = Auth::id();
        RefreshServerModelsJob::dispatch($server->id, $triggeredBy);

        return response()->json($this->serverToArray($server), 201);
    }

    public function show($id)
    {
        $server = Server::find($id);
        if (!$server) {
            return response()->json(['error' => 'Server not found'], 404);
        }
        $arr = $this->serverToArray($server);
        return response()->json($arr, 200);
    }

    public function update(Request $request, $id)
    {
        $server = Server::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'server_url' => 'required|string|max:255',
            'token' => 'nullable|string|max:255',
            'provider_type' => [
                'sometimes',
                'string',
                Rule::in(array_map(fn ($pt) => $pt->value, ProviderType::cases())),
            ],
        ]);

        // Normalize server_url through ServerAddress before storage.
        try {
            $address = ServerAddress::fromInput($validated['server_url']);
            $validated['server_url'] = (string) $address;
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'errors' => [
                    'server_url' => [
                        sprintf('Invalid server address "%s": %s',
                            $validated['server_url'],
                            $e->getMessage(),
                        ),
                    ],
                ],
            ], 422);
        }

        // Handle token semantics: absent = preserve, null/empty = clear, non-empty = replace.
        // Use the casted (decrypted) value for comparison, not the raw encrypted string.
        $originalTokenCasted = $server->token; // Decrypted value via 'encrypted' cast.
        $tokenKeyPresent = array_key_exists('token', $validated);
        if ($tokenKeyPresent) {
            if ($validated['token'] === null || $validated['token'] === '') {
                $validated['token'] = null;
            }
        }
        // If token key is absent, simply remove it from $validated so update()
        // won't touch the column at all (preserving whatever is in the DB).

        // Determine if server_url, token, or provider_type actually changed.
        // An omitted provider_type key means "keep the stored value" (same
        // semantics as an omitted token) — it must never be read as "changed
        // to the default", or a rename-only PUT on a non-default-provider
        // server would incorrectly trigger a refresh job.
        $originalUrl = $server->getAttributes()['server_url'];
        $originalProviderType = $server->getAttributes()['provider_type'] ?? 'openai';
        $providerTypeKeyPresent = array_key_exists('provider_type', $validated);
        $urlChanged = $validated['server_url'] !== $originalUrl;
        $tokenChanged = $tokenKeyPresent && ($validated['token'] !== $originalTokenCasted);
        $providerTypeChanged = $providerTypeKeyPresent && ($validated['provider_type'] !== $originalProviderType);

        $server->update($validated);

        // Dispatch refresh job only if server_url, token, or provider_type changed (D12).
        if ($urlChanged || $tokenChanged || $providerTypeChanged) {
            $triggeredBy = Auth::id();
            RefreshServerModelsJob::dispatch($server->id, $triggeredBy);
        }

        return response()->json($this->serverToArray($server), 200);
    }

    public function destroy($id)
    {
        $server = Server::findOrFail($id);
        $server->delete();
        return response()->json([], 204);
    }
}
