<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use Auth;
use ClarionApp\LlmClient\Exceptions\ConsensusNoEligibleContributorsException;
use ClarionApp\LlmClient\Models\ConsensusRequest;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\ConsensusQuery;
use ClarionApp\LlmClient\Services\ConsensusService;
use ClarionApp\LlmClient\Services\RoleResolver;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 104-multi-agent-consensus (US1, contracts/consensus-api.md §1/§2).
 *
 * Read/write-thin, mirroring ManagedTaskController's/DelegationController's
 * precedent (Grounding note item 5): store() is the ONLY endpoint that ever
 * creates a ConsensusRequest, and is synchronous end-to-end (research.md
 * D5, unlike ManagedTaskController::store()'s 202) -- it returns a terminal
 * result on every request, since ConsensusService::dispatch() already runs
 * the whole pipeline (including finalize(), for the batch branch) before
 * returning.
 */
class ConsensusController extends Controller
{
    private const APPROXIMATION_NOTICE = 'Agreement is assessed by comparing contributors\' answers for '
        .'substantive agreement, not exact wording — this is an approximation, not a guarantee.';

    public function __construct(
        private readonly ConsensusService $consensusService,
        private readonly ConsensusQuery $consensusQuery,
    ) {}

    /**
     * POST /consensus-requests -- body {question, conversation_id?, channel?}
     * (contracts §1).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateQuestionRequest($request);

        $emptyQuestionResponse = $this->emptyQuestionResponseIfNeeded($validated);
        if ($emptyQuestionResponse !== null) {
            return $emptyQuestionResponse;
        }

        $conversationOrResponse = $this->resolveConversation($validated);
        if ($conversationOrResponse instanceof JsonResponse) {
            return $conversationOrResponse;
        }

        try {
            $consensusRequest = $this->consensusService->dispatch($conversationOrResponse, $validated['question']);
        } catch (ConsensusNoEligibleContributorsException $e) {
            return $this->noEligibleContributorsResponse($e);
        }

        return $this->responseForRequest($consensusRequest);
    }

    /**
     * GET /consensus-requests/{id} -- read-back of a past request (contracts §2).
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $callerUserId = Auth::user()->id;

        $consensusRequest = $this->consensusQuery->findRequest($callerUserId, $id);
        if ($consensusRequest === null) {
            return $this->notFoundResponse('Consensus request not found', 'consensus_request_not_found');
        }

        return $this->responseForRequest($consensusRequest);
    }

    /**
     * POST /consensus-requests/cost-estimate -- preview the additional cost
     * before submitting (contracts §4, US2). Same body shape, conversation-
     * resolution, and 404/422 error shapes as store() (T032), but creates
     * NO ConsensusRequest row -- ConsensusService::estimateCost() is a pure
     * computation over the resolved conversation's currently active helper
     * assignments, reusing dispatch()'s own estimated_additional_cost
     * formula without ever persisting anything.
     */
    public function estimateCost(Request $request): JsonResponse
    {
        $validated = $this->validateQuestionRequest($request);

        $emptyQuestionResponse = $this->emptyQuestionResponseIfNeeded($validated);
        if ($emptyQuestionResponse !== null) {
            return $emptyQuestionResponse;
        }

        $conversationOrResponse = $this->resolveConversation($validated);
        if ($conversationOrResponse instanceof JsonResponse) {
            return $conversationOrResponse;
        }

        try {
            $result = $this->consensusService->estimateCost($conversationOrResponse, $validated['question']);
        } catch (ConsensusNoEligibleContributorsException $e) {
            return $this->noEligibleContributorsResponse($e);
        }

        return response()->json([
            'dispatched_count' => $result['dispatched_count'],
            'estimated_additional_cost' => $result['estimated_additional_cost'],
        ], 200);
    }

    /**
     * Shared request validation for store()/estimateCost() -- identical
     * body shape (contracts §1/§4). Deliberately no `exists:conversations,id`
     * on conversation_id (a divergence from AgentController::__invoke()'s
     * own validation rule, Grounding note item 4): that rule would make
     * Laravel's own automatic 422 validation-failure response fire for a
     * genuinely nonexistent id before this class's own 404 logic below is
     * ever reached -- contracts/consensus-api.md §1 requires 404 for that
     * case, so the existence check is left to resolveConversation()'s own
     * explicit lookup instead.
     */
    private function validateQuestionRequest(Request $request): array
    {
        return $request->validate([
            'question' => 'nullable|string',
            'conversation_id' => 'nullable|string',
            'channel' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/'],
        ]);
    }

    private function emptyQuestionResponseIfNeeded(array $validated): ?JsonResponse
    {
        if (trim((string) ($validated['question'] ?? '')) !== '') {
            return null;
        }

        return response()->json([
            'error' => 'empty_question',
            'message' => 'question must be a non-empty string.',
        ], 422);
    }

    private function noEligibleContributorsResponse(ConsensusNoEligibleContributorsException $e): JsonResponse
    {
        return response()->json([
            'error' => 'no_eligible_contributors',
            'message' => $e->getMessage(),
        ], 422);
    }

    /**
     * Mirrors AgentController::__invoke()'s conversation-resolution shape
     * (Grounding note item 4), with ONE deliberate divergence: contracts/
     * consensus-api.md §1 declares a single uniform 404 for "does not exist
     * or is not owned by the caller" -- unlike AgentController's own
     * distinct 403 for the unowned case -- so both collapse to 404 here.
     * An explicit conversation_id -> 404/409 as appropriate; otherwise
     * channel + inactivity-threshold lookup-or-create, 422 no_server if the
     * Inference role has no effective model.
     *
     * @return Conversation|JsonResponse
     */
    private function resolveConversation(array $validated)
    {
        $user = Auth::user();
        $channel = $validated['channel'] ?? 'web';

        if (!empty($validated['conversation_id'])) {
            $conversation = Conversation::find($validated['conversation_id']);

            if (!$conversation || $conversation->user_id !== $user->id) {
                return response()->json([
                    'error' => 'Conversation not found',
                    'code' => 'conversation_not_found',
                ], 404);
            }

            if ($conversation->is_processing) {
                return response()->json([
                    'error' => 'Conversation is already processing',
                    'code' => 'processing',
                ], 409);
            }

            return $conversation;
        }

        $thresholdHours = config('llm-client.conversation.inactivity_threshold_hours', 4);
        $conversation = Conversation::where('user_id', $user->id)
            ->where('channel', $channel)
            ->where('updated_at', '>', now()->subHours($thresholdHours))
            ->where('is_processing', false)
            ->latest('updated_at')
            ->first();

        if ($conversation) {
            return $conversation;
        }

        $resolution = app(RoleResolver::class)->resolve(ModelRole::Inference, $user->id);
        if (!$resolution->hasEffectiveModel()) {
            return response()->json([
                'error' => 'No inference model is assigned',
                'code' => 'no_server',
            ], 422);
        }

        return Conversation::create([
            'user_id' => $user->id,
            'server_id' => $resolution->server->id,
            'model' => $resolution->model,
            'character' => 'Clarion',
            'channel' => $channel,
        ]);
    }

    /**
     * Maps a terminal ConsensusRequest into contracts/consensus-api.md §1's
     * response shape (also reused verbatim by show(), contracts §2's
     * "identical shape to whichever terminal response §1 produced").
     */
    private function responseForRequest(ConsensusRequest $consensusRequest): JsonResponse
    {
        if ($consensusRequest->status === 'failed') {
            return response()->json([
                'consensus_request_id' => $consensusRequest->id,
                'status' => $consensusRequest->status,
                'failure_reason' => $consensusRequest->failure_reason,
                'message' => 'Multi-opinion mode could not produce a result.',
            ], 500);
        }

        $body = [
            'consensus_request_id' => $consensusRequest->id,
            'conversation_id' => $consensusRequest->conversation_id,
            'answer_message_id' => $consensusRequest->answer_message_id,
            'status' => $consensusRequest->status,
            'agreement_classification' => $consensusRequest->agreement_classification,
            'reconciled_answer' => $consensusRequest->reconciled_answer,
            'disagreement_detail' => $consensusRequest->disagreement_detail,
            'independence_note' => $consensusRequest->independence_note,
            'dispatched_count' => $consensusRequest->dispatched_count,
            'successful_count' => $consensusRequest->successful_count,
            'quorum_required' => $consensusRequest->quorum_required,
            'estimated_additional_cost' => $consensusRequest->estimated_additional_cost,
            'actual_additional_cost' => $consensusRequest->actual_additional_cost,
        ];

        if ($consensusRequest->status === 'completed') {
            $body['approximation_notice'] = self::APPROXIMATION_NOTICE;

            if ($consensusRequest->successful_count !== null
                && $consensusRequest->dispatched_count !== null
                && $consensusRequest->successful_count < $consensusRequest->dispatched_count
            ) {
                $failedCount = $consensusRequest->dispatched_count - $consensusRequest->successful_count;
                $body['message'] = "{$failedCount} of {$consensusRequest->dispatched_count} contributors did not respond "
                    ."(timed out) and was excluded from this result. See GET "
                    ."/consensus-requests/{$consensusRequest->id}/contributors for details.";
            }
        } elseif ($consensusRequest->status === 'insufficient_quorum') {
            $body['message'] = "A reliable outcome could not be produced: only {$consensusRequest->successful_count} of "
                ."{$consensusRequest->dispatched_count} contributors responded successfully, below the required "
                ."minimum of {$consensusRequest->quorum_required}.";
        } elseif ($consensusRequest->status === 'single_contributor_fallback') {
            $body['message'] = 'Only one contributor was available — this is a plain single-contributor answer, '
                .'not a reconciled multi-opinion result.';
        }

        return response()->json($body, 200);
    }

    /**
     * The uniform "not found" body shape (Grounding note item 5) -- every
     * controller in this package declares its own private copy.
     */
    private function notFoundResponse(string $error, string $code): JsonResponse
    {
        return response()->json([
            'error' => $error,
            'code' => $code,
        ], 404);
    }
}
