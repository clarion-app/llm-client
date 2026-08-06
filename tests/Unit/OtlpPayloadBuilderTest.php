<?php

namespace Tests\Unit;

use ClarionApp\LlmClient\Services\OtlpPayloadBuilder;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for OtlpPayloadBuilder — maps a closed AgentRun (+ its steps and
 * actions) into an OTLP/HTTP JSON ExportTraceServiceRequest, per
 * contracts/otlp-export-payload.md.
 *
 * OtlpPayloadBuilder does not exist yet (T017) — every test in this file is
 * expected to fail/error until it lands.
 */
class OtlpPayloadBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.run_trace.enabled', true);
        // Explicit, independent of whatever config/llm-client.php currently
        // ships as its default — mirrors ContentSanitizerTest's setUp so this
        // file's redaction assertions don't silently depend on ordering
        // against other tests that also mutate this config key.
        $this->app['config']->set('llm-client.run_trace.redaction_patterns', [
            'headers' => ['authorization', 'x-api-key', 'proxy-authorization'],
            'json_fields' => ['password', 'secret', 'token', 'api_key', 'access_key', 'private_key'],
            'url_params' => ['access_token', 'api_key', 'password', 'secret'],
            'token_prefixes' => ['sk-', 'ghp_', 'gho_', 'ghu_', 'ghs_'],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['agent_run_actions', 'agent_run_steps', 'agent_run_messages', 'agent_runs'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    // ========== fixture helpers ==========

    private function insertRun(
        string $id,
        string $userId,
        ?string $conversationId,
        RunEndState $endState,
        ?string $endReason,
        CarbonImmutable $startedAt,
        CarbonImmutable $endedAt,
        string $kind = 'interactive',
        ?string $source = 'conversation',
    ): void {
        DB::table('agent_runs')->insert([
            'id' => $id,
            'kind' => $kind,
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'source' => $source,
            'end_state' => $endState->value,
            'end_reason' => $endReason,
            'started_at' => $startedAt->format('Y-m-d H:i:s.u'),
            'ended_at' => $endedAt->format('Y-m-d H:i:s.u'),
            'duration_ms' => $startedAt->diffInMilliseconds($endedAt),
            'step_count' => 1,
            'created_at' => $startedAt->format('Y-m-d H:i:s.u'),
        ]);
    }

    private function insertStep(
        string $id,
        string $runId,
        int $position,
        RunEndState $endState,
        ?string $endReason,
        CarbonImmutable $startedAt,
        CarbonImmutable $endedAt,
        int $attemptCount = 1,
    ): void {
        DB::table('agent_run_steps')->insert([
            'id' => $id,
            'run_id' => $runId,
            'position' => $position,
            'attempt_group_id' => null,
            'end_state' => $endState->value,
            'end_reason' => $endReason,
            'started_at' => $startedAt->format('Y-m-d H:i:s.u'),
            'ended_at' => $endedAt->format('Y-m-d H:i:s.u'),
            'duration_ms' => $startedAt->diffInMilliseconds($endedAt),
            'wait_ms' => null,
            'attempt_count' => $attemptCount,
        ]);
    }

    private function insertAction(
        string $id,
        string $stepId,
        string $runId,
        ActionType $actionType,
        ?string $target,
        ?string $parentActionId,
        ActionOutcome $outcome,
        ?string $failureReason,
        ?string $content,
        CarbonImmutable $startedAt,
        CarbonImmutable $endedAt,
    ): void {
        DB::table('agent_run_actions')->insert([
            'id' => $id,
            'run_id' => $runId,
            'step_id' => $stepId,
            'action_type' => $actionType->value,
            'target' => $target,
            'attempt_group_id' => null,
            'parent_action_id' => $parentActionId,
            'outcome' => $outcome->value,
            'failure_reason' => $failureReason,
            'paused_at' => null,
            'started_at' => $startedAt->format('Y-m-d H:i:s.u'),
            'ended_at' => $endedAt->format('Y-m-d H:i:s.u'),
            'duration_ms' => $startedAt->diffInMilliseconds($endedAt),
            'content' => $content,
            'created_at' => $startedAt->format('Y-m-d H:i:s.u'),
        ]);
    }

    // ========== payload navigation helpers ==========

    /** @return array<int, array<string, mixed>> */
    private function spansOf(array $payload): array
    {
        return $payload['resourceSpans'][0]['scopeSpans'][0]['spans'];
    }

    private function findSpanBySpanId(array $spans, string $spanId): ?array
    {
        foreach ($spans as $span) {
            if (($span['spanId'] ?? null) === $spanId) {
                return $span;
            }
        }

        return null;
    }

    private function attr(array $span, string $key): mixed
    {
        foreach ($span['attributes'] ?? [] as $entry) {
            if ($entry['key'] === $key) {
                $value = $entry['value'];
                return $value['stringValue'] ?? $value['intValue'] ?? $value['boolValue'] ?? null;
            }
        }

        return null;
    }

    private function eventContent(array $span): ?string
    {
        foreach ($span['events'] ?? [] as $event) {
            if (($event['name'] ?? null) !== 'content') {
                continue;
            }
            foreach ($event['attributes'] ?? [] as $entry) {
                if ($entry['key'] === 'content') {
                    return $entry['value']['stringValue'] ?? null;
                }
            }
        }

        return null;
    }

    private function traceIdOf(string $uuid): string
    {
        return str_replace('-', '', $uuid);
    }

    private function spanIdOf(string $uuid): string
    {
        return substr(str_replace('-', '', $uuid), 0, 16);
    }

    private function toNanos(CarbonImmutable $time): string
    {
        $micro = (int) $time->format('u');
        $seconds = $time->getTimestamp();

        return (string) (($seconds * 1_000_000 + $micro) * 1000);
    }

    // ========== field mapping / identifier derivation ==========

    #[Test]
    public function field_mapping_for_run_with_step_and_nested_actions_matches_otlp_shape(): void
    {
        $runId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $conversationId = (string) Str::uuid();
        $stepId = (string) Str::uuid();
        $actionId1 = (string) Str::uuid();
        $actionId2 = (string) Str::uuid();

        $t0 = CarbonImmutable::now()->subMinutes(5);
        $tMid = $t0->addSeconds(10);
        $t1 = $t0->addSeconds(30);

        $this->insertRun($runId, $userId, $conversationId, RunEndState::Completed, null, $t0, $t1);
        $this->insertStep($stepId, $runId, 1, RunEndState::Completed, null, $t0, $t1, 2);
        $this->insertAction(
            $actionId1,
            $stepId,
            $runId,
            ActionType::ToolInvocation,
            'contacts.store',
            null,
            ActionOutcome::Success,
            null,
            '{"body":{"name":"Alice"}}',
            $t0,
            $tMid,
        );
        $this->insertAction(
            $actionId2,
            $stepId,
            $runId,
            ActionType::LlmRequest,
            null,
            $actionId1,
            ActionOutcome::Success,
            null,
            'nested action content',
            $tMid,
            $t1,
        );

        $builder = new OtlpPayloadBuilder();
        $payload = $builder->build($runId);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('resourceSpans', $payload);
        $this->assertCount(1, $payload['resourceSpans']);

        $resource = $payload['resourceSpans'][0]['resource'];
        $serviceName = null;
        foreach ($resource['attributes'] as $entry) {
            if ($entry['key'] === 'service.name') {
                $serviceName = $entry['value']['stringValue'] ?? null;
            }
        }
        $this->assertSame('clarion-app.llm-client', $serviceName);

        $scopeSpans = $payload['resourceSpans'][0]['scopeSpans'];
        $this->assertSame('clarion-app/llm-client', $scopeSpans[0]['scope']['name']);

        $spans = $scopeSpans[0]['spans'];
        $this->assertCount(4, $spans, 'expected exactly run + step + 2 actions');

        $traceId = $this->traceIdOf($runId);
        $runSpanId = $this->spanIdOf($runId);
        $stepSpanId = $this->spanIdOf($stepId);
        $action1SpanId = $this->spanIdOf($actionId1);
        $action2SpanId = $this->spanIdOf($actionId2);

        $runSpan = $this->findSpanBySpanId($spans, $runSpanId);
        $stepSpan = $this->findSpanBySpanId($spans, $stepSpanId);
        $action1Span = $this->findSpanBySpanId($spans, $action1SpanId);
        $action2Span = $this->findSpanBySpanId($spans, $action2SpanId);

        $this->assertNotNull($runSpan, 'run span not found by derived span id');
        $this->assertNotNull($stepSpan, 'step span not found by derived span id');
        $this->assertNotNull($action1Span, 'parent action span not found by derived span id');
        $this->assertNotNull($action2Span, 'nested action span not found by derived span id');

        foreach ([$runSpan, $stepSpan, $action1Span, $action2Span] as $span) {
            $this->assertSame($traceId, $span['traceId']);
            $this->assertSame(1, $span['kind']);
        }

        // Parent chaining: run (root, no parentSpanId) -> step -> action1 -> action2 (nested).
        $this->assertArrayNotHasKey('parentSpanId', $runSpan);
        $this->assertSame($runSpanId, $stepSpan['parentSpanId']);
        $this->assertSame($stepSpanId, $action1Span['parentSpanId']);
        $this->assertSame($action1SpanId, $action2Span['parentSpanId']);

        // Span naming.
        $this->assertSame('run:interactive', $runSpan['name']);
        $this->assertSame('step:1', $stepSpan['name']);
        $this->assertSame('action:tool_invocation', $action1Span['name']);
        $this->assertSame('action:llm_request', $action2Span['name']);

        // Status: everything here is Completed/Success -> OK (1).
        foreach ([$runSpan, $stepSpan, $action1Span, $action2Span] as $span) {
            $this->assertSame(1, $span['status']['code']);
        }

        // Run-level correlation attributes (FR-026 — opaque UUIDs only).
        $this->assertSame($runId, $this->attr($runSpan, 'clarion.run_id'));
        $this->assertSame($userId, $this->attr($runSpan, 'clarion.user_id'));
        $this->assertSame($conversationId, $this->attr($runSpan, 'clarion.conversation_id'));
        $this->assertSame('interactive', $this->attr($runSpan, 'clarion.run_kind'));
        $this->assertSame('conversation', $this->attr($runSpan, 'clarion.source'));

        // Step attribute.
        $this->assertSame('2', (string) $this->attr($stepSpan, 'clarion.attempt_count'));

        // Action target attribute.
        $this->assertSame('contacts.store', $this->attr($action1Span, 'clarion.target'));

        // Action content carried verbatim as a span event.
        $this->assertSame('{"body":{"name":"Alice"}}', $this->eventContent($action1Span));
        $this->assertSame('nested action content', $this->eventContent($action2Span));

        // Timestamps: nanosecond precision derived from the stored start/end.
        $this->assertSame($this->toNanos($t0), $runSpan['startTimeUnixNano']);
        $this->assertSame($this->toNanos($t1), $runSpan['endTimeUnixNano']);
        $this->assertSame($this->toNanos($t0), $action1Span['startTimeUnixNano']);
        $this->assertSame($this->toNanos($tMid), $action1Span['endTimeUnixNano']);
    }

    #[Test]
    public function build_returns_null_for_a_run_that_no_longer_resolves(): void
    {
        $builder = new OtlpPayloadBuilder();

        $payload = $builder->build((string) Str::uuid());

        $this->assertNull($payload);
    }

    // ========== status-code mapping: run end state ==========

    public static function runEndStateStatusProvider(): array
    {
        return [
            'Completed maps to OK' => [RunEndState::Completed, null, 1, false],
            'Failed maps to ERROR with message' => [RunEndState::Failed, 'boom', 2, true],
            'StoppedEarly maps to ERROR with message' => [RunEndState::StoppedEarly, 'stopped early', 2, true],
            'Abandoned maps to ERROR with message' => [RunEndState::Abandoned, 'abandoned by sweep', 2, true],
        ];
    }

    #[Test]
    #[DataProvider('runEndStateStatusProvider')]
    public function run_end_state_maps_to_the_documented_otlp_status_code(
        RunEndState $endState,
        ?string $endReason,
        int $expectedCode,
        bool $expectMessage,
    ): void {
        $runId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $stepId = (string) Str::uuid();
        $t0 = CarbonImmutable::now()->subMinutes(2);
        $t1 = $t0->addSeconds(5);

        $this->insertRun($runId, $userId, null, $endState, $endReason, $t0, $t1);
        $this->insertStep($stepId, $runId, 1, RunEndState::Completed, null, $t0, $t1);

        $builder = new OtlpPayloadBuilder();
        $payload = $builder->build($runId);

        $spans = $this->spansOf($payload);
        $runSpan = $this->findSpanBySpanId($spans, $this->spanIdOf($runId));

        $this->assertNotNull($runSpan);
        $this->assertSame($expectedCode, $runSpan['status']['code']);

        if ($expectMessage) {
            $this->assertSame($endReason, $runSpan['status']['message'] ?? null);
        }
    }

    // ========== status-code mapping: action outcome ==========

    public static function actionOutcomeStatusProvider(): array
    {
        return [
            'Success maps to OK' => [ActionOutcome::Success, null, 1, false],
            'Failure maps to ERROR with message' => [ActionOutcome::Failure, 'tool call failed', 2, true],
            'Unfinished maps to ERROR with message' => [ActionOutcome::Unfinished, 'action exceeded 5-minute timeout', 2, true],
        ];
    }

    #[Test]
    #[DataProvider('actionOutcomeStatusProvider')]
    public function action_outcome_maps_to_the_documented_otlp_status_code(
        ActionOutcome $outcome,
        ?string $failureReason,
        int $expectedCode,
        bool $expectMessage,
    ): void {
        $runId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $stepId = (string) Str::uuid();
        $actionId = (string) Str::uuid();
        $t0 = CarbonImmutable::now()->subMinutes(2);
        $t1 = $t0->addSeconds(5);

        $this->insertRun($runId, $userId, null, RunEndState::Completed, null, $t0, $t1);
        $this->insertStep($stepId, $runId, 1, RunEndState::Completed, null, $t0, $t1);
        $this->insertAction(
            $actionId,
            $stepId,
            $runId,
            ActionType::ToolInvocation,
            'some.tool',
            null,
            $outcome,
            $failureReason,
            null,
            $t0,
            $t1,
        );

        $builder = new OtlpPayloadBuilder();
        $payload = $builder->build($runId);

        $spans = $this->spansOf($payload);
        $actionSpan = $this->findSpanBySpanId($spans, $this->spanIdOf($actionId));

        $this->assertNotNull($actionSpan);
        $this->assertSame($expectedCode, $actionSpan['status']['code']);

        if ($expectMessage) {
            $this->assertSame($failureReason, $actionSpan['status']['message'] ?? null);
        }
    }

    // ========== secret redaction (FR-024) ==========

    #[Test]
    public function target_with_an_embedded_query_string_secret_is_redacted_in_the_built_payload(): void
    {
        $runId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $stepId = (string) Str::uuid();
        $actionId = (string) Str::uuid();
        $t0 = CarbonImmutable::now()->subMinutes(2);
        $t1 = $t0->addSeconds(5);

        $secretValue = 'SUPERSECRETVALUE1234567890';
        $target = "https://api.example.com/oauth/callback?access_token={$secretValue}&foo=bar";

        $this->insertRun($runId, $userId, null, RunEndState::Completed, null, $t0, $t1);
        $this->insertStep($stepId, $runId, 1, RunEndState::Completed, null, $t0, $t1);
        $this->insertAction(
            $actionId,
            $stepId,
            $runId,
            ActionType::ToolInvocation,
            $target,
            null,
            ActionOutcome::Success,
            null,
            null,
            $t0,
            $t1,
        );

        $builder = new OtlpPayloadBuilder();
        $payload = $builder->build($runId);

        $spans = $this->spansOf($payload);
        $actionSpan = $this->findSpanBySpanId($spans, $this->spanIdOf($actionId));

        $this->assertNotNull($actionSpan);
        $redactedTarget = $this->attr($actionSpan, 'clarion.target');

        $this->assertIsString($redactedTarget);
        $this->assertStringNotContainsString($secretValue, $redactedTarget);
        $this->assertStringContainsString('[REDACTED]', $redactedTarget);
        // The non-secret portion of the query string is untouched.
        $this->assertStringContainsString('foo=bar', $redactedTarget);

        // Also verify the raw payload, JSON-encoded, never contains the secret
        // value anywhere (belt-and-braces against a redaction that only
        // touched a different copy of the string).
        $this->assertStringNotContainsString($secretValue, json_encode($payload));
    }

    #[Test]
    public function failure_reason_with_an_embedded_secret_is_redacted_in_the_built_payload(): void
    {
        $runId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $stepId = (string) Str::uuid();
        $actionId = (string) Str::uuid();
        $t0 = CarbonImmutable::now()->subMinutes(2);
        $t1 = $t0->addSeconds(5);

        $secretValue = 'ANOTHERSECRETVALUE0987654321';
        // The Bearer-token pattern is unconditional in ContentSanitizer (always
        // added regardless of the redaction_patterns config array), so this
        // assertion does not depend on url_params/json_fields configuration.
        $failureReason = "request failed: Bearer {$secretValue} was rejected";

        $this->insertRun($runId, $userId, null, RunEndState::Completed, null, $t0, $t1);
        $this->insertStep($stepId, $runId, 1, RunEndState::Completed, null, $t0, $t1);
        $this->insertAction(
            $actionId,
            $stepId,
            $runId,
            ActionType::ToolInvocation,
            'some.tool',
            null,
            ActionOutcome::Failure,
            $failureReason,
            null,
            $t0,
            $t1,
        );

        $builder = new OtlpPayloadBuilder();
        $payload = $builder->build($runId);

        $spans = $this->spansOf($payload);
        $actionSpan = $this->findSpanBySpanId($spans, $this->spanIdOf($actionId));

        $this->assertNotNull($actionSpan);
        $this->assertStringNotContainsString($secretValue, json_encode($actionSpan));
    }

    /**
     * SC-009 end-to-end: unlike the two tests above (which insert already-plain
     * content directly into agent_run_actions and only exercise
     * OtlpPayloadBuilder's own defense-in-depth sanitize() calls on
     * target/failure_reason), this test drives the *full* production path for
     * the `content` field -- RunTraceRecorder::closeAction() (which passes
     * content through ContentSanitizer::prepare() at write time) followed by
     * OtlpPayloadBuilder::build() (which carries content verbatim, per
     * contracts/otlp-export-payload.md's "Content handling") -- with tool
     * arguments/results shaped like a real credential leak: a Bearer token, an
     * sk-prefixed API key, and an access_token query-string parameter, each in
     * a different action's content. Proves zero occurrences of any of the
     * three secret values survive end to end into the built OTLP payload.
     */
    #[Test]
    public function content_with_credential_shaped_strings_recorded_via_run_trace_recorder_is_redacted_end_to_end(): void
    {
        $bearerSecret = 'SC009BEARERTOKENVALUE123456';
        $apiKeySecret = 'sk-SC009APIKEYVALUE1234567890ABCDEF';
        $accessTokenSecret = 'SC009ACCESSTOKENVALUE987654321';

        $recorder = $this->app->make(RunTraceRecorder::class);

        $userId = (string) Str::uuid();
        $runId = $recorder->openRun(RunKind::Interactive, $userId);
        $this->assertNotNull($runId);

        $stepId = $recorder->openStep($runId);
        $this->assertNotNull($stepId);

        $bearerActionId = $recorder->openAction($stepId, ActionType::ToolInvocation, 'contacts.store');
        $recorder->closeAction(
            $bearerActionId,
            ActionOutcome::Success,
            content: json_encode(['headers' => ['Authorization' => "Bearer {$bearerSecret}"]]),
        );

        $apiKeyActionId = $recorder->openAction($stepId, ActionType::ToolInvocation, 'billing.charge');
        $recorder->closeAction(
            $apiKeyActionId,
            ActionOutcome::Success,
            content: json_encode(['api_key' => $apiKeySecret]),
        );

        $accessTokenActionId = $recorder->openAction($stepId, ActionType::ToolInvocation, 'oauth.callback');
        $recorder->closeAction(
            $accessTokenActionId,
            ActionOutcome::Success,
            content: "result: https://api.example.com/callback?access_token={$accessTokenSecret}&ok=1",
        );

        $recorder->closeStep($stepId, RunEndState::Completed);
        $recorder->closeRun($runId, RunEndState::Completed);

        $builder = new OtlpPayloadBuilder();
        $payload = $builder->build($runId);

        $this->assertIsArray($payload);
        $encoded = json_encode($payload);

        $this->assertStringNotContainsString($bearerSecret, $encoded);
        $this->assertStringNotContainsString($apiKeySecret, $encoded);
        $this->assertStringNotContainsString($accessTokenSecret, $encoded);

        // Belt-and-braces: confirm redaction markers are actually present
        // (i.e. the assertions above pass because the secret was redacted,
        // not because the content silently went missing from the payload).
        $this->assertStringContainsString('[REDACTED]', $encoded);
    }
}
