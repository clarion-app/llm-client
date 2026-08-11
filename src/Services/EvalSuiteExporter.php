<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\EvalCase;
use ClarionApp\LlmClient\Models\EvalSuite;

/**
 * The read-only translation from a live EvalSuite to the self-contained
 * document shape data-model.md §6 defines (research.md D10): schema_version,
 * the suite's own name/agent_identifier, and one entry per live case
 * rendered from that case's current version.
 *
 * The document carries no row identity (id, suite_id, case_id, version_id,
 * version_number) and no source-installation timestamp anywhere — export
 * represents the suite's current definition, not a copy of its rows. An
 * archived case is never included (mutation-checklist row 12): a
 * re-imported suite must not resurrect content an operator removed.
 */
class EvalSuiteExporter
{
    /**
     * @return array<string, mixed>
     */
    public function export(EvalSuite $suite): array
    {
        $cases = $suite->cases()->with('currentVersion')->get();

        return [
            'schema_version' => 1,
            'name' => $suite->name,
            'agent_identifier' => $suite->agent_identifier,
            'cases' => $cases->map(fn (EvalCase $case) => $this->formatCase($case))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCase(EvalCase $case): array
    {
        $version = $case->currentVersion;

        return [
            'given' => $version?->given,
            'expected_behavior' => $version?->expected_behavior,
            'expectations' => $version?->expectations ?? [],
        ];
    }
}
