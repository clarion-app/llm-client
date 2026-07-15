<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\ValueObjects\MessageScore;
use Illuminate\Support\Facades\Cache;

/**
 * Deterministic scoring engine for conversation messages.
 *
 * Assigns a value score (0.0–1.0) to each message based on role, content patterns,
 * tool-call relationships, recency, and user-pinned keywords. No LLM calls on the hot path.
 */
class MessageScorer
{
    /** @var array<string, mixed> */
    private array $config;

    /** Pleasantry patterns for short messages (< 50 chars). */
    private const PLEASANTRY_PATTERNS = [
        '/\b(hi|hello|hey|sup|yo)\b/i',
        '/\b(thanks|thank you|thx|ty)\b/i',
        '/\b(yes|yeah|yep|sure|ok|okay)\b/i',
        '/\b(no|nope|nah)\b/i',
        '/\b(um|uh|hmm|er)\b/i',
        '/\b(roger|copied|got it|understood|noted)\b/i',
        '/^[.!?]+$/i',
        '/\b(nice|cool|great|awesome)\b/i',
    ];

    /** Pin keywords that mark content as user-pinned (score 1.0, exempt from trimming). */
    private const PIN_PATTERNS = [
        '/\b(remember|keep\s+this\s+in\s+mind)\b/i',
        '/do[sn]\'\s*t\s*forget/i',
        '/do\s+not\s+forget/i',
        '/\b(this\s+is\s+important|important:\s|critical:\s|must\s+remember)\b/i',
        '/\b(save\s+this|hold\s+onto\s+this|retain\s+this)\b/i',
    ];

    /** Error indicators in tool results. */
    private const ERROR_PATTERNS = [
        '/\b(error|failed|failure|exception|fatal|panic)\b/i',
        '/\b(4[0-9]{2}|5[0-9]{2})\s+(not\s+found|forbidden|unauthorized|server\s+error)/i',
        '/^error[:\s]/i',
    ];

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? self::resolveConfig();
    }

    private static function resolveConfig(): array
    {
        try {
            return function_exists('config')
                ? config('llm-client.smart_history_trimming', [])
                : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Score all messages in a conversation history.
     *
     * @param list<array{role: string, content: string|null, tool_calls?: array, tool_call_id?: string}> $messages
     * @param string $conversationId For cache key
     *
     * @return list<MessageScore> One score per message index
     */
    public function scoreMessages(array $messages, string $conversationId): array
    {
        $historyHash = $this->computeHistoryHash($messages);
        $cacheKey = "smart_trim_scores:{$conversationId}:{$historyHash}";

        // Try cache first
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $this->deserializeScores($cached);
        }

        // Compute scores
        $scores = $this->computeScores($messages);

        // Cache results
        $ttl = (int) ($this->config['score_cache_ttl_minutes'] ?? 5);
        Cache::put($cacheKey, $this->serializeScores($scores), now()->addMinutes($ttl));

        return $scores;
    }

    /**
     * Compute scores for all messages using deterministic heuristics.
     *
     * @param list<array{role: string, content: string|null, tool_calls?: array, tool_call_id?: string}> $messages
     * @return list<MessageScore>
     */
    public function computeScores(array $messages): array
    {
        $scores = [];
        $total = count($messages);

        // First pass: role-based base scores + pin detection
        foreach ($messages as $index => $message) {
            $scores[$index] = $this->scoreByRole($message, $index);
        }

        // Second pass: tool-call relationship analysis
        // Detect superseded tool results and resolved errors
        $this->analyzeToolRelationships($messages, $scores);

        return $scores;
    }

    /**
     * Score a single message based on its role and content.
     */
    private function scoreByRole(array $message, int $index): MessageScore
    {
        $role = $message['role'] ?? 'user';
        $content = $message['content'] ?? '';

        // System messages are always pinned
        if ($role === 'system') {
            return new MessageScore(
                messageIndex: $index,
                score: 1.0,
                reason: 'system_message',
                pinned: true,
            );
        }

        // Check for pin keywords in user messages
        if ($role === 'user' && $this->hasPinKeywords($content)) {
            return new MessageScore(
                messageIndex: $index,
                score: 1.0,
                reason: 'user_pinned',
                pinned: true,
            );
        }

        // Assistant with tool_calls (active decisions)
        if ($role === 'assistant' && !empty($message['tool_calls'])) {
            return new MessageScore(
                messageIndex: $index,
                score: 0.7,
                reason: 'assistant_with_tool_calls',
            );
        }

        // Tool results — base score (may be adjusted by relationship analysis)
        if ($role === 'tool') {
            // Check if this is an error
            if ($this->isErrorResult($content)) {
                return new MessageScore(
                    messageIndex: $index,
                    score: 0.3,
                    reason: 'tool_error',
                );
            }
            return new MessageScore(
                messageIndex: $index,
                score: 0.5,
                reason: 'tool_result',
            );
        }

        // User messages
        if ($role === 'user') {
            // Check for pleasantries (short messages only)
            if (strlen($content) < 50 && $this->isPleasantry($content)) {
                return new MessageScore(
                    messageIndex: $index,
                    score: 0.2,
                    reason: 'pleasantry',
                );
            }
            return new MessageScore(
                messageIndex: $index,
                score: 0.9,
                reason: 'user_message',
            );
        }

        // Plain assistant messages (reasoning/decisions)
        if ($role === 'assistant') {
            // Check for pleasantries
            if (strlen($content) < 50 && $this->isPleasantry($content)) {
                return new MessageScore(
                    messageIndex: $index,
                    score: 0.2,
                    reason: 'pleasantry',
                );
            }
            return new MessageScore(
                messageIndex: $index,
                score: 0.6,
                reason: 'assistant_statement',
            );
        }

        // Unknown role — medium score
        return new MessageScore(
            messageIndex: $index,
            score: 0.5,
            reason: 'unknown_role',
        );
    }

    /**
     * Analyze tool-call relationships to detect superseded results and resolved errors.
     *
     * Tracks tool_call_id → tool_name mappings and marks earlier results as superseded
     * when a later successful call to the same tool exists. Also marks errors as resolved
     * when a subsequent call to the same tool succeeds.
     */
    private function analyzeToolRelationships(array $messages, array &$scores): void
    {
        // Build map: tool_name → list of [index, is_error, tool_call_id]
        $toolResults = [];
        $toolCallChains = []; // tool_call_id → tool_name

        foreach ($messages as $index => $message) {
            // Track assistant tool_calls
            if ($message['role'] === 'assistant' && !empty($message['tool_calls'])) {
                foreach ($message['tool_calls'] as $call) {
                    $callId = $call['id'] ?? '';
                    $toolName = $call['function']['name'] ?? '';
                    if ($callId && $toolName) {
                        $toolCallChains[$callId] = $toolName;
                    }
                }
            }

            // Track tool results
            if ($message['role'] === 'tool') {
                $callId = $message['tool_call_id'] ?? '';
                $content = $message['content'] ?? '';
                $toolName = $toolCallChains[$callId] ?? 'unknown';
                $isError = $this->isErrorResult($content);

                if (!isset($toolResults[$toolName])) {
                    $toolResults[$toolName] = [];
                }
                $toolResults[$toolName][] = [
                    'index' => $index,
                    'is_error' => $isError,
                    'call_id' => $callId,
                ];
            }
        }

        // For each tool, if there's a later success, mark earlier results as superseded
        foreach ($toolResults as $toolName => $results) {
            if (count($results) < 2) {
                continue;
            }

            // Find the last non-error result
            $lastSuccessIndex = null;
            for ($i = count($results) - 1; $i >= 0; $i--) {
                if (!$results[$i]['is_error']) {
                    $lastSuccessIndex = $i;
                    break;
                }
            }

            if ($lastSuccessIndex === null) {
                continue;
            }

            // Mark all results before the last success as superseded
            for ($i = 0; $i < $lastSuccessIndex; $i++) {
                $msgIndex = $results[$i]['index'];

                // If it was an error and now resolved, mark as resolved_error
                if ($results[$i]['is_error']) {
                    $scores[$msgIndex] = new MessageScore(
                        messageIndex: $msgIndex,
                        score: 0.1,
                        reason: 'resolved_error',
                    );
                } else {
                    // Non-error result superseded by later result
                    $scores[$msgIndex] = new MessageScore(
                        messageIndex: $msgIndex,
                        score: 0.1,
                        reason: 'superseded_tool_result',
                    );
                }
            }
        }
    }

    /**
     * Check if content matches pleasantry patterns.
     */
    private function isPleasantry(string $content): bool
    {
        $trimmed = trim($content);
        if (strlen($trimmed) === 0) {
            return false;
        }

        foreach (self::PLEASANTRY_PATTERNS as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if content contains pin keywords.
     */
    private function hasPinKeywords(string $content): bool
    {
        $trimmed = trim($content);
        if (strlen($trimmed) === 0) {
            return false;
        }

        foreach (self::PIN_PATTERNS as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a tool result indicates an error.
     */
    private function isErrorResult(string $content): bool
    {
        $trimmed = trim($content);
        if (strlen($trimmed) === 0) {
            return false;
        }

        foreach (self::ERROR_PATTERNS as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compute a hash of the message history for cache invalidation.
     */
    private function computeHistoryHash(array $messages): string
    {
        $parts = [];
        foreach ($messages as $message) {
            $parts[] = ($message['role'] ?? '') . '|' . ($message['content'] ?? '');
        }

        return hash('xxh128', implode('||', $parts));
    }

    /**
     * Serialize scores for caching.
     */
    private function serializeScores(array $scores): string
    {
        $data = [];
        foreach ($scores as $score) {
            $data[] = [
                'messageIndex' => $score->messageIndex,
                'score' => $score->score,
                'reason' => $score->reason,
                'dependsOn' => $score->dependsOn,
                'pinned' => $score->pinned,
            ];
        }

        return json_encode($data);
    }

    /**
     * Deserialize scores from cache.
     */
    private function deserializeScores(string $data): array
    {
        $array = json_decode($data, true) ?? [];
        $scores = [];

        foreach ($array as $item) {
            $scores[] = new MessageScore(
                messageIndex: (int) ($item['messageIndex'] ?? 0),
                score: (float) ($item['score'] ?? 0.5),
                reason: $item['reason'] ?? 'unknown',
                dependsOn: $item['dependsOn'] ?? [],
                pinned: (bool) ($item['pinned'] ?? false),
            );
        }

        return $scores;
    }
}
