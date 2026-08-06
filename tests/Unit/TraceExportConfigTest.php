<?php

namespace Tests\Unit;

use ClarionApp\LlmClient\ValueObjects\TraceExportConfig;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Unit tests for TraceExportConfig — the validated, memoized view of
 * config('llm-client.run_trace.export.*').
 */
class TraceExportConfigTest extends TestCase
{
    /** @var array<int, object{level:string,message:string,context:array}> */
    private array $warnings = [];

    protected function setUp(): void
    {
        parent::setUp();

        TraceExportConfig::reset();

        $this->warnings = [];
        Log::listen(function ($entry) {
            if ($entry->level === 'warning') {
                $this->warnings[] = $entry;
            }
        });
    }

    protected function tearDown(): void
    {
        TraceExportConfig::reset();

        parent::tearDown();
    }

    private function setExport(array $overrides): void
    {
        foreach ($overrides as $key => $value) {
            $this->app['config']->set("llm-client.run_trace.export.{$key}", $value);
        }
    }

    // ========== valid config ==========

    /** @test */
    public function valid_config_resolves_every_field_as_is(): void
    {
        $this->setExport([
            'destinations' => ['internal', 'external'],
            'otlp_endpoint' => 'https://tempo.example.com:4318/v1/traces',
            'otlp_auth_header' => 'X-Custom-Auth',
            'otlp_auth_value' => 'Bearer secret-token',
            'buffer_max_records' => 5000,
            'max_attempts' => 5,
            'retry_base_seconds' => 15,
            'retry_max_seconds' => 600,
            'http_timeout_seconds' => 20,
            'max_records_per_run' => 50,
            'max_payload_bytes' => 32768,
        ]);

        $config = TraceExportConfig::resolve();

        $this->assertSame(['internal', 'external'], $config->destinations);
        $this->assertSame('https://tempo.example.com:4318/v1/traces', $config->otlpEndpoint);
        $this->assertSame('X-Custom-Auth', $config->otlpAuthHeader);
        $this->assertSame('Bearer secret-token', $config->otlpAuthValue);
        $this->assertSame(5000, $config->bufferMaxRecords);
        $this->assertSame(5, $config->maxAttempts);
        $this->assertSame(15, $config->retryBaseSeconds);
        $this->assertSame(600, $config->retryMaxSeconds);
        $this->assertSame(20, $config->httpTimeoutSeconds);
        $this->assertSame(50, $config->maxRecordsPerRun);
        $this->assertSame(32768, $config->maxPayloadBytes);
        $this->assertCount(0, $this->warnings);
    }

    // ========== destinations ==========

    /** @test */
    public function empty_destinations_falls_back_to_internal_and_logs_once(): void
    {
        $this->setExport(['destinations' => []]);

        $config = TraceExportConfig::resolve();

        $this->assertSame(['internal'], $config->destinations);
        $this->assertCount(1, $this->warnings);
    }

    /** @test */
    public function invalid_destination_value_falls_back_to_internal_and_logs_once(): void
    {
        $this->setExport(['destinations' => ['internal', 'bogus']]);

        $config = TraceExportConfig::resolve();

        $this->assertSame(['internal'], $config->destinations);
        $this->assertCount(1, $this->warnings);
    }

    // ========== otlp_endpoint ==========

    /** @test */
    public function external_with_missing_endpoint_drops_external_and_logs_once(): void
    {
        $this->setExport([
            'destinations' => ['internal', 'external'],
            'otlp_endpoint' => null,
        ]);

        $config = TraceExportConfig::resolve();

        $this->assertSame(['internal'], $config->destinations);
        $this->assertCount(1, $this->warnings);
    }

    /** @test */
    public function external_with_empty_endpoint_drops_external_and_logs_once(): void
    {
        $this->setExport([
            'destinations' => ['internal', 'external'],
            'otlp_endpoint' => '',
        ]);

        $config = TraceExportConfig::resolve();

        $this->assertSame(['internal'], $config->destinations);
        $this->assertCount(1, $this->warnings);
    }

    /** @test */
    public function external_with_non_http_endpoint_drops_external_and_logs_once(): void
    {
        $this->setExport([
            'destinations' => ['internal', 'external'],
            'otlp_endpoint' => 'ftp://tempo.example.com/traces',
        ]);

        $config = TraceExportConfig::resolve();

        $this->assertSame(['internal'], $config->destinations);
        $this->assertCount(1, $this->warnings);
    }

    /** @test */
    public function external_with_valid_endpoint_keeps_external_and_does_not_log(): void
    {
        $this->setExport([
            'destinations' => ['internal', 'external'],
            'otlp_endpoint' => 'https://tempo.example.com:4318/v1/traces',
        ]);

        $config = TraceExportConfig::resolve();

        $this->assertSame(['internal', 'external'], $config->destinations);
        $this->assertCount(0, $this->warnings);
    }

    // ========== otlp_auth_value ==========

    /** @test */
    public function missing_auth_value_with_external_selected_does_not_log(): void
    {
        $this->setExport([
            'destinations' => ['internal', 'external'],
            'otlp_endpoint' => 'https://tempo.example.com:4318/v1/traces',
            'otlp_auth_value' => null,
        ]);

        $config = TraceExportConfig::resolve();

        $this->assertSame(['internal', 'external'], $config->destinations);
        $this->assertNull($config->otlpAuthValue);
        $this->assertCount(0, $this->warnings);
    }

    // ========== numeric fields ==========

    public static function numericFieldProvider(): array
    {
        return [
            'buffer_max_records non-numeric' => ['buffer_max_records', 'abc', 10000],
            'buffer_max_records zero' => ['buffer_max_records', 0, 10000],
            'buffer_max_records negative' => ['buffer_max_records', -5, 10000],
            'max_attempts non-numeric' => ['max_attempts', 'abc', 3],
            'max_attempts zero' => ['max_attempts', 0, 3],
            'max_attempts negative' => ['max_attempts', -5, 3],
            'retry_base_seconds non-numeric' => ['retry_base_seconds', 'abc', 30],
            'retry_base_seconds zero' => ['retry_base_seconds', 0, 30],
            'retry_base_seconds negative' => ['retry_base_seconds', -5, 30],
            'retry_max_seconds non-numeric' => ['retry_max_seconds', 'abc', 900],
            'retry_max_seconds zero' => ['retry_max_seconds', 0, 900],
            'retry_max_seconds negative' => ['retry_max_seconds', -5, 900],
            'http_timeout_seconds non-numeric' => ['http_timeout_seconds', 'abc', 10],
            'http_timeout_seconds zero' => ['http_timeout_seconds', 0, 10],
            'http_timeout_seconds negative' => ['http_timeout_seconds', -5, 10],
            'max_records_per_run non-numeric' => ['max_records_per_run', 'abc', 100],
            'max_records_per_run zero' => ['max_records_per_run', 0, 100],
            'max_records_per_run negative' => ['max_records_per_run', -5, 100],
            'max_payload_bytes non-numeric' => ['max_payload_bytes', 'abc', 65536],
            'max_payload_bytes zero' => ['max_payload_bytes', 0, 65536],
            'max_payload_bytes negative' => ['max_payload_bytes', -5, 65536],
        ];
    }

    /** @test */
    public function numeric_fields_fall_back_to_default_and_log_once(): void
    {
        $propertyMap = [
            'buffer_max_records' => 'bufferMaxRecords',
            'max_attempts' => 'maxAttempts',
            'retry_base_seconds' => 'retryBaseSeconds',
            'retry_max_seconds' => 'retryMaxSeconds',
            'http_timeout_seconds' => 'httpTimeoutSeconds',
            'max_records_per_run' => 'maxRecordsPerRun',
            'max_payload_bytes' => 'maxPayloadBytes',
        ];

        foreach (self::numericFieldProvider() as [$key, $invalidValue, $default]) {
            TraceExportConfig::reset();
            $this->warnings = [];

            // Reset every numeric field to a known-good value first — config
            // overrides accumulate across loop iterations via config->set(),
            // so a prior iteration's invalid value would otherwise still be
            // in effect and produce an extra, unrelated warning here.
            $this->setExport([
                'buffer_max_records' => 10000,
                'max_attempts' => 3,
                'retry_base_seconds' => 30,
                'retry_max_seconds' => 900,
                'http_timeout_seconds' => 10,
                'max_records_per_run' => 100,
                'max_payload_bytes' => 65536,
            ]);
            $this->setExport([$key => $invalidValue]);

            $config = TraceExportConfig::resolve();
            $property = $propertyMap[$key];

            $this->assertSame($default, $config->$property, "field {$key} with value " . var_export($invalidValue, true));
            $this->assertCount(1, $this->warnings, "expected exactly one warning for {$key} with value " . var_export($invalidValue, true));
        }
    }

    // ========== max_attempts ceiling ==========

    /** @test */
    public function max_attempts_above_255_is_clamped_and_logs_once(): void
    {
        $this->setExport(['max_attempts' => 1000]);

        $config = TraceExportConfig::resolve();

        $this->assertSame(255, $config->maxAttempts);
        $this->assertCount(1, $this->warnings);
    }

    /** @test */
    public function max_attempts_at_255_is_not_clamped_and_does_not_log(): void
    {
        $this->setExport(['max_attempts' => 255]);

        $config = TraceExportConfig::resolve();

        $this->assertSame(255, $config->maxAttempts);
        $this->assertCount(0, $this->warnings);
    }

    // ========== memoization ==========

    /** @test */
    public function repeated_resolve_calls_log_each_invalid_field_only_once(): void
    {
        $this->setExport(['destinations' => []]);

        TraceExportConfig::resolve();
        TraceExportConfig::resolve();
        TraceExportConfig::resolve();

        $this->assertCount(1, $this->warnings);
    }

    /** @test */
    public function repeated_resolve_calls_return_the_same_memoized_instance(): void
    {
        $first = TraceExportConfig::resolve();
        $second = TraceExportConfig::resolve();

        $this->assertSame($first, $second);
    }

    // ========== __debugInfo redaction ==========

    /** @test */
    public function debug_info_redacts_auth_value(): void
    {
        $this->setExport([
            'destinations' => ['internal', 'external'],
            'otlp_endpoint' => 'https://tempo.example.com:4318/v1/traces',
            'otlp_auth_value' => 'Bearer super-secret-token',
        ]);

        $config = TraceExportConfig::resolve();

        $debug = $config->__debugInfo();

        $this->assertSame('[REDACTED]', $debug['otlpAuthValue']);
        $this->assertStringNotContainsString('super-secret-token', print_r($debug, true));
    }
}
