<?php

namespace ClarionApp\LlmClient\ValueObjects;

use Illuminate\Support\Facades\Log;

/**
 * Validated, memoized view of config('llm-client.run_trace.export.*').
 *
 * Resolves the raw config once per process and falls back to documented
 * defaults for anything invalid, logging each invalid field at most once
 * (no repeated per-record error noise). Callers should always go through
 * resolve() rather than reading config() directly, so every call site
 * agrees on what "the destinations" currently means.
 */
final class TraceExportConfig
{
    private const VALID_DESTINATIONS = ['internal', 'external'];

    private const DEFAULT_BUFFER_MAX_RECORDS = 10000;
    private const DEFAULT_MAX_ATTEMPTS = 3;
    private const DEFAULT_RETRY_BASE_SECONDS = 30;
    private const DEFAULT_RETRY_MAX_SECONDS = 900;
    private const DEFAULT_HTTP_TIMEOUT_SECONDS = 10;
    private const DEFAULT_MAX_RECORDS_PER_RUN = 100;
    private const DEFAULT_MAX_PAYLOAD_BYTES = 65536;

    /**
     * agent_run_export_queue.attempts is an unsigned tinyint; a configured
     * max_attempts above this would never be reachable once the column
     * saturates, silently defeating the "bounded number of attempts"
     * guarantee instead of enforcing it.
     */
    private const MAX_ATTEMPTS_CEILING = 255;

    private static ?self $instance = null;

    /** @var array<string, true> */
    private static array $loggedFields = [];

    /**
     * @param list<string> $destinations
     */
    public function __construct(
        public readonly array $destinations,
        public readonly ?string $otlpEndpoint,
        public readonly string $otlpAuthHeader,
        public readonly ?string $otlpAuthValue,
        public readonly int $bufferMaxRecords,
        public readonly int $maxAttempts,
        public readonly int $retryBaseSeconds,
        public readonly int $retryMaxSeconds,
        public readonly int $httpTimeoutSeconds,
        public readonly int $maxRecordsPerRun,
        public readonly int $maxPayloadBytes,
    ) {}

    public static function resolve(): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $raw = (array) config('llm-client.run_trace.export', []);

        $destinations = self::resolveDestinations($raw);

        $otlpEndpoint = self::normalizeString($raw['otlp_endpoint'] ?? null);
        if (in_array('external', $destinations, true) && !self::isValidHttpUrl($otlpEndpoint)) {
            $destinations = array_values(array_diff($destinations, ['external']));

            self::warnOnce(
                'otlp_endpoint',
                'TraceExportConfig: invalid otlp_endpoint, external destination disabled',
                ['value' => $otlpEndpoint],
            );
        }

        $otlpAuthHeader = self::normalizeString($raw['otlp_auth_header'] ?? null) ?? 'Authorization';

        // otlp_auth_value is intentionally never validated/logged here: a
        // missing credential is a legitimate "anonymous ingest" choice, not
        // a misconfiguration (config-reference.md's Validation contract).
        $otlpAuthValue = self::normalizeString($raw['otlp_auth_value'] ?? null);

        $bufferMaxRecords = self::resolveNumeric($raw, 'buffer_max_records', self::DEFAULT_BUFFER_MAX_RECORDS);

        $maxAttempts = self::resolveNumeric($raw, 'max_attempts', self::DEFAULT_MAX_ATTEMPTS);
        if ($maxAttempts > self::MAX_ATTEMPTS_CEILING) {
            self::warnOnce(
                'max_attempts_ceiling',
                'TraceExportConfig: max_attempts exceeds the agent_run_export_queue.attempts column ceiling, clamping',
                ['value' => $maxAttempts, 'ceiling' => self::MAX_ATTEMPTS_CEILING],
            );

            $maxAttempts = self::MAX_ATTEMPTS_CEILING;
        }

        $retryBaseSeconds = self::resolveNumeric($raw, 'retry_base_seconds', self::DEFAULT_RETRY_BASE_SECONDS);
        $retryMaxSeconds = self::resolveNumeric($raw, 'retry_max_seconds', self::DEFAULT_RETRY_MAX_SECONDS);
        $httpTimeoutSeconds = self::resolveNumeric($raw, 'http_timeout_seconds', self::DEFAULT_HTTP_TIMEOUT_SECONDS);
        $maxRecordsPerRun = self::resolveNumeric($raw, 'max_records_per_run', self::DEFAULT_MAX_RECORDS_PER_RUN);
        $maxPayloadBytes = self::resolveNumeric($raw, 'max_payload_bytes', self::DEFAULT_MAX_PAYLOAD_BYTES);

        return self::$instance = new self(
            destinations: $destinations,
            otlpEndpoint: $otlpEndpoint,
            otlpAuthHeader: $otlpAuthHeader,
            otlpAuthValue: $otlpAuthValue,
            bufferMaxRecords: $bufferMaxRecords,
            maxAttempts: $maxAttempts,
            retryBaseSeconds: $retryBaseSeconds,
            retryMaxSeconds: $retryMaxSeconds,
            httpTimeoutSeconds: $httpTimeoutSeconds,
            maxRecordsPerRun: $maxRecordsPerRun,
            maxPayloadBytes: $maxPayloadBytes,
        );
    }

    /**
     * Test isolation only — never called by production code. Clears the
     * memoized instance and per-field logging state so the next resolve()
     * re-validates from scratch, as if a new process had started.
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$loggedFields = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'destinations' => $this->destinations,
            'otlpEndpoint' => $this->otlpEndpoint,
            'otlpAuthHeader' => $this->otlpAuthHeader,
            'otlpAuthValue' => '[REDACTED]',
            'bufferMaxRecords' => $this->bufferMaxRecords,
            'maxAttempts' => $this->maxAttempts,
            'retryBaseSeconds' => $this->retryBaseSeconds,
            'retryMaxSeconds' => $this->retryMaxSeconds,
            'httpTimeoutSeconds' => $this->httpTimeoutSeconds,
            'maxRecordsPerRun' => $this->maxRecordsPerRun,
            'maxPayloadBytes' => $this->maxPayloadBytes,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @return list<string>
     */
    private static function resolveDestinations(array $raw): array
    {
        $rawValue = $raw['destinations'] ?? [];
        if (!is_array($rawValue)) {
            $rawValue = [$rawValue];
        }

        $candidates = array_values(array_filter(
            array_map(static fn ($v) => is_string($v) ? trim($v) : $v, $rawValue),
            static fn ($v) => $v !== null && $v !== '',
        ));

        $isValid = !empty($candidates) && empty(array_diff($candidates, self::VALID_DESTINATIONS));

        if (!$isValid) {
            self::warnOnce(
                'destinations',
                'TraceExportConfig: invalid destinations, using default',
                ['value' => $raw['destinations'] ?? null],
            );

            return ['internal'];
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function resolveNumeric(array $raw, string $key, int $default): int
    {
        $value = $raw[$key] ?? null;

        if (!is_numeric($value) || (int) $value <= 0) {
            self::warnOnce(
                $key,
                "TraceExportConfig: invalid {$key}, using default",
                ['value' => $value, 'default' => $default],
            );

            return $default;
        }

        return (int) $value;
    }

    private static function isValidHttpUrl(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
    }

    private static function normalizeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function warnOnce(string $field, string $message, array $context = []): void
    {
        if (isset(self::$loggedFields[$field])) {
            return;
        }

        self::$loggedFields[$field] = true;

        Log::warning($message, $context);
    }
}
