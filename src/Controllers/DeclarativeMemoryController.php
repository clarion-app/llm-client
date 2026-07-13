<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Contracts\DeclarativeMemoryService as DeclarativeMemoryServiceContract;
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
        // TODO (US3): Implement paginated list with optional type filter.
        throw new \RuntimeException('index not yet implemented');
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
        // TODO (US1): Validate type ∈ {fact,preference,rule}, non-empty content.
        // Call createByUser and return 201 with the entry.
        throw new \RuntimeException('store not yet implemented');
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
        // TODO (US3): Replace content in place and return updated entry.
        throw new \RuntimeException('update not yet implemented');
    }

    /**
     * Permanently delete a declarative memory entry.
     *
     * @param string $id DeclarativeMemory UUID
     * @return Response 204 no content on success, or 404 if not found/not owned
     */
    public function destroy(string $id): Response
    {
        // TODO (US3): Call forceDelete scoped to user_id.
        throw new \RuntimeException('destroy not yet implemented');
    }
}
