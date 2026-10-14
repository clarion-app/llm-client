<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentRunResumeEvent;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * The resume/retry event log. Every event kind
 * is written with its bounded payload: strings are cut (labels 256, reasons
 * 512), lists hold at most 50 entries, and tool-call arguments and provider
 * error text are never stored. Each write logs one line whose context
 * carries the run id (the Context-merge pattern spec 069 proved) and
 * announces the run as changed.
 */
class ResumeEventLog
{
    /** Keys that are never stored, at any depth (arguments, provider text). */
    private const NEVER_STORE_KEYS = ['arguments', 'provider_error'];

    /**
     * Record one resume/retry event.
     *
     * @param array<string, mixed> $payload
     */
    public function record(string $runId, string $kind, ?int $stepPosition, ?int $attempt, array $payload): void
    {
        AgentRunResumeEvent::create([
            'run_id' => $runId,
            'kind' => $kind,
            'step_position' => $stepPosition,
            'attempt' => $attempt,
            'payload' => $this->boundValue('', $payload),
        ]);

        $previous = Context::get('run_id');
        Context::add('run_id', $runId);
        try {
            Log::info('resume event', [
                'kind' => $kind,
                'step_position' => $stepPosition,
                'attempt' => $attempt,
            ]);
        } finally {
            if ($previous === null) {
                Context::forget('run_id');
            } else {
                Context::add('run_id', $previous);
            }
        }

        app(RunTraceRecorder::class)->announceRunChanged($runId);
    }

    /**
     * Bound a value: strip the never-store keys, cut labels to 256 and
     * reasons to 512, and keep lists to at most 50 entries.
     */
    private function boundValue(string $key, mixed $value): mixed
    {
        if (is_array($value)) {
            foreach (self::NEVER_STORE_KEYS as $forbidden) {
                unset($value[$forbidden]);
            }

            if (array_is_list($value)) {
                $value = array_slice($value, 0, 50);
                foreach ($value as $i => $entry) {
                    $value[$i] = $this->boundValue('', $entry);
                }

                return $value;
            }

            foreach ($value as $k => $v) {
                $value[$k] = $this->boundValue((string) $k, $v);
            }

            return $value;
        }

        if (is_string($value)) {
            return match ($key) {
                'label', 'tool' => mb_substr($value, 0, 256),
                'reason', 'reason_code' => mb_substr($value, 0, 512),
                default => $value,
            };
        }

        return $value;
    }
}
