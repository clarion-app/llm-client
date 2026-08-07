<?php

namespace ClarionApp\LlmClient\Tests\Unit\Models;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\ModelPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for ModelPrice::currentFor() — the effective-dated lookup used
 * once, at write time, to resolve which price applies to a usage record
 * (research.md D2, data-model.md §1/§4.2).
 */
class ModelPriceTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('model_prices')->delete();
        parent::tearDown();
    }

    private function makePrice(array $overrides = []): ModelPrice
    {
        return ModelPrice::create(array_merge([
            'provider_type' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'reused_input_rate' => '0.30000000',
            'fresh_input_rate' => '3.00000000',
            'output_rate' => '15.00000000',
            'effective_from' => Carbon::parse('2026-08-01 00:00:00'),
            'effective_until' => null,
        ], $overrides));
    }

    #[Test]
    public function current_for_returns_null_when_queried_before_effective_from(): void
    {
        $this->makePrice(['effective_from' => Carbon::parse('2026-08-01 00:00:00')]);

        $result = ModelPrice::currentFor('anthropic', 'claude-sonnet-5', Carbon::parse('2026-07-31 23:59:59'));

        $this->assertNull($result);
    }

    #[Test]
    public function current_for_returns_the_row_when_queried_at_or_after_effective_from_and_before_effective_until(): void
    {
        $price = $this->makePrice([
            'effective_from' => Carbon::parse('2026-08-01 00:00:00'),
            'effective_until' => Carbon::parse('2026-09-01 00:00:00'),
        ]);

        $atExactStart = ModelPrice::currentFor('anthropic', 'claude-sonnet-5', Carbon::parse('2026-08-01 00:00:00'));
        $wellWithinRange = ModelPrice::currentFor('anthropic', 'claude-sonnet-5', Carbon::parse('2026-08-15 00:00:00'));

        $this->assertNotNull($atExactStart);
        $this->assertSame($price->id, $atExactStart->id);
        $this->assertNotNull($wellWithinRange);
        $this->assertSame($price->id, $wellWithinRange->id);
    }

    #[Test]
    public function current_for_returns_null_once_queried_at_or_after_effective_until(): void
    {
        $this->makePrice([
            'effective_from' => Carbon::parse('2026-08-01 00:00:00'),
            'effective_until' => Carbon::parse('2026-09-01 00:00:00'),
        ]);

        $atExactBoundary = ModelPrice::currentFor('anthropic', 'claude-sonnet-5', Carbon::parse('2026-09-01 00:00:00'));
        $wellPastBoundary = ModelPrice::currentFor('anthropic', 'claude-sonnet-5', Carbon::parse('2026-09-15 00:00:00'));

        $this->assertNull($atExactBoundary);
        $this->assertNull($wellPastBoundary);
    }

    #[Test]
    public function current_for_returns_the_currently_open_row_for_now(): void
    {
        $price = $this->makePrice([
            'effective_from' => Carbon::now()->subDay(),
            'effective_until' => null,
        ]);

        $result = ModelPrice::currentFor('anthropic', 'claude-sonnet-5', Carbon::now());

        $this->assertNotNull($result);
        $this->assertSame($price->id, $result->id);
        $this->assertNull($result->effective_until);
    }

    #[Test]
    public function current_for_returns_null_when_provider_type_is_null(): void
    {
        $this->makePrice();

        $result = ModelPrice::currentFor(null, 'claude-sonnet-5', Carbon::now());

        $this->assertNull($result);
    }

    #[Test]
    public function current_for_returns_null_when_model_is_null(): void
    {
        $this->makePrice();

        $result = ModelPrice::currentFor('anthropic', null, Carbon::now());

        $this->assertNull($result);
    }

    #[Test]
    public function a_row_with_all_three_rates_set_to_exactly_zero_is_stored_and_read_back_as_a_genuine_zero(): void
    {
        $price = $this->makePrice([
            'provider_type' => 'llama_cpp',
            'model' => 'local-llama',
            'reused_input_rate' => '0',
            'fresh_input_rate' => '0',
            'output_rate' => '0',
        ]);

        $fresh = ModelPrice::find($price->id);

        $this->assertNotNull($fresh, 'A genuine zero price must be stored, not treated as absent');
        $this->assertEquals(0, (float) $fresh->reused_input_rate);
        $this->assertEquals(0, (float) $fresh->fresh_input_rate);
        $this->assertEquals(0, (float) $fresh->output_rate);

        // And it must be resolvable via currentFor() like any other price — a
        // genuine zero price is not "no price" for lookup purposes either.
        $resolved = ModelPrice::currentFor('llama_cpp', 'local-llama', Carbon::now());
        $this->assertNotNull($resolved);
        $this->assertSame($price->id, $resolved->id);
    }
}
