<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\AgentFileUnreadableException;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\ValueObjects\DivergenceState;
use ClarionApp\LlmClient\ValueObjects\FileDivergenceReport;

/**
 * Computes an agent's divergence against its linked definition file, fresh
 * on every call — no caching, no scheduled job, no polling (contracts §12,
 * research.md D9/D10).
 *
 * Both the live file's hash and the current version's content_hash are
 * compared against the `linked_synced_file_hash` baseline column — never
 * file-hash-directly-against-current-version-hash — which is what makes
 * the *direction* of drift determinable (mutation-checklist row 7): a
 * direct two-way comparison alone could tell "in step" from "diverged" but
 * not which side moved.
 *
 * `governs` is always the literal 'stored_agent' whenever the agent is
 * linked and the file was successfully read (research.md D10 — never
 * varies with drift direction); it is null for NotLinked and for
 * Unavailable alike, since neither state has a meaningful drift direction
 * to report governance for (contracts §10's own worked examples show both
 * as `governs: null`).
 */
class AgentDivergenceChecker
{
    public function __construct(
        private readonly GitDefinitionFileReader $reader,
    ) {
    }

    public function check(Agent $agent): FileDivergenceReport
    {
        if ($agent->linked_repository_path === null || $agent->linked_file_path === null) {
            return new FileDivergenceReport(
                state: DivergenceState::NotLinked,
                governs: null,
                liveFileHash: null,
                currentVersionHash: null,
                checkedAt: new \DateTimeImmutable(),
            );
        }

        $currentVersionHash = $agent->currentVersion?->content_hash;

        try {
            $liveContent = $this->reader->readWorkingTreeContent(
                $agent->linked_repository_path,
                $agent->linked_file_path
            );
        } catch (AgentFileUnreadableException $e) {
            return new FileDivergenceReport(
                state: DivergenceState::Unavailable,
                governs: null,
                liveFileHash: null,
                currentVersionHash: $currentVersionHash,
                checkedAt: new \DateTimeImmutable(),
                unavailableReason: $e->getMessage(),
            );
        }

        $liveFileHash = hash('sha256', $liveContent);
        $baseline = $agent->linked_synced_file_hash;

        $fileAhead = $liveFileHash !== $baseline;
        $storedAhead = $currentVersionHash !== $baseline;

        $state = match (true) {
            ! $fileAhead && ! $storedAhead => DivergenceState::InStep,
            $fileAhead && ! $storedAhead => DivergenceState::FileAhead,
            ! $fileAhead && $storedAhead => DivergenceState::StoredAhead,
            default => DivergenceState::BothChanged,
        };

        return new FileDivergenceReport(
            state: $state,
            governs: 'stored_agent',
            liveFileHash: $liveFileHash,
            currentVersionHash: $currentVersionHash,
            checkedAt: new \DateTimeImmutable(),
        );
    }
}
