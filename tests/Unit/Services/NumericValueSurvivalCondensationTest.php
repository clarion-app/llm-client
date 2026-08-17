<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Services\StructureReducer;
use ClarionApp\LlmClient\Services\ToolResultCondenser;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature 113 — US5 (P2, FR-013/FR-014, SC-006/SC-007): every numeric
 * figure in a bounded/summarized answer must remain exact.
 *
 * (a) The structured (JSON) condensation path already preserves int/float
 *     scalars and monetary-amount-shaped strings unchanged at any depth
 *     (StructureReducer::reduceValue()) — proven here, not merely assumed.
 * (b) The prose/LLM-summarization path does not, by itself, guarantee this;
 *     ToolResultCondenser::extractPreservedValues() is extended with an
 *     additive numeric-value pattern so a currency figure, a percentage,
 *     and a plain multi-digit number survive verbatim into the "Preserved
 *     details" block, mirroring how URL preservation was added for citations.
 */
class NumericValueSurvivalCondensationTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('llm-client.tool_result_condensation', [
            'enabled' => true,
            'threshold_tokens' => 2000,
            'max_condensed_tokens' => 500,
            'summarization_timeout_seconds' => 5,
            'cache_ttl_minutes' => 240,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // --- (a) The structured path already preserves numeric values exactly. ---

    #[Test]
    public function numeric_and_monetary_values_survive_structured_reduction_at_every_sampled_depth(): void
    {
        $items = [];
        for ($i = 0; $i < 20; $i++) {
            $items[] = [
                'id' => $i,
                'balance' => 1000.5 + $i,
                'count' => 42 + $i,
                'amount' => '$'.number_format(1200.75 + $i, 2),
                'nested' => [
                    'score' => 3.14159 + $i,
                    'total' => 500000 + $i,
                ],
            ];
        }

        $reducer = new StructureReducer();
        $reduced = $reducer->reduce($items, 5000, 5);

        // reduceList() keeps the first N (sampleItems = 5) items verbatim,
        // reducing each nested value with reduceValue() -- this is the
        // property under test, not an assumption about which items survive.
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($items[$i]['balance'], $reduced[$i]['balance'], "float at index {$i} must survive structured reduction exactly");
            $this->assertSame($items[$i]['count'], $reduced[$i]['count'], "int at index {$i} must survive structured reduction exactly");
            $this->assertSame($items[$i]['amount'], $reduced[$i]['amount'], "monetary-shaped string at index {$i} must survive structured reduction exactly");
            $this->assertSame($items[$i]['nested']['score'], $reduced[$i]['nested']['score'], "nested float at index {$i} must survive structured reduction exactly");
            $this->assertSame($items[$i]['nested']['total'], $reduced[$i]['nested']['total'], "nested int at index {$i} must survive structured reduction exactly");
        }
    }

    // --- (b) The prose path must be extended to preserve numeric values. ---

    #[Test]
    public function currency_percentage_and_plain_numbers_survive_prose_condensation_verbatim(): void
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturn([
            'choices' => [['message' => ['content' => 'A concise summary of the report, with figures rounded for brevity.']]],
        ]);

        $condenser = new ToolResultCondenser(null, $provider, null, [
            'enabled' => true,
            'threshold_tokens' => 100, // low so our content is over the threshold
            'max_condensed_tokens' => 500,
        ]);

        $currency = '$45,231.60';
        $percentage = '12.5%';
        $plainNumber = '1,204';

        $content = "Quarterly revenue reached {$currency}, up {$percentage} year over year, across {$plainNumber} transactions. "
            .str_repeat('lorem ipsum dolor sit amet ', 100);

        $result = $condenser->condense('conv-1', 'execute_operation', $content);

        $this->assertTrue($result['condensed']);
        $this->assertStringContainsString($currency, $result['content'], 'the exact currency figure must survive into the preserved-values block');
        $this->assertStringContainsString($percentage, $result['content'], 'the exact percentage must survive into the preserved-values block');
        $this->assertStringContainsString($plainNumber, $result['content'], 'the exact plain multi-digit number must survive into the preserved-values block');
    }

    #[Test]
    public function incidental_single_digit_numbers_do_not_flood_the_preserved_block(): void
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturn([
            'choices' => [['message' => ['content' => 'A concise summary with no leftover figures.']]],
        ]);

        $condenser = new ToolResultCondenser(null, $provider, null, [
            'enabled' => true,
            'threshold_tokens' => 100,
            'max_condensed_tokens' => 500,
        ]);

        // Only single-digit numbers, sprinkled through prose -- deliberately
        // excluded by the numeric pattern's `\d{2,}` minimum, per research.md
        // D7, so incidental small numbers don't flood the preserved block.
        $content = str_repeat('There are 5 apples and 3 oranges in item 9 of the report. ', 30);

        $result = $condenser->condense('conv-1', 'execute_operation', $content);

        $this->assertTrue($result['condensed']);
        $this->assertStringNotContainsString(
            'Preserved details:',
            $result['content'],
            'no error/URL/path/UUID/2+-digit-number pattern is present in this fixture, so no preserved-values block should be appended at all'
        );
    }
}
