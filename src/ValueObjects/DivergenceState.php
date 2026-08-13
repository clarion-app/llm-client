<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The outcome of AgentDivergenceChecker::check() (data-model.md §4) —
 * computed fresh on every check, never stored: a divergence report is
 * always a live computation, not a cached column subject to going stale.
 *
 * NotLinked — the agent has no linked_repository_path/linked_file_path.
 * InStep — neither the live file nor the current version has moved past
 * the last synced baseline.
 * FileAhead — the file changed since the last sync, the stored agent did
 * not.
 * StoredAhead — the stored agent changed (a product_edit or restoration)
 * since the last sync, the file did not.
 * BothChanged — both moved independently since the last sync.
 * Unavailable — the file could not be read (missing, permission denied,
 * repository path invalid) — reported distinctly from NotLinked so a
 * caller can tell "nothing to check" from "something is wrong with the
 * check."
 */
enum DivergenceState: string
{
    case NotLinked = 'not_linked';
    case InStep = 'in_step';
    case FileAhead = 'file_ahead';
    case StoredAhead = 'stored_ahead';
    case BothChanged = 'both_changed';
    case Unavailable = 'unavailable';
}
