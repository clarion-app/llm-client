<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use Illuminate\Support\Facades\DB;

/**
 * Maps a closed AgentRun (+ its steps and actions) into an OTLP/HTTP JSON
 * ExportTraceServiceRequest, per contracts/otlp-export-payload.md.
 *
 * This is a system-level export sweep, not a user-facing read: it loads
 * agent_runs/agent_run_steps/agent_run_actions directly by run id, with no
 * ownership/user filter -- unlike RunTraceQuery, which every caller here is
 * deliberately bypassing (the export queue only ever stores a run id, and
 * the delivery command has no caller identity to filter by).
 */
class OtlpPayloadBuilder
{
    private ContentSanitizer $sanitizer;

    public function __construct(?ContentSanitizer $sanitizer = null)
    {
        $this->sanitizer = $sanitizer ?? app(ContentSanitizer::class);
    }

    /**
     * @return array<string, mixed>|null Null when the run no longer resolves
     *         (e.g. already purged by PurgeExpiredRunTracesCommand while the
     *         forwarding row was still queued -- data-model.md §2).
     */
    public function build(string $runId): ?array
    {
        $run = DB::table('agent_runs')->where('id', $runId)->first();

        if ($run === null) {
            return null;
        }

        $steps = DB::table('agent_run_steps')
            ->where('run_id', $runId)
            ->orderBy('position')
            ->get();

        $actions = DB::table('agent_run_actions')
            ->where('run_id', $runId)
            ->orderBy('started_at')
            ->get();

        $traceId = $this->stripHyphens($run->id);
        $rootSpanId = substr($traceId, 0, 16);

        $spans = [$this->buildRunSpan($run, $traceId, $rootSpanId)];

        // Every span id (step and action alike) is derived purely from its
        // own row id, so this map can be built before any span is emitted --
        // a nested action's parent lookup never depends on iteration order.
        $stepSpanIds = [];
        foreach ($steps as $step) {
            $stepSpanIds[$step->id] = $this->spanIdOf($step->id);
        }

        $actionSpanIds = [];
        foreach ($actions as $action) {
            $actionSpanIds[$action->id] = $this->spanIdOf($action->id);
        }

        foreach ($steps as $step) {
            $spans[] = $this->buildStepSpan($step, $traceId, $stepSpanIds[$step->id], $rootSpanId);
        }

        foreach ($actions as $action) {
            $parentSpanId = $action->parent_action_id !== null
                ? ($actionSpanIds[$action->parent_action_id] ?? $this->spanIdOf($action->parent_action_id))
                : ($stepSpanIds[$action->step_id] ?? $this->spanIdOf($action->step_id));

            $spans[] = $this->buildActionSpan($action, $traceId, $actionSpanIds[$action->id], $parentSpanId);
        }

        return [
            'resourceSpans' => [
                [
                    'resource' => [
                        'attributes' => [
                            $this->stringAttr('service.name', 'clarion-app.llm-client'),
                        ],
                    ],
                    'scopeSpans' => [
                        [
                            'scope' => [
                                'name' => 'clarion-app/llm-client',
                                'version' => '1.0',
                            ],
                            'spans' => $spans,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param object $run Raw agent_runs row.
     */
    private function buildRunSpan(object $run, string $traceId, string $spanId): array
    {
        $status = $this->runStatus(RunEndState::from($run->end_state), $run->end_reason);

        $attributes = [
            $this->stringAttr('clarion.run_id', $run->id),
            $this->stringAttr('clarion.run_kind', $run->kind),
            $this->stringAttr('clarion.user_id', $run->user_id),
        ];

        if ($run->source !== null) {
            $attributes[] = $this->stringAttr('clarion.source', $run->source);
        }

        if ($run->conversation_id !== null) {
            $attributes[] = $this->stringAttr('clarion.conversation_id', $run->conversation_id);
        }

        if ($status['message'] !== null) {
            $attributes[] = $this->stringAttr('clarion.end_reason', $status['message']);
        }

        return [
            'traceId' => $traceId,
            'spanId' => $spanId,
            'name' => 'run:' . $run->kind,
            'kind' => 1,
            'startTimeUnixNano' => $this->toNanos($run->started_at),
            'endTimeUnixNano' => $this->toNanos($run->ended_at),
            'attributes' => $attributes,
            'status' => $this->statusPayload($status),
        ];
    }

    /**
     * @param object $step Raw agent_run_steps row.
     */
    private function buildStepSpan(object $step, string $traceId, string $spanId, string $parentSpanId): array
    {
        $status = $this->runStatus(RunEndState::from($step->end_state), $step->end_reason);

        $attributes = [
            $this->intAttr('clarion.attempt_count', (int) $step->attempt_count),
        ];

        if ($status['message'] !== null) {
            $attributes[] = $this->stringAttr('clarion.end_reason', $status['message']);
        }

        return [
            'traceId' => $traceId,
            'spanId' => $spanId,
            'parentSpanId' => $parentSpanId,
            'name' => 'step:' . $step->position,
            'kind' => 1,
            'startTimeUnixNano' => $this->toNanos($step->started_at),
            'endTimeUnixNano' => $this->toNanos($step->ended_at),
            'attributes' => $attributes,
            'status' => $this->statusPayload($status),
        ];
    }

    /**
     * @param object $action Raw agent_run_actions row.
     */
    private function buildActionSpan(object $action, string $traceId, string $spanId, string $parentSpanId): array
    {
        $status = $this->actionStatus(ActionOutcome::from($action->outcome), $action->failure_reason);

        $attributes = [];

        // target is defense-in-depth sanitized here (FR-024) -- it is set at
        // write time and never itself passed through ContentSanitizer until
        // now, unlike `content` below which was already sanitized+truncated
        // by ContentSanitizer::prepare() at closeAction() time.
        if ($action->target !== null) {
            $attributes[] = $this->stringAttr('clarion.target', $this->sanitizer->sanitize($action->target));
        }

        if ($status['message'] !== null) {
            $attributes[] = $this->stringAttr('clarion.failure_reason', $status['message']);
        }

        $span = [
            'traceId' => $traceId,
            'spanId' => $spanId,
            'parentSpanId' => $parentSpanId,
            'name' => 'action:' . $action->action_type,
            'kind' => 1,
            'startTimeUnixNano' => $this->toNanos($action->started_at),
            'endTimeUnixNano' => $this->toNanos($action->ended_at),
            'attributes' => $attributes,
            'status' => $this->statusPayload($status),
        ];

        // content is carried verbatim -- it already passed through
        // ContentSanitizer::prepare() at RunTraceRecorder::closeAction() time,
        // so no re-sanitization happens here (contracts/otlp-export-payload.md
        // "Content handling").
        if ($action->content !== null) {
            $span['events'] = [
                [
                    'timeUnixNano' => $this->toNanos($action->ended_at ?? $action->started_at),
                    'name' => 'content',
                    'attributes' => [
                        $this->stringAttr('content', $action->content),
                    ],
                ],
            ];
        }

        return $span;
    }

    /**
     * @return array{code: int, message: ?string}
     */
    private function runStatus(RunEndState $endState, ?string $reason): array
    {
        if ($endState === RunEndState::Completed) {
            return ['code' => 1, 'message' => null];
        }

        return [
            'code' => 2,
            'message' => $reason !== null ? $this->sanitizer->sanitize($reason) : null,
        ];
    }

    /**
     * @return array{code: int, message: ?string}
     */
    private function actionStatus(ActionOutcome $outcome, ?string $reason): array
    {
        if ($outcome === ActionOutcome::Success) {
            return ['code' => 1, 'message' => null];
        }

        return [
            'code' => 2,
            'message' => $reason !== null ? $this->sanitizer->sanitize($reason) : null,
        ];
    }

    /**
     * @param array{code: int, message: ?string} $status
     */
    private function statusPayload(array $status): array
    {
        if ($status['message'] === null) {
            return ['code' => $status['code']];
        }

        return ['code' => $status['code'], 'message' => $status['message']];
    }

    private function stringAttr(string $key, string $value): array
    {
        return ['key' => $key, 'value' => ['stringValue' => $value]];
    }

    private function intAttr(string $key, int $value): array
    {
        // OTLP/HTTP JSON represents int64 fields as strings (proto3 JSON
        // mapping), matching contracts/otlp-export-payload.md's example.
        return ['key' => $key, 'value' => ['intValue' => (string) $value]];
    }

    private function stripHyphens(string $uuid): string
    {
        return str_replace('-', '', $uuid);
    }

    private function spanIdOf(string $uuid): string
    {
        return substr($this->stripHyphens($uuid), 0, 16);
    }

    private function toNanos(?string $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        $carbon = \Carbon\Carbon::parse($timestamp);
        $micro = (int) $carbon->format('u');
        $seconds = $carbon->getTimestamp();

        return (string) (($seconds * 1_000_000 + $micro) * 1000);
    }
}
