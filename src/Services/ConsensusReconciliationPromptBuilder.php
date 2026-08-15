<?php

namespace ClarionApp\LlmClient\Services;

/**
 * Pure prompt construction for a single reconciliation call -- no I/O, no
 * provider call, no randomness. Mirrors RubricJudgmentPromptBuilder's own
 * shape (104-multi-agent-consensus, Grounding note item 3,
 * contracts/consensus-reconciliation-contract.md §2): a system message
 * carrying the fixed approximation-caveat disclaimer (research.md D3 --
 * "this is an approximation, not a guarantee", stated here rather than
 * left to the judge model so its presence can never be dropped by prompt
 * drift) plus a strict JSON-only output contract, and a user message
 * carrying the original question and every contributor's answer, each
 * keyed by its own delegation_id so the judge's own `positions[].supporting`
 * entries can name them back unambiguously (contracts §3).
 */
final class ConsensusReconciliationPromptBuilder
{
    /**
     * @param  array<int, array{delegation_id: string, helper_agent_id: string, answer: string}>  $contributorAnswers
     * @return list<array{role: string, content: string}>
     */
    public function buildMessages(string $question, array $contributorAnswers): array
    {
        return [
            ['role' => 'system', 'content' => $this->systemMessage()],
            ['role' => 'user', 'content' => $this->userMessage($question, $contributorAnswers)],
        ];
    }

    private function systemMessage(): string
    {
        return implode("\n\n", [
            'You are reconciling several independent contributors\' answers to '
                .'the same question into a single account of whether they agree. '
                .'Judge SUBSTANTIVE agreement, not wording -- two answers that '
                .'reach the same conclusion in different words agree; two answers '
                .'that use similar words but reach opposite conclusions do not.',
            'This is an approximation, not a guarantee: your classification is '
                .'advisory, not authoritative ground truth.',
            'Respond with JSON only -- no surrounding prose, no markdown fencing '
                .'-- in exactly this shape: {"classification": "agreed"|'
                .'"materially_disagreed"|"no_consensus", "reconciled_answer": '
                .'"<string>", "positions": [{"summary": "<string>", '
                .'"supporting": ["<delegation_id>", ...]}]}. positions MUST be '
                .'an empty array when classification is "agreed", and MUST name '
                .'at least two positions otherwise -- each position\'s '
                .'"supporting" array MUST only ever name delegation_ids given to '
                .'you below.',
        ]);
    }

    /**
     * @param  array<int, array{delegation_id: string, helper_agent_id: string, answer: string}>  $contributorAnswers
     */
    private function userMessage(string $question, array $contributorAnswers): string
    {
        $lines = ["Question:\n".$question, ''];

        foreach ($contributorAnswers as $contributor) {
            $lines[] = "Contributor [{$contributor['delegation_id']}]:\n{$contributor['answer']}";
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }
}
