<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\BudgetReservationLedger;
use ClarionApp\LlmClient\Models\CostReservation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Services\Concerns\RetriesConcurrencyAborts;
use ClarionApp\LlmClient\Support\CalendarPeriod;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\ReservationSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The sole reader/writer of budget_reservation_ledger/cost_reservations
 * (contracts/reservation-api.md §3, research.md D3/D5/D7).
 *
 * Every scope key received by reserve() is bounded — this class has no
 * per-axis enforcement-mode information and applies the same conditional
 * form to all of them, because the caller (BudgetGate::admit()) has
 * already filtered scope keys down to stop-mode axes before calling this
 * method at all (research.md D5, grounding note item 7). A warn-mode axis
 * is never passed in and never reserved against.
 */
class ReservationLedger
{
    use RetriesConcurrencyAborts;

    public function __construct(private readonly SpendingCeilingService $ceilings)
    {
    }

    /**
     * Atomic per research.md D5. Returns null when the bound would be
     * exceeded (decline); the CostReservation when it succeeds. Never
     * partially reserves across scope_keys — all axes attempted, or none
     * committed.
     */
    public function reserve(
        array $scopeKeys,
        string $estimatedAmount,
        BudgetWorkKind $workKind,
        ?string $userId = null,
        ?string $conversationId = null,
        ?string $runId = null,
    ): ?CostReservation {
        if (!preg_match('/^-?\d+\.\d{10}$/', $estimatedAmount)) {
            throw new \InvalidArgumentException("Invalid reservation amount: '{$estimatedAmount}'");
        }

        $reservation = null;

        try {
            $this->transactionWithConcurrencyRetries(function () use (
                $scopeKeys,
                $estimatedAmount,
                $workKind,
                $userId,
                $conversationId,
                $runId,
                &$reservation
            ) {
                foreach ($scopeKeys as $scopeKey) {
                    if (!$this->boundOneAxis($scopeKey, $estimatedAmount)) {
                        // Abort the whole attempt without an exception
                        // escaping to the caller as a genuine error —
                        // throwing here is what makes DB::transaction()
                        // roll back everything already written for earlier
                        // axes in this same attempt (all-or-nothing).
                        // Caught immediately below.
                        throw new ReservationDeclinedSignal();
                    }
                }

                $reservation = CostReservation::create([
                    'scope_keys' => $scopeKeys,
                    'user_id' => $userId,
                    'conversation_id' => $conversationId,
                    'run_id' => $runId,
                    'work_kind' => $workKind->value,
                    'estimated_amount' => $estimatedAmount,
                    'actual_amount' => null,
                    'status' => CostReservation::STATUS_HELD,
                    'held_at' => now(),
                    'resolved_at' => null,
                ]);
            });
        } catch (ReservationDeclinedSignal) {
            return null;
        }

        return $reservation;
    }

    /**
     * Idempotent: a no-op, including its ledger decrement, when the
     * reservation is not (or no longer) 'held'. Never trusts the passed
     * CostReservation object's in-memory `status` — always re-checks
     * `status = 'held'` in its own `UPDATE ... WHERE id = ? AND status =
     * 'held'`, inside one DB::transaction(), and only decrements
     * budget_reservation_ledger when THAT statement's own affected-row
     * count is 1 (research.md D7, grounding note item 8).
     */
    public function reconcile(CostReservation $reservation, string $actualAmount): void
    {
        $this->resolve($reservation, CostReservation::STATUS_RECONCILED, $actualAmount);
    }

    public function release(CostReservation $reservation): void
    {
        $this->resolve($reservation, CostReservation::STATUS_RELEASED, null);
    }

    /**
     * Sole reader, mirroring BudgetLedger::forUser()/forInstallation()
     * exactly — including on failure: a \Throwable from the underlying
     * read is caught internally and reported as
     * ReservationSnapshot::unavailable(), never thrown.
     */
    public function heldFor(string $scopeKey): ReservationSnapshot
    {
        [$scopeType, $scopeId] = $this->parseScopeKey($scopeKey);

        try {
            $row = BudgetReservationLedger::query()
                ->where('scope_type', $scopeType)
                ->where('scope_id', $scopeId)
                ->first();

            if ($row === null) {
                // No row means nothing is held — a fully known fact, unlike
                // a failed read.
                return new ReservationSnapshot('0.0000000000', true);
            }

            return new ReservationSnapshot($row->reserved_total, true);
        } catch (\Throwable $e) {
            Log::warning('Held reservation total could not be read', [
                'scope' => $scopeKey,
                'error' => $e->getMessage(),
            ]);

            return ReservationSnapshot::unavailable();
        }
    }

    /**
     * One scope axis's compare-and-set UPDATE (research.md D5). Creates
     * the anchor row first via insertOrIgnore (idempotent under
     * concurrency via the unique index) — never for a scope with no
     * applicable ceiling, preserving evaluate()'s own "nothing configured
     * costs nothing" short-circuit. Returns whether the axis's bound held.
     */
    private function boundOneAxis(string $scopeKey, string $estimatedAmount): bool
    {
        [$scopeType, $scopeId] = $this->parseScopeKey($scopeKey);

        $ceiling = $scopeType === 'installation'
            ? $this->ceilings->resolveInstallation()
            : $this->ceilings->resolveForUser($scopeId);

        if ($ceiling === null || $ceiling->amount === null) {
            // No applicable ceiling to bound against on this axis — a race
            // against a ceiling removed between evaluate() and admit(), or
            // a waived row. Nothing constrains this axis, so it is treated
            // as fitting, and nothing is written for it.
            return true;
        }

        DB::table('budget_reservation_ledger')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'reserved_total' => '0.0000000000',
            'updated_at' => now(),
        ]);

        [$from, $to] = CalendarPeriod::containing($ceiling->period_type);

        // Both sides of the final comparison are wrapped in
        // CAST(... AS DECIMAL(20,10)) — not decorative. Under SQLite's
        // storage-class comparison rules, an arithmetic expression over a
        // NUMERIC-affinity column (the left side) evaluates to the REAL
        // storage class, while a bound `?` parameter carries no affinity of
        // its own and is compared as TEXT; SQLite's ordering rule is that
        // *every* REAL sorts below *every* TEXT regardless of numeric
        // value, so an uncast "12.0 <= '10.0000000000'" evaluates true.
        // The CAST forces both sides into the same NUMERIC-affinity
        // storage class for a genuine numeric comparison — and is a no-op
        // on MySQL/MariaDB, where a DECIMAL column already compares
        // numerically against a numeric-looking string.
        $affected = DB::update(
            'UPDATE budget_reservation_ledger
             SET reserved_total = reserved_total + ?, updated_at = ?
             WHERE scope_type = ? AND scope_id = ?
               AND (
                 CAST(
                   reserved_total + ?
                   + (SELECT COALESCE(SUM(priced_cost_total), 0) FROM cost_summaries
                       WHERE entity_type = ? AND period_date BETWEEN ? AND ?
                         AND (? != ? OR entity_id = ?))
                 AS DECIMAL(20,10))
               ) <= CAST(? AS DECIMAL(20,10))',
            [
                $estimatedAmount, now(),
                $scopeType, $scopeId,
                $estimatedAmount,
                CostSummary::ENTITY_USER, $from, $to,
                $scopeType, 'user', $scopeId,
                $ceiling->amount,
            ]
        );

        return $affected === 1;
    }

    /**
     * reconcile()/release()'s shared mechanism. Never branches on the
     * passed CostReservation object's in-memory `status` — the object may
     * be stale, exactly as in the cross-process race research.md D7
     * describes. Performs its own single UPDATE ... WHERE id = ? AND
     * status = 'held' first, inside one DB::transaction(), and only
     * decrements the ledger when that statement's own affected-row count
     * is 1. A 0 affected-row count (someone else already transitioned it)
     * makes the whole call, including the ledger, a no-op.
     */
    private function resolve(CostReservation $reservation, string $status, ?string $actualAmount): void
    {
        $this->transactionWithConcurrencyRetries(function () use ($reservation, $status, $actualAmount) {
            $affected = DB::table('cost_reservations')
                ->where('id', $reservation->id)
                ->where('status', CostReservation::STATUS_HELD)
                ->update([
                    'status' => $status,
                    'actual_amount' => $actualAmount,
                    'resolved_at' => now(),
                ]);

            if ($affected !== 1) {
                return;
            }

            foreach ((array) $reservation->scope_keys as $scopeKey) {
                [$scopeType, $scopeId] = $this->parseScopeKey($scopeKey);

                DB::update(
                    'UPDATE budget_reservation_ledger
                     SET reserved_total = reserved_total - ?, updated_at = ?
                     WHERE scope_type = ? AND scope_id = ?',
                    [$reservation->estimated_amount, now(), $scopeType, $scopeId]
                );
            }
        });
    }

    /**
     * 'installation' -> ['installation', the sentinel]; 'user:<uuid>' ->
     * ['user', <uuid>] — the exact two shapes BudgetGate::scopeKey()
     * produces.
     */
    private function parseScopeKey(string $scopeKey): array
    {
        if ($scopeKey === 'installation') {
            return ['installation', SpendingCeiling::INSTALLATION_SCOPE_ID];
        }

        return ['user', substr($scopeKey, strlen('user:'))];
    }
}

/**
 * Internal control-flow signal only — thrown inside reserve()'s own
 * DB::transaction() closure to abort the whole attempt when any axis's
 * bounded UPDATE affects zero rows, so DB::transaction() rolls back
 * everything already written for earlier axes in the same attempt. Caught
 * immediately by reserve() itself (via transactionWithConcurrencyRetries'
 * $catching parameter) so a decline can be reported as a clean `null`
 * return rather than an exception escaping to BudgetGate. Never leaves
 * this file.
 */
final class ReservationDeclinedSignal extends \RuntimeException
{
}
