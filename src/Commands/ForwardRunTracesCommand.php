<?php

namespace ClarionApp\LlmClient\Commands;

use ClarionApp\LlmClient\Services\BufferEvictionCounter;
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
 * Phase 5 (US3): a payload exceeding export.max_payload_bytes is discarded
 * with no HTTP attempt (research.md §5); a failed delivery increments
 * attempts and, below max_attempts, sets next_attempt_at using an
 * exponential-with-cap backoff (research.md §4) -- at or above max_attempts
 * the row is deleted (discarded). The per-invocation aggregate log line
 * also folds in buffer_evicted, read (and reset) once per invocation from
 * BufferEvictionCounter -- the hot-path buffer-overflow trim happens on a
 * request process, in RunTraceRecorder::enqueueForwarding(), not here.
 */
class ForwardRunTracesCommand extends Command
{
    protected $signature = 'llm-client:forward-run-traces
                            {--dry-run : Report counts without sending any HTTP request or mutating any row}';

    protected $description = 'Forward queued agent run traces to the configured OTLP destination';

    public function handle(OtlpPayloadBuilder $builder): int
    {
        try {
            $config = TraceExportConfig::resolve();
            $dryRun = (bool) $this->option('dry-run');

            if (!in_array('external', $config->destinations, true)) {
                return $this->handleForwardingDisabled($dryRun);
            }

            return $this->handleDelivery($config, $builder, $dryRun);
        } catch (\Throwable $e) {
            // Exit code is always 0 (a destination being down, or any other
            // failure here, is expected operation, not a command failure --
            // contracts/cli-commands.md). Nothing about this invocation may
            // ever crash the scheduler.
            Log::warning('ForwardRunTracesCommand: invocation failed', [
                'error' => $e->getMessage(),
            ]);

            return self::SUCCESS;
        }
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

        $this->reportSummary(delivered: 0, retried: 0, discarded: $count, bufferEvicted: BufferEvictionCounter::readAndReset());

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
        $retried = 0;
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

            // Oversized-payload precheck (research.md §5): a payload that is
            // too large is not going to become smaller on retry, so it is
            // discarded outright with no HTTP attempt at all -- checked
            // before deliver() is ever called.
            $payloadBytes = strlen((string) json_encode($payload));
            if ($payloadBytes > $config->maxPayloadBytes) {
                DB::table('agent_run_export_queue')->where('id', $row->id)->delete();
                $discarded++;

                continue;
            }

            $result = $this->deliver($config, $payload);

            if ($result['delivered']) {
                DB::table('agent_run_export_queue')->where('id', $row->id)->delete();
                $delivered++;

                continue;
            }

            // Delivery failed (non-2xx, connection error, or timeout):
            // exponential-with-cap backoff (research.md §4), or discard once
            // the bound is reached (FR-019/SC-007).
            $attempts = (int) $row->attempts + 1;

            if ($attempts >= $config->maxAttempts) {
                // The row is being deleted -- nothing left to read last_error
                // off of, so there is no point writing it first.
                DB::table('agent_run_export_queue')->where('id', $row->id)->delete();
                $discarded++;

                continue;
            }

            $delaySeconds = min(
                $config->retryBaseSeconds * (2 ** ($attempts - 1)),
                $config->retryMaxSeconds,
            );

            DB::table('agent_run_export_queue')->where('id', $row->id)->update([
                'attempts' => $attempts,
                'next_attempt_at' => now()->addSeconds($delaySeconds)->format('Y-m-d H:i:s'),
                'last_error' => $result['error'],
            ]);
            $retried++;
        }

        $this->reportSummary(
            delivered: $delivered,
            retried: $retried,
            discarded: $discarded,
            bufferEvicted: BufferEvictionCounter::readAndReset(),
        );

        return self::SUCCESS;
    }

    /**
     * The column agent_run_export_queue.last_error is truncated to, per
     * data-model.md §2. A short fixed prefix ("HTTP 500: ") is included in
     * this budget rather than added on top of it, so the stored value never
     * exceeds the column regardless of how long the prefix or body get.
     */
    private const LAST_ERROR_MAX_LENGTH = 512;

    /**
     * Attempt delivery of one payload. Returns `['delivered' => bool, 'error' => ?string]`.
     *
     * `error` is built exclusively from the HTTP *response* (status code +
     * truncated body/reason phrase) on a non-2xx reply, or from a short
     * synthetic message on a connection failure/timeout where no response
     * body exists at all -- never from the outgoing request, the endpoint,
     * or the configured credential (contracts/config-reference.md's
     * secret-handling contract). `error` is null whenever delivery succeeds.
     *
     * @param array<string, mixed> $payload
     * @return array{delivered: bool, error: ?string}
     */
    private function deliver(TraceExportConfig $config, array $payload): array
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

            if ($response->successful()) {
                return ['delivered' => true, 'error' => null];
            }

            $error = 'HTTP ' . $response->status() . ': ' . $response->body();

            return ['delivered' => false, 'error' => $this->truncateError($error)];
        } catch (\Throwable $e) {
            // No HTTP response exists on a connection failure or timeout --
            // a synthetic message stands in, built only from the exception's
            // own message, never from anything sent in the request.
            $error = 'Connection error: ' . $e->getMessage();

            return ['delivered' => false, 'error' => $this->truncateError($error)];
        }
    }

    private function truncateError(string $error): string
    {
        return mb_substr($error, 0, self::LAST_ERROR_MAX_LENGTH);
    }

    private function reportSummary(int $delivered, int $retried, int $discarded, int $bufferEvicted): void
    {
        Log::info('Agent run traces forwarded', [
            'delivered' => $delivered,
            'retried' => $retried,
            'discarded' => $discarded,
            'buffer_evicted' => $bufferEvicted,
        ]);

        $this->info("Delivered: {$delivered}, Retried: {$retried}, Discarded: {$discarded}, Buffer evicted: {$bufferEvicted}");
    }
}
