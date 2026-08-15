<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\ValueObjects\RouterDecision;

/**
 * Decides which agent, if any, an unbound conversation's triggering request
 * should be routed to (102-router-pattern, contracts/routing-mechanism.md
 * §1, research.md D1/D4).
 *
 * Phase 3 (US1) implements only steps 1-4 of the full five-step contract —
 * candidate pool, single-candidate short-circuit (FR-016), and a cheap
 * in-process token-overlap scorer with a deterministic tie-break (D4). Step
 * 5 (the default-handler fallback) is deliberately deferred to Phase 6
 * (tasks.md's own Ordering rationale) — every "no candidates"/"no positive
 * score" exit in this phase returns RouterDecision(null, null, 'none')
 * directly.
 *
 * No constructor dependencies (mirrors AgentQuery's own container-resolved
 * "app(AgentQuery::class)" call convention used elsewhere in this package) —
 * every collaborator this service needs is resolved via app() inside
 * route() itself, so `new RouterService()` and `app(RouterService::class)`
 * are interchangeable.
 *
 * Never throws (contracts §1's own "degrade to null/skip, never throw"
 * posture) — a single malformed candidate (e.g. a missing AgentVersion, or
 * an instructions field that no longer parses) degrades to a score of 0 for
 * that one candidate, never aborting the whole call.
 */
final class RouterService
{
    /**
     * A small built-in English stopword list (research.md D1). Only words
     * of length >= 3 are worth listing here — tokenize() already drops
     * anything shorter regardless of this list.
     */
    private const STOPWORDS = [
        'the', 'and', 'for', 'are', 'but', 'not', 'you', 'your', 'with',
        'have', 'has', 'had', 'this', 'that', 'from', 'was', 'were', 'will',
        'would', 'can', 'could', 'should', 'about', 'into', 'than', 'then',
        'them', 'they', 'their', 'there', 'here', 'who', 'what', 'when',
        'where', 'why', 'how', 'all', 'any', 'both', 'each', 'few', 'more',
        'most', 'other', 'some', 'such', 'only', 'own', 'same', 'she',
        'her', 'his', 'him', 'our', 'out', 'off', 'over', 'under', 'again',
        'once', 'does', 'did', 'doing', 'having', 'been', 'being', 'just',
        'also', 'get', 'got', 'need', 'want',
    ];

    public function route(string $callerUserId, string $triggerText, array $excludeAgentIds = []): RouterDecision
    {
        $candidates = app(AgentQuery::class)->listActiveForUser($callerUserId)
            ->reject(fn (Agent $agent): bool => in_array($agent->id, $excludeAgentIds, true))
            ->values();

        if ($candidates->isEmpty()) {
            return $this->defaultHandlerOrNone($callerUserId);
        }

        if ($candidates->count() === 1) {
            $only = $candidates->first();

            return new RouterDecision($only->id, $only->current_version_id, 'automatic');
        }

        $queryTokens = $this->tokenize($triggerText);

        $winner = null;
        $winnerScore = 0;

        foreach ($candidates as $candidate) {
            $score = $this->scoreCandidate($candidate, $queryTokens);

            if ($score <= 0) {
                continue;
            }

            if ($winner === null || $this->candidateWinsTiebreak($candidate, $score, $winner, $winnerScore)) {
                $winner = $candidate;
                $winnerScore = $score;
            }
        }

        if ($winner === null) {
            return $this->defaultHandlerOrNone($callerUserId);
        }

        return new RouterDecision($winner->id, $winner->current_version_id, 'automatic');
    }

    /**
     * Step 5 (data-model.md §1/D5): consulted only once steps 1-4 have
     * already failed to produce a match — no candidates at all, or no
     * candidate scoring above 0. Found: the caller's designated default
     * handler, reason 'default'. Not found: the original, pre-Phase-6
     * degrade, reason 'none'.
     */
    private function defaultHandlerOrNone(string $callerUserId): RouterDecision
    {
        $default = app(AgentQuery::class)->findDefaultHandler($callerUserId);

        if ($default !== null) {
            return new RouterDecision($default->id, $default->current_version_id, 'default');
        }

        return new RouterDecision(null, null, 'none');
    }

    /**
     * True when $candidate (at $score) should replace $current (at
     * $currentScore) as the running winner. A strictly higher score always
     * wins; an equal, positive score is broken by created_at ascending,
     * then id ascending as a final, stable tie-break (research.md D4).
     */
    private function candidateWinsTiebreak(Agent $candidate, int $score, Agent $current, int $currentScore): bool
    {
        if ($score !== $currentScore) {
            return $score > $currentScore;
        }

        if ($candidate->created_at != $current->created_at) {
            return $candidate->created_at < $current->created_at;
        }

        return strcmp((string) $candidate->id, (string) $current->id) < 0;
    }

    /**
     * The count of distinct query tokens also present in $candidate's own
     * token set (name + declared instructions). Never throws — any failure
     * resolving or parsing the candidate's instructions degrades to a score
     * of 0 for this one candidate only.
     */
    private function scoreCandidate(Agent $candidate, array $queryTokens): int
    {
        try {
            $tokens = $this->tokenize((string) $candidate->name);

            $rawDefinition = $candidate->currentVersion?->raw_definition;

            if ($rawDefinition !== null) {
                try {
                    $instructions = app(AgentDefinitionParser::class)->parse($rawDefinition)->instructions;
                } catch (AgentDefinitionParseException|AgentDefinitionResolutionException) {
                    // Fall back to name-only matching for this one candidate
                    // rather than aborting the whole call (mirrors
                    // AgentQuery::searchForUser()'s own precedent).
                    $instructions = '';
                }

                $tokens = array_merge($tokens, $this->tokenize($instructions));
            }

            return count(array_intersect($queryTokens, array_unique($tokens)));
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Lowercase word extraction (`\b[a-z0-9]+\b`), dropping any token under
     * 3 characters and any built-in stopword (research.md D1).
     *
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        preg_match_all('/\b[a-z0-9]+\b/', strtolower($text), $matches);

        $tokens = array_filter(
            $matches[0],
            static fn (string $token): bool => strlen($token) >= 3 && !in_array($token, self::STOPWORDS, true),
        );

        return array_values(array_unique($tokens));
    }
}
