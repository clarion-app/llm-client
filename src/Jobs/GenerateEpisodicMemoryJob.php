<?php

namespace ClarionApp\LlmClient\Jobs;

use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Events\EpisodicMemoryGenerationFailed;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\EpisodicMemory;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\EmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that reads a conversation transcript and generates
 * a concise episodic memory summary + topic tags via the LLM.
 *
 * Dispatched on ConversationEnded events. Non-blocking, 120-second timeout.
 * Does NOT retry on failure per spec — failures are broadcast to the user.
 */
class GenerateEpisodicMemoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 120;

    /**
     * The number of times to attempt to process the job.
     * Set to 1 — no retry on failure per spec edge cases.
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $conversationId,
        public readonly string $agentId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        ProviderRegistry $providerRegistry,
        EmbeddingService $embeddingService
    ): void {
        // Deduplicate: skip if EpisodicMemory already exists for this conversation
        if (EpisodicMemory::withoutGlobalScope('user')
            ->where('conversation_id', $this->conversationId)
            ->exists()
        ) {
            Log::info('EpisodicMemory already exists for conversation, skipping generation', [
                'conversation_id' => $this->conversationId,
            ]);
            return;
        }

        // Load conversation to get user_id and server_id
        $conversation = Conversation::find($this->conversationId);
        if (!$conversation) {
            Log::warning('Conversation not found for episodic memory generation', [
                'conversation_id' => $this->conversationId,
            ]);
            return;
        }

        $userId = (string) $conversation->user_id;

        // Read transcript from Message store
        $messages = Message::where('conversation_id', $this->conversationId)
            ->orderBy('created_at')
            ->get();

        // Skip if conversation has fewer than 3 meaningful exchanges
        $meaningfulExchanges = $messages->filter(fn ($m) => trim($m->content) !== '');
        if ($meaningfulExchanges->count() < 3) {
            Log::info('Conversation too short for episodic memory generation', [
                'conversation_id' => $this->conversationId,
                'message_count' => $meaningfulExchanges->count(),
            ]);
            return;
        }

        // Compute word count from transcript
        $transcript = $messages->map(fn ($m) => "{$m->role}: {$m->content}")->implode("\n");
        $wordCount = str_word_count(strip_tags($transcript));

        if ($wordCount < 10) {
            Log::info('Conversation word count too low for episodic memory', [
                'conversation_id' => $this->conversationId,
                'word_count' => $wordCount,
            ]);
            return;
        }

        // Calculate max summary word count (20% of original)
        $maxSummaryWords = max(10, (int) ($wordCount * config('llm-client.episodic_memory.summary_max_ratio', 0.20)));

        // Resolve LLM provider for this conversation
        $provider = null;
        try {
            $server = $conversation->server;
            if ($server) {
                $provider = $providerRegistry->resolve($server);
            }
        } catch (\RuntimeException $e) {
            Log::warning('Failed to resolve LLM provider for episodic memory', [
                'conversation_id' => $this->conversationId,
                'error' => $e->getMessage(),
            ]);
        }

        if (!$provider) {
            // Store minimal placeholder when LLM is unavailable
            $this->storePlaceholder($userId, $this->conversationId, $wordCount, 'LLM provider unavailable');
            return;
        }

        // Send to LLM for summarization
        $summaryResult = $this->summarize($provider, $transcript, $maxSummaryWords, $wordCount);

        if (!$summaryResult) {
            // Summarization failed — broadcast failure event
            $this->broadcastFailure($userId, 'Summarization failed or returned empty result');
            return;
        }

        // Validate summary ratio (reject if > 50% of word_count — likely near-verbatim)
        $summaryWordCount = str_word_count(strip_tags($summaryResult['summary']));
        if ($wordCount > 0 && ($summaryWordCount / $wordCount) > 0.50) {
            Log::warning('Summary ratio too high, storing minimal placeholder', [
                'conversation_id' => $this->conversationId,
                'summary_ratio' => round($summaryWordCount / $wordCount, 4),
            ]);

            $this->storePlaceholder($userId, $this->conversationId, $wordCount, 'Summary exceeded 50% ratio threshold');
            return;
        }

        // Store EpisodicMemory record
        $memory = EpisodicMemory::create([
            'user_id' => $userId,
            'conversation_id' => $this->conversationId,
            'summary' => $summaryResult['summary'],
            'topics' => $summaryResult['topics'] ?? ['general'],
            'word_count' => $wordCount,
            'summary_word_count' => $summaryWordCount,
        ]);

        // Generate embedding (best effort, non-blocking)
        $this->generateEmbedding($memory, $embeddingService);
    }

    /**
     * Send transcript to LLM for summarization with topic extraction.
     *
     * @return array{summary: string, topics: string[]}|null
     */
    protected function summarize(LlmProvider $provider, string $transcript, int $maxSummaryWords, int $wordCount): ?array
    {
        $systemPrompt = $this->buildSummarizationPrompt($maxSummaryWords);

        try {
            $result = $provider->chat([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $transcript],
            ], [], [
                'response_format' => 'json',
                'temperature' => 0.3,
            ]);

            $content = $result['choices'][0]['message']['content'] ?? null;
            if (!$content) {
                return null;
            }

            $parsed = json_decode($content, true);
            if (!$parsed || !isset($parsed['summary'])) {
                return null;
            }

            // Enforce max topics per entry
            $maxTopics = config('llm-client.episodic_memory.max_topics_per_entry', 10);
            $topics = array_slice($parsed['topics'] ?? ['general'], 0, $maxTopics);

            return [
                'summary' => $parsed['summary'],
                'topics' => $topics,
            ];
        } catch (\Exception $e) {
            Log::error('LLM summarization error for episodic memory', [
                'conversation_id' => $this->conversationId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Build the system prompt for summarization.
     */
    protected function buildSummarizationPrompt(int $maxSummaryWords): string
    {
        return <<<PROMPT
You are a conversation summarizer. Your task is to read a conversation transcript and produce a concise summary.

Rules:
1. Extract key events, decisions, agreements, and outcomes from the conversation.
2. Do NOT store the full verbatim transcript — only the essential information.
3. The summary MUST be at most {$maxSummaryWords} words.
4. Identify 3-10 topic tags that represent what was discussed.
5. Return your response as JSON with this exact structure:
   {
     "summary": "Concise summary of key events, decisions, and outcomes...",
     "topics": ["topic1", "topic2", "topic3"]
   }
6. If the conversation has no meaningful decisions or events, provide a brief summary of what was discussed.
7. Be specific about decisions and agreements — include the "what" and "why".
PROMPT;
    }

    /**
     * Store a minimal placeholder record when summarization cannot proceed.
     */
    protected function storePlaceholder(string $userId, string $conversationId, int $wordCount, string $reason): void
    {
        EpisodicMemory::create([
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'summary' => '[Memory capture skipped: '.$reason.']',
            'topics' => ['skipped'],
            'word_count' => $wordCount,
            'summary_word_count' => 0,
        ]);
    }

    /**
     * Generate embedding for the memory entry (best effort).
     */
    protected function generateEmbedding(EpisodicMemory $memory, EmbeddingService $embeddingService): void
    {
        if (!$embeddingService->isEnabled()) {
            return;
        }

        try {
            $embedding = $embeddingService->generate($memory->summary);
            $memory->update(['embedding' => $embedding]);
        } catch (\Exception $e) {
            // Embedding generation failure is non-blocking
            Log::warning('Embedding generation failed for episodic memory', [
                'memory_id' => $memory->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Broadcast failure event to the user.
     */
    protected function broadcastFailure(string $userId, string $error): void
    {
        try {
            Event::dispatch(new EpisodicMemoryGenerationFailed(
                $userId,
                $this->conversationId,
                $error
            ));
        } catch (\Exception $e) {
            Log::error('Failed to broadcast EpisodicMemoryGenerationFailed event', [
                'user_id' => $userId,
                'conversation_id' => $this->conversationId,
                'original_error' => $error,
                'broadcast_error' => $e->getMessage(),
            ]);
        }

        Log::error('Episodic memory generation failed', [
            'conversation_id' => $this->conversationId,
            'user_id' => $userId,
            'error' => $error,
        ]);
    }
}
