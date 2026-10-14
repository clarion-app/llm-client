<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The resumable state of a run (data-model.md §1's state diagram).
 * `NULL` on the row means a terminal run or a pre-deployment (legacy) run.
 */
enum ResumeState: string
{
    case Working = 'working';
    case Paused = 'paused';
    case WaitingProviderRetry = 'waiting_provider_retry';
    case Interrupted = 'interrupted';
    case Resuming = 'resuming';
}
