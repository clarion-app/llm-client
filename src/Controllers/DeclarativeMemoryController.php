<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Contracts\DeclarativeMemoryService as DeclarativeMemoryServiceContract;
use ClarionApp\LlmClient\Models\DeclarativeMemory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * DeclarativeMemoryController
 *
 * API endpoints for user-driven declarative memory operations:
 * - GET list (paginated, per-user scoped)
 * - POST create (user-stated, immediate, no confirmation)
 * - PUT edit (user edit, immediate)
 * - DELETE (hard delete, immediate removal)
 *
 * All endpoints are behind auth:api and scoped to the authenticated user.
 */
class DeclarativeMemoryController extends Controller
{
    public function __construct(
        private readonly DeclarativeMemoryServiceContract $declarativeMemoryService
    ) {}

    /**
     * List declarative memories for the authenticated user.
     *
     * @param Request $request HTTP request with optional query parameters
     * @return JsonResponse Paginated list of declarative memories
     */
    public function index(Request $request): JsonResponse
    {
        $userId = (string) auth()->id();

        $query = DeclarativeMemory::withoutGlobalScope('user')
            ->where('user_id', $userId);

        // Optional type filter
        if ($request->has('type')) {
            $type = $request->input('type');
            if (in_array($type, DeclarativeMemory::TYPES, true)) {
                $query->where('type', $type);
            }
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $page = (int) $request->input('page', 1);

        $paginator = $query->orderBy('updated_at', 'desc')->paginate($perPage, ['*'], 'page', $page);

        $data = array_map(function ($entry) {
            return [
                'id' => $entry->id,
                'type' => $entry->type,
                'content' => $entry->content,
                'source' => $entry->source,
                'confidence_level' => $entry->confidence_level,
                'created_at' => $entry->created_at?->toIso8601String(),
                'updated_at' => $entry->updated_at?->toIso8601String(),
            ];
        }, $paginator->items());

        return response()->json([
            'data' => $data,
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Create a declarative memory entry directly by the user.
     *
     * Source is forced to 'user_stated'. Applied immediately with no confirmation step.
     * Runs semantic conflict/supersede check.
     *
     * @param Request $request HTTP request with type and content
     * @return JsonResponse Created (or superseded) entry with 201 status
     */
    public function store(Request $request): JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'type' => ['required', 'string', 'in:fact,preference,rule'],
            'content' => ['required', 'string', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $type = $request->input('type');
        $content = trim($request->input('content'));

        if ($content === '') {
            return response()->json(['error' => 'Content must not be empty'], 422);
        }

        $userId = (string) auth()->id();
        $entry = $this->declarativeMemoryService->createByUser($userId, $type, $content);

        return response()->json([
            'id' => $entry->id,
            'type' => $entry->type,
            'content' => $entry->content,
            'source' => $entry->source,
            'confidence_level' => $entry->confidence_level,
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
        ], 201);
    }

    /**
     * Update an existing declarative memory entry by the user.
     *
     * @param Request $request HTTP request with new content
     * @param string $id DeclarativeMemory UUID
     * @return JsonResponse Updated entry with 200 status, or 404 if not found/not owned
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'content' => ['required', 'string', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $content = trim($request->input('content'));
        $userId = (string) auth()->id();

        try {
            $entry = $this->declarativeMemoryService->updateByUser($userId, $id, $content);

            return response()->json([
                'id' => $entry->id,
                'type' => $entry->type,
                'content' => $entry->content,
                'source' => $entry->source,
                'confidence_level' => $entry->confidence_level,
                'created_at' => $entry->created_at?->toIso8601String(),
                'updated_at' => $entry->updated_at?->toIso8601String(),
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Entry not found'], 404);
        }
    }

    /**
     * Permanently delete a declarative memory entry.
     *
     * @param string $id DeclarativeMemory UUID
     * @return Response 204 no content on success, or 404 if not found/not owned
     */
    public function destroy(string $id): Response
    {
        $userId = (string) auth()->id();

        try {
            $this->declarativeMemoryService->delete($userId, $id);
            return response()->noContent(204);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Entry not found'], 404);
        }
    }
}
