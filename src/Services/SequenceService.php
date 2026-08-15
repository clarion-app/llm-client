<?php

namespace ClarionApp\LlmClient\Services;

/**
 * 105-stage-pipeline. The business-logic layer behind SequenceController —
 * defining a sequence, invoking it, and (later) resuming it. Empty in
 * Phase 2 (Foundational) so SequenceController compiles; Phase 3 (US1)
 * adds defineSequence()/invoke(), Phase 4 (US2) adds the broadcast()
 * helper, Phase 6 (US4) adds resumeSafety().
 */
class SequenceService
{
}
