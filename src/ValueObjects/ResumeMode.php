<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The execution path a run took, kept on the run so a resume re-enters the
 * same path (FR-007).
 */
enum ResumeMode: string
{
    case Sync = 'sync';
    case Stream = 'stream';
}
