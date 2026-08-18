<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Models\CodingWorkspaceRefusal;
use Illuminate\Support\Facades\Log;

/**
 * 121-workspace-boundary-hardening, US2 (contracts/refusal-recording.md §2,
 * research.md D2). Writes a durable record of every boundary refusal from
 * the one seam CodingWorkspaceController::containmentFailureResponse()
 * already funnels every containment failure through -- called from
 * exactly that one place, not duplicated per controller method.
 *
 * Never throws -- mirrors RunTraceRecorder::broadcast()'s established
 * try/catch/Log::warning shape exactly. A write failure degrades to a
 * logged warning; the caller's already-decided refusal response is built
 * and returned either way, unaffected by whether the record write itself
 * succeeded.
 *
 * Has no code path that distinguishes an agent-originated caller from a
 * direct, non-agent caller, or a synchronous web request from a queued
 * job -- both reach this method through the identical controller seam, so
 * recording behaves identically regardless of origin by construction.
 */
class WorkspaceRefusalRecorder
{
    public function record(CodingProject $project, string $operation, string $reason): void
    {
        try {
            CodingWorkspaceRefusal::create([
                'coding_project_id' => $project->id,
                'operation' => $operation,
                'reason' => $reason,
            ]);
        } catch (\Throwable $e) {
            Log::warning('WorkspaceRefusalRecorder: record failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
