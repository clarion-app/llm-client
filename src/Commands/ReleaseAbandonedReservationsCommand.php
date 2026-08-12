<?php

namespace ClarionApp\LlmClient\Commands;

use ClarionApp\LlmClient\Models\CostReservation;
use ClarionApp\LlmClient\Services\ReservationLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command to release cost reservations left held by a process that
 * never resolved them itself (research.md D6) — the crashed-worker case: a
 * reservation admitted for a unit of work whose owning process ended
 * without ever reaching a reconciliation or a fallback release.
 *
 * Eligibility: status = 'held' AND held_at older than the configured
 * abandonment cutoff — driven purely by cost_reservations' own
 * ['status', 'held_at'] index. Deliberately independent of any run-tracking
 * table: a held reservation is swept by its own age alone, whether or not
 * it happens to name a run, and whether or not that run still exists or is
 * itself still open — that is an entirely different sweep's concern.
 *
 * Usage:
 *   php artisan llm-client:release-abandoned-reservations [--minutes=30] [--dry-run]
 */
class ReleaseAbandonedReservationsCommand extends Command
{
    protected $signature = 'llm-client:release-abandoned-reservations
                            {--minutes= : Abandonment threshold in minutes (default: from config)}
                            {--dry-run : Show what would be released without actually releasing}';

    protected $description = 'Release held cost reservations abandoned by a process that never resolved them';

    public function handle(ReservationLedger $ledger): int
    {
        $minutes = (int) ($this->option('minutes')
            ?? config('llm-client.budget.reservation.abandonment_minutes', 30));
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = now()->subMinutes($minutes);

        $this->info("Abandonment threshold: {$minutes} minutes (cutoff: {$cutoff->toDateTimeString()})");
        if ($dryRun) {
            $this->warn('Dry-run mode — no changes will be made');
        }

        $eligible = CostReservation::query()
            ->where('status', CostReservation::STATUS_HELD)
            ->where('held_at', '<', $cutoff)
            ->get();

        if ($eligible->isEmpty()) {
            $this->info('No abandoned reservations to release');
            if ($dryRun) {
                $this->comment('Dry-run complete — no changes were made');
            }
            return self::SUCCESS;
        }

        $count = 0;

        foreach ($eligible as $reservation) {
            if ($dryRun) {
                $count++;
                continue;
            }

            // abandon()'s own WHERE status = 'held' guard (inside its
            // shared resolve() mechanism) means a reservation already
            // transitioned by a racing caller between the query above and
            // this call is left untouched, not double-resolved.
            $ledger->abandon($reservation);
            $count++;

            Log::info('Abandoned reservation released', [
                'reservation_id' => $reservation->id,
                'held_at' => $reservation->held_at,
            ]);
        }

        $verb = $dryRun ? 'would be released' : 'released';
        $this->info("Reservations {$verb}: {$count}");

        if ($dryRun) {
            $this->comment('Dry-run complete — no changes were made');
        }

        return self::SUCCESS;
    }
}
