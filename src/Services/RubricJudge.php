<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\BudgetExceededException;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\RubricJudgmentResult;
use Illuminate\Support\Str;

/**
 * Scores a single response against operator-authored rubric criteria by
 * making one bounded, synchronous LlmProvider::chat() call, resolved
 * through the Judge role exactly like every other model-consuming role in
 * this package.
 *
 * Never throws. Every failure mode — an unassigned or broken judge role, a
 * refused spending ceiling, a provider request failure, or a malformed/
 * out-of-range model response — converges on the same explicit unjudged
 * result with a human-readable reason, never a thrown exception and never a
 * silently-assumed pass or fail. This is deliberate: a judging failure must
 * never turn an already-successful agent execution into an error, and there
 * is no synchronous caller waiting to be told immediately the way a live
 * turn would be.
 */
final class RubricJudge
{
    /**
     * @param  list<array<string, mixed>>  $attemptedActions  each shaped
     *   ['tool' => ?string, 'arguments' => array]
     * @param  string  $source  a short, stable label identifying the caller
     *   for budget-refusal records — 'eval_rubric_judgment' for an in-run
     *   judgment, 'eval_judgment_consistency_check' for a consistency
     *   sample repeat.
     */
    public function judge(
        string $criteria,
        string $given,
        ?string $producedResponse,
        array $attemptedActions,
        Conversation $judgeConversation,
        string $source,
    ): RubricJudgmentResult {
        try {
            $resolution = app(RoleResolver::class)->resolve(ModelRole::Judge, null);

            if (!$resolution->hasEffectiveModel()) {
                return RubricJudgmentResult::unjudged(
                    $resolution->brokenReason ?? 'No judge model is assigned.',
                );
            }

            try {
                app(BudgetGate::class)->admit(
                    null,
                    BudgetWorkKind::SystemInitiated,
                    $judgeConversation->id,
                    $source,
                );
            } catch (BudgetExceededException) {
                return RubricJudgmentResult::unjudged('spending ceiling reached');
            }

            $messages = app(RubricJudgmentPromptBuilder::class)->buildMessages(
                $criteria,
                $given,
                $producedResponse,
                $attemptedActions,
            );

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
                return RubricJudgmentResult::unjudged('provider request failed: '.$e->getMessage());
            }

            $content = $response['choices'][0]['message']['content'] ?? null;
            $parsed = $this->extractScoreAndJustification($content);

            if ($parsed === null) {
                return RubricJudgmentResult::unjudged('malformed judge response');
            }

            $scoreScaleMax = (int) config('llm-client.eval_judging.score_scale_max', 10);

            if ($parsed['score'] < 1 || $parsed['score'] > $scoreScaleMax) {
                return RubricJudgmentResult::unjudged('malformed judge response');
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
                agentId: 'eval-judge',
            );

            return RubricJudgmentResult::judged(
                $parsed['score'],
                $parsed['justification'],
                $resolution->model,
                $resolution->server->id,
                $judgeConversation->id,
            );
        } catch (\Throwable $e) {
            // A final backstop, not a named branch of its own: nothing
            // above should reach here, but a judging-side surprise must
            // still never propagate past this method (research.md D7).
            return RubricJudgmentResult::unjudged('unexpected judging error: '.$e->getMessage());
        }
    }

    /**
     * Parse a judge response's raw text into a validated score/justification
     * pair, tolerating a provider that ignores the response_format hint and
     * wraps the JSON object in extra prose. Returns null on any parse or
     * shape failure — the caller treats null as "malformed judge response".
     *
     * @return array{score: int, justification: string}|null
     */
    private function extractScoreAndJustification(?string $content): ?array
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

        if (!array_key_exists('score', $decoded) || !is_int($decoded['score'])) {
            return null;
        }

        if (
            !array_key_exists('justification', $decoded)
            || !is_string($decoded['justification'])
            || trim($decoded['justification']) === ''
        ) {
            return null;
        }

        return ['score' => $decoded['score'], 'justification' => $decoded['justification']];
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    private function concatMessageText(array $messages): string
    {
        return implode("\n", array_map(fn (array $message) => (string) ($message['content'] ?? ''), $messages));
    }
}
