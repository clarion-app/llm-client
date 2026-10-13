<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * Who drives a run's recovery. `root` runs are resumed by the detection
 * sweep; the helper kinds are resumed by their parent (or left to the
 * parent's own resume); `none` is not resumable (e.g. evals).
 */
enum ResumeDriver: string
{
    case Root = 'root';
    case Parent = 'parent';
    case BatchMember = 'batch_member';
    case SequenceStage = 'sequence_stage';
    case Consensus = 'consensus';
    case None = 'none';
}
