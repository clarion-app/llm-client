<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Events\ConversationCondensed;
use ClarionApp\LlmClient\Models\ChunkSummary;
use ClarionApp\LlmClient\Services\ChunkPartitioner;
use ClarionApp\LlmClient\Services\CondensationSummaryStore;
use ClarionApp\LlmClient\Services\ConversationCondenser;
use ClarionApp\LlmClient\Services\ContextWindowBudgeter;
use ClarionApp\LlmClient\Presets\CondensationPreset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ConversationCondenserTest extends TestCase
{
    private string $conversationId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadLlmClientMigrations();

        $this->conversationId = (string) \Illuminate\Support\Str::uuid();

        // Configure condensation
        $this->app['config']->set('llm-client.condensation', [
            'enabled' => true,
            'chunk_size' => 4,
            'model' => null,
            'provider' => null,
            'timeout_seconds' => 20,
            'failure_threshold' => 3,
            'cooldown_seconds' => 300,
            'prewarm' => true,
        ]);
    }

    protected function tearDown(): void
    {
        ChunkSummary::query()->delete();
        \ClarionApp\LlmClient\Models\CondensationState::query()->delete();
        parent::tearDown();
    }

    #[Test]
    public function cache_hit_assembly_order_is_system_summaries_verbatim(): void
    {
        $messages = $this->createMessages(8);
        $systemMsg = ['role' => 'system', 'content' => 'You are helpful.'];
        $fullMessages = array_merge([$systemMsg], $messages);

        // Pre-populate chunk 0 summary (cached)
        ChunkSummary::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $this->conversationId,
            'chunk_index' => 0,
            'source_hash' => $this->partitioner()->computeSourceHash($messages, 0, 4),
            'source_message_count' => 4,
            'summary' => ['decisions' => ['use blue'], 'constraints' => [], 'open_questions' => [], 'facts' => [], 'commitments' => []],
            'summary_tokens' => 10,
            'condensation_model' => 'gpt-4o',
            'condensation_provider' => 'openai',
        ]);

        $budgeter = new ContextWindowBudgeter(['enabled' => false]);
        $condenser = $this->createCondenser($budgeter);

        $estimator = fn(string $text) => strlen($text) / 4;
        // Budget=24: verbatimCount=2, droppedCutoff=6, chunk 0 sealed
        $result = $condenser->condenseOrTrim(
            $fullMessages,
            'gpt-4o',
            ProviderType::OpenAI,
            $estimator,
            $this->conversationId,
            24
        );

        // Result should have: system, summary block, verbatim recent
        $this->assertEquals('system', $result[0]['role']);
        $this->assertEquals('You are helpful.', $result[0]['content']);

        // Summary block should be present (system role with summary content)
        $summaryFound = false;
        foreach ($result as $msg) {
            if (($msg['role'] === 'system') && str_contains($msg['content'] ?? '', 'Condensed')) {
                $summaryFound = true;
                break;
            }
        }
        $this->assertTrue($summaryFound, 'Summary block should be present in assembled messages');
    }

    #[Test]
    public function verbatim_recent_tail_is_byte_identical_to_input(): void
    {
        $messages = $this->createMessages(8);
        $systemMsg = ['role' => 'system', 'content' => 'System prompt.'];
        $fullMessages = array_merge([$systemMsg], $messages);

        // Pre-populate chunk 0 summary (cached)
        ChunkSummary::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $this->conversationId,
            'chunk_index' => 0,
            'source_hash' => $this->partitioner()->computeSourceHash($messages, 0, 4),
            'source_message_count' => 4,
            'summary' => ['decisions' => ['cached'], 'constraints' => [], 'open_questions' => [], 'facts' => [], 'commitments' => []],
            'summary_tokens' => 10,
            'condensation_model' => 'gpt-4o',
            'condensation_provider' => 'openai',
        ]);

        $budgeter = new ContextWindowBudgeter(['enabled' => false]);
        $condenser = $this->createCondenser($budgeter);

        $estimator = fn(string $text) => strlen($text) / 4;
        $result = $condenser->condenseOrTrim(
            $fullMessages,
            'gpt-4o',
            ProviderType::OpenAI,
            $estimator,
            $this->conversationId,
            24
        );

        // The verbatim messages should be byte-identical to the input tail
        // With budget=24, verbatimCount=2, so last 2 messages are verbatim
        $verbatimTail = array_slice($messages, -2);
        $resultTail = array_slice($result, -count($verbatimTail));

        foreach ($verbatimTail as $idx => $msg) {
            $this->assertEquals($msg['role'], $resultTail[$idx]['role']);
            $this->assertEquals($msg['content'], $resultTail[$idx]['content']);
        }
    }

    #[Test]
    public function cache_miss_triggers_exactly_one_synchronous_condensation(): void
    {
        $messages = $this->createMessages(8);
        $systemMsg = ['role' => 'system', 'content' => 'System prompt.'];
        $fullMessages = array_merge([$systemMsg], $messages);

        $fakeProvider = $this->createMock(LlmProvider::class);
        $fakeProvider->expects($this->once())
            ->method('chat')
            ->willReturn([
                'choices' => [['message' => [
                    'role' => 'assistant',
                    'content' => json_encode([
                        'decisions' => ['early decision'],
                        'constraints' => [],
                        'open_questions' => [],
                        'facts' => [],
                        'commitments' => [],
                    ]),
                ]]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            ]);

        $budgeter = new ContextWindowBudgeter(['enabled' => false]);
        $condenser = $this->createCondenser($budgeter, $fakeProvider);

        $estimator = fn(string $text) => strlen($text) / 4;
        $condenser->condenseOrTrim(
            $fullMessages,
            'gpt-4o',
            ProviderType::OpenAI,
            $estimator,
            $this->conversationId,
            24
        );

        // Exactly one chat call was made (verified by mock)
        // And a ChunkSummary should now exist
        $this->assertEquals(1, ChunkSummary::where('conversation_id', $this->conversationId)->count());
    }

    #[Test]
    public function steady_state_performs_zero_llm_calls(): void
    {
        $messages = $this->createMessages(8);
        $systemMsg = ['role' => 'system', 'content' => 'System prompt.'];
        $fullMessages = array_merge([$systemMsg], $messages);

        // Pre-populate cache for chunk 0
        ChunkSummary::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $this->conversationId,
            'chunk_index' => 0,
            'source_hash' => $this->partitioner()->computeSourceHash($messages, 0, 4),
            'source_message_count' => 4,
            'summary' => ['decisions' => ['cached decision'], 'constraints' => [], 'open_questions' => [], 'facts' => [], 'commitments' => []],
            'summary_tokens' => 10,
            'condensation_model' => 'gpt-4o',
            'condensation_provider' => 'openai',
        ]);

        $fakeProvider = $this->createMock(LlmProvider::class);
        $fakeProvider->expects($this->never())
            ->method('chat');

        $budgeter = new ContextWindowBudgeter(['enabled' => false]);
        $condenser = $this->createCondenser($budgeter, $fakeProvider);

        $estimator = fn(string $text) => strlen($text) / 4;
        $condenser->condenseOrTrim(
            $fullMessages,
            'gpt-4o',
            ProviderType::OpenAI,
            $estimator,
            $this->conversationId,
            24
        );
    }

    #[Test]
    public function chunk_zero_summary_is_byte_identical_across_passes(): void
    {
        $messages = $this->createMessages(8);
        $systemMsg = ['role' => 'system', 'content' => 'System prompt.'];
        $fullMessages = array_merge([$systemMsg], $messages);

        $fakeProvider = $this->createMock(LlmProvider::class);
        $fakeProvider->expects($this->once())
            ->method('chat')
            ->willReturn([
                'choices' => [['message' => [
                    'role' => 'assistant',
                    'content' => json_encode([
                        'decisions' => ['fixed decision'],
                        'constraints' => [],
                        'open_questions' => [],
                        'facts' => [],
                        'commitments' => [],
                    ]),
                ]]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            ]);

        $budgeter = new ContextWindowBudgeter(['enabled' => false]);
        $condenser = $this->createCondenser($budgeter, $fakeProvider);

        $estimator = fn(string $text) => strlen($text) / 4;

        // First pass — triggers condensation
        $condenser->condenseOrTrim($fullMessages, 'gpt-4o', ProviderType::OpenAI, $estimator, $this->conversationId, 24);

        $summaryAfter = ChunkSummary::where('conversation_id', $this->conversationId)
            ->where('chunk_index', 0)
            ->first();

        // Second pass — should be cache hit, no LLM call
        $condenser->condenseOrTrim($fullMessages, 'gpt-4o', ProviderType::OpenAI, $estimator, $this->conversationId, 24);

        $summaryBefore = ChunkSummary::where('conversation_id', $this->conversationId)
            ->where('chunk_index', 0)
            ->first();

        // Summary should be byte-identical (never re-derived)
        $this->assertNotNull($summaryAfter);
        $this->assertNotNull($summaryBefore);
        $this->assertEquals($summaryAfter->summary, $summaryBefore->summary);
        $this->assertEquals($summaryAfter->source_hash, $summaryBefore->source_hash);
    }

    #[Test]
    public function no_chunk_from_another_conversation_is_assembled(): void
    {
        $otherConversationId = (string) \Illuminate\Support\Str::uuid();
        $messages = $this->createMessages(8);
        $systemMsg = ['role' => 'system', 'content' => 'System prompt.'];
        $fullMessages = array_merge([$systemMsg], $messages);

        // Pre-populate cache for OTHER conversation
        ChunkSummary::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $otherConversationId,
            'chunk_index' => 0,
            'source_hash' => 'other-hash',
            'source_message_count' => 4,
            'summary' => ['decisions' => ['other decision'], 'constraints' => [], 'open_questions' => [], 'facts' => [], 'commitments' => []],
            'summary_tokens' => 10,
            'condensation_model' => 'gpt-4o',
            'condensation_provider' => 'openai',
        ]);

        $fakeProvider = $this->createMock(LlmProvider::class);
        $fakeProvider->method('chat')->willReturn([
            'choices' => [['message' => [
                'role' => 'assistant',
                'content' => json_encode([
                    'decisions' => ['our decision'],
                    'constraints' => [],
                    'open_questions' => [],
                    'facts' => [],
                    'commitments' => [],
                ]),
            ]]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
        ]);

        $budgeter = new ContextWindowBudgeter(['enabled' => false]);
        $condenser = $this->createCondenser($budgeter, $fakeProvider);

        $estimator = fn(string $text) => strlen($text) / 4;
        $result = $condenser->condenseOrTrim(
            $fullMessages,
            'gpt-4o',
            ProviderType::OpenAI,
            $estimator,
            $this->conversationId,
            24
        );

        // Verify that only our conversation's summaries are in the result
        $hasSummary = false;
        foreach ($result as $msg) {
            $content = $msg['content'] ?? '';
            if (str_contains($content, 'Condensed')) {
                $hasSummary = true;
                $this->assertStringNotContainsString('other decision', $content);
            }
        }
        $this->assertTrue($hasSummary, 'Summary block should be present');
    }

    #[Test]
    public function conversation_condensed_event_fires_on_condensation(): void
    {
        Event::fake([ConversationCondensed::class]);

        $messages = $this->createMessages(8);
        $systemMsg = ['role' => 'system', 'content' => 'System prompt.'];
        $fullMessages = array_merge([$systemMsg], $messages);

        $fakeProvider = $this->createMock(LlmProvider::class);
        $fakeProvider->method('chat')->willReturn([
            'choices' => [['message' => [
                'role' => 'assistant',
                'content' => json_encode([
                    'decisions' => ['decision'],
                    'constraints' => [],
                    'open_questions' => [],
                    'facts' => [],
                    'commitments' => [],
                ]),
            ]]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
        ]);

        $budgeter = new ContextWindowBudgeter(['enabled' => false]);
        $condenser = $this->createCondenser($budgeter, $fakeProvider);

        $estimator = fn(string $text) => strlen($text) / 4;
        $condenser->condenseOrTrim($fullMessages, 'gpt-4o', ProviderType::OpenAI, $estimator, $this->conversationId, 24);

        Event::assertDispatched(ConversationCondensed::class, function ($event) {
            return $event->synchronous === true
                && $event->condensationModel === 'gpt-4o'
                && $event->condensationProvider === 'openai';
        });
    }

    private function createMessages(int $count): array
    {
        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            $messages[] = [
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Message content {$i}",
                'created_at' => \Carbon\Carbon::now()->addSeconds($i),
            ];
        }
        return $messages;
    }

    private function partitioner(): ChunkPartitioner
    {
        return new ChunkPartitioner();
    }

    private function createCondenser(ContextWindowBudgeter $budgeter, ?LlmProvider $fakeProvider = null): ConversationCondenser
    {
        $store = new CondensationSummaryStore(
            Cache::store('array'),
            config('llm-client.condensation')
        );

        return new ConversationCondenser(
            $this->partitioner(),
            $store,
            $budgeter,
            new CondensationPreset(),
            $fakeProvider
        );
    }

    private function loadLlmClientMigrations(): void
    {
        $migrationFile = __DIR__ . '/../../src/Migrations/2026_07_14_000000_create_condensation_tables.php';
        $migration = include $migrationFile;
        $migration->up();
    }
}
