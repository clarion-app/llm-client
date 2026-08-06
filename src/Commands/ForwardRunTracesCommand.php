<?php

namespace ClarionApp\LlmClient\Commands;

use ClarionApp\LlmClient\Services\OtlpPayloadBuilder;
use ClarionApp\LlmClient\ValueObjects\TraceExportConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled command that drains agent_run_export_queue against a configured
 * OTLP destination, per contracts/cli-commands.md.
 *
 * Delivery lives exclusively here, on the scheduler tick -- never on the
 * request/response hot path. RunTraceRecorder::closeRun() only ever inserts
 * a queue row (via enqueueForwarding()); it makes no HTTP call itself.
 *
 * Full retry/backoff (attempt accounting, exponential delay, payload-size
 * precheck) lands in a later phase (US3) -- this first pass never crashes
 * and never leaves a delivered/discarded row behind, but a failed attempt
 * simply leaves the row in place for the next tick to retry unconditionally.
 */
class ForwardRunTracesCommand extends Command
{
    protected $signature = 'llm-client:forward-run-traces
                            {--dry-run : Report counts without sending any HTTP request or mutating any row}';

    protected $description = 'Forward queued agent run traces to the configured OTLP destination';

    public function handle(OtlpPayloadBuilder $builder): int
    {
        $config = TraceExportConfig::resolve();
        $dryRun = (bool) $this->option('dry-run');

        if (!in_array('external', $config->destinations, true)) {
            return $this->handleForwardingDisabled($dryRun);
        }

        return $this->handleDelivery($config, $builder, $dryRun);
    }

    /**
     * `external` not selected -- either never configured, or dropped by
     * TraceExportConfig::resolve() itself on a malformed/missing
     * otlp_endpoint (in which case resolve() has already logged exactly one
     * warning; this method adds no per-record noise on top of that).
     *
     * "No buffer is maintained" when forwarding is off (FR-003): every
     * leftover row is discarded, and no HTTP attempt is ever made.
     */
    private function handleForwardingDisabled(bool $dryRun): int
    {
        $count = DB::table('agent_run_export_queue')->count();

        if ($dryRun) {
            $this->info("Forwarding disabled -- would discard {$count} queued record(s).");

            return self::SUCCESS;
        }

        if ($count > 0) {
            DB::table('agent_run_export_queue')->delete();
        }

        $this->reportSummary(delivered: 0, discarded: $count);

        return self::SUCCESS;
    }

    private function handleDelivery(TraceExportConfig $config, OtlpPayloadBuilder $builder, bool $dryRun): int
    {
        $now = now();

        $dueQuery = DB::table('agent_run_export_queue')
            ->where(function ($query) use ($now) {
                $query->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', $now);
            })
            ->orderBy('created_at')
            ->limit($config->maxRecordsPerRun);

        if ($dryRun) {
            $dueCount = (clone $dueQuery)->count();
            $this->info("Would attempt delivery for {$dueCount} due record(s).");

            return self::SUCCESS;
        }

        $rows = $dueQuery->get();

        $delivered = 0;
        $discarded = 0;

        foreach ($rows as $row) {
            $payload = $builder->build($row->run_id);

            // The run this row points at no longer resolves -- aged out
            // internally (purged) while still awaiting forwarding
            // (data-model.md §2). Discard the queue row rather than attempt
            // delivery for a run that no longer exists to describe.
            if ($payload === null) {
                DB::table('agent_run_export_queue')->where('id', $row->id)->delete();
                $discarded++;

                continue;
            }

            if ($this->deliver($config, $payload)) {
                DB::table('agent_run_export_queue')->where('id', $row->id)->delete();
                $delivered++;
            }

            // Any other outcome (non-2xx, connection failure, timeout):
            // leave the row in place. Attempt accounting and backoff are not
            // implemented yet -- the next tick simply tries again.
        }

        $this->reportSummary(delivered: $delivered, discarded: $discarded);

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function deliver(TraceExportConfig $config, array $payload): bool
    {
        // otlp_auth_value is read here, at the single point of use, and never
        // stored in a variable that outlives this call (config-reference.md
        // secret-handling contract). When null/empty, the header is omitted
        // entirely rather than sent empty -- Http::withHeaders() would
        // otherwise send a present-but-empty header, which some destinations
        // reject as malformed rather than treat as anonymous ingest.
        $headers = [];
        if ($config->otlpAuthValue !== null && $config->otlpAuthValue !== '') {
            $headers[$config->otlpAuthHeader] = $config->otlpAuthValue;
        }

        try {
            $response = Http::timeout($config->httpTimeoutSeconds)
                ->withHeaders($headers)
                ->post($config->otlpEndpoint, $payload);

            return $response->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function reportSummary(int $delivered, int $discarded): void
    {
        Log::info('Agent run traces forwarded', [
            'delivered' => $delivered,
            'discarded' => $discarded,
        ]);

        $this->info("Delivered: {$delivered}, Discarded: {$discarded}");
    }
}
