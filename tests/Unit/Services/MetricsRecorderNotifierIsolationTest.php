<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Services\BudgetThresholdNotifier;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Where the threshold notifier is called from inside
 * MetricsRecorder::recordUsage(), which is two placement questions rather
 * than one, and both have a wrong answer that looks right.
 *
 * **Outside the transaction, not inside it.** Called inside the
 * DB::transaction() closure the notifier reads a total that has not been
 * committed yet, and can broadcast a warning about consumption a
 * subsequent rollback erases — a durable latch row claiming a period's
 * warning has already fired, for spend that never happened, which no later
 * unit of work can undo because the latch is once-per-period by design.
 * The assertion below is on the transaction depth observed *by the
 * notifier itself*, because the outcome of a correct and an incorrect
 * placement is indistinguishable in every case where nothing rolls back.
 *
 * **Its own try/catch, not the caller's.** MetricsRecorder is
 * fire-and-forget: it already isolates reused-input extraction, cost
 * computation, and the cost-summary upsert in three separate inner
 * try/catch blocks precisely so a failure in one cannot suppress the
 * others. A notifier call without one puts a broadcast failure in front of
 * the usage record — the same failure mode spec 070 hit in its Phase 6,
 * where a bare event() inside an outer catch turned a successful insert's
 * return value into null.
 */
class MetricsRecorderNotifierIsolationTest extends TestCase
{
    private string $userId;
    private string $conversationId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00', 'UTC'));

        $this->userId = (string) Str::uuid();
        $this->conversationId = (string) Str::uuid();

        ModelPrice::create([
            'provider_type' => 'llama_cpp',
            'model' => 'priced-model',
            'reused_input_rate' => '0.00000000',
            'fresh_input_rate' => '100000.00000000',
            'output_rate' => '0.00000000',
            'effective_from' => Carbon::now()->subDay(),
            'effective_until' => null,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        DB::table('budget_threshold_notifications')->delete();
        DB::table('cost_summaries')->delete();
        DB::table('usage_summaries')->delete();
        DB::table('usage_records')->delete();
        DB::table('model_prices')->delete();

        Mockery::close();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixtures — deliberately not named seed(); Orchestra's TestCase
    // declares a public seed() of its own.
    // ---------------------------------------------------------------

    /** One completed unit of work costing $tokens x 0.10. */
    private function record(int $tokens = 200): void
    {
        (new MetricsRecorder())->recordUsage(
            conversationId: $this->conversationId,
            userId: $this->userId,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: [
                'prompt_tokens' => $tokens,
                'completion_tokens' => 0,
                'total_tokens' => $tokens,
            ],
            inputText: 'input',
            outputText: '',
            model: 'priced-model',
            providerType: 'llama_cpp',
        );
    }

    private function committedUserTotal(): string
    {
        $row = DB::table('cost_summaries')
            ->where('entity_type', CostSummary::ENTITY_USER)
            ->where('entity_id', $this->userId)
            ->first();

        return $row === null ? '0.0000000000' : (string) $row->priced_cost_total;
    }

    // ---------------------------------------------------------------
    // Placement: after the commit, never inside the transaction
    // ---------------------------------------------------------------

    #[Test]
    public function the_notifier_is_called_after_the_transaction_has_committed(): void
    {
        $observedDepth = null;
        $observedTotal = null;

        $notifier = Mockery::mock(BudgetThresholdNotifier::class);
        $notifier->shouldReceive('notify')
            ->once()
            ->andReturnUsing(function () use (&$observedDepth, &$observedTotal) {
                $observedDepth = DB::transactionLevel();
                $observedTotal = $this->committedUserTotal();
            });

        $this->app->instance(BudgetThresholdNotifier::class, $notifier);

        $this->record(200);

        $this->assertSame(
            0,
            $observedDepth,
            'The notifier ran inside recordUsage() transaction — it would read a total a rollback can still erase'
        );

        // ...and the figure it can see is the one the unit of work just
        // added, so a post-commit call is not a call that arrived too late
        // to see anything.
        $this->assertNotNull($observedTotal);
        $this->assertSame(0, bccomp((string) $observedTotal, '20.00', 10));
    }

    #[Test]
    public function the_notifier_is_called_with_the_user_the_usage_was_attributed_to(): void
    {
        $notifier = Mockery::mock(BudgetThresholdNotifier::class);
        $notifier->shouldReceive('notify')->once()->with($this->userId);

        $this->app->instance(BudgetThresholdNotifier::class, $notifier);

        $this->record(200);

        $this->addToAssertionCount(1);
    }

    /**
     * The consequence the placement rule exists for: when the unit of work
     * does not survive, neither does anything said about it. Nothing is
     * latched and nothing is broadcast for consumption that was rolled
     * back — a latch is once per period, so a false one cannot be undone
     * by any later unit of work.
     */
    #[Test]
    public function a_rolled_back_unit_of_work_produces_no_warning_for_consumption_it_erased(): void
    {
        $notifier = Mockery::mock(BudgetThresholdNotifier::class);
        $notifier->shouldReceive('notify')->never();

        $this->app->instance(BudgetThresholdNotifier::class, $notifier);

        // Fail the transaction at a point that is not inside any of
        // recordUsage()'s inner isolation blocks, so the closure itself
        // aborts and the whole write is rolled back.
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'usage_summaries')
                && str_starts_with(strtolower(ltrim($query->sql)), 'update')) {
                throw new \RuntimeException('forced failure inside the transaction');
            }
        });

        $this->record(200);

        $this->assertSame(
            0,
            bccomp($this->committedUserTotal(), '0', 10),
            'Precondition: the rollback erased the consumption'
        );
        $this->assertSame(0, DB::table('usage_records')->count());
        $this->assertSame(0, DB::table('budget_threshold_notifications')->count());
    }

    // ---------------------------------------------------------------
    // Isolation: a notifier failure is not a metrics failure
    // ---------------------------------------------------------------

    /**
     * The usage record and the cost-summary increment are the metrics
     * path's actual product. A notifier that throws must cost neither of
     * them, and must not surface as a failure in the conversation either.
     */
    #[Test]
    public function a_throwing_notifier_leaves_the_usage_record_and_the_cost_summary_intact(): void
    {
        $notifier = Mockery::mock(BudgetThresholdNotifier::class);
        $notifier->shouldReceive('notify')
            ->once()
            ->andThrow(new \RuntimeException('the broadcaster is down'));

        $this->app->instance(BudgetThresholdNotifier::class, $notifier);

        $this->record(200);

        $this->addToAssertionCount(1); // recordUsage() did not throw

        $this->assertSame(
            1,
            DB::table('usage_records')->where('user_id', $this->userId)->count(),
            'A broadcast failure must never suppress the usage record'
        );
        $this->assertSame(
            0,
            bccomp($this->committedUserTotal(), '20.00', 10),
            'A broadcast failure must never suppress the cost-summary increment'
        );
    }

    /**
     * Stated separately from the assertion above because the two fail for
     * different reasons: this one is about the exception escaping into the
     * conversation, that one is about the writes being lost.
     */
    #[Test]
    public function a_throwing_notifier_never_propagates_out_of_record_usage(): void
    {
        $notifier = Mockery::mock(BudgetThresholdNotifier::class);
        $notifier->shouldReceive('notify')->twice()->andThrow(new \RuntimeException('boom'));

        $this->app->instance(BudgetThresholdNotifier::class, $notifier);

        $this->record(200);
        $this->record(200);

        $this->assertSame(2, DB::table('usage_records')->where('user_id', $this->userId)->count());
    }
}
