<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Events\ContextWindowTrimmed;
use Illuminate\Support\Facades\Event;

/**
 * Sliding-window token budgeter for agent conversation history.
 *
 * Given the canonical OpenAI-shaped message array from AgentLoopService::buildMessagesPayload(),
 * returns a trimmed canonical array that fits the resolved history budget while preserving
 * structural invariants — and dispatches ContextWindowTrimmed when trimming occurs.
 *
 * The budgeter is a pure function over arrays with an injected token estimator.
 * No live provider or network calls are made.
 *
 * ResolvedBudget::source is exposed on the ContextWindowTrimmed event as budgetSource
 * (same concept, different field name by design — avoid renaming either).
 */
final class ContextWindowBudgeter
{
    /** @var array<string, mixed> */
    private array $config;

    /** Fixed per-message envelope constant to approximate role/delimiter overhead. */
    private const PER_MESSAGE_ENVELOPE = 4;

    /**
     * @param array|null $config The 'context_window' config block (injected; defaults to config('llm-client.context_window')).
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? self::resolveConfig();
    }

    /**
     * Safely resolve the context_window config block, returning [] when no Laravel app is available.
     */
    private static function resolveConfig(): array
    {
        try {
            return function_exists('config') ? config('llm-client.context_window', []) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Trim the canonical message array to fit the model's history budget.
     *
     * @param list<array{role: string, content: string|null, tool_calls?: array, tool_call_id?: string}> $messages
     *        Output of buildMessagesPayload() (system first).
     * @param string|null $model Conversation model (null → provider default model name).
     * @param ProviderType $provider Effective provider type.
     * @param callable(string): int $estimator Token estimator, e.g. $provider->countTokens(...).
     * @param string $conversationId For the event payload.
     *
     * @return list<array{role: string, content: string|null, tool_calls?: array, tool_call_id?: string}>
     *         Trimmed array (system pinned; newest kept, possibly truncated).
     */
    public function trim(
        array $messages,
        ?string $model,
        ProviderType $provider,
        callable $estimator,
        string $conversationId
    ): array {
        // Check master toggle — passthrough when disabled.
        if (!($this->config['enabled'] ?? true)) {
            return $messages;
        }

        // Deep-copy input to avoid mutation.
        $messages = array_values($messages);
        $tokensBefore = 0;
        foreach ($messages as $m) {
            $tokensBefore += $this->estimateMessage($m, $estimator);
        }

        // Extract system message (always first if present).
        $systemMessage = null;
        $historyMessages = $messages;
        if (!empty($messages) && $messages[0]['role'] === 'system') {
            $systemMessage = array_shift($historyMessages);
        }

        // Resolve budget.
        $resolved = $this->resolveBudget($model, $provider);
        $context = $resolved['context'];
        $responseReserve = $resolved['responseReserve'];
        $source = $resolved['source'];

        // Compute history budget.
        $headroomRatio = (float) ($this->config['headroom_ratio'] ?? 0.15);
        $injectedSectionReserve = (int) ($this->config['injected_section_reserve'] ?? 1500);

        $effectiveContext = (int) floor($context * (1.0 - $headroomRatio));
        $systemEstimate = $systemMessage ? $this->estimateMessage($systemMessage, $estimator) : 0;
        $historyBudget = $effectiveContext - $responseReserve - $injectedSectionReserve - $systemEstimate;

        // Group history messages into turn units.
        $units = $this->groupIntoTurnUnits($historyMessages, $estimator);

        // If no history units, return as-is.
        if (empty($units)) {
            $result = $systemMessage ? [$systemMessage] : [];
            return $result;
        }

        // Calculate total history cost.
        $totalHistoryCost = 0;
        foreach ($units as $unit) {
            $totalHistoryCost += $unit['estimatedTokens'];
        }

        // If total fits, passthrough — no trimming needed.
        if ($totalHistoryCost <= $historyBudget) {
            $result = $systemMessage ? [$systemMessage, ...$historyMessages] : $historyMessages;
            return $result;
        }

        // Newest-first admission: walk units newest → oldest, admit while they fit.
        $admittedIndices = [];
        $remainingBudget = $historyBudget;
        $unitsReversed = array_values(array_reverse($units));

        foreach ($unitsReversed as $revIdx => $unit) {
            $originalIdx = count($units) - 1 - $revIdx;
            if ($unit['estimatedTokens'] <= $remainingBudget) {
                $admittedIndices[] = $originalIdx;
                $remainingBudget -= $unit['estimatedTokens'];
            } else {
                // This unit doesn't fit — it's the newest non-admitted unit.
                // If this is the newest unit (last in original order), truncate it.
                // Otherwise drop it and everything older.
                if ($originalIdx === count($units) - 1) {
                    // Newest unit is oversized — truncate and admit.
                    $truncatedUnit = $this->truncateUnit($unit, $remainingBudget, $estimator);
                    $units[$originalIdx] = $truncatedUnit;
                    $admittedIndices[] = $originalIdx;
                }
                // Stop — everything older is dropped.
                break;
            }
        }

        // Sort admitted indices to restore original order.
        sort($admittedIndices);

        // Build result messages from admitted units.
        $truncated = false;
        $resultMessages = [];
        $unitsDropped = count($units) - count($admittedIndices);

        foreach ($admittedIndices as $idx) {
            $unit = $units[$idx];
            if ($unit['truncated'] ?? false) {
                $truncated = true;
            }
            foreach ($unit['messages'] as $msg) {
                $resultMessages[] = $msg;
            }
        }

        // If no units were admitted (historyBudget ≤ 0), still try to admit
        // a truncated version of the newest unit so the request is not empty.
        if (empty($admittedIndices) && $historyBudget <= 0) {
            $newestUnit = $units[count($units) - 1];
            // Give it a minimal budget: just enough for the envelope + a few chars.
            $minimalBudget = max(1, $historyBudget + $newestUnit['estimatedTokens']);
            $truncatedUnit = $this->truncateUnit($newestUnit, $minimalBudget, $estimator);
            $truncated = true;
            $unitsDropped = count($units) - 1;
            foreach ($truncatedUnit['messages'] as $msg) {
                $resultMessages[] = $msg;
            }
        }

        // Prefix with system message.
        $result = $systemMessage ? [$systemMessage, ...$resultMessages] : $resultMessages;

        $tokensAfter = 0;
        foreach ($result as $m) {
            $tokensAfter += $this->estimateMessage($m, $estimator);
        }

        // Dispatch event when trimming or truncation occurred.
        if ($unitsDropped > 0 || $truncated) {
            Event::dispatch(new ContextWindowTrimmed(
                conversationId: $conversationId,
                model: $model,
                provider: $provider->value,
                budgetSource: $source,
                context: $context,
                historyBudget: $historyBudget,
                tokensBefore: $tokensBefore,
                tokensAfter: $tokensAfter,
                messagesBefore: count($messages),
                messagesAfter: count($result),
                unitsDropped: $unitsDropped,
                truncated: $truncated,
            ));
        }

        return $result;
    }

    /**
     * Estimate the token cost of a single canonical message.
     *
     * @param array $message Canonical message array
     * @param callable(string): int $estimator Token estimator
     * @return int Estimated tokens
     */
    private function estimateMessage(array $message, callable $estimator): int
    {
        $text = '';

        // Content (user, assistant, tool, system)
        if (!empty($message['content'])) {
            $text .= $message['content'];
        }

        // Tool calls (assistant with tool_calls)
        if (!empty($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $call) {
                $text .= $call['function']['name'] ?? '';
                $text .= $call['function']['arguments'] ?? '';
            }
        }

        // Tool call ID (tool role)
        if (!empty($message['tool_call_id'])) {
            $text .= $message['tool_call_id'];
        }

        return $estimator($text) + self::PER_MESSAGE_ENVELOPE;
    }

    /**
     * Resolve the budget from the config tiers: model → provider → fallback.
     *
     * @return array{context: int, responseReserve: int, source: string}
     */
    private function resolveBudget(?string $model, ProviderType $provider): array
    {
        $models = $this->config['models'] ?? [];
        $providers = $this->config['providers'] ?? [];
        $fallback = $this->config['fallback'] ?? ['context' => 8192, 'response_reserve' => 2048];

        // Tier 1: Exact model match.
        if ($model && isset($models[$model])) {
            return [
                'context' => (int) $models[$model]['context'],
                'responseReserve' => (int) $models[$model]['response_reserve'],
                'source' => 'model',
            ];
        }

        // Tier 2: Provider default.
        $providerKey = $provider->value;
        if (isset($providers[$providerKey])) {
            return [
                'context' => (int) $providers[$providerKey]['context'],
                'responseReserve' => (int) $providers[$providerKey]['response_reserve'],
                'source' => 'provider',
            ];
        }

        // Tier 3: Conservative fallback.
        return [
            'context' => (int) $fallback['context'],
            'responseReserve' => (int) $fallback['response_reserve'],
            'source' => 'fallback',
        ];
    }

    /**
     * Group canonical history messages into ordered turn units.
     *
     * A standalone user/plain assistant = one unit.
     * An assistant with tool_calls + contiguous tool results = one atomic unit.
     *
     * @param list<array> $messages History messages (no system)
     * @param callable(string): int $estimator Token estimator
     *
     * @return list<array{messages: list<array>, estimatedTokens: int, isNewest: bool}>
     */
    private function groupIntoTurnUnits(array $messages, callable $estimator): array
    {
        $units = [];
        $i = 0;
        $total = count($messages);

        while ($i < $total) {
            $msg = $messages[$i];

            if ($msg['role'] === 'assistant' && !empty($msg['tool_calls'])) {
                // This is an assistant with tool_calls — gather contiguous tool results.
                $unitMessages = [$msg];
                $i++;
                while ($i < $total && $messages[$i]['role'] === 'tool') {
                    $unitMessages[] = $messages[$i];
                    $i++;
                }
            } else {
                // Standalone user or plain assistant.
                $unitMessages = [$msg];
                $i++;
            }

            $estimatedTokens = 0;
            foreach ($unitMessages as $um) {
                $estimatedTokens += $this->estimateMessage($um, $estimator);
            }

            $units[] = [
                'messages' => $unitMessages,
                'estimatedTokens' => $estimatedTokens,
                'isNewest' => false, // Will be set for the last unit below.
            ];
        }

        // Mark the last unit as newest.
        if (!empty($units)) {
            $units[count($units) - 1]['isNewest'] = true;
        }

        return $units;
    }

    /**
     * Truncate a turn unit's largest text field to fit the remaining budget.
     *
     * @param array $unit Turn unit array
     * @param int $targetBudget Target token budget (may be ≤ 0)
     * @param callable(string): int $estimator Token estimator
     *
     * @return array Truncated unit with 'truncated' => true flag
     */
    private function truncateUnit(array $unit, int $targetBudget, callable $estimator): array
    {
        $messages = $unit['messages'];

        // Determine which message has the largest content to truncate.
        // For tool-call units, truncate the tool result content (keep tool_calls intact).
        $truncateTarget = null;
        $maxContentLength = 0;

        foreach ($messages as $idx => $msg) {
            if ($msg['role'] === 'tool' && !empty($msg['content'])) {
                // Prefer truncating tool results in tool-call units.
                if (strlen($msg['content']) > $maxContentLength) {
                    $maxContentLength = strlen($msg['content']);
                    $truncateTarget = ['idx' => $idx, 'msg' => $msg];
                }
            } elseif ($msg['role'] !== 'assistant' && (!empty($msg['content']))) {
                // For non-tool-call units, truncate the largest content.
                if (strlen($msg['content']) > $maxContentLength) {
                    $maxContentLength = strlen($msg['content']);
                    $truncateTarget = ['idx' => $idx, 'msg' => $msg];
                }
            }
        }

        // Fallback: if no suitable target found, truncate the last message.
        if ($truncateTarget === null) {
            $lastMsg = end($messages);
            $lastIdx = key($messages);
            if ($lastMsg && !empty($lastMsg['content'])) {
                $truncateTarget = ['idx' => $lastIdx, 'msg' => $lastMsg];
            }
        }

        if ($truncateTarget !== null && strlen($truncateTarget['msg']['content']) > 0) {
            // Calculate target character budget from the target token budget.
            // We invert the estimator: for OpenAI (÷4), chars = tokens * 4.
            // We aim for: estimator(content) + envelope ≤ targetBudget
            // So content_chars ≈ (targetBudget - envelope) * divisor
            // Since we don't know the exact divisor, we use binary search.
            $originalContent = $truncateTarget['msg']['content'];
            $targetTokens = max(1, $targetBudget - self::PER_MESSAGE_ENVELOPE);

            // Binary search for the right content length.
            $low = 1;
            $high = strlen($originalContent);
            $bestLength = 1;

            while ($low <= $high) {
                $mid = (int) floor(($low + $high) / 2);
                $sample = mb_substr($originalContent, 0, $mid);
                $tokens = $estimator($sample);
                if ($tokens <= $targetTokens) {
                    $bestLength = $mid;
                    $low = $mid + 1;
                } else {
                    $high = $mid - 1;
                }
            }

            // Truncate and add marker.
            $marker = '[truncated]';
            $contentLength = max(1, $bestLength - strlen($marker));
            $truncatedContent = mb_substr($originalContent, 0, $contentLength) . $marker;

            // Create a deep copy of the message with truncated content.
            $newMessages = $messages;
            $newMessages[$truncateTarget['idx']] = array_merge(
                $newMessages[$truncateTarget['idx']],
                ['content' => $truncatedContent]
            );
            $messages = $newMessages;
        }

        return [
            'messages' => $messages,
            'estimatedTokens' => $unit['estimatedTokens'], // Estimate is now lower but we keep the flag.
            'isNewest' => $unit['isNewest'],
            'truncated' => true,
        ];
    }
}
