<?php

namespace ClarionApp\LlmClient\Jobs;

use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Events\ConversationCondensed;
use ClarionApp\LlmClient\Models\ChunkSummary;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Presets\CondensationPreset;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\ChunkPartitioner;
use ClarionApp\LlmClient\Services\CondensationSummaryStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that pre-warms a sealed chunk summary asynchronously.
 *
 * Mirrors the synchronous condensation path but runs off the hot path,
 * writing the same (conversation_id, chunk_index) cache row the synchronous
 * path reads. Dispatched opportunistically when a chunk newly seals.
 *
 * Is a no-op if the chunk is already cached (no duplicate chat() calls).
 * Emits ConversationCondensed with synchronous = false.
 */
class PreWarmChunkSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 60;

    /**
     * The number of times to attempt to process the job.
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $conversationId,
        public readonly int $chunkIndex
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        ProviderRegistry $providerRegistry,
        CondensationSummaryStore $store,
        CondensationPreset $preset,
        AgentLoopService $agentLoopService
    ): void {
        // Resolve config
        $config = function_exists('config') ? config('llm-client.condensation', []) : [];
        $chunkSize = (int) ($config['chunk_size'] ?? 20);
        $condensationModel = $config['model'] ?? null;

        $conversation = Conversation::find($this->conversationId);
        if (!$conversation) {
            Log::info('Conversation not found for pre-warm chunk summary job', [
                'conversation_id' => $this->conversationId,
            ]);
            return;
        }

        $condensationProviderValue = ($config['provider'] ?? null)
            ?: $conversation->effectiveProviderType->value;

        // Build the same history array the synchronous path partitions. Reading
        // the Message rows directly would produce a different shape (tool calls
        // are not expanded into their result messages), yielding a source hash
        // that never matches what the request path looks up — every pre-warmed
        // summary would be paid for and then missed.
        $messages = $agentLoopService->buildHistoryMessages($conversation);

        if (empty($messages)) {
            Log::info('No messages found for pre-warm chunk summary job', [
                'conversation_id' => $this->conversationId,
                'chunk_index' => $this->chunkIndex,
            ]);
            return;
        }

        $llmProvider = $this->resolveProvider($providerRegistry, $conversation, $config);
        if (!$llmProvider) {
            Log::warning('No condensation provider available for pre-warm chunk summary job', [
                'conversation_id' => $this->conversationId,
                'chunk_index' => $this->chunkIndex,
            ]);
            return;
        }

        // Compute source hash for this chunk
        $partitioner = new ChunkPartitioner();
        $sourceHash = $partitioner->computeSourceHash($messages, $this->chunkIndex, $chunkSize);

        // Check if already cached — no-op if hash matches
        $existing = $store->get($this->conversationId, $this->chunkIndex, $sourceHash);
        if ($existing) {
            Log::info('Chunk already cached, skipping pre-warm', [
                'conversation_id' => $this->conversationId,
                'chunk_index' => $this->chunkIndex,
            ]);
            return;
        }

        // Extract chunk messages
        $chunkMessages = $partitioner->partition($messages, $chunkSize);
        $chunkContent = $chunkMessages[$this->chunkIndex] ?? [];

        if (empty($chunkContent)) {
            Log::info('Chunk is empty, skipping pre-warm', [
                'conversation_id' => $this->conversationId,
                'chunk_index' => $this->chunkIndex,
            ]);
            return;
        }

        // Build transcript for this chunk
        $transcript = '';
        foreach ($chunkContent as $msg) {
            $transcript .= ($msg['role'] ?? 'user') . ': ' . ($msg['content'] ?? '') . "\n";
        }

        // Build the condensation request
        $systemPrompt = $preset->getSystemPrompt();

        $options = [
            'response_format' => 'json',
            'temperature' => 0.3,
        ];

        if ($condensationModel) {
            $options['model'] = $condensationModel;
        }

        try {
            $result = $llmProvider->chat([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $transcript],
            ], [], $options);

            $content = $result['choices'][0]['message']['content'] ?? null;
            if (!$content) {
                Log::warning('Empty condensation response for pre-warm', [
                    'conversation_id' => $this->conversationId,
                    'chunk_index' => $this->chunkIndex,
                ]);
                return;
            }

            $parsed = json_decode($content, true);
            if (!$parsed || !isset($parsed['decisions'])) {
                Log::warning('Invalid condensation JSON response for pre-warm', [
                    'conversation_id' => $this->conversationId,
                    'chunk_index' => $this->chunkIndex,
                ]);
                return;
            }

            // Estimate summary tokens
            $rendered = $this->renderSingleSummary($parsed);
            $summaryTokens = (int) ceil(strlen($rendered) / 4);

            // Persist via store->remember (atomic upsert)
            $summary = $store->remember($this->conversationId, $this->chunkIndex, $sourceHash, function () use ($parsed, $summaryTokens, $result, $condensationModel, $condensationProviderValue) {
                return [
                    'summary' => $parsed,
                    'summary_tokens' => $summaryTokens,
                    'usage' => $result['usage'] ?? null,
                    'condensation_model' => $condensationModel,
                    'condensation_provider' => $condensationProviderValue,
                ];
            });

            if ($summary) {
                // Dispatch event with synchronous = false
                Event::dispatch(new ConversationCondensed(
                    conversationId: $this->conversationId,
                    chunkIndex: $this->chunkIndex,
                    sourceMessageCount: $summary->source_message_count ?: count($chunkContent),
                    condensationModel: $summary->condensation_model,
                    condensationProvider: $summary->condensation_provider ?? $condensationProviderValue,
                    promptTokens: $result['usage']['prompt_tokens'] ?? 0,
                    completionTokens: $result['usage']['completion_tokens'] ?? 0,
                    totalTokens: $result['usage']['total_tokens'] ?? 0,
                    synchronous: false,
                    summaryTokens: $summary->summary_tokens ?? 0,
                ));

                // Update source_message_count if not set
                if ($summary->source_message_count === 0) {
                    $summary->update(['source_message_count' => count($chunkContent)]);
                }

                $store->recordSuccess($this->conversationId);

                Log::info('Pre-warm chunk summary completed', [
                    'conversation_id' => $this->conversationId,
                    'chunk_index' => $this->chunkIndex,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Pre-warm chunk summary failed', [
                'conversation_id' => $this->conversationId,
                'chunk_index' => $this->chunkIndex,
                'error' => $e->getMessage(),
            ]);

            $store->recordFailure($this->conversationId);
        }
    }

    /**
     * Resolve the provider that performs condensation for this conversation.
     *
     * Mirrors ConversationCondenser::resolveCondensationProvider(): a configured
     * condensation provider wins, falling back to the conversation's own provider.
     * The registry needs the Server for credentials, so a conversation without one
     * cannot be condensed.
     */
    private function resolveProvider(
        ProviderRegistry $providerRegistry,
        Conversation $conversation,
        array $config
    ): ?LlmProvider {
        $server = $conversation->server;
        if (!$server) {
            return null;
        }

        $configuredType = $config['provider'] ?? null;
        if ($configuredType) {
            try {
                return $providerRegistry->resolveByType(ProviderType::from($configuredType), $server);
            } catch (\Throwable) {
                // Unknown or unresolvable configured type — fall back below.
            }
        }

        try {
            return $providerRegistry->resolveByType($conversation->effectiveProviderType, $server);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Render a single summary to text.
     */
    private function renderSingleSummary(array $summary): string
    {
        $parts = [];

        if (!empty($summary['decisions'])) {
            $parts[] = 'Decisions: ' . implode(', ', $summary['decisions']);
        }
        if (!empty($summary['constraints'])) {
            $parts[] = 'Constraints: ' . implode(', ', $summary['constraints']);
        }
        if (!empty($summary['facts'])) {
            $parts[] = 'Facts: ' . implode(', ', $summary['facts']);
        }
        if (!empty($summary['commitments'])) {
            $parts[] = 'Commitments: ' . implode(', ', $summary['commitments']);
        }
        if (!empty($summary['open_questions'])) {
            $parts[] = 'Open Questions: ' . implode(', ', $summary['open_questions']);
        }
        if (!empty($summary['context'])) {
            $parts[] = $summary['context'];
        }

        return implode('. ', $parts);
    }
}
