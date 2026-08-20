<?php

namespace Tests\RealSpecKit\Support;

/**
 * The three FR-015-required, mutually exclusive outcomes for a single
 * (aiTarget, commandName) pair (data-model.md §2).
 *
 * A command that errors during a real invocation is still Invoked — the
 * error is captured as the SpecKitLedgerEntry's own detail, not a fourth
 * case here (data-model.md §2's own rationale: "invoked and failed" is a
 * property of an invocation, not a different discoverability class).
 */
enum SpecKitCommandOutcome: string
{
    /** Discovered by CommandPackLoader::discover() AND exercised through a real invocation. */
    case Invoked = 'invoked';

    /** Discovered, listed, never invoked in this run. */
    case DiscoveredOnly = 'discovered_only';

    /** Absent from CommandPackLoader::discover()->commands. */
    case NotDiscovered = 'not_discovered';
}
