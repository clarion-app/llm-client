<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The disposition of one tool call in a resume plan
 * (checkpoint-ledger contract §4).
 */
enum CallDispositionKind: string
{
    /** The result is already durable (the message or checkpoint holds it); reuse it. */
    case Reuse = 'reuse';

    /** The call never started; execute it fresh. */
    case Execute = 'execute';

    /** The call started and its effect is not safe to repeat; re-run it once. */
    case Rerun = 'rerun';

    /** The call's outcome is unknown; give the agent an outcome-unknown result. */
    case OutcomeUnknown = 'outcome_unknown';

    /** A durable call (delegation / capability / ask_user); resolve it from its own record. */
    case ResolveDurable = 'resolve_durable';
}
