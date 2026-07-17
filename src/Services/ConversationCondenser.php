<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Events\ConversationCondensed;
use ClarionApp\LlmClient\Jobs\PreWarmChunkSummaryJob;
use ClarionApp\LlmClient\Models\ChunkSummary;
use ClarionApp\LlmClient\Models\Server;
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
        private ?SmartHistoryTrimmer $smartTrimmer = null,
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
     * @param ?Server $server The conversation's server, required to resolve a condensation
     *                        provider from the registry. Without it (and without an injected
     *                        provider) condensation cannot run and the budgeter trims instead.
     * @return list<array{role: string, content: string|null, tool_calls?: array, tool_call_id?: string}>
     */
    public function condenseOrTrim(
        array $messages,
        ?string $model,
        ProviderType $provider,
        callable $estimator,
        string $conversationId,
        ?int $historyBudget = null,
        ?Server $server = null
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

        // Use provided budget or resolve the model-aware budget from the budgeter.
        if ($historyBudget === null) {
            $systemEstimate = $systemMessage ? $this->estimateMessage($systemMessage, $estimator) : 0;
            $historyBudget = $this->budgeter->resolveHistoryBudget($model, $provider, $systemEstimate);
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
            // No sealed chunks to condense — try smart trim, then budgeter as safety net
            $afterSmartTrim = $this->applySmartTrim($messages, $historyBudget, $estimator, $conversationId);
            return $this->budgeter->trim($afterSmartTrim, $model, $provider, $estimator, $conversationId);
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
                $estimator,
                $server
            );

            if ($result !== null) {
                $summaries[$missing['chunkIndex']] = $result;
            } else {
                // Condensation failed — try smart trim, then budgeter as safety net
                $afterSmartTrim = $this->applySmartTrim($messages, $historyBudget, $estimator, $conversationId);
                return $this->budgeter->trim($afterSmartTrim, $model, $provider, $estimator, $conversationId);
            }
        }

        // Sort summaries by chunk index (chronological order)
        ksort($summaries);

        // Opportunistic pre-warm: dispatch job for the next unsealed chunk
        $this->dispatchPreWarmJobs($conversationId, $historyMessages, $chunkSize, $sealedChunks);

        // Render summaries to text
        $renderedSummaries = $this->renderSummaries(array_values($summaries));
        $summaryMessage = ['role' => 'system', 'content' => $renderedSummaries];
        $summaryTokens = $this->estimateMessage($summaryMessage, $estimator);

        // The summaries and the verbatim tail share one budget. The boundary
        // computed above spent all of it on verbatim messages, so re-derive it
        // against what is left once the summaries are accounted for; otherwise
        // the condensed payload overshoots the budget it was meant to satisfy.
        $verbatimCount = min(
            $verbatimCount,
            $this->calculateVerbatimBoundary($historyMessages, max(0, $historyBudget - $summaryTokens), $estimator)
        );

        // Get verbatim recent portion
        $verbatimStart = $totalMessages - $verbatimCount;
        $verbatimMessages = array_slice($historyMessages, $verbatimStart);

        // Assemble: [system] + [summaries] + [verbatim recent]
        $assembled = [];
        if ($systemMessage) {
            $assembled[] = $systemMessage;
        }
        $assembled[] = $summaryMessage;
        $assembled = array_merge($assembled, $verbatimMessages);

        // The history budget excludes the system message, so measure only the
        // portion it governs: the summaries plus the verbatim tail.
        $assembledHistoryTokens = $summaryTokens;
        foreach ($verbatimMessages as $m) {
            $assembledHistoryTokens += $this->estimateMessage($m, $estimator);
        }

        // Condensation succeeded only if it both fits the budget and actually
        // compressed. Summaries alone can exceed the budget on a pathologically
        // small context, in which case trimming is the honest answer.
        if ($assembledHistoryTokens <= $historyBudget && $assembledHistoryTokens <= $totalTokens) {
            return $assembled;
        }

        // Fall back to smart trim, then budgeter as safety net
        $afterSmartTrim = $this->applySmartTrim($messages, $historyBudget, $estimator, $conversationId);
        return $this->budgeter->trim($afterSmartTrim, $model, $provider, $estimator, $conversationId);
    }

    /**
     * Apply smart history trimming if the trimmer is configured.
     *
     * @param list<array{role: string, content: string|null, tool_calls?: array, tool_call_id?: string}> $messages
     * @param int $historyBudget Token budget for history
     * @param callable(string): int $estimator Token estimator
     * @param string $conversationId Conversation identifier for cache keys
     *
     * @return list<array{role: string, content: string|null, tool_calls?: array, tool_call_id?: string}>
     */
    private function applySmartTrim(
        array $messages,
        int $historyBudget,
        callable $estimator,
        string $conversationId,
    ): array {
        if ($this->smartTrimmer === null) {
            return $messages;
        }

        return $this->smartTrimmer->trim($messages, $historyBudget, $estimator, $conversationId);
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
        callable $estimator,
        ?Server $server = null
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

        $produce = function () use ($transcript, $conversationId, $chunkIndex, $sourceHash, $chunkContent, $model, $provider, $estimator, $server) {
            $llmProvider = $this->resolveCondensationProvider($server, $provider);

            if (!$llmProvider) {
                throw new \RuntimeException('No condensation provider available');
            }

            $condensationModel = $this->config['model'] ?? $model;
            $condensationProviderValue = ($this->config['provider'] ?? null) ?: $provider->value;

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
     * Resolve the provider that performs condensation.
     *
     * The registry keys providers by type and needs the Server for credentials, so
     * without a Server only an explicitly injected provider can be used. A configured
     * `condensation.provider` overrides the conversation's own provider — allowing a
     * cheaper model to do the summarizing — and falls back to it when unresolvable.
     */
    private function resolveCondensationProvider(?Server $server, ProviderType $provider): ?LlmProvider
    {
        if ($this->condensationProvider) {
            return $this->condensationProvider;
        }

        if (!$this->providerRegistry || !$server) {
            return null;
        }

        $condensationProviderType = $this->config['provider'] ?? null;
        if ($condensationProviderType) {
            try {
                return $this->providerRegistry->resolveByType(
                    ProviderType::from($condensationProviderType),
                    $server
                );
            } catch (\Throwable) {
                // Unknown or unresolvable configured type — fall back to the
                // conversation's own provider rather than skipping condensation.
            }
        }

        try {
            return $this->providerRegistry->resolveByType($provider, $server);
        } catch (\Throwable) {
            return null;
        }
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
