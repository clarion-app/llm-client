<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Events\SmartHistoryTrimmed;
use ClarionApp\LlmClient\ValueObjects\MessageScore;
use ClarionApp\LlmClient\ValueObjects\TrimAudit;
use ClarionApp\LlmClient\ValueObjects\TrimDecision;
use Illuminate\Support\Facades\Event;

/**
 * Value-aware history trimming service.
 *
 * Scores messages via MessageScorer, then evicts lowest-value turn units first
 * while preserving recent message pairs and pinned content. Uses atomic turn
 * unit grouping (assistant + tool_calls + tool_results as one unit) to maintain
 * sequence validity.
 */
class SmartHistoryTrimmer
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct(
        private readonly MessageScorer $scorer,
        private readonly ?CoherenceValidator $coherenceValidator = null,
        ?array $config = null,
    ) {
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
     * Trim messages to fit within the token budget using value-aware eviction.
     *
     * @param list<array{role: string, content: string|null, tool_calls?: array, tool_call_id?: string}> $messages
     *        Canonical message array (system first if present).
     * @param int $budgetTokens Target token budget for history (excludes system).
     * @param callable(string): int $estimator Token estimator.
     * @param string $conversationId For cache keys and event payload.
     *
     * @return list<array{role: string, content: string|null, tool_calls?: array, tool_call_id?: string}>
     *         Trimmed message array.
     */
    public function trim(
        array $messages,
        int $budgetTokens,
        callable $estimator,
        string $conversationId,
    ): array {
        // Check master toggle
        if (!($this->config['enabled'] ?? true)) {
            return $messages;
        }

        // Deep-copy to avoid mutation
        $messages = array_values($messages);

        // Extract system message
        $systemMessage = null;
        $historyMessages = $messages;
        if (!empty($messages) && $messages[0]['role'] === 'system') {
            $systemMessage = array_shift($historyMessages);
        }

        // Estimate total tokens for history messages
        $tokensBefore = 0;
        foreach ($historyMessages as $m) {
            $tokensBefore += $this->estimateMessage($m, $estimator);
        }

        // If total fits, no trimming needed
        if ($tokensBefore <= $budgetTokens) {
            $result = $systemMessage ? [$systemMessage, ...$historyMessages] : $historyMessages;
            return $result;
        }

        // Score messages
        $scores = $this->scorer->computeScores($historyMessages);

        // Group into turn units (reuse ContextWindowBudgeter logic via local implementation)
        $units = $this->groupIntoTurnUnits($historyMessages, $estimator);

        // Determine preserved unit indices (most recent N pairs)
        // Each pair = user message + assistant response = 2 units
        $preservedPairs = (int) ($this->config['preserved_pairs'] ?? 10);
        $preservedUnitCount = min($preservedPairs * 2, count($units));
        $preservedIndices = [];
        for ($i = count($units) - $preservedUnitCount; $i < count($units); $i++) {
            if ($i >= 0) {
                $preservedIndices[] = $i;
            }
        }

        // Build score map per unit (use the minimum score of messages in the unit)
        $unitScores = [];
        foreach ($units as $unitIndex => $unit) {
            $minScore = 1.0;
            $reason = 'unit';
            $pinned = false;
            foreach ($unit['messageIndices'] as $msgIdx) {
                $score = $scores[$msgIdx] ?? new MessageScore($msgIdx, 0.5, 'unknown');
                if ($score->score < $minScore) {
                    $minScore = $score->score;
                    $reason = $score->reason;
                }
                if ($score->pinned) {
                    $pinned = true;
                }
            }
            $unitScores[$unitIndex] = [
                'score' => $minScore,
                'reason' => $reason,
                'pinned' => $pinned,
            ];
        }

        // Evict lowest-value units first (oldest first among same score tier)
        $decisions = [];
        $remainingTokens = $tokensBefore;
        $evictedIndices = [];

        // Sort units by score ascending (lowest value first), then by index ascending (oldest first)
        $sortable = [];
        foreach ($units as $unitIndex => $unit) {
            $sortable[] = [
                'unitIndex' => $unitIndex,
                'score' => $unitScores[$unitIndex]['score'],
                'pinned' => $unitScores[$unitIndex]['pinned'],
                'preserved' => in_array($unitIndex, $preservedIndices, true),
                'tokens' => $unit['estimatedTokens'],
                'reason' => $unitScores[$unitIndex]['reason'],
            ];
        }

        usort($sortable, function ($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $a['score'] <=> $b['score'];
            }
            return $a['unitIndex'] <=> $b['unitIndex'];
        });

        // Evict units until we fit the budget
        foreach ($sortable as $candidate) {
            if ($remainingTokens <= $budgetTokens) {
                break;
            }

            $unitIndex = $candidate['unitIndex'];

            // Skip pinned units
            if ($candidate['pinned']) {
                $decisions[] = new TrimDecision(
                    messageIndex: $unitIndex,
                    action: 'pinned_protected',
                    score: $candidate['score'],
                    reason: 'pinned_content_protected',
                );
                continue;
            }

            // Skip preserved (recent) units
            if ($candidate['preserved']) {
                continue;
            }

            // Evict this unit
            $evictedIndices[] = $unitIndex;
            $remainingTokens -= $candidate['tokens'];

            $decisions[] = new TrimDecision(
                messageIndex: $unitIndex,
                action: 'dropped',
                score: $candidate['score'],
                reason: $candidate['reason'],
                tokenSavings: $candidate['tokens'],
            );
        }

        // Build retained decisions for non-evicted units
        foreach ($units as $unitIndex => $unit) {
            if (!in_array($unitIndex, $evictedIndices, true)) {
                $decisions[] = new TrimDecision(
                    messageIndex: $unitIndex,
                    action: 'retained',
                    score: $unitScores[$unitIndex]['score'],
                    reason: 'retained',
                );
            }
        }

        // If coherence validator is available, check for dangling references
        if ($this->coherenceValidator && !empty($evictedIndices)) {
            $cascadeIndices = $this->coherenceValidator->validate(
                $historyMessages,
                $units,
                $evictedIndices,
                $preservedIndices,
            );
            $evictedIndices = array_values(array_unique([...$evictedIndices, ...$cascadeIndices]));

            // Add cascade decisions
            foreach ($cascadeIndices as $cascadeIdx) {
                if (!in_array($cascadeIdx, $evictedIndices, true) || true) {
                    $decisions[] = new TrimDecision(
                        messageIndex: $cascadeIdx,
                        action: 'dropped_cascade',
                        score: $unitScores[$cascadeIdx]['score'] ?? 0.5,
                        reason: 'dangling_reference_cascade',
                        tokenSavings: $units[$cascadeIdx]['estimatedTokens'] ?? 0,
                    );
                }
            }
        }

        // Build result: only include non-evicted units
        sort($evictedIndices);
        $resultMessages = [];
        foreach ($units as $unitIndex => $unit) {
            if (!in_array($unitIndex, $evictedIndices, true)) {
                foreach ($unit['messages'] as $msg) {
                    $resultMessages[] = $msg;
                }
            }
        }

        // Prefix with system message
        $result = $systemMessage ? [$systemMessage, ...$resultMessages] : $resultMessages;

        // Compute final token count
        $tokensAfter = 0;
        foreach ($resultMessages as $m) {
            $tokensAfter += $this->estimateMessage($m, $estimator);
        }

        // Build and emit audit event
        $audit = new TrimAudit(
            conversationId: $conversationId,
            messagesBefore: count($messages),
            messagesAfter: count($result),
            tokensBefore: $tokensBefore + ($systemMessage ? $this->estimateMessage($systemMessage, $estimator) : 0),
            tokensAfter: $tokensAfter + ($systemMessage ? $this->estimateMessage($systemMessage, $estimator) : 0),
            decisions: $decisions,
        );

        if (($this->config['emit_events'] ?? true) && count($evictedIndices) > 0) {
            Event::dispatch(new SmartHistoryTrimmed($audit));
        }

        return $result;
    }

    /**
     * Group history messages into turn units with message index tracking.
     *
     * @param list<array> $messages History messages (no system)
     * @param callable(string): int $estimator Token estimator
     *
     * @return list<array{messages: list<array>, messageIndices: list<int>, estimatedTokens: int}>
     */
    private function groupIntoTurnUnits(array $messages, callable $estimator): array
    {
        $units = [];
        $i = 0;
        $total = count($messages);

        while ($i < $total) {
            $msg = $messages[$i];
            $startIdx = $i;

            if ($msg['role'] === 'assistant' && !empty($msg['tool_calls'])) {
                // Assistant with tool_calls — gather contiguous tool results
                $unitMessages = [$msg];
                $unitIndices = [$i];
                $i++;
                while ($i < $total && $messages[$i]['role'] === 'tool') {
                    $unitMessages[] = $messages[$i];
                    $unitIndices[] = $i;
                    $i++;
                }
            } else {
                // Standalone user or plain assistant
                $unitMessages = [$msg];
                $unitIndices = [$i];
                $i++;
            }

            $estimatedTokens = 0;
            foreach ($unitMessages as $um) {
                $estimatedTokens += $this->estimateMessage($um, $estimator);
            }

            $units[] = [
                'messages' => $unitMessages,
                'messageIndices' => $unitIndices,
                'estimatedTokens' => $estimatedTokens,
            ];
        }

        return $units;
    }

    /**
     * Estimate token cost of a single canonical message.
     */
    private function estimateMessage(array $message, callable $estimator): int
    {
        $text = '';

        if (!empty($message['content'])) {
            $text .= $message['content'];
        }

        if (!empty($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $call) {
                $text .= $call['function']['name'] ?? '';
                $text .= $call['function']['arguments'] ?? '';
            }
        }

        if (!empty($message['tool_call_id'])) {
            $text .= $message['tool_call_id'];
        }

        return $estimator($text) + 4; // Per-message envelope
    }
}
