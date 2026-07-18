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
use ClarionApp\LlmClient\ValueObjects\ContextManagementOutcome;
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

        // Result is [original system, summary, ...verbatim tail]. The summary and
        // the verbatim tail share the history budget, so the tail is whatever
        // still fits once the summary is paid for — never a fixed count.
        $this->assertSame('system', $result[0]['role']);
        $this->assertSame('system', $result[1]['role']);
        $this->assertStringContainsString('Condensed Context', $result[1]['content']);

        $resultTail = array_slice($result, 2);
        $this->assertNotEmpty($resultTail, 'At least one verbatim message should survive');

        // Whatever survives must be byte-identical to the corresponding input tail.
        $verbatimTail = array_slice($messages, -count($resultTail));
        foreach ($verbatimTail as $idx => $msg) {
            $this->assertEquals($msg['role'], $resultTail[$idx]['role']);
            $this->assertEquals($msg['content'], $resultTail[$idx]['content']);
        }
    }

    #[Test]
    public function condensed_payload_respects_the_history_budget(): void
    {
        $messages = $this->createMessages(8);
        $systemMsg = ['role' => 'system', 'content' => 'System prompt.'];
        $fullMessages = array_merge([$systemMsg], $messages);

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
        $budget = 24;
        $result = $condenser->condenseOrTrim(
            $fullMessages,
            'gpt-4o',
            ProviderType::OpenAI,
            $estimator,
            $this->conversationId,
            $budget
        );

        // The history budget excludes the original system message, so measure
        // everything the condenser put after it: summary plus verbatim tail.
        $historyTokens = 0;
        foreach (array_slice($result, 1) as $m) {
            $historyTokens += (int) ceil($estimator($m['content'] ?? '') + 4);
        }

        $this->assertLessThanOrEqual(
            $budget,
            $historyTokens,
            'Condensed payload must fit the history budget it was given'
        );
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

    /**
     * A failed condensation must surface as a recorded `condense` step carrying the error,
     * not vanish behind the trim it falls back to. Otherwise operators cannot distinguish
     * "condensation was never needed" from "condensation broke every request".
     */
    #[Test]
    public function failed_condensation_records_a_condense_step_with_the_error(): void
    {
        $messages = $this->createMessages(8);
        $systemMsg = ['role' => 'system', 'content' => 'System prompt.'];
        $fullMessages = array_merge([$systemMsg], $messages);

        $fakeProvider = $this->createMock(LlmProvider::class);
        $fakeProvider->method('chat')->willThrowException(
            new \RuntimeException('condensation upstream exploded')
        );

        $budgeter = new ContextWindowBudgeter(['enabled' => false]);
        $condenser = $this->createCondenser($budgeter, $fakeProvider);

        $estimator = fn (string $text) => strlen($text) / 4;
        $outcome = new ContextManagementOutcome();

        $condenser->condenseOrTrim(
            $fullMessages,
            'gpt-4o',
            ProviderType::OpenAI,
            $estimator,
            $this->conversationId,
            24,
            null,
            $outcome
        );

        $condenseSteps = array_values(array_filter(
            $outcome->getSteps(),
            fn ($s) => $s->mechanism === 'condense'
        ));

        $this->assertCount(1, $condenseSteps, 'The failed condensation attempt must be recorded');
        $this->assertNotNull($condenseSteps[0]->error, 'The condense step must carry the failure reason');
        $this->assertStringContainsString('condensation upstream exploded', $condenseSteps[0]->error);
        $this->assertSame(0, $condenseSteps[0]->tokensSaved, 'A failed condensation saves nothing');
    }

    /**
     * The budgeter runs after the condenser's own mechanisms and must not clobber the steps
     * they already recorded — the whole point of the outcome accumulating rather than being
     * replaced.
     */
    #[Test]
    public function steps_from_earlier_mechanisms_survive_the_budgeter(): void
    {
        $messages = $this->createMessages(8);
        $systemMsg = ['role' => 'system', 'content' => 'System prompt.'];
        $fullMessages = array_merge([$systemMsg], $messages);

        $fakeProvider = $this->createMock(LlmProvider::class);
        $fakeProvider->method('chat')->willThrowException(new \RuntimeException('boom'));

        // An enabled budgeter so it actually runs (and populates) after the failure.
        $budgeter = new ContextWindowBudgeter();
        $condenser = $this->createCondenser($budgeter, $fakeProvider);

        $estimator = fn (string $text) => strlen($text) / 4;
        $outcome = new ContextManagementOutcome();

        $condenser->condenseOrTrim(
            $fullMessages,
            'gpt-4o',
            ProviderType::OpenAI,
            $estimator,
            $this->conversationId,
            24,
            null,
            $outcome
        );

        $mechanisms = array_map(fn ($s) => $s->mechanism, $outcome->getSteps());
        $this->assertContains(
            'condense',
            $mechanisms,
            'The condense failure step must survive the subsequent budgeter call'
        );
        $this->assertSame(
            'condense',
            $mechanisms[0],
            'Steps must stay in execution order, earliest mechanism first'
        );
    }

    /**
     * Utilization must be measured against what entered the request, not what happened to
     * reach the budgeter after an upstream mechanism already shrank the payload.
     */
    #[Test]
    public function request_tokens_before_is_claimed_by_the_first_mechanism(): void
    {
        $outcome = new ContextManagementOutcome();

        $outcome->recordRequestTokensBefore(5000);
        // A later mechanism reports its own, smaller input.
        $outcome->recordContext(
            contextCapacity: 128000,
            historyBudget: 90000,
            tokensBefore: 1200,
            tokensAfter: 900,
            model: 'gpt-4o',
            providerType: 'openai',
        );

        $this->assertSame(5000, $outcome->tokensBefore, 'tokensBefore is first-writer-wins');
        $this->assertSame(900, $outcome->tokensAfter, 'tokensAfter is last-writer-wins');
        $this->assertSame(128000, $outcome->contextCapacity);
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
            null,
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
