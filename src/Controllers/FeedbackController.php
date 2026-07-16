<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Contracts\DeclarativeMemoryService as DeclarativeMemoryServiceContract;
use ClarionApp\LlmClient\Events\FeedbackReceived;
use ClarionApp\LlmClient\Models\DeclarativeMemory;
use ClarionApp\LlmClient\Models\FeedbackExtractionLog;
use ClarionApp\LlmClient\Models\FeedbackOptOut;
use ClarionApp\LlmClient\Models\FeedbackSignal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * FeedbackController
 *
 * API endpoints for user feedback and learned preference management:
 * - POST /feedback — Submit feedback signal
 * - GET  /feedback/preferences/proposed — List proposed preferences
 * - POST /feedback/preferences/{pattern_key}/confirm — Confirm a proposed preference
 * - POST /feedback/preferences/{pattern_key}/decline — Decline (opt-out) a pattern
 * - GET  /feedback/preferences/learned — List learned preferences
 * - PATCH /feedback/preferences/{id} — Edit a learned preference
 * - DELETE /feedback/preferences/{id} — Delete a learned preference
 * - GET  /feedback/audit/{preference_id} — Get audit trail for a preference
 */
class FeedbackController extends Controller
{
    public function __construct(
        private readonly DeclarativeMemoryServiceContract $declarativeMemoryService
    ) {}

    /**
     * Submit a feedback signal.
     *
     * @param Request $request HTTP request with signal_type, context, conversation_id
     * @return JsonResponse Created signal data
     */
    public function store(Request $request): JsonResponse
    {
        $userId = (string) auth()->id();

        // Validate input
        $validated = $request->validate([
            'signal_type' => ['required', 'string', 'in:' . implode(',', FeedbackSignal::SIGNAL_TYPES)],
            'context' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'string'],
            'source_event_id' => ['nullable', 'string'],
        ]);

        // Generate source_event_id if not provided
        $sourceEventId = $validated['source_event_id'] ?? Str::uuid()->toString();

        // Dispatch FeedbackReceived event (triggers PersistFeedbackSignal listener)
        event(new FeedbackReceived(
            $userId,
            $sourceEventId,
            $validated['signal_type'],
            $validated['context'],
            $validated['conversation_id'] ?? null,
            null
        ));

        return response()->json([
            'message' => 'Feedback recorded successfully',
            'signal_type' => $validated['signal_type'],
            'source_event_id' => $sourceEventId,
        ], 201);
    }

    /**
     * List proposed preferences awaiting user confirmation.
     *
     * @param Request $request HTTP request with optional pagination params
     * @return JsonResponse Paginated list of proposed preferences
     */
    public function proposed(Request $request): JsonResponse
    {
        $userId = (string) auth()->id();

        $perPage = min((int) $request->input('per_page', 20), 100);
        $page = (int) $request->input('page', 1);

        $paginator = FeedbackExtractionLog::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->where('outcome', FeedbackExtractionLog::OUTCOME_PROPOSED)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        $data = array_map(function ($entry) {
            return [
                'id' => $entry->id,
                'pattern_key' => $entry->pattern_key,
                'signals_count' => $entry->signals_count,
                'confidence_score' => $entry->confidence_score,
                'created_at' => $entry->created_at?->toIso8601String(),
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
     * Confirm a proposed preference (persists via DeclarativeMemoryService).
     *
     * @param Request $request HTTP request with pattern_key in URL
     * @param string $patternKey Pattern key to confirm
     * @return JsonResponse Created declarative memory
     */
    public function confirm(Request $request, string $patternKey): JsonResponse
    {
        $userId = (string) auth()->id();

        // Find the proposed extraction log for this pattern
        $log = FeedbackExtractionLog::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->where('pattern_key', $patternKey)
            ->where('outcome', FeedbackExtractionLog::OUTCOME_PROPOSED)
            ->latest('created_at')
            ->first();

        if (!$log) {
            return response()->json([
                'error' => 'No proposed preference found for this pattern',
            ], 404);
        }

        // Confirm via DeclarativeMemoryService (userConfirmed=true)
        $memory = $this->declarativeMemoryService->applyAgentWrite(
            $userId,
            'preference',
            $patternKey,
            true, // userConfirmed = true → persists immediately
            null,
            $log->confidence_score
        );

        // Update extraction log to link to declarative memory
        $log->update(['declarative_memory_id' => $memory->id]);

        return response()->json([
            'message' => 'Preference confirmed and persisted',
            'data' => [
                'id' => $memory->id,
                'pattern_key' => $patternKey,
                'type' => $memory->type,
                'content' => $memory->content,
                'source' => $memory->source,
                'confidence_level' => $memory->confidence_level,
                'created_at' => $memory->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Decline a proposed preference (records opt-out for this pattern).
     *
     * @param Request $request HTTP request with pattern_key in URL
     * @param string $patternKey Pattern key to decline
     * @return JsonResponse Opt-out confirmation
     */
    public function decline(Request $request, string $patternKey): JsonResponse
    {
        $userId = (string) auth()->id();

        // Record opt-out
        $optOut = FeedbackOptOut::optOut($userId, $patternKey);

        return response()->json([
            'message' => 'Preference declined — will not be suggested again',
            'data' => [
                'pattern_key' => $patternKey,
                'opt_out_id' => $optOut->id,
                'created_at' => $optOut->created_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * List learned preferences (confirmed via user or agent).
     *
     * @param Request $request HTTP request with optional pagination params
     * @return JsonResponse Paginated list of learned preferences
     */
    public function learned(Request $request): JsonResponse
    {
        $userId = (string) auth()->id();

        $perPage = min((int) $request->input('per_page', 20), 100);
        $page = (int) $request->input('page', 1);

        $paginator = DeclarativeMemory::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->where('source', DeclarativeMemory::LEARNED_PATTERN)
            ->orderBy('updated_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

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
     * Edit a learned preference (transitions source to user_stated).
     *
     * @param Request $request HTTP request with updated content
     * @param string $id Declarative memory ID
     * @return JsonResponse Updated declarative memory
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $userId = (string) auth()->id();

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
        ]);

        $memory = $this->declarativeMemoryService->updateByUser(
            $userId,
            $id,
            $validated['content']
        );

        return response()->json([
            'message' => 'Preference updated',
            'data' => [
                'id' => $memory->id,
                'type' => $memory->type,
                'content' => $memory->content,
                'source' => $memory->source,
                'confidence_level' => $memory->confidence_level,
                'created_at' => $memory->created_at?->toIso8601String(),
                'updated_at' => $memory->updated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Delete a learned preference.
     *
     * @param Request $request HTTP request
     * @param string $id Declarative memory ID
     * @return JsonResponse Deletion confirmation
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $userId = (string) auth()->id();

        $result = $this->declarativeMemoryService->delete($userId, $id);

        if (!$result) {
            return response()->json([
                'error' => 'Preference not found or not authorized',
            ], 404);
        }

        return response()->json([
            'message' => 'Preference deleted',
        ]);
    }

    /**
     * Get audit trail for a preference.
     *
     * @param Request $request HTTP request
     * @param string $preferenceId Declarative memory ID
     * @return JsonResponse List of extraction log entries
     */
    public function audit(Request $request, string $preferenceId): JsonResponse
    {
        $userId = (string) auth()->id();

        $logs = FeedbackExtractionLog::getAuditTrail($preferenceId)
            ->filter(fn ($log) => $log->user_id === $userId);

        $data = array_map(function ($entry) {
            return [
                'id' => $entry->id,
                'pattern_key' => $entry->pattern_key,
                'signals_count' => $entry->signals_count,
                'confidence_score' => $entry->confidence_score,
                'outcome' => $entry->outcome,
                'llm_call_id' => $entry->llm_call_id,
                'created_at' => $entry->created_at?->toIso8601String(),
            ];
        }, $logs->toArray());

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => count($data),
            ],
        ]);
    }
}
