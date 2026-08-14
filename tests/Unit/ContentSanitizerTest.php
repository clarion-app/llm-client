<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Services\ContentSanitizer;
use Tests\TestCase;

class ContentSanitizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('llm-client.run_trace.action_content_cap_bytes', 16384);
        $this->app['config']->set('llm-client.run_trace.redaction_patterns', [
            'headers' => ['authorization', 'x-api-key', 'proxy-authorization'],
            'json_fields' => ['password', 'secret', 'token', 'api_key', 'access_key', 'private_key'],
            'url_params' => ['access_token', 'api_key', 'password', 'secret'],
            'token_prefixes' => ['sk-', 'ghp_', 'gho_', 'ghu_', 'ghs_'],
        ]);
    }

    private function makeSanitizer(): ContentSanitizer
    {
        return new ContentSanitizer();
    }

    // ========== Bearer token redaction ==========

    /** @test */
    public function bearer_token_in_authorization_header_is_redacted(): void
    {
        $sanitizer = $this->makeSanitizer();
        $input = '{"authorization": "Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.abc123"}';
        $result = $sanitizer->sanitize($input);

        $this->assertStringContainsString('[REDACTED]', $result);
        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9', $result);
        $this->assertStringNotContainsString('abc123', $result);
    }

    /** @test */
    public function bearer_token_standalone_is_redacted(): void
    {
        $sanitizer = $this->makeSanitizer();
        $input = 'Sending request with Bearer super-secret-token-value-xyz here';
        $result = $sanitizer->sanitize($input);

        $this->assertStringContainsString('Bearer [REDACTED]', $result);
        $this->assertStringNotContainsString('super-secret-token-value-xyz', $result);
    }

    // ========== API key prefix redaction ==========

    /** @test */
    public function sk_token_prefix_is_redacted(): void
    {
        $sanitizer = $this->makeSanitizer();
        $input = 'api_key: sk-abcdefghijklmnopqrstuvwxyz1234567890';
        $result = $sanitizer->sanitize($input);

        $this->assertStringContainsString('sk-[REDACTED]', $result);
        $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz1234567890', $result);
    }

    /** @test */
    public function ghp_prefix_token_is_redacted(): void
    {
        $sanitizer = $this->makeSanitizer();
        $input = 'token: ghp_abcdefghijklmnopqrstuvwxyz1234567890';
        $result = $sanitizer->sanitize($input);

        $this->assertStringContainsString('ghp_[REDACTED]', $result);
        $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz1234567890', $result);
    }

    /** @test */
    public function short_sk_prefix_is_not_redacted(): void
    {
        $sanitizer = $this->makeSanitizer();
        $input = 'sk-short';
        $result = $sanitizer->sanitize($input);

        // Only 20+ chars after prefix should be redacted.
        $this->assertEquals('sk-short', $result);
    }

    // ========== JSON field redaction ==========

    /** @test */
    public function password_field_in_json_is_redacted(): void
    {
        $sanitizer = $this->makeSanitizer();
        $input = '{"username": "admin", "password": "hunter2"}';
        $result = $sanitizer->sanitize($input);

        $this->assertStringContainsString('"password": "[REDACTED]"', $result);
        $this->assertStringNotContainsString('hunter2', $result);
        $this->assertStringContainsString('"username": "admin"', $result);
    }

    /** @test */
    public function secret_field_in_json_is_redacted(): void
    {
        $sanitizer = $this->makeSanitizer();
        $input = '{"client_secret": "my-secret-value", "token": "tok_abc123"}';
        $result = $sanitizer->sanitize($input);

        $this->assertStringContainsString('"token": "[REDACTED]"', $result);
        $this->assertStringNotContainsString('tok_abc123', $result);
    }

    /** @test */
    public function api_key_field_in_json_is_redacted(): void
    {
        $sanitizer = $this->makeSanitizer();
        $input = '{"api_key": "key-12345", "other_field": "safe-value"}';
        $result = $sanitizer->sanitize($input);

        $this->assertStringContainsString('"api_key": "[REDACTED]"', $result);
        $this->assertStringNotContainsString('key-12345', $result);
        $this->assertStringContainsString('"other_field": "safe-value"', $result);
    }

    // ========== URL query param redaction ==========

    /** @test */
    public function access_token_in_url_query_is_redacted(): void
    {
        $sanitizer = $this->makeSanitizer();
        $input = 'https://api.example.com/callback?access_token=abc123&redirect=/home';
        $result = $sanitizer->sanitize($input);

        $this->assertStringContainsString('access_token=[REDACTED]', $result);
        $this->assertStringNotContainsString('abc123', $result);
        $this->assertStringContainsString('redirect=/home', $result);
    }

    /** @test */
    public function api_key_in_url_ampersand_is_redacted(): void
    {
        $sanitizer = $this->makeSanitizer();
        $input = 'https://api.example.com/data?format=json&api_key=secret-key-999';
        $result = $sanitizer->sanitize($input);

        $this->assertStringContainsString('api_key=[REDACTED]', $result);
        $this->assertStringNotContainsString('secret-key-999', $result);
    }

    // ========== Header redaction ==========

    /** @test */
    public function authorization_header_in_json_is_redacted(): void
    {
        $sanitizer = $this->makeSanitizer();
        $input = '{"headers": {"authorization": "Bearer my-token-here"}}';
        $result = $sanitizer->sanitize($input);

        // The header pattern matches "authorization": "value"
        $this->assertStringContainsString('"authorization": "[REDACTED]"', $result);
        $this->assertStringNotContainsString('my-token-here', $result);
    }

    /** @test */
    public function x_api_key_header_in_json_is_redacted(): void
    {
        $sanitizer = $this->makeSanitizer();
        $input = '{"x-api-key": "my-api-key-value"}';
        $result = $sanitizer->sanitize($input);

        $this->assertStringContainsString('"x-api-key": "[REDACTED]"', $result);
        $this->assertStringNotContainsString('my-api-key-value', $result);
    }

    // ========== Truncation ==========

    /** @test */
    public function truncation_at_16kb_cap_appends_marker(): void
    {
        $this->app['config']->set('llm-client.run_trace.action_content_cap_bytes', 16384);
        $sanitizer = $this->makeSanitizer();

        $content = str_repeat('x', 16385);
        $result = $sanitizer->truncate($content);

        $this->assertStringEndsWith("\n\n[TRUNCATED: original content exceeded cap]", $result);
        $this->assertLessThanOrEqual(16384, strlen($result));
    }

    /** @test */
    public function truncation_at_custom_cap(): void
    {
        $this->app['config']->set('llm-client.run_trace.action_content_cap_bytes', 100);
        $sanitizer = $this->makeSanitizer();

        $content = str_repeat('x', 200);
        $result = $sanitizer->truncate($content);

        $this->assertStringEndsWith("\n\n[TRUNCATED: original content exceeded cap]", $result);
        $this->assertLessThanOrEqual(100, strlen($result));
    }

    /** @test */
    public function no_truncation_on_short_content(): void
    {
        $sanitizer = $this->makeSanitizer();
        $content = 'short content';
        $result = $sanitizer->truncate($content);

        $this->assertEquals('short content', $result);
    }

    /** @test */
    public function truncation_at_exact_cap_boundary(): void
    {
        $this->app['config']->set('llm-client.run_trace.action_content_cap_bytes', 50);
        $sanitizer = $this->makeSanitizer();

        // Exactly at cap — no truncation needed.
        $content = str_repeat('x', 50);
        $result = $sanitizer->truncate($content);
        $this->assertEquals($content, $result);

        // One byte over — truncation kicks in.
        $content = str_repeat('x', 51);
        $result = $sanitizer->truncate($content);
        $this->assertStringEndsWith("\n\n[TRUNCATED: original content exceeded cap]", $result);
        $this->assertLessThanOrEqual(50, strlen($result));
    }

    // ========== truncate() with an optional per-call cap override (T009, 099-result-aggregation) ==========

    /** @test */
    public function cap_override_smaller_than_default_truncates_at_the_override(): void
    {
        $this->app['config']->set('llm-client.run_trace.action_content_cap_bytes', 16384);
        $sanitizer = $this->makeSanitizer();

        $content = str_repeat('x', 200);
        $result = $sanitizer->truncate($content, 50);

        $this->assertStringEndsWith("\n\n[TRUNCATED: original content exceeded cap]", $result);
        $this->assertLessThanOrEqual(50, strlen($result));
    }

    /** @test */
    public function cap_override_larger_than_content_length_leaves_it_untouched(): void
    {
        $this->app['config']->set('llm-client.run_trace.action_content_cap_bytes', 10);
        $sanitizer = $this->makeSanitizer();

        $content = str_repeat('x', 50);
        $result = $sanitizer->truncate($content, 1000);

        $this->assertEquals($content, $result);
    }

    /** @test */
    public function omitting_cap_override_is_byte_identical_to_default_behavior(): void
    {
        $this->app['config']->set('llm-client.run_trace.action_content_cap_bytes', 100);
        $sanitizer = $this->makeSanitizer();

        $content = str_repeat('x', 200);
        $withoutOverride = $sanitizer->truncate($content);
        $withNullOverride = $sanitizer->truncate($content, null);

        $this->assertSame($withoutOverride, $withNullOverride);
        $this->assertStringEndsWith("\n\n[TRUNCATED: original content exceeded cap]", $withoutOverride);
        $this->assertLessThanOrEqual(100, strlen($withoutOverride));
    }

    // ========== No-op on clean content ==========

    /** @test */
    public function sanitize_noop_on_content_without_secrets(): void
    {
        $sanitizer = $this->makeSanitizer();
        $input = '{"message": "hello world", "count": 42, "status": "ok"}';
        $result = $sanitizer->sanitize($input);

        $this->assertEquals($input, $result);
    }

    // ========== Regex error tolerance ==========

    /** @test */
    public function sanitize_returns_input_unchanged_on_regex_error(): void
    {
        // Set a broken regex pattern in config — use an invalid header name that
        // produces a bad regex, so ALL configured patterns are broken.
        $this->app['config']->set('llm-client.run_trace.redaction_patterns', [
            'headers' => ['[invalid'],  // Invalid regex character
            'json_fields' => ['[broken'],
            'url_params' => [],
            'token_prefixes' => ['['],  // Invalid regex character
        ]);

        $sanitizer = new ContentSanitizer();
        $input = 'Bearer my-token';
        $result = $sanitizer->sanitize($input);

        // Bearer pattern is always added, but with all other patterns broken,
        // the Bearer pattern itself is valid — so it will redact. The point is
        // that the broken patterns don't crash the whole sanitize().
        // Verify it doesn't throw and produces valid output.
        $this->assertIsString($result);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    /** @test */
    public function sanitize_skips_broken_pattern_and_continues(): void
    {
        // Mixed config: one broken token_prefix, plus valid json_fields.
        $this->app['config']->set('llm-client.run_trace.redaction_patterns', [
            'headers' => [],
            'json_fields' => ['password'],
            'url_params' => [],
            'token_prefixes' => ['['],  // Invalid regex character
        ]);

        $sanitizer = new ContentSanitizer();
        $input = '{"password": "hunter2"}';
        $result = $sanitizer->sanitize($input);

        // The valid json_fields pattern should still work despite broken token_prefixes.
        $this->assertStringContainsString('[REDACTED]', $result);
        $this->assertStringNotContainsString('hunter2', $result);
    }

    // ========== prepare() = truncate(sanitize(input)) ==========

    /** @test */
    public function prepare_applies_sanitize_then_truncate(): void
    {
        $this->app['config']->set('llm-client.run_trace.action_content_cap_bytes', 100);
        $sanitizer = $this->makeSanitizer();

        // Content has a secret at the start AND exceeds the cap.
        // Sanitize runs first (redacts), then truncate cuts to cap.
        $content = '{"password": "hunter2"}' . str_repeat('x', 90);
        $result = $sanitizer->prepare($content);

        // Secret should be redacted (sanitization applied before truncation).
        $this->assertStringNotContainsString('hunter2', $result);
        $this->assertStringContainsString('[REDACTED]', $result);

        // Content should be within cap (truncation applied).
        $this->assertLessThanOrEqual(100, strlen($result));
    }

    /** @test */
    public function prepare_on_short_clean_content_is_noop(): void
    {
        $sanitizer = $this->makeSanitizer();
        $input = 'just some normal text';
        $result = $sanitizer->prepare($input);

        $this->assertEquals($input, $result);
    }

    /** @test */
    public function prepare_redacts_before_truncating(): void
    {
        $this->app['config']->set('llm-client.run_trace.action_content_cap_bytes', 128);
        $sanitizer = $this->makeSanitizer();

        // Secret at the start — sanitize redacts it, then truncate cuts the tail.
        $content = '{"password": "super-secret-value-here"}' . str_repeat('x', 200);
        $result = $sanitizer->prepare($content);

        // Password value should not appear in the output.
        $this->assertStringNotContainsString('super-secret-value-here', $result);
        // Redaction marker should be present (sanitize ran before truncate).
        $this->assertStringContainsString('[REDACTED]', $result);
        $this->assertLessThanOrEqual(128, strlen($result));
    }

    // ========== isTruncated() (T033, US2 — research.md D4) ==========

    /** @test */
    public function is_truncated_true_for_content_ending_in_exact_marker(): void
    {
        $sanitizer = $this->makeSanitizer();
        $content = 'some captured content' . "\n\n[TRUNCATED: original content exceeded cap]";

        $this->assertTrue($sanitizer->isTruncated($content));
    }

    /** @test */
    public function is_truncated_false_for_untruncated_content(): void
    {
        $sanitizer = $this->makeSanitizer();

        $this->assertFalse($sanitizer->isTruncated('plain, untruncated content'));
        $this->assertFalse($sanitizer->isTruncated(''));
    }

    /** @test */
    public function is_truncated_false_when_marker_text_appears_mid_string_not_as_suffix(): void
    {
        $sanitizer = $this->makeSanitizer();

        // The exact marker text is present, but truncate() never appended it —
        // it merely occurs mid-string (e.g. the model itself emitted text that
        // happens to match). isTruncated() must key off suffix position, not
        // mere substring containment (research.md D4, quickstart.md mutation
        // row 4).
        $content = 'before ' . "\n\n[TRUNCATED: original content exceeded cap]" . ' after — not actually truncated';

        $this->assertFalse($sanitizer->isTruncated($content));
    }

    /** @test */
    public function is_truncated_matches_actual_truncate_output(): void
    {
        $this->app['config']->set('llm-client.run_trace.action_content_cap_bytes', 50);
        $sanitizer = $this->makeSanitizer();

        $truncated = $sanitizer->truncate(str_repeat('x', 200));
        $this->assertTrue($sanitizer->isTruncated($truncated));

        $unaffected = $sanitizer->truncate('short content');
        $this->assertFalse($sanitizer->isTruncated($unaffected));
    }
}
