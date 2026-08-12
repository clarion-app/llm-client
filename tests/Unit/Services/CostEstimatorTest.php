<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Services\CostEstimator;
use ClarionApp\LlmClient\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for CostEstimator — a not-yet-executed unit of work's estimate,
 * composed from UsageEstimator::estimateInput() and ModelPrice::currentFor()
 * (contracts/reservation-api.md §3, research.md D2/D8).
 *
 * Every monetary assertion below compares plain-decimal strings via
 * bccomp(), never a (float) cast — a float formed anywhere in this
 * computation would propagate straight into ReservationLedger's atomic
 * bound.
 */
class CostEstimatorTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('model_prices')->delete();
        DB::table('messages')->delete();
        DB::table('conversations')->delete();

        parent::tearDown();
    }

    private function seedPrice(array $overrides = []): ModelPrice
    {
        return ModelPrice::create(array_merge([
            'provider_type' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'reused_input_rate' => '0.30000000',
            'fresh_input_rate' => '3.00000000',
            'output_rate' => '15.00000000',
            'effective_from' => Carbon::now()->subDay(),
            'effective_until' => null,
        ], $overrides));
    }

    private function seedConversation(array $overrides = []): Conversation
    {
        return Conversation::create(array_merge([
            'server_id' => null,
            'title' => 'Test conversation',
            'model' => 'claude-sonnet-5',
            'character' => 'Clarion',
            'user_id' => (string) \Illuminate\Support\Str::uuid(),
            'is_processing' => false,
        ], $overrides));
    }

    private function addMessage(Conversation $conversation, string $content): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'Test User',
            'content' => $content,
            'responseTime' => 0,
        ]);
    }

    // -----------------------------------------------------------------
    // Basic composition: input from history, output from config default.
    // -----------------------------------------------------------------

    #[Test]
    public function a_conversation_with_no_messages_estimates_input_as_zero_and_still_prices_the_configured_output_default(): void
    {
        $this->seedPrice();
        $conversation = $this->seedConversation();

        $estimator = new CostEstimator();
        $estimate = $estimator->estimate($conversation->id, 'anthropic', 'claude-sonnet-5');

        $this->assertFalse($estimate->unpriced);
        $this->assertNotNull($estimate->amount);

        // input_tokens = 0, output_tokens = configured default (1000).
        // cost = 0 * 3.0/1e6 + 1000 * 15.0/1e6 = 0.015
        $expected = Decimal::round(bcdiv(bcmul('1000', '15.00000000', 20), '1000000', 20), 10);
        $this->assertSame(0, bccomp($estimate->amount, $expected, 10), "expected {$expected}, got {$estimate->amount}");
    }

    #[Test]
    public function input_tokens_are_estimated_from_the_conversations_already_persisted_message_history(): void
    {
        $this->seedPrice();
        $conversation = $this->seedConversation();

        // 130 characters, ~100 tokens at 1.3 chars/token.
        $this->addMessage($conversation, str_repeat('a', 130));

        $estimator = new CostEstimator();
        $estimate = $estimator->estimate($conversation->id, 'anthropic', 'claude-sonnet-5');

        // input_tokens = ceil(130/1.3) = 100, output_tokens = 1000 (default).
        $inputCost = Decimal::round(bcdiv(bcmul('100', '3.00000000', 20), '1000000', 20), 10);
        $outputCost = Decimal::round(bcdiv(bcmul('1000', '15.00000000', 20), '1000000', 20), 10);
        $expected = bcadd($inputCost, $outputCost, 10);

        $this->assertSame(0, bccomp($estimate->amount, $expected, 10), "expected {$expected}, got {$estimate->amount}");
    }

    #[Test]
    public function multiple_messages_are_concatenated_before_estimation(): void
    {
        $this->seedPrice();
        $conversation = $this->seedConversation();

        $this->addMessage($conversation, str_repeat('a', 65));
        $this->addMessage($conversation, str_repeat('b', 65));

        $estimator = new CostEstimator();
        $estimate = $estimator->estimate($conversation->id, 'anthropic', 'claude-sonnet-5');

        // Concatenated: 130 chars => ceil(130/1.3) = 100 input tokens,
        // identical to the single-message 130-char case above.
        $inputCost = Decimal::round(bcdiv(bcmul('100', '3.00000000', 20), '1000000', 20), 10);
        $outputCost = Decimal::round(bcdiv(bcmul('1000', '15.00000000', 20), '1000000', 20), 10);
        $expected = bcadd($inputCost, $outputCost, 10);

        $this->assertSame(0, bccomp($estimate->amount, $expected, 10), "expected {$expected}, got {$estimate->amount}");
    }

    #[Test]
    public function the_output_half_never_derives_from_any_estimateoutput_call_only_the_configured_default(): void
    {
        // Mutation-checklist row 6: CostEstimator must never call
        // UsageEstimator::estimateOutput() on any text — estimate()'s
        // signature carries no output text at all, but this test also
        // pins the *value* used: changing the config default changes the
        // output-half of the cost, proving the default is genuinely read
        // and applied rather than a hardcoded 1000 or a silent zero.
        $this->seedPrice();
        $conversation = $this->seedConversation();

        config(['llm-client.budget.reservation.estimated_output_tokens_default' => 200]);

        $estimator = new CostEstimator();
        $estimate = $estimator->estimate($conversation->id, 'anthropic', 'claude-sonnet-5');

        $expected = Decimal::round(bcdiv(bcmul('200', '15.00000000', 20), '1000000', 20), 10);
        $this->assertSame(0, bccomp($estimate->amount, $expected, 10), "expected {$expected}, got {$estimate->amount}");
    }

    #[Test]
    public function no_reused_input_rate_component_is_ever_applied(): void
    {
        // An estimate has no cache-read concept — only fresh_input_rate
        // and output_rate are consulted. A very high reused_input_rate
        // must have zero effect on the computed estimate.
        $this->seedPrice([
            'reused_input_rate' => '999.00000000',
        ]);
        $conversation = $this->seedConversation();
        $this->addMessage($conversation, str_repeat('a', 130));

        $estimator = new CostEstimator();
        $estimate = $estimator->estimate($conversation->id, 'anthropic', 'claude-sonnet-5');

        $inputCost = Decimal::round(bcdiv(bcmul('100', '3.00000000', 20), '1000000', 20), 10);
        $outputCost = Decimal::round(bcdiv(bcmul('1000', '15.00000000', 20), '1000000', 20), 10);
        $expected = bcadd($inputCost, $outputCost, 10);

        $this->assertSame(0, bccomp($estimate->amount, $expected, 10), "expected {$expected}, got {$estimate->amount}");
    }

    // -----------------------------------------------------------------
    // Unpriced.
    // -----------------------------------------------------------------

    #[Test]
    public function unpriced_is_true_exactly_when_modelprice_currentfor_returns_null(): void
    {
        // No ModelPrice row exists for this pair at all.
        $conversation = $this->seedConversation();

        $estimator = new CostEstimator();
        $estimate = $estimator->estimate($conversation->id, 'openai', 'gpt-nonexistent');

        $this->assertTrue($estimate->unpriced);
    }

    #[Test]
    public function a_priced_pair_is_never_reported_unpriced(): void
    {
        $this->seedPrice();
        $conversation = $this->seedConversation();

        $estimator = new CostEstimator();
        $estimate = $estimator->estimate($conversation->id, 'anthropic', 'claude-sonnet-5');

        $this->assertFalse($estimate->unpriced);
    }

    #[Test]
    public function a_nonexistent_conversation_id_estimates_from_an_empty_history_rather_than_throwing(): void
    {
        $this->seedPrice();

        $estimator = new CostEstimator();
        $estimate = $estimator->estimate((string) \Illuminate\Support\Str::uuid(), 'anthropic', 'claude-sonnet-5');

        $this->assertFalse($estimate->unpriced);

        $expected = Decimal::round(bcdiv(bcmul('1000', '15.00000000', 20), '1000000', 20), 10);
        $this->assertSame(0, bccomp($estimate->amount, $expected, 10), "expected {$expected}, got {$estimate->amount}");
    }

    #[Test]
    public function a_mistyped_provider_type_or_model_never_throws_and_is_reported_unpriced_through_the_return_value(): void
    {
        $this->seedPrice();
        $conversation = $this->seedConversation();

        $estimator = new CostEstimator();

        $estimate = $estimator->estimate($conversation->id, null, null);
        $this->assertTrue($estimate->unpriced);

        $estimate = $estimator->estimate($conversation->id, 'totally-unknown-provider', 'totally-unknown-model');
        $this->assertTrue($estimate->unpriced);
    }

    #[Test]
    public function with_the_default_stop_policy_an_unpriced_estimate_carries_no_numeric_amount(): void
    {
        config(['llm-client.budget.on_unpriced_model' => 'stop']);
        $conversation = $this->seedConversation();

        $estimator = new CostEstimator();
        $estimate = $estimator->estimate($conversation->id, 'openai', 'gpt-nonexistent');

        $this->assertTrue($estimate->unpriced);
        $this->assertNull($estimate->amount);
    }

    #[Test]
    public function with_the_admit_untracked_policy_an_unpriced_estimate_also_carries_no_numeric_amount(): void
    {
        config(['llm-client.budget.on_unpriced_model' => 'admit_untracked']);
        $conversation = $this->seedConversation();

        $estimator = new CostEstimator();
        $estimate = $estimator->estimate($conversation->id, 'openai', 'gpt-nonexistent');

        $this->assertTrue($estimate->unpriced);
        $this->assertNull($estimate->amount);
    }

    // -----------------------------------------------------------------
    // reserve_flat_estimate config-validation (research.md D8).
    // -----------------------------------------------------------------

    #[Test]
    public function reserve_flat_estimate_policy_with_no_configured_flat_estimate_throws_immediately_rather_than_silently_estimating_null(): void
    {
        config([
            'llm-client.budget.on_unpriced_model' => 'reserve_flat_estimate',
            'llm-client.budget.unpriced_model_flat_estimate' => null,
        ]);
        $conversation = $this->seedConversation();

        $estimator = new CostEstimator();

        $this->expectException(\InvalidArgumentException::class);

        $estimator->estimate($conversation->id, 'openai', 'gpt-nonexistent');
    }

    #[Test]
    public function reserve_flat_estimate_policy_with_a_configured_flat_estimate_reports_that_amount_for_an_unpriced_model(): void
    {
        config([
            'llm-client.budget.on_unpriced_model' => 'reserve_flat_estimate',
            'llm-client.budget.unpriced_model_flat_estimate' => '5.00',
        ]);
        $conversation = $this->seedConversation();

        $estimator = new CostEstimator();
        $estimate = $estimator->estimate($conversation->id, 'openai', 'gpt-nonexistent');

        $this->assertTrue($estimate->unpriced);
        $this->assertSame(0, bccomp($estimate->amount, '5.00', 10));
    }

    #[Test]
    public function reserve_flat_estimate_policy_never_affects_a_priced_pairs_computed_estimate(): void
    {
        config([
            'llm-client.budget.on_unpriced_model' => 'reserve_flat_estimate',
            'llm-client.budget.unpriced_model_flat_estimate' => '5.00',
        ]);
        $this->seedPrice();
        $conversation = $this->seedConversation();

        $estimator = new CostEstimator();
        $estimate = $estimator->estimate($conversation->id, 'anthropic', 'claude-sonnet-5');

        $this->assertFalse($estimate->unpriced);

        $expected = Decimal::round(bcdiv(bcmul('1000', '15.00000000', 20), '1000000', 20), 10);
        $this->assertSame(0, bccomp($estimate->amount, $expected, 10), "expected {$expected}, got {$estimate->amount}");
    }
}
