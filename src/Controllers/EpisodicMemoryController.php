<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Contracts\EpisodicMemoryService as EpisodicMemoryServiceContract;
use ClarionApp\LlmClient\Services\EpisodicMemorySearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * EpisodicMemoryController
 *
 * API endpoints for episodic memory operations:
 * - GET list (paginated, per-user scoped)
 * - POST search (keyword/semantic/hybrid modes)
 * - PATCH :id/protect (toggle protection flag)
 * - DELETE :id (hard delete, immediate removal per FR-012)
 */
class EpisodicMemoryController extends Controller
{
    public function __construct(
        private readonly EpisodicMemoryServiceContract $episodicMemoryService,
        private readonly EpisodicMemorySearchService $episodicMemorySearchService
    ) {}

    /**
     * List episodic memories for the authenticated user.
     *
     * @param Request $request HTTP request with optional query parameters
     * @return JsonResponse Paginated list of episodic memories
     */
    public function index(Request $request): JsonResponse
    {
        $userId = auth()->id();

        $query = \ClarionApp\LlmClient\Models\EpisodicMemory::withoutGlobalScope('user')
            ->where('user_id', $userId);

        // Optional topic filter
        if ($request->has('topic')) {
            $topic = $request->input('topic');
            $query->where('topics', 'like', "%{$topic}%");
        }

        // Optional protection status filter
        if ($request->has('protected')) {
            $protected = filter_var($request->input('protected'), FILTER_VALIDATE_BOOLEAN);
            $query->where('protected', $protected);
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $page = (int) $request->input('page', 1);

        $paginator = $query->latest('created_at')->paginate($perPage, ['*'], 'page', $page);

        // Return response matching contract spec with 'meta' wrapper
        return response()->json([
            'data' => $paginator->items(),
            'links' => [
                'first'  => $paginator->url(1),
                'last'   => $paginator->url($paginator->lastPage()),
                'prev'   => $paginator->previousPageUrl(),
                'next'   => $paginator->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * Search episodic memories by keyword or semantic topic.
     *
     * @param Request $request HTTP request with search parameters
     * @return JsonResponse Search results with metadata
     */
    public function search(Request $request): JsonResponse
    {
        $userId = auth()->id();

        // Manual validation to return custom error format matching contracts
        $query = $request->input('query');
        $mode = $request->input('mode', 'hybrid');
        $limit = (int) ($request->input('limit', 20));

        if (empty($query)) {
            return response()->json(['error' => 'Query is required'], 422);
        }

        if ($limit > 100) {
            return response()->json(['error' => 'Limit exceeds maximum of 100'], 422);
        }

        if (!in_array($mode, ['keyword', 'semantic', 'hybrid'], true)) {
            return response()->json(['error' => 'Invalid search mode'], 422);
        }

        try {
            $results = $this->episodicMemorySearchService->search($userId, $query, $mode, $limit);

            return response()->json([
                'data' => $results,
                'meta' => [
                    'mode'  => $mode,
                    'total' => count($results),
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();
            // Map "Semantic search unavailable" messages to the contract error
            if (str_contains($message, 'Semantic search unavailable')) {
                return response()->json(['error' => 'Semantic search unavailable. Use keyword mode.'], 422);
            }
            return response()->json(['error' => $message], 422);
        }
    }

    /**
     * Toggle protection flag on an episodic memory entry.
     *
     * @param string $id Episodic memory ID
     * @param Request $request HTTP request with protection flag
     * @return JsonResponse Updated memory entry
     */
    public function protect(string $id, Request $request): JsonResponse
    {
        $userId = auth()->id();

        $validated = $request->validate([
            'protected' => 'required|boolean',
        ]);

        try {
            if ($validated['protected']) {
                $success = $this->episodicMemoryService->protect($userId, $id);
            } else {
                $success = $this->episodicMemoryService->unprotect($userId, $id);
            }

            if (!$success) {
                return response()->json(['error' => 'Entry not found'], 404);
            }

            // Fetch updated record
            $memory = \ClarionApp\LlmClient\Models\EpisodicMemory::withoutGlobalScope('user')
                ->where('user_id', $userId)
                ->where('id', $id)
                ->first();

            if (!$memory) {
                return response()->json(['error' => 'Entry not found'], 404);
            }

            return response()->json([
                'id'         => $memory->id,
                'protected'  => $memory->protected,
                'updated_at' => $memory->updated_at,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Entry not found'], 404);
        }
    }

    /**
     * Permanently delete an episodic memory entry.
     *
     * @param string $id Episodic memory ID
     * @return Response 204 No Content on success
     */
    public function destroy(string $id): Response
    {
        $userId = auth()->id();

        $success = $this->episodicMemoryService->delete($userId, $id);

        if (!$success) {
            return response()->json(['error' => 'Entry not found'], 404);
        }

        return response()->noContent();
    }
}
