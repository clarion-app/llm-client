<?php

namespace Tests\Integration\Harness;

use Illuminate\Support\Str;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\DeclarativeMemory;
use ClarionApp\LlmClient\Models\MemoryEntry;
use ClarionApp\LlmClient\Services\ContextWindowBudgeter;

class ConversationFixture
{
    public User $user;
    public Server $server;
    public Conversation $conversation;
    protected array $preferences = [];
    protected array $memories = [];
    protected array $userMessages = [];
    protected bool $embeddingsDisabled = false;
    protected bool $autoMemoryRetrieval = false;
    protected int $historySize = 0;

    public function __construct(protected $test)
    {
    }

    public function build(): self
    {
        $this->user = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);

        $this->server = Server::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Server',
            'provider_type' => 'openai',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test-token',
        ]);

        $this->conversation = Conversation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'gpt-4',
            'title' => 'Test Conversation',
        ]);

        // Seed preferences. Each carries the content-derived embedding the
        // boundary would have produced, because the declarative store scores by
        // cosine against a stored vector and silently skips entries without one
        // — an unembedded preference is invisible, not merely unranked.
        $embedder = new DeterministicEmbedder(
            (int) config('llm-client.memory.embedding.dimension', 1536)
        );

        foreach ($this->preferences as $pref) {
            DeclarativeMemory::create([
                'id' => (string) Str::uuid(),
                'user_id' => $this->user->id,
                'type' => 'preference',
                'content' => $pref['content'],
                'source' => $pref['source'] ?? 'test',
                'embedding' => $embedder->embed($pref['content']),
            ]);
        }

        // Seed memories (using short_term scope - episodic is not a valid MemoryScope)
        foreach ($this->memories as $memory) {
            MemoryEntry::create([
                'id' => (string) Str::uuid(),
                'scope' => 'short_term',
                'agent_id' => $this->server->id,
                'user_id' => $this->user->id,
                'conversation_id' => $this->conversation->id,
                'content' => $memory['content'],
            ]);
        }

        // Point embedding generation at the seeded server, so embedding traffic
        // reaches the scripted boundary over the same LlmProvider contract the
        // chat path uses. Without this the product has no embedding provider at
        // all and every scenario degrades before the transport is consulted.
        config(['llm-client.memory.embedding.server_id' => $this->server->id]);

        // Apply deviations
        if ($this->embeddingsDisabled) {
            // Fail at the transport, not by disabling the service (contract C4).
            // Config-disabling would short-circuit EmbeddingService before the
            // fallback path this scenario exists to prove ever runs.
            $this->test->transport()->disableEmbeddings();
        }
        if ($this->autoMemoryRetrieval) {
            config(['llm-client.auto_memory_retrieval.enabled' => true]);
        }

        // Seed history messages if requested
        if ($this->historySize > 0) {
            $this->seedHistoryMessages();
        }

        // Seed trailing user messages after any history, so the last one really
        // is last.
        foreach ($this->userMessages as $offset => $content) {
            Message::create([
                'id' => (string) Str::uuid(),
                'conversation_id' => $this->conversation->id,
                'role' => 'user',
                'content' => $content,
                'sequence_number' => $this->historySize + $offset,
            ]);
        }

        return $this;
    }

    /**
     * Make the embedding boundary fail, so the product's real no-embedding
     * fallback executes. Scenarios using this must declare the resulting
     * degradation on the ledger (FR-011a).
     */
    public function withEmbeddingsDisabled(): self
    {
        $this->embeddingsDisabled = true;
        return $this;
    }

    public function withAutoMemoryRetrieval(): self
    {
        $this->autoMemoryRetrieval = true;
        return $this;
    }

    public function withPreference(string $content, string $source = 'test'): self
    {
        $this->preferences[] = compact('content', 'source');
        return $this;
    }

    public function withMemory(string $content): self
    {
        $this->memories[] = compact('content');
        return $this;
    }

    /**
     * Seed a trailing user message.
     *
     * Memory retrieval keys off the last user message, so a scenario driving
     * start() (which takes no message argument) must seed one or the assembled
     * system silently takes the no-query fallback.
     */
    public function withUserMessage(string $content): self
    {
        $this->userMessages[] = $content;
        return $this;
    }

    public function withOverBudgetHistory(): self
    {
        // Get the budget from ContextWindowBudgeter
        $budgeter = app(ContextWindowBudgeter::class);
        $budget = method_exists($budgeter, 'resolveHistoryBudget')
            ? $budgeter->resolveHistoryBudget()
            : 4000; // fallback
        $this->historySize = (int) ceil($budget / 100) + 2; // exceed budget
        return $this;
    }

    protected function seedHistoryMessages(): void
    {
        for ($i = 0; $i < $this->historySize; $i++) {
            Message::create([
                'id' => (string) Str::uuid(),
                'conversation_id' => $this->conversation->id,
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => str_repeat('word ', 50) . " (message {$i})",
                'sequence_number' => $i,
            ]);
        }
    }
}
