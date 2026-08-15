<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\ConsensusNoEligibleContributorsException;
use ClarionApp\LlmClient\Models\AgentHelperAssignment;
use ClarionApp\LlmClient\Models\ConsensusRequest;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Support\Decimal;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\RoleResolution;

/**
 * The single write path for a multi-opinion request (104-multi-agent-
 * consensus, data-model.md §3, contracts/consensus-reconciliation-
 * contract.md §1). Mirrors DelegationService's/ManagerService's role as
 * the sole owner of its own table.
 *
 * dispatch() and finalize() are both public: dispatch() calls finalize()
 * itself, internally, for the batch branch only (contracts §1's own
 * pipeline diagram nests "ConsensusService::finalize($request, $results)"
 * directly under dispatch()'s "count >= max(2, min_contributor_count)"
 * branch, immediately after delegateBatch() returns -- finalize() needs
 * $batchResults, which only dispatch() has in memory at that point, so no
 * external caller could invoke it standalone for a real batch anyway).
 * finalize() stays public and independently callable because
 * ConsensusServiceFinalizeTest exercises it directly against a fixture
 * ConsensusRequest + a directly-constructed six-field $batchResults array,
 * without needing a real delegateBatch() call.
 */
class ConsensusService
{
    /**
     * contracts/consensus-reconciliation-contract.md §2: verbatim, never
     * model-composed for this classification -- a fixed string removes any
     * risk of the judge's own reconciled_answer drifting into a hedge that
     * reads like an actual answer (FR-007).
     */
    private const NO_CONSENSUS_STATEMENT = 'No consensus was reached — the contributors\' answers could not be '
        .'reconciled into a shared, defensible position. See each contributor\'s individual answer.';

    public function __construct(
        private readonly DelegationService $delegationService,
        private readonly ConsensusReconciliationJudge $judge,
        private readonly RoleResolver $roleResolver,
        private readonly CostEstimator $costEstimator,
        private readonly UsageEstimator $usageEstimator,
    ) {}

    /**
     * @throws ConsensusNoEligibleContributorsException when zero contributors
     *   are eligible, or two-or-more but still fewer than
     *   consensus.min_contributor_count (only reachable when an installation
     *   raises that config above its default of 2) -- no ConsensusRequest
     *   row is created for either refusal.
     */
    public function dispatch(Conversation $conversation, string $question): ConsensusRequest
    {
        $ownerUserId = (string) $conversation->user_id;
        $agentId = $conversation->agent_id;

        $eligible = $agentId !== null
            ? AgentHelperAssignment::where('parent_agent_id', $agentId)->whereNull('deleted_at')->get()
            : collect();

        $eligibleCount = $eligible->count();
        $minRequired = (int) config('llm-client.consensus.min_contributor_count', 2);

        // **Branch order is load-bearing** (Grounding notes, contracts §1):
        // "exactly one eligible" MUST be evaluated BEFORE any comparison
        // against min_contributor_count. Reversing this would make the
        // default min_contributor_count of 2 swallow the single-contributor
        // case into the 422 refusal below, breaking FR-016.
        if ($eligibleCount === 0) {
            throw new ConsensusNoEligibleContributorsException(0, $minRequired);
        }

        if ($eligibleCount === 1) {
            return $this->dispatchSingleContributorFallback($conversation, $question, $eligible->first());
        }

        if ($eligibleCount < $minRequired) {
            throw new ConsensusNoEligibleContributorsException($eligibleCount, $minRequired);
        }

        return $this->dispatchBatch($conversation, $question, $eligible, $ownerUserId);
    }

    /**
     * finalize() -- called by dispatch()'s own batch branch immediately
     * after delegateBatch() returns (still within the same synchronous
     * request, research.md D5), and independently by
     * ConsensusServiceFinalizeTest against a directly-constructed fixture.
     *
     * @param  array<string, array<string, mixed>>  $batchResults  the six-field
     *   shape delegateBatch() returns, keyed by tool_call_id.
     */
    public function finalize(ConsensusRequest $request, array $batchResults): void
    {
        try {
            $successful = array_values(array_filter(
                $batchResults,
                fn (array $result) => in_array($result['status'] ?? null, ['success', 'partial'], true),
            ));
            $successfulCount = count($successful);

            $request->successful_count = $successfulCount;

            $quorumRequired = (int) $request->quorum_required;

            if ($successfulCount < $quorumRequired) {
                $request->status = 'insufficient_quorum';
                $request->actual_additional_cost = $this->computeActualAdditionalCost($request, $successfulCount);
                $request->completed_at = now();
                $request->save();

                return;
            }

            $delegationIds = array_map(fn (array $result) => $result['delegation_id'], $successful);

            $delegations = Delegation::whereIn('id', $delegationIds)
                ->orderBy('started_at')
                ->get();

            $contributorAnswers = $delegations->map(fn (Delegation $delegation) => [
                'delegation_id' => $delegation->id,
                'helper_agent_id' => $delegation->helper_agent_id,
                'answer' => (string) $delegation->result_summary,
            ])->all();

            $judgeConversation = $this->createJudgeConversation($request);

            $judgeResult = $this->judge->reconcile($request->question, $contributorAnswers, $judgeConversation);

            if ($judgeResult->isReconciled()) {
                $reconciledAnswer = $judgeResult->classification === 'no_consensus'
                    ? self::NO_CONSENSUS_STATEMENT
                    : $judgeResult->reconciledAnswer;

                $request->agreement_classification = $judgeResult->classification;
                $request->reconciled_answer = $reconciledAnswer;
                $request->disagreement_detail = $judgeResult->classification === 'agreed'
                    ? null
                    : array_map(fn (array $position) => [
                        'position_summary' => $position['summary'],
                        'supporting_contributor_delegation_ids' => $position['supportingDelegationIds'],
                    ], $judgeResult->positions);

                $message = Message::create([
                    'conversation_id' => $request->conversation_id,
                    'content' => $reconciledAnswer,
                    'role' => 'assistant',
                    'user' => 'Clarion',
                    'responseTime' => 0,
                ]);

                $request->answer_message_id = $message->id;
                $request->status = 'completed';
            } else {
                $request->status = 'failed';
                $request->failure_reason = $judgeResult->reason;
            }

            $request->actual_additional_cost = $this->computeActualAdditionalCost($request, $successfulCount);
            $request->completed_at = now();
            $request->save();
        } catch (\Throwable $e) {
            // Crash-exposure discipline (research.md D5): an unexpected
            // exception anywhere above must still leave a terminal, honestly
            // labeled row -- never an unset one.
            $request->status = 'failed';
            $request->failure_reason = 'unexpected error: '.$e->getMessage();
            $request->completed_at = now();
            $request->save();
        }
    }

    // -----------------------------------------------------------------
    // dispatch()'s two terminal branches
    // -----------------------------------------------------------------

    private function dispatchSingleContributorFallback(
        Conversation $conversation,
        string $question,
        AgentHelperAssignment $assignment,
    ): ConsensusRequest {
        $request = ConsensusRequest::create([
            'conversation_id' => $conversation->id,
            'owner_user_id' => (string) $conversation->user_id,
            'coordinator_agent_id' => $conversation->agent_id,
            'question' => $question,
            'dispatched_count' => 1,
            'quorum_required' => null,
            'status' => 'single_contributor_fallback',
            'estimated_additional_cost' => null,
            'started_at' => now(),
        ]);

        // 098's solo path -- deliberately NOT delegateBatch() (FR-016).
        $result = $this->delegationService->delegate($conversation, $assignment->helper_agent_id, $question, null);

        $answer = (string) ($result['summary'] ?? '');
        $successfulCount = in_array($result['status'] ?? null, ['success', 'partial'], true) ? 1 : 0;

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $answer,
            'role' => 'assistant',
            'user' => 'Clarion',
            'responseTime' => 0,
        ]);

        $request->reconciled_answer = $answer;
        $request->answer_message_id = $message->id;
        $request->successful_count = $successfulCount;
        $request->completed_at = now();
        $request->save();

        return $request;
    }

    private function dispatchBatch(
        Conversation $conversation,
        string $question,
        \Illuminate\Support\Collection $eligible,
        string $ownerUserId,
    ): ConsensusRequest {
        $defaultCount = (int) config('llm-client.consensus.default_contributor_count', 3);
        $quorumFraction = (float) config('llm-client.consensus.quorum_fraction', 0.5);

        $selected = $eligible->take($defaultCount)->values();
        $selectedCount = $selected->count();

        $quorumRequired = max(2, (int) ceil($selectedCount * $quorumFraction));

        /** @var RoleResolution[] $resolutions */
        $resolutions = [];
        foreach ($selected as $assignment) {
            $resolutions[] = $this->resolveContributorModel($ownerUserId);
        }

        $independenceNote = $this->computeIndependenceNote($resolutions, $selectedCount);
        $estimatedAdditionalCost = $this->estimateAdditionalCost($conversation, $question, $resolutions, $selectedCount);

        $request = ConsensusRequest::create([
            'conversation_id' => $conversation->id,
            'owner_user_id' => $ownerUserId,
            'coordinator_agent_id' => $conversation->agent_id,
            'question' => $question,
            'dispatched_count' => $selectedCount,
            'quorum_required' => $quorumRequired,
            'status' => 'in_progress',
            'independence_note' => $independenceNote,
            'estimated_additional_cost' => $estimatedAdditionalCost,
            'started_at' => now(),
        ]);

        $calls = [];
        foreach ($selected as $index => $assignment) {
            $calls[] = [
                'tool_call_id' => 'consensus_'.$index,
                'helper_agent_id' => $assignment->helper_agent_id,
                'task' => $question,
                'context' => null,
            ];
        }

        $batchResults = $this->delegationService->delegateBatch($conversation, $calls);

        $request->batch_id = $this->batchIdFromResults($batchResults);
        $request->save();

        $this->finalize($request, $batchResults);

        return $request->fresh();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * The resolved (provider_type, model) pair a given contributor would run
     * under -- currently always RoleResolver's own Inference-role
     * resolution for the owning user (there is no per-agent model override
     * in this codebase today, D7). Isolated in its own protected method,
     * rather than calling roleResolver->resolve() directly inline, so a
     * test can override this ONE seam (via a partial mock of this
     * non-final class) to construct a scenario where selected contributors
     * resolve to genuinely different models -- RoleResolver itself is
     * final and cannot be mocked directly when type-hinted (Mockery), and
     * its own resolution is deterministic per (role, userId) today, so
     * there is no other way to exercise the "contributors differ" branch
     * of the independence check under test.
     */
    protected function resolveContributorModel(string $ownerUserId): RoleResolution
    {
        return $this->roleResolver->resolve(ModelRole::Inference, $ownerUserId);
    }

    /**
     * Every delegateBatch() member shares one batch_id, but delegateBatch()
     * itself never returns it directly -- recovered here from the first
     * result that actually carries a delegation_id (a refused call, e.g.
     * an inactive helper agent, has neither).
     */
    private function batchIdFromResults(array $batchResults): ?string
    {
        foreach ($batchResults as $result) {
            $delegationId = $result['delegation_id'] ?? null;
            if ($delegationId !== null) {
                return Delegation::find($delegationId)?->batch_id;
            }
        }

        return null;
    }

    /**
     * FR-015/contracts §4: compares every selected contributor's resolved
     * (provider_type, model) pair -- never helper_agent_id, which always
     * differs across distinct helper agents regardless of what model each
     * one resolves to.
     *
     * @param  RoleResolution[]  $resolutions
     */
    private function computeIndependenceNote(array $resolutions, int $selectedCount): ?string
    {
        $pairs = [];
        foreach ($resolutions as $resolution) {
            if (!$resolution->hasEffectiveModel()) {
                continue;
            }
            $pairs[] = $resolution->server->provider_type->value.'|'.$resolution->model;
        }

        // Only claim a shared configuration when EVERY selected contributor
        // resolved to an effective model in the first place -- a mix of
        // "resolved" and "unresolved" is not verified-identical.
        if (count($pairs) !== count($resolutions) || $pairs === []) {
            return null;
        }

        $distinct = array_unique($pairs);
        if (count($distinct) > 1) {
            return null;
        }

        [$providerType, $model] = explode('|', $distinct[array_key_first($distinct)], 2);

        return "All {$selectedCount} contributors were configured to use the same underlying model "
            ."({$providerType}/{$model}); agreement among them does not indicate independent verification.";
    }

    /**
     * research.md D6, contracts §5 (T021 -- basic non-zero wiring only;
     * Phase 4/T029 completes the full formula, including the priced
     * question-text addition and the exact (dispatched_count - 1)
     * multiplier's averaging discipline across differently-priced models).
     * An unpriced/unresolvable contributor contributes 0 rather than
     * aborting the whole computation (graceful degradation, mirrors
     * UnpricedModelPolicy's own posture).
     *
     * @param  RoleResolution[]  $resolutions
     */
    private function estimateAdditionalCost(
        Conversation $conversation,
        string $question,
        array $resolutions,
        int $selectedCount,
    ): ?string {
        if ($selectedCount <= 1 || $resolutions === []) {
            return null;
        }

        // Wired in per T021 (basic, non-zero estimate) -- the pending
        // question text is not yet persisted on the conversation, so
        // CostEstimator::estimate() alone would under-count by exactly this
        // much; the full priced treatment of this figure is Phase 4's job.
        $this->usageEstimator->estimateInput($question);

        $amounts = [];
        foreach ($resolutions as $resolution) {
            if (!$resolution->hasEffectiveModel()) {
                $amounts[] = '0';
                continue;
            }

            $estimated = $this->costEstimator->estimate(
                $conversation->id,
                $resolution->server->provider_type->value,
                $resolution->model,
            );

            $amounts[] = ($estimated->unpriced || $estimated->amount === null) ? '0' : $estimated->amount;
        }

        $sum = array_reduce($amounts, fn (string $carry, string $amount) => bcadd($carry, $amount, 10), '0');
        $average = bcdiv($sum, (string) count($amounts), 10);

        return Decimal::round(bcmul($average, (string) ($selectedCount - 1), 10), 10);
    }

    /**
     * actual_additional_cost (contracts §5): sums usage_records.total_cost
     * over EVERY Delegation sharing this request's batch_id (not only the
     * successful ones -- a contributor that incurred a provider cost before
     * ultimately failing is still real spend), then subtracts one
     * contributor's average share.
     */
    private function computeActualAdditionalCost(ConsensusRequest $request, int $successfulCount): string
    {
        if ($request->batch_id === null) {
            return '0.0000000000';
        }

        $conversationIds = Delegation::where('batch_id', $request->batch_id)
            ->where('owner_user_id', $request->owner_user_id)
            ->pluck('helper_conversation_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($conversationIds === []) {
            return '0.0000000000';
        }

        $totalRaw = UsageRecord::whereIn('conversation_id', $conversationIds)
            ->selectRaw('SUM(total_cost) as cost')
            ->value('cost') ?? '0';

        $total = Decimal::round(Decimal::fromNumeric($totalRaw), 10);

        if ($successfulCount <= 0) {
            return $total;
        }

        return bcsub($total, bcdiv($total, (string) $successfulCount, 10), 10);
    }

    /**
     * One dedicated Conversation per finalize() call for the reconciliation
     * judge's own usage attribution -- mirrors EvalCaseExecutor's
     * dedicated-judge-conversation pattern, scoped to the real requesting
     * user (rather than a null/system user_id) since this is a real user's
     * spend, not a system eval.
     *
     * Protected (not private): ConsensusServiceFinalizeTest overrides this
     * one seam via a partial mock to force an unexpected exception inside
     * finalize()'s try block, proving the crash-exposure discipline
     * (research.md D5) without needing a way to make the never-throws
     * ConsensusReconciliationJudge throw, which it deliberately cannot do.
     */
    protected function createJudgeConversation(ConsensusRequest $request): Conversation
    {
        return Conversation::create([
            'user_id' => $request->owner_user_id,
            'title' => 'consensus-reconciliation:'.$request->id,
            'character' => 'Clarion',
            'channel' => 'consensus-reconciliation',
        ]);
    }
}
