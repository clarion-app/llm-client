<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Services\ModelPriceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for ModelPriceService::setPrice() — the only write path for
 * model_prices (data-model.md §1's state-transition rules, FR-001/FR-002/FR-003).
 *
 * setPrice() is assumed to return an array shaped
 * ['price' => ModelPrice, 'previous_effective_until' => ?\DateTimeInterface|string]
 * per T020's description ("returns both the new row and the previous row's
 * now-closed effective_until... so the controller can echo previous_effective_until") —
 * this is the concrete return shape ModelPriceController (T021) is expected
 * to consume when echoing contracts/cost-api.md §1's "previous_effective_until"
 * response field.
 */
class ModelPriceServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('model_prices')->delete();
        parent::tearDown();
    }

    private function rates(array $overrides = []): array
    {
        return array_merge([
            'reused_input_rate' => '0.30000000',
            'fresh_input_rate' => '3.00000000',
            'output_rate' => '15.00000000',
        ], $overrides);
    }

    #[Test]
    public function set_price_on_a_pair_with_no_prior_price_creates_an_open_row_and_reports_no_previous_version(): void
    {
        $service = new ModelPriceService();

        $result = $service->setPrice('anthropic', 'claude-sonnet-5', $this->rates());

        $this->assertArrayHasKey('price', $result);
        $this->assertArrayHasKey('previous_effective_until', $result);

        $this->assertInstanceOf(ModelPrice::class, $result['price']);
        $this->assertNull($result['price']->effective_until);
        $this->assertSame('anthropic', $result['price']->provider_type);
        $this->assertSame('claude-sonnet-5', $result['price']->model);
        $this->assertNull($result['previous_effective_until'], 'The pair\'s first-ever price has no prior version to close');

        $count = DB::table('model_prices')
            ->where('provider_type', 'anthropic')
            ->where('model', 'claude-sonnet-5')
            ->count();
        $this->assertSame(1, $count);
    }

    #[Test]
    public function a_second_call_for_the_same_pair_closes_the_prior_open_row_and_opens_a_new_one(): void
    {
        $service = new ModelPriceService();

        $first = $service->setPrice(
            'anthropic',
            'claude-sonnet-5',
            $this->rates(),
            Carbon::parse('2026-08-01 00:00:00')
        );

        $second = $service->setPrice(
            'anthropic',
            'claude-sonnet-5',
            $this->rates(['fresh_input_rate' => '4.00000000']),
            Carbon::parse('2026-09-01 00:00:00')
        );

        // The prior row, re-read from the database, now has its
        // effective_until set to the second call's effective_from.
        $firstRowReread = ModelPrice::find($first['price']->id);
        $this->assertNotNull($firstRowReread->effective_until);
        $this->assertSame(
            '2026-09-01 00:00:00',
            Carbon::parse($firstRowReread->effective_until)->format('Y-m-d H:i:s')
        );

        // The new row is open (effective_until = null).
        $this->assertNull($second['price']->effective_until);
        $this->assertSame('4.00000000', sprintf('%.8f', (float) $second['price']->fresh_input_rate));

        // The service reports the prior version's now-closed effective_until.
        $this->assertNotNull($second['previous_effective_until']);
        $this->assertSame(
            '2026-09-01 00:00:00',
            Carbon::parse($second['previous_effective_until'])->format('Y-m-d H:i:s')
        );

        $count = DB::table('model_prices')
            ->where('provider_type', 'anthropic')
            ->where('model', 'claude-sonnet-5')
            ->count();
        $this->assertSame(2, $count, 'Both the closed prior row and the new open row must exist');
    }

    #[Test]
    public function an_explicit_effective_from_argument_is_honored_rather_than_always_defaulting_to_now(): void
    {
        $service = new ModelPriceService();
        $explicit = Carbon::parse('2020-01-01 00:00:00');

        $result = $service->setPrice('anthropic', 'claude-sonnet-5', $this->rates(), $explicit);

        $this->assertSame(
            '2020-01-01 00:00:00',
            Carbon::parse($result['price']->effective_from)->format('Y-m-d H:i:s')
        );
    }

    #[Test]
    public function all_three_rates_at_zero_is_accepted_and_stored(): void
    {
        $service = new ModelPriceService();

        $result = $service->setPrice('llama_cpp', 'local-llama', [
            'reused_input_rate' => '0',
            'fresh_input_rate' => '0',
            'output_rate' => '0',
        ]);

        $fresh = ModelPrice::find($result['price']->id);

        $this->assertNotNull($fresh);
        $this->assertEquals(0, (float) $fresh->reused_input_rate);
        $this->assertEquals(0, (float) $fresh->fresh_input_rate);
        $this->assertEquals(0, (float) $fresh->output_rate);
    }
}
