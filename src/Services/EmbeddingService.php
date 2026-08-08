<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\MemoryScope;
use ClarionApp\LlmClient\Exceptions\BudgetExceededException;
use ClarionApp\LlmClient\Exceptions\RoleAssignmentFailedException;
use ClarionApp\LlmClient\Models\MemoryEntry;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\RoleResolver;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\RoleResolutionStatus;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Service for generating and managing memory entry embeddings.
 *
 * Handles provider resolution (dedicated embedding server vs chat provider fallback),
 * embedding generation with error handling, and content truncation.
 */
class EmbeddingService
{
    /**
     * Maximum input length for embedding generation (characters).
     * Truncate content exceeding this limit before embedding.
     */
    private const MAX_INPUT_LENGTH = 8000;

    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
        private readonly RoleResolver $roleResolver
    ) {}

    /**
     * Check if embedding generation is enabled.
     */
    public function isEnabled(): bool
    {
        return config('llm-client.memory.embedding.enabled', true) === true;
    }

    /**
     * Resolve the embedding provider.
     *
     * Priority:
     * 1. RoleResolver embedding role assignment (user, then installation scope)
     * 2. Dedicated embedding server (configured via memory.embedding.server_id)
     * 3. Null (no provider available)
     *
     * @throws RoleAssignmentFailedException If the role is broken (model vanished)
     */
    public function getProvider(?string $userId = null): ?LlmProvider
    {
        if (!$this->isEnabled()) {
            return null;
        }

        // Try role-based resolution first
        $resolution = $this->roleResolver->resolve(ModelRole::Embedding, $userId);

        if ($resolution->status === RoleResolutionStatus::Broken) {
            throw new RoleAssignmentFailedException(
                ModelRole::Embedding,
                $resolution->model ?? 'unknown',
                $resolution->brokenReason,
            );
        }

        if ($resolution->hasEffectiveModel()) {
            return $this->providerRegistry->resolve($resolution->server);
        }

        // Unassigned — fall back to config-file values (FR-017)
        $serverId = config('llm-client.memory.embedding.server_id', null);
        if ($serverId !== null) {
            $server = Server::find($serverId);
            if ($server !== null) {
                try {
                    return $this->providerRegistry->resolve($server);
                } catch (RuntimeException $e) {
                    Log::warning('Failed to resolve dedicated embedding provider', [
                        'server_id' => $serverId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // No dedicated embedding provider available.
        // Return null — callers should handle this gracefully.
        return null;
    }

    /**
     * Generate an embedding for the given text.
     *
     * @param string $content Text to embed
     * @param int|null $timeoutMs Bound the provider request to this many
     *                            milliseconds. Callers on a latency budget (the
     *                            synchronous retrieval hot path) must pass this;
     *                            background callers should omit it and take the
     *                            client default, which is far more generous.
     * @param string|null $userId User ID for role-based resolution (optional)
     * @return float[] Embedding vector
     * @throws RuntimeException If embedding generation fails
     * @throws RoleAssignmentFailedException If the embedding role is broken
     */
    public function generate(string $content, ?int $timeoutMs = null, ?string $userId = null): array
    {
        // Resolve provider and determine which model name to use
        $resolution = $this->roleResolver->resolve(ModelRole::Embedding, $userId);
        $roleModel = null;

        if ($resolution->status === RoleResolutionStatus::Broken) {
            throw new RoleAssignmentFailedException(
                ModelRole::Embedding,
                $resolution->model ?? 'unknown',
                $resolution->brokenReason,
            );
        }

        if ($resolution->hasEffectiveModel()) {
            $roleModel = $resolution->model;
        }

        $provider = $this->getProvider($userId);
        if ($provider === null) {
            throw new RuntimeException(
                'No embedding provider available. Configure memory.embedding.server_id or disable semantic search.'
            );
        }

        // Truncate content if too long
        $input = $this->truncateForEmbedding($content);

        // Use role-assigned model if resolved, otherwise fall back to config
        $model = $roleModel ?? config('llm-client.memory.embedding.model', null);
        $options = [];
        if ($model !== null && $model !== '') {
            $options['model'] = $model;
        }
        if ($timeoutMs !== null && $timeoutMs > 0) {
            $options['timeout_ms'] = $timeoutMs;
        }

        // Embedding costs real money, so it crosses the spending gate like
        // every other model-consuming path.
        //
        // The gate is called DIRECTLY rather than through
        // RunTraceRecorder::traceSystemRun(), and that is a deliberate
        // departure from the other three system paths this feature funnelled.
        // Almost every embedding here is a query embedded while a live turn
        // assembles its context, so routing it through traceSystemRun() would
        // open and close a whole nested run — six writes and a broadcast that
        // re-reads the run — on every single turn. That regresses the run
        // recorder's measured per-step overhead budget (its own benchmark
        // catches it) and
        // fills the run listing with one embedding run per turn, for no gain
        // enforcement needs: admit() writes its own refusal record, so a
        // refused embedding is just as visible to an operator either way.
        //
        // Two more things this has to get right:
        //
        // (a) A null $userId is legitimate here and means the installation
        //     ceiling alone is evaluated — there is no user whose ceiling could
        //     apply.
        //
        // (b) This method is frequently reached from INSIDE already-admitted
        //     work — AutoMemoryRetriever and MemoryService embed a query while
        //     building the context of a live turn, DeclarativeMemoryService
        //     embeds during one, and GenerateEpisodicMemoryJob embeds inside
        //     the traceSystemRun() that already gated that job. BudgetGate's
        //     per-unit-of-work admission record is the only thing stopping
        //     those calls throwing mid-turn and abandoning a half-built
        //     response. A change to either side breaks that silently, so the
        //     dependency is stated here as well as there.
        try {
            app(BudgetGate::class)->admit(
                $userId === null ? null : (string) $userId,
                BudgetWorkKind::SystemInitiated,
                null,
                'embedding',
            );

            $result = $provider->embed([$input], $options);
        } catch (BudgetExceededException $e) {
            // A ceiling refusal is not a broken role assignment; it must not be
            // reshaped into one by the catch below.
            throw $e;
        } catch (\Throwable $e) {
            // FR-014: a model assigned to a role it cannot perform (a chat model
            // assigned to embedding, say) fails here, at first use. Name the role
            // and the model rather than letting the provider's raw error surface
            // unexplained — but only when the model came from a role assignment;
            // a config-file model failing is not a role's fault (research.md D8).
            if ($roleModel !== null) {
                throw new RoleAssignmentFailedException(
                    ModelRole::Embedding,
                    $roleModel,
                    $e->getMessage(),
                    $e,
                );
            }
            throw $e;
        }

        $embeddings = $result['embeddings'] ?? [];

        if (empty($embeddings) || !is_array($embeddings[0] ?? null)) {
            if ($roleModel !== null) {
                throw new RoleAssignmentFailedException(
                    ModelRole::Embedding,
                    $roleModel,
                    'the provider returned an empty or malformed embedding',
                );
            }

            throw new RuntimeException('Embedding provider returned invalid result (empty or non-array embeddings).');
        }

        return $embeddings[0];
    }

    /**
     * Generate and save embedding for a memory entry.
     *
     * Returns true if embedding was successfully generated and saved,
     * false if embedding generation was skipped or failed.
     *
     * This is non-blocking — failures are logged but don't throw.
     */
    public function generateForEntry(MemoryEntry $entry): bool
    {
        // Only generate embeddings for long-term entries
        if ($entry->scope !== MemoryScope::LONG_TERM) {
            return false;
        }

        // Skip if embedding is disabled
        if (!$this->isEnabled()) {
            return false;
        }

        // Skip if no provider available
        if ($this->getProvider($entry->user_id) === null) {
            Log::warning('Skipping embedding generation: no embedding provider available', [
                'entry_id' => $entry->id,
                'key' => $entry->key,
            ]);
            return false;
        }

        try {
            $embedding = $this->generate($entry->content, null, $entry->user_id);
            $entry->embedding = $embedding;
            $entry->save();
            return true;
        } catch (RuntimeException $e) {
            Log::warning('Failed to generate embedding for memory entry', [
                'entry_id' => $entry->id,
                'key' => $entry->key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Truncate content to maximum embedding input length.
     */
    private function truncateForEmbedding(string $content): string
    {
        if (mb_strlen($content) <= self::MAX_INPUT_LENGTH) {
            return $content;
        }

        return mb_substr($content, 0, self::MAX_INPUT_LENGTH);
    }

    /**
     * Compute cosine similarity between two embedding vectors.
     *
     * @param float[] $a First vector
     * @param float[] $b Second vector
     * @return float Cosine similarity in range [-1.0, 1.0]
     */
    public static function cosineSimilarity(array $a, array $b): float
    {
        $lenA = count($a);
        $lenB = count($b);

        if ($lenA === 0 || $lenA !== $lenB) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $lenA; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA == 0.0 || $normB == 0.0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($normA) * sqrt($normB));
    }

    /**
     * Normalize cosine similarity from [-1.0, 1.0] to [0.0, 1.0].
     */
    public static function normalizeSimilarity(float $cosineSimilarity): float
    {
        return ($cosineSimilarity + 1.0) / 2.0;
    }
}
