<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Auth;

class RoleAssignmentController extends Controller
{
    public function __construct(
        private readonly RoleAssignmentService $service,
    ) {}

    public function show()
    {
        return response()->json($this->service->describeAllRoles(Auth::id()), 200);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'role'      => ['required', Rule::in(['inference', 'embedding', 'image'])],
            'scope'     => ['required', Rule::in(['user', 'installation'])],
            'server_id' => ['required', 'uuid', 'exists:llm_servers,id'],
            'model'     => ['required', 'string'],
        ]);

        $ownerId = $validated['scope'] === 'installation'
            ? RoleAssignment::INSTALLATION_SCOPE_ID
            : Auth::id();

        $this->service->set(
            ModelRole::from($validated['role']),
            $ownerId,
            $validated['server_id'],
            $validated['model'],
        );

        $allRoles = $this->service->describeAllRoles(Auth::id());

        return response()->json($allRoles[$validated['role']], 200);
    }

    public function destroy(Request $request)
    {
        $validated = $request->validate([
            'role'  => ['required', Rule::in(['inference', 'embedding', 'image'])],
            'scope' => ['required', Rule::in(['user', 'installation'])],
        ]);

        $ownerId = $validated['scope'] === 'installation'
            ? RoleAssignment::INSTALLATION_SCOPE_ID
            : Auth::id();

        $this->service->clear(
            ModelRole::from($validated['role']),
            $ownerId,
        );

        $allRoles = $this->service->describeAllRoles(Auth::id());

        return response()->json($allRoles[$validated['role']], 200);
    }
}
