<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Models\CodingWorkspaceChange;
use Illuminate\Support\Facades\Log;

/**
 * 122-workspace-browser-ui, US3 (data-model.md §1, research.md D1/D6).
 * Writes one durable coding_workspace_changes row per file mutation, from
 * the two seams CodingWorkspaceController::writeFile()/deleteFile() call
 * it from, at the moment of mutation (FR-006) -- never reconstructed
 * afterward.
 *
 * Mirrors WorkspaceRefusalRecorder's shape exactly: never throws, degrades
 * to a logged warning on a write failure, so a recording problem can
 * never turn an already-successful file mutation's response into an
 * error.
 *
 * Accepts old/new content directly rather than recomputing anything of
 * its own -- writeFile()/deleteFile() already read a threshold-bounded
 * sample from the same already-open, already-identity-verified handle
 * they use for the mutation itself (research.md D6). This service's own
 * responsibility is applying the cap/binary/truncated classification
 * symmetrically to both sides, matching
 * CodingWorkspaceController::readFile()'s own binary/truncated
 * mutual-exclusion contract exactly: binary content is never stored
 * (content is null, binary is true), and truncated is only ever true for
 * non-binary content whose actual size exceeds the configured threshold.
 */
class WorkspaceChangeRecorder
{
    public function __construct(
        private readonly WorkspaceFilePolicy $filePolicy = new WorkspaceFilePolicy(),
    ) {
    }

    public function record(
        CodingProject $project,
        string $path,
        string $operation,
        ?string $oldContent,
        ?int $oldSize,
        ?string $newContent,
        ?int $newSize,
        ?string $agentId = null,
        ?string $agentName = null,
        ?string $conversationId = null,
    ): void {
        try {
            $threshold = (int) config('llm-client.coding_agent.file_size_threshold_bytes');

            $old = $this->classifySide($oldContent, $oldSize, $threshold);
            $new = $this->classifySide($newContent, $newSize, $threshold);

            CodingWorkspaceChange::create([
                'coding_project_id' => $project->id,
                'user_id' => $project->user_id,
                'root_path' => $project->root_path,
                'path' => $path,
                'operation' => $operation,
                'old_content' => $old['content'],
                'old_content_truncated' => $old['truncated'],
                'old_binary' => $old['binary'],
                'old_size' => $oldSize,
                'new_content' => $new['content'],
                'new_content_truncated' => $new['truncated'],
                'new_binary' => $new['binary'],
                'new_size' => $newSize,
                'agent_id' => $agentId,
                'agent_name' => $agentName,
                'conversation_id' => $conversationId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('WorkspaceChangeRecorder: record failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{content: ?string, truncated: bool, binary: bool}
     */
    private function classifySide(?string $content, ?int $actualSize, int $threshold): array
    {
        if ($content === null) {
            return ['content' => null, 'truncated' => false, 'binary' => false];
        }

        if ($this->filePolicy->isBinary($content)) {
            return ['content' => null, 'truncated' => false, 'binary' => true];
        }

        $truncated = $actualSize !== null && $actualSize > $threshold;

        return ['content' => $content, 'truncated' => $truncated, 'binary' => false];
    }
}
