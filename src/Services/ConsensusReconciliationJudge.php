<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\BudgetExceededException;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\ConsensusReconciliationResult;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Illuminate\Support\Str;

/**
 * Reconciles several independent contributors' answers to the same
 * question into one classification + reconciled answer, by making one
 * bounded, synchronous LlmProvider::chat() call resolved through the Judge
 * role -- mirrors RubricJudge's shape and never-throws discipline exactly
 * (104-multi-agent-consensus, Grounding note item 3, data-model.md §5,
 * contracts/consensus-reconciliation-contract.md §2/§3).
 *
 * Never throws. Every failure mode -- an unassigned or broken judge role, a
 * refused spending ceiling, a provider request failure, or a malformed
 * response -- converges on the same explicit unreconciled result with a
 * human-readable reason, never a thrown exception and never a fabricated
 * answer.
 */
final class ConsensusReconciliationJudge
{
    private const ALLOWED_CLASSIFICATIONS = ['agreed', 'materially_disagreed', 'no_consensus'];

    /**
     * @param  array<int, array{delegation_id: string, helper_agent_id: string, answer: string}>  $contributorAnswers
     */
    public function reconcile(
        string $question,
        array $contributorAnswers,
        Conversation $judgeConversation,
    ): ConsensusReconciliationResult {
        try {
            $resolution = app(RoleResolver::class)->resolve(ModelRole::Judge, null);

            if (!$resolution->hasEffectiveModel()) {
                return ConsensusReconciliationResult::unreconciled(
                    $resolution->brokenReason ?? 'No judge model is assigned.',
                );
            }

            try {
                app(BudgetGate::class)->admit(
                    null,
                    BudgetWorkKind::SystemInitiated,
                    $judgeConversation->id,
                    'consensus_reconciliation',
                );
            } catch (BudgetExceededException) {
                return ConsensusReconciliationResult::unreconciled('spending ceiling reached');
            }

            $messages = app(ConsensusReconciliationPromptBuilder::class)->buildMessages($question, $contributorAnswers);

            try {
                $response = app(ProviderRegistry::class)->resolve($resolution->server)->chat(
                    $messages,
                    [],
                    [
                        'response_format' => 'json',
                        'timeout_ms' => (int) config('llm-client.eval_judging.timeout_ms', 20000),
                    ],
                );
            } catch (\Throwable $e) {
                return ConsensusReconciliationResult::unreconciled('provider request failed: '.$e->getMessage());
            }

            $content = $response['choices'][0]['message']['content'] ?? null;
            $parsed = $this->extractAndValidate($content, $contributorAnswers);

            if ($parsed === null) {
                return ConsensusReconciliationResult::unreconciled('malformed judge response');
            }

            app(MetricsRecorder::class)->recordUsage(
                conversationId: $judgeConversation->id,
                userId: (string) $judgeConversation->user_id,
                attemptGroupId: (string) Str::uuid(),
                providerUsage: $response['usage'] ?? [],
                inputText: $this->concatMessageText($messages),
                outputText: (string) $content,
                model: $resolution->model,
                providerType: $resolution->server->provider_type->value,
                agentId: 'consensus-judge',
            );

            return ConsensusReconciliationResult::reconciled(
                $parsed['classification'],
                $parsed['reconciled_answer'],
                $parsed['positions'],
                $resolution->model,
                $resolution->server->id,
                $judgeConversation->id,
            );
        } catch (\Throwable $e) {
            // A final backstop, not a named branch of its own: nothing above
            // should reach here, but a reconciliation-side surprise must
            // still never propagate past this method.
            return ConsensusReconciliationResult::unreconciled('unexpected reconciliation error: '.$e->getMessage());
        }
    }

    /**
     * Tolerant-parse-then-validate, mirroring
     * RubricJudge::extractScoreAndJustification()'s idiom exactly: accepts
     * a JSON object embedded in surrounding prose, then applies every one
     * of contracts §3's five rejection rules. Returns null on any parse or
     * shape failure -- the caller treats null as "malformed judge response".
     *
     * @param  array<int, array{delegation_id: string, helper_agent_id: string, answer: string}>  $contributorAnswers
     * @return array{classification: string, reconciled_answer: string, positions: list<array{summary: string, supportingDelegationIds: list<string>}>}|null
     */
    private function extractAndValidate(?string $content, array $contributorAnswers): ?array
    {
        if ($content === null || trim($content) === '') {
            return null;
        }

        $decoded = json_decode($content, true);

        if (!is_array($decoded) && preg_match('/\{.*\}/s', $content, $matches) === 1) {
            $decoded = json_decode($matches[0], true);
        }

        if (!is_array($decoded)) {
            return null;
        }

        $classification = $decoded['classification'] ?? null;
        if (!is_string($classification) || !in_array($classification, self::ALLOWED_CLASSIFICATIONS, true)) {
            return null;
        }

        $reconciledAnswer = $decoded['reconciled_answer'] ?? null;
        if (!is_string($reconciledAnswer) || trim($reconciledAnswer) === '') {
            return null;
        }

        $positionsRaw = $decoded['positions'] ?? null;
        if (!is_array($positionsRaw)) {
            return null;
        }

        if ($classification === 'agreed' && count($positionsRaw) > 0) {
            return null;
        }

        if ($classification !== 'agreed' && count($positionsRaw) < 2) {
            return null;
        }

        $validDelegationIds = array_map(
            fn (array $contributor) => $contributor['delegation_id'],
            $contributorAnswers,
        );

        $positions = [];
        foreach ($positionsRaw as $position) {
            if (!is_array($position)) {
                return null;
            }

            $summary = $position['summary'] ?? null;
            $supporting = $position['supporting'] ?? null;

            if (!is_string($summary) || trim($summary) === '') {
                return null;
            }

            if (!is_array($supporting)) {
                return null;
            }

            foreach ($supporting as $delegationId) {
                if (!in_array($delegationId, $validDelegationIds, true)) {
                    return null;
                }
            }

            $positions[] = [
                'summary' => $summary,
                'supportingDelegationIds' => array_values($supporting),
            ];
        }

        return [
            'classification' => $classification,
            'reconciled_answer' => $reconciledAnswer,
            'positions' => $positions,
        ];
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    private function concatMessageText(array $messages): string
    {
        return implode("\n", array_map(fn (array $message) => (string) ($message['content'] ?? ''), $messages));
    }
}
