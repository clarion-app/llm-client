<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Events\ConversationCondensed;
use ClarionApp\LlmClient\Jobs\PreWarmChunkSummaryJob;
use ClarionApp\LlmClient\Models\ChunkSummary;
use ClarionApp\LlmClient\Presets\CondensationPreset;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use Illuminate\Support\Facades\Event;

class ConversationCondenser
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct(
        private ChunkPartitioner $partitioner,
        private CondensationSummaryStore $store,
        private ContextWindowBudgeter $budgeter,
        private CondensationPreset $preset,
        private ?LlmProvider $condensationProvider = null,
        private ?ProviderRegistry $providerRegistry = null,
        ?array $config = null
    ) {
        $this->config = $config ?? self::resolveConfig();
    }

    private static function resolveConfig(): array
    {
        try {
            return function_exists('config') ? config('llm-client.condensation', []) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Condense or trim the message array.
     *
     * @param list<array{role: string, content: string|null, tool_calls?: array, tool_call_id?: string}> $messages
     * @param callable(string): int $estimator Token estimator
     * @return list<array{role: string, content: string|null, tool_calls?: array, tool_call_id?: string}>
     */
    public function condenseOrTrim(
        array $messages,
        ?string $model,
        ProviderType $provider,
        callable $estimator,
        string $conversationId,
        ?int $historyBudget = null
    ): array {
        // Fallback: condensation disabled
        if (!($this->config['enabled'] ?? true)) {
            return $this->budgeter->trim($messages, $model, $provider, $estimator, $conversationId);
        }

        // Fallback: in cooldown
        if ($this->store->inCooldown($conversationId)) {
            return $this->budgeter->trim($messages, $model, $provider, $estimator, $conversationId);
        }

        // Deep-copy to avoid mutation
        $messages = array_values($messages);

        // Extract system message
        $systemMessage = null;
        $historyMessages = $messages;
        if (!empty($messages) && $messages[0]['role'] === 'system') {
            $systemMessage = array_shift($historyMessages);
        }

        // Use provided budget or compute from budgeter
        if ($historyBudget === null) {
            $historyBudget = $this->computeHistoryBudget($messages, $model, $provider, $estimator);
        }

        // Estimate total tokens for history messages
        $totalTokens = 0;
        foreach ($historyMessages as $m) {
            $totalTokens += $this->estimateMessage($m, $estimator);
        }

        // If total fits within budget, no condensation needed
        if ($totalTokens <= $historyBudget) {
            $result = $systemMessage ? [$systemMessage, ...$historyMessages] : $historyMessages;
            return $result;
        }

        // Determine verbatim boundary: how many newest messages fit the budget
        $verbatimCount = $this->calculateVerbatimBoundary($historyMessages, $historyBudget, $estimator);
        $totalMessages = count($historyMessages);
        $chunkSize = (int) ($this->config['chunk_size'] ?? 20);

        // If all messages fit in verbatim, no condensation needed
        if ($verbatimCount >= $totalMessages) {
            $result = $systemMessage ? [$systemMessage, ...$historyMessages] : $historyMessages;
            return $result;
        }

        // Find sealed chunks among the dropped portion
        $sealedChunks = $this->partitioner->findSealedChunks($historyMessages, $verbatimCount, $chunkSize);

        if (empty($sealedChunks)) {
            // No sealed chunks to condense — fall back to trimming
            return $this->budgeter->trim($messages, $model, $provider, $estimator, $conversationId);
        }

        // Look up or produce summaries for each sealed chunk
        $summaries = [];
        $condensationNeeded = [];

        foreach ($sealedChunks as $chunkIndex) {
            $sourceHash = $this->partitioner->computeSourceHash($historyMessages, $chunkIndex, $chunkSize);
            $cached = $this->store->get($conversationId, $chunkIndex, $sourceHash);

            if ($cached) {
                $summaries[$chunkIndex] = $cached;
            } else {
                $condensationNeeded[] = ['chunkIndex' => $chunkIndex, 'sourceHash' => $sourceHash];
            }
        }

        // Condense missing chunks (at most one per request per spec)
        foreach ($condensationNeeded as $missing) {
            $result = $this->condenseChunk(
                $conversationId,
                $missing['chunkIndex'],
                $missing['sourceHash'],
                $historyMessages,
                $chunkSize,
                $model,
                $provider,
                $estimator
            );

            if ($result !== null) {
                $summaries[$missing['chunkIndex']] = $result;
            } else {
                // Condensation failed — fall back to trimming
                return $this->budgeter->trim($messages, $model, $provider, $estimator, $conversationId);
            }
        }

        // Sort summaries by chunk index (chronological order)
        ksort($summaries);

        // Opportunistic pre-warm: dispatch job for the next unsealed chunk
        $this->dispatchPreWarmJobs($conversationId, $historyMessages, $chunkSize, $sealedChunks);

        // Render summaries to text
        $renderedSummaries = $this->renderSummaries(array_values($summaries));

        // Get verbatim recent portion
        $verbatimStart = $totalMessages - $verbatimCount;
        $verbatimMessages = array_slice($historyMessages, $verbatimStart);

        // Assemble: [system] + [summaries] + [verbatim recent]
        $assembled = [];
        if ($systemMessage) {
            $assembled[] = $systemMessage;
        }
        $assembled[] = ['role' => 'system', 'content' => $renderedSummaries];
        $assembled = array_merge($assembled, $verbatimMessages);

        // Check if assembled fits — compare against the original history cost
        // (not the budget), since condensation is meant to compress
        $assembledTokens = 0;
        foreach ($assembled as $m) {
            $assembledTokens += $this->estimateMessage($m, $estimator);
        }

        // Compression is successful if assembled is smaller than original total
        if ($assembledTokens <= $totalTokens) {
            return $assembled;
        }

        // Assembled is larger than original (unlikely with proper summaries)
        // Fall back to trimming
        return $this->budgeter->trim($messages, $model, $provider, $estimator, $conversationId);
    }

    /**
     * Condense a single chunk.
     */
    private function condenseChunk(
        string $conversationId,
        int $chunkIndex,
        string $sourceHash,
        array $messages,
        int $chunkSize,
        ?string $model,
        ProviderType $provider,
        callable $estimator
    ): ?ChunkSummary {
        // Build the chunk messages for the LLM
        $chunkMessages = $this->partitioner->partition($messages, $chunkSize);
        $chunkContent = $chunkMessages[$chunkIndex] ?? [];

        if (empty($chunkContent)) {
            return null;
        }

        // Build transcript for this chunk
        $transcript = '';
        foreach ($chunkContent as $msg) {
            $transcript .= ($msg['role'] ?? 'user') . ': ' . ($msg['content'] ?? '') . "\n";
        }

        $produce = function () use ($transcript, $conversationId, $chunkIndex, $sourceHash, $chunkContent, $model, $provider, $estimator) {
            $llmProvider = $this->resolveCondensationProvider($model, $provider);

            if (!$llmProvider) {
                throw new \RuntimeException('No condensation provider available');
            }

            $condensationModel = $this->config['model'] ?? $model;
            $condensationProviderValue = $this->config['provider']
                ? $this->config['provider']
                : $provider->value;

            $timeout = (int) ($this->config['timeout_seconds'] ?? 20);

            $systemPrompt = $this->preset->getSystemPrompt();
            $schema = $this->preset->getSchema();

            $options = [
                'response_format' => 'json',
                'temperature' => 0.3,
            ];

            if ($condensationModel) {
                $options['model'] = $condensationModel;
            }

            $result = $llmProvider->chat([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $transcript],
            ], [], $options);

            $content = $result['choices'][0]['message']['content'] ?? null;
            if (!$content) {
                throw new \RuntimeException('Empty condensation response');
            }

            $parsed = json_decode($content, true);
            if (!$parsed || !isset($parsed['decisions'])) {
                throw new \RuntimeException('Invalid condensation JSON response');
            }

            // Estimate summary tokens
            $rendered = $this->renderSingleSummary($parsed);
            $summaryTokens = (int) ceil(strlen($rendered) / 4);

            return [
                'summary' => $parsed,
                'summary_tokens' => $summaryTokens,
                'usage' => $result['usage'] ?? null,
                'condensation_model' => $condensationModel,
                'condensation_provider' => $condensationProviderValue,
            ];
        };

        $summary = $this->store->remember($conversationId, $chunkIndex, $sourceHash, $produce);

        if ($summary) {
            // Dispatch event
            $usage = [];
            // Try to get usage from the result (stored in the summary row metadata)
            Event::dispatch(new ConversationCondensed(
                conversationId: $conversationId,
                chunkIndex: $chunkIndex,
                sourceMessageCount: $summary->source_message_count ?: count($chunkContent),
                condensationModel: $summary->condensation_model,
                condensationProvider: $summary->condensation_provider ?? 'openai',
                promptTokens: 0,
                completionTokens: 0,
                totalTokens: 0,
                synchronous: true,
                summaryTokens: $summary->summary_tokens ?? 0,
            ));

            // Update source_message_count if not set
            if ($summary->source_message_count === 0) {
                $summary->update(['source_message_count' => count($chunkContent)]);
            }

            $this->store->recordSuccess($conversationId);
        }

        return $summary;
    }

    /**
     * Compute history budget from the budgeter config.
     */
    private function computeHistoryBudget(array $messages, ?string $model, ProviderType $provider, callable $estimator): int
    {
        // Default conservative budget (matches budgeter fallback)
        $context = 8192;
        $responseReserve = 2048;
        $headroomRatio = 0.15;
        $injectedSectionReserve = 1500;
        $effectiveContext = (int) floor($context * (1.0 - $headroomRatio));
        $systemMessage = null;
        if (!empty($messages) && $messages[0]['role'] === 'system') {
            $systemMessage = $messages[0];
        }
        $systemEstimate = $systemMessage ? $this->estimateMessage($systemMessage, $estimator) : 0;
        return $effectiveContext - $responseReserve - $injectedSectionReserve - $systemEstimate;
    }

    private function resolveCondensationProvider(?string $model, ProviderType $provider): ?LlmProvider
    {
        if ($this->condensationProvider) {
            return $this->condensationProvider;
        }

        if ($this->providerRegistry) {
            $condensationProviderType = $this->config['provider'] ?? null;
            if ($condensationProviderType) {
                try {
                    return $this->providerRegistry->resolveByType(ProviderType::from($condensationProviderType), $this->config['model'] ?? null);
                } catch (\Throwable) {
                    // Fall through to default provider
                }
            }
            try {
                return $this->providerRegistry->resolveByType($provider, $model);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Render structured summaries to a single text block.
     */
    private function renderSummaries(array $summaries): string
    {
        $parts = [];
        foreach ($summaries as $idx => $summary) {
            $parts[] = $this->renderSingleSummary($summary->summary);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Render a single structured summary to text.
     */
    private function renderSingleSummary(array $summary): string
    {
        $lines = [];
        $lines[] = '--- Condensed Context ---';

        if (!empty($summary['decisions'])) {
            $lines[] = 'Decisions: ' . implode(', ', $summary['decisions']);
        }
        if (!empty($summary['constraints'])) {
            $lines[] = 'Constraints: ' . implode(', ', $summary['constraints']);
        }
        if (!empty($summary['open_questions'])) {
            $lines[] = 'Open Questions: ' . implode(', ', $summary['open_questions']);
        }
        if (!empty($summary['facts'])) {
            $lines[] = 'Facts: ' . implode(', ', $summary['facts']);
        }
        if (!empty($summary['commitments'])) {
            $lines[] = 'Commitments: ' . implode(', ', $summary['commitments']);
        }
        if (!empty($summary['context'])) {
            $lines[] = 'Context: ' . $summary['context'];
        }

        return implode("\n", $lines);
    }

    private function estimateMessage(array $message, callable $estimator): int
    {
        $content = $message['content'] ?? '';
        if (is_array($content)) {
            $content = json_encode($content);
        }
        $tokens = $estimator((string) $content);

        // Add envelope overhead
        $tokens += 4;

        // Add tool_calls overhead
        if (!empty($message['tool_calls'])) {
            $tokens += strlen(json_encode($message['tool_calls'])) / 4;
        }

        return (int) ceil($tokens);
    }

    private function calculateVerbatimBoundary(array $historyMessages, int $historyBudget, callable $estimator): int
    {
        if (empty($historyMessages)) {
            return 0;
        }

        $totalMessages = count($historyMessages);
        $remainingBudget = $historyBudget;

        // Walk newest → oldest, count how many fit
        $count = 0;
        for ($i = $totalMessages - 1; $i >= 0; $i--) {
            $msgTokens = $this->estimateMessage($historyMessages[$i], $estimator);
            if ($msgTokens <= $remainingBudget) {
                $remainingBudget -= $msgTokens;
                $count++;
            } else {
                break;
            }
        }

        return $count;
    }

    /**
     * Opportunistically dispatch pre-warm jobs for chunks that will seal next.
     *
     * Gated on the `condensation.prewarm` config flag. Only dispatches for chunks
     * that are not yet cached (checked via store->get).
     *
     * @param array<int> $sealedChunks Already-processed sealed chunk indices
     */
    private function dispatchPreWarmJobs(
        string $conversationId,
        array $historyMessages,
        int $chunkSize,
        array $sealedChunks
    ): void {
        // Check prewarm flag
        if (!($this->config['prewarm'] ?? false)) {
            return;
        }

        // Find the next chunk after the last sealed chunk
        $maxSealedIndex = max($sealedChunks);
        $nextChunkIndex = $maxSealedIndex + 1;

        // Check if this next chunk has enough messages to be sealed
        $nextChunkEnd = ($nextChunkIndex + 1) * $chunkSize;
        if ($nextChunkEnd > count($historyMessages)) {
            return;
        }

        // Check if already cached
        $sourceHash = $this->partitioner->computeSourceHash($historyMessages, $nextChunkIndex, $chunkSize);
        $existing = $this->store->get($conversationId, $nextChunkIndex, $sourceHash);

        if ($existing) {
            return;
        }

        // Dispatch pre-warm job (queued, not synchronous)
        try {
            PreWarmChunkSummaryJob::dispatch($conversationId, $nextChunkIndex);
        } catch (\Throwable $e) {
            // Silently ignore dispatch failures — pre-warm is opportunistic
        }
    }
}
