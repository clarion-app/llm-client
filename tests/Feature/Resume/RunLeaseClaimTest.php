<?php

namespace Tests\Feature\Resume;

use ClarionApp\LlmClient\Exceptions\LeaseLost;
use ClarionApp\LlmClient\Services\RunLease;
use ClarionApp\LlmClient\ValueObjects\ClaimResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RunLease — the execution lease (lease contract §1).
 *
 * The claim is one statement: exactly one worker wins a dispatch id, a
 * takeover is detected from the row the claim left, and a lost claim names
 * why (live lease, stale dispatch, terminal, cancelled). The hand-off
 * releases the lease and mints the next dispatch id on a compare-and-set;
 * the nonce is per process.
 */
class RunLeaseClaimTest extends TestCase
{
    private string $userId = '11111111-1111-1111-1111-111111111111';

    private string $foreignNonce = '99999999-9999-9999-9999-999999999999';

    private function lease(): RunLease
    {
        return app(RunLease::class);
    }

    /** Insert a minimal in-progress run row with no lease held. */
    private function makeRun(string $id, array $overrides = []): void
    {
        DB::table('agent_runs')->insert(array_merge([
            'id' => $id,
            'kind' => 'interactive',
            'user_id' => $this->userId,
            'started_at' => now()->format('Y-m-d H:i:s.u'),
            'end_state' => 'in_progress',
            'dispatch_id' => '10000000-0000-0000-0000-000000000001',
            'consecutive_interruptions' => 0,
        ], $overrides));
    }

    /** @return object the run row */
    private function runRow(string $id): object
    {
        $row = DB::table('agent_runs')->where('id', $id)->first();
        $this->assertNotNull($row, "run {$id} must exist");

        return $row;
    }

    private function assertWithin(Carbon $expected, ?string $actual, int $toleranceSeconds, string $message = ''): void
    {
        $this->assertNotNull($actual, $message ?: 'the timestamp is set');
        $delta = abs(Carbon::parse($actual)->diffInSeconds($expected));
        $this->assertLessThanOrEqual($toleranceSeconds, $delta, $message." (expected {$expected}, got {$actual})");
    }

    // ---------------------------------------------------------------
    // claim — one winner per dispatch
    // ---------------------------------------------------------------

    #[Test]
    public function one_winner_per_dispatch(): void
    {
        $this->makeRun('run-1');
        $first = $this->lease()->claim('run-1', '10000000-0000-0000-0000-000000000001');

        $this->assertTrue($first->won, 'the first claim wins');
        $this->assertNotNull($first->nonce);

        // A second claim of the same dispatch id: the lease is live, so it loses.
        $second = $this->lease()->claim('run-1', '10000000-0000-0000-0000-000000000001');
        $this->assertFalse($second->won, 'the second claim of the same dispatch id loses');
        $this->assertSame('taken', $second->reason, 'a live lease is not taken');
    }

    #[Test]
    public function stale_dispatch_loses(): void
    {
        // quickstart M5: a stale dispatch id never claims.
        $this->makeRun('run-1');

        $result = $this->lease()->claim('run-1', '20000000-0000-0000-0000-000000000002');

        $this->assertFalse($result->won);
        $this->assertSame('stale_dispatch', $result->reason);
        $this->assertNull($this->runRow('run-1')->lease_holder, 'a lost claim writes nothing');
    }

    #[Test]
    public function live_lease_not_taken(): void
    {
        // quickstart M6: a live lease (held by another process) is not taken.
        $this->makeRun('run-1', [
            'lease_holder' => $this->foreignNonce,
            'lease_expires_at' => now()->addSeconds(300)->format('Y-m-d H:i:s.u'),
        ]);

        $result = $this->lease()->claim('run-1', '10000000-0000-0000-0000-000000000001');

        $this->assertFalse($result->won);
        $this->assertSame('taken', $result->reason);
        $this->assertSame($this->foreignNonce, $this->runRow('run-1')->lease_holder, 'the live lease is untouched');
    }

    #[Test]
    public function expired_lease_is_takeover(): void
    {
        // An expired, still-held lease: the claim takes the run over and
        // detects the interruption in the one statement.
        $this->makeRun('run-1', [
            'lease_holder' => $this->foreignNonce,
            'lease_expires_at' => now()->subSeconds(1)->format('Y-m-d H:i:s.u'),
            'resume_state' => 'working',
            'consecutive_interruptions' => 0,
        ]);

        $result = $this->lease()->claim('run-1', '10000000-0000-0000-0000-000000000001');

        $this->assertTrue($result->won);
        $this->assertTrue($result->takeover, 'an expired held lease is a takeover');
        $this->assertTrue($result->detectedHere, 'the claim itself detected the interruption');
        $row = $this->runRow('run-1');
        $this->assertSame('resuming', $row->resume_state);
        $this->assertSame(1, (int) $row->consecutive_interruptions, 'the interruption is counted in the one statement');
        $this->assertNotNull($row->interrupted_at);
        $this->assertWithin(Carbon::now(), $row->interrupted_at, 5, 'interrupted_at is the claim time');
        $this->assertNotSame($this->foreignNonce, $row->lease_holder, 'the lease is now held by the claimant');
    }

    #[Test]
    public function sweep_interrupted_claim_counts_nothing(): void
    {
        // A row the sweep already marked interrupted (lease released, count
        // already incremented): the claim lands resuming but counts nothing.
        $this->makeRun('run-1', [
            'resume_state' => 'interrupted',
            'lease_holder' => null,
            'consecutive_interruptions' => 1,
            'interrupted_at' => now()->subSeconds(60)->format('Y-m-d H:i:s.u'),
        ]);

        $result = $this->lease()->claim('run-1', '10000000-0000-0000-0000-000000000001');

        $this->assertTrue($result->won);
        $this->assertTrue($result->takeover, 'a sweep-marked interrupted row is a takeover');
        $this->assertFalse($result->detectedHere, 'the sweep counted it, so the claim detects nothing');
        $row = $this->runRow('run-1');
        $this->assertSame('resuming', $row->resume_state);
        $this->assertSame(1, (int) $row->consecutive_interruptions, 'the count is not incremented again');
    }

    #[Test]
    public function clean_handoff_is_not_takeover(): void
    {
        // A clean hand-off (lease released, working): the claim is not a
        // takeover and lands working.
        $this->makeRun('run-1', [
            'lease_holder' => null,
            'resume_state' => 'working',
        ]);

        $result = $this->lease()->claim('run-1', '10000000-0000-0000-0000-000000000001');

        $this->assertTrue($result->won);
        $this->assertFalse($result->takeover, 'a clean hand-off is not a takeover');
        $this->assertSame('working', $this->runRow('run-1')->resume_state);
    }

    #[Test]
    public function interrupted_claim_is_resuming(): void
    {
        $this->makeRun('run-1', [
            'resume_state' => 'interrupted',
            'lease_holder' => null,
        ]);

        $result = $this->lease()->claim('run-1', '10000000-0000-0000-0000-000000000001');

        $this->assertTrue($result->won);
        $this->assertSame('resuming', $this->runRow('run-1')->resume_state);
    }

    #[Test]
    public function cancelled_never_claims(): void
    {
        $this->makeRun('run-1', [
            'cancelled_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        $result = $this->lease()->claim('run-1', '10000000-0000-0000-0000-000000000001');

        $this->assertFalse($result->won);
        $this->assertSame('cancelled', $result->reason);
    }

    #[Test]
    public function terminal_loses(): void
    {
        $this->makeRun('run-1', [
            'end_state' => 'completed',
        ]);

        $result = $this->lease()->claim('run-1', '10000000-0000-0000-0000-000000000001');

        $this->assertFalse($result->won);
        $this->assertSame('terminal', $result->reason);
    }

    // ---------------------------------------------------------------
    // handOff
    // ---------------------------------------------------------------

    #[Test]
    public function handOff_mints_new_dispatch_and_releases(): void
    {
        $this->makeRun('run-1');
        $this->assertTrue($this->lease()->claim('run-1', '10000000-0000-0000-0000-000000000001')->won, 'precondition: the lease is held');

        $newDispatch = $this->lease()->handOff('run-1');

        $row = $this->runRow('run-1');
        $this->assertNotSame('10000000-0000-0000-0000-000000000001', $newDispatch, 'a new dispatch id is minted');
        $this->assertSame($newDispatch, $row->dispatch_id);
        $this->assertNull($row->lease_holder, 'the lease is released');
        $this->assertWithin(
            Carbon::now()->addSeconds(300),
            $row->lease_expires_at,
            60,
            'the lease is refreshed to now + the detection window',
        );

        // A foreign nonce: the compare-and-set fails and throws LeaseLost.
        DB::table('agent_runs')->where('id', 'run-1')->update(['lease_holder' => $this->foreignNonce]);
        $this->expectException(LeaseLost::class);
        $this->lease()->handOff('run-1');
    }

    // ---------------------------------------------------------------
    // release
    // ---------------------------------------------------------------

    #[Test]
    public function release_clears_holder(): void
    {
        $this->makeRun('run-1');
        $this->assertTrue($this->lease()->claim('run-1', '10000000-0000-0000-0000-000000000001')->won, 'precondition: the lease is held');

        $this->lease()->release('run-1');

        $this->assertNull($this->runRow('run-1')->lease_holder, 'release clears the holder');
    }

    // ---------------------------------------------------------------
    // nonce
    // ---------------------------------------------------------------

    #[Test]
    public function nonce_is_per_process(): void
    {
        $a = new RunLease();
        $b = new RunLease();

        $this->assertSame($a->nonce(), $b->nonce(), 'two instances in one process share the nonce');
        $this->assertSame($a->nonce(), $a->nonce(), 'the nonce is stable across calls');
        $this->assertNotEmpty($a->nonce());
    }
}
