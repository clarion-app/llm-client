<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use Illuminate\Support\Facades\DB;

use PHPUnit\Framework\Attributes\Test;

/**
 * User Story 1 end-to-end (spec.md Acceptance Scenarios 1-3, FR-002, FR-017):
 * an operator configures a model's price (three rate components), views it
 * back, changes it later and sees when the change took effect, and a
 * genuine zero price is stored and listed like any other price. A
 * non-operator caller is locked out of both reading and writing prices.
 *
 * Response shape assumptions, resolved against contracts/cost-api.md §1:
 * - GET /model-prices returns {"currency": ..., "data": [ {provider_type,
 *   model, reused_input_rate, fresh_input_rate, output_rate, effective_from,
 *   effective_until}, ... ]}.
 * - PUT /model-prices returns the new price row's fields at the top level
 *   plus "previous_effective_until" (null on a pair's first-ever price).
 * - GET /model-prices?history=true is read here as returning every
 *   historical version for a pair as its own flat entry in "data" (rather
 *   than nesting versions under one entry per pair) — the contract text
 *   ("additionally includes each pair's full historical price sequence")
 *   does not pin down flat-vs-nested, and a flat list is the minimal
 *   reading consistent with the single-item shape already fixed by the
 *   non-history response.
 */
class ModelPriceConfigurationJourneyTest extends TestCase
{
    private User $operator;
    private User $nonOperator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->create();
        $this->nonOperator = User::factory()->create();

        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);
    }

    protected function tearDown(): void
    {
        DB::table('model_prices')->delete();
        DB::table('users')->delete();
        parent::tearDown();
    }

    private function endpoint(): string
    {
        return '/api/clarion-app/llm-client/model-prices';
    }

    #[Test]
    public function put_then_get_shows_the_same_three_rates_back(): void
    {
        $put = $this->actingAs($this->operator)->putJson($this->endpoint(), [
            'provider_type' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'reused_input_rate' => '0.30000000',
            'fresh_input_rate' => '3.00000000',
            'output_rate' => '15.00000000',
        ]);

        $put->assertStatus(200);
        $this->assertNull($put->json('previous_effective_until'), 'First-ever price has no prior version to close');

        $get = $this->actingAs($this->operator)->getJson($this->endpoint());
        $get->assertStatus(200);

        $row = collect($get->json('data'))->firstWhere('model', 'claude-sonnet-5');

        $this->assertNotNull($row, 'The stored price must be visible in the list');
        $this->assertSame('anthropic', $row['provider_type']);
        $this->assertEquals(0.3, (float) $row['reused_input_rate']);
        $this->assertEquals(3.0, (float) $row['fresh_input_rate']);
        $this->assertEquals(15.0, (float) $row['output_rate']);
        $this->assertNull($row['effective_until']);
    }

    #[Test]
    public function a_genuine_zero_price_is_stored_and_never_absent_from_the_list(): void
    {
        $put = $this->actingAs($this->operator)->putJson($this->endpoint(), [
            'provider_type' => 'llama_cpp',
            'model' => 'local-llama',
            'reused_input_rate' => '0',
            'fresh_input_rate' => '0',
            'output_rate' => '0',
        ]);

        $put->assertStatus(200);

        $get = $this->actingAs($this->operator)->getJson($this->endpoint());
        $row = collect($get->json('data'))->firstWhere('model', 'local-llama');

        $this->assertNotNull($row, 'A genuine zero price must appear in the list, not be treated as absent');
        $this->assertEquals(0, (float) $row['reused_input_rate']);
        $this->assertEquals(0, (float) $row['fresh_input_rate']);
        $this->assertEquals(0, (float) $row['output_rate']);
    }

    #[Test]
    public function a_second_put_changes_the_rates_echoes_previous_effective_until_and_history_shows_both_versions(): void
    {
        $first = $this->actingAs($this->operator)->putJson($this->endpoint(), [
            'provider_type' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'reused_input_rate' => '0.30000000',
            'fresh_input_rate' => '3.00000000',
            'output_rate' => '15.00000000',
        ]);
        $first->assertStatus(200);

        $second = $this->actingAs($this->operator)->putJson($this->endpoint(), [
            'provider_type' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'reused_input_rate' => '0.40000000',
            'fresh_input_rate' => '4.00000000',
            'output_rate' => '20.00000000',
        ]);

        $second->assertStatus(200);
        $second->assertJsonStructure(['previous_effective_until']);
        $this->assertNotNull($second->json('previous_effective_until'), 'The prior version was closed and its effective_until must be echoed');
        $this->assertEquals(4.0, (float) $second->json('fresh_input_rate'));

        $history = $this->actingAs($this->operator)->getJson($this->endpoint().'?history=true');
        $history->assertStatus(200);

        $versionsForPair = collect($history->json('data'))
            ->filter(fn ($row) => $row['provider_type'] === 'anthropic' && $row['model'] === 'claude-sonnet-5');

        $this->assertGreaterThanOrEqual(2, $versionsForPair->count(), 'History must include both the closed and the current version');
    }

    #[Test]
    public function a_non_operator_caller_receives_403_on_both_get_and_put(): void
    {
        $get = $this->actingAs($this->nonOperator)->getJson($this->endpoint());
        $get->assertStatus(403);

        $put = $this->actingAs($this->nonOperator)->putJson($this->endpoint(), [
            'provider_type' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'reused_input_rate' => '0.30000000',
            'fresh_input_rate' => '3.00000000',
            'output_rate' => '15.00000000',
        ]);
        $put->assertStatus(403);

        // A non-operator's write must never have taken effect.
        $count = DB::table('model_prices')->count();
        $this->assertSame(0, $count);
    }
}
