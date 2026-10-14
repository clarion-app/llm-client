<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The shape of a resume plan (checkpoint-ledger contract §4). The resumer
 * applies exactly one; the planner never executes anything.
 */
enum PlanKind
{
    /** Start the next fresh iteration. */
    case NextIteration;

    /** Repeat the interrupted model call (its step is still open). */
    case RepeatModelCall;

    /** Finish the interrupted tool batch (the model response is recorded). */
    case FinishToolBatch;

    /** Commit the step whose message already holds every result. */
    case CloseCommittedStep;
}
