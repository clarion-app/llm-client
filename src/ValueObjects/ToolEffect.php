<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The effect class of a tool call, decided at C3 by the ToolEffectClassifier
 * (research D3). `effect_free` calls may be re-run freely on a resume;
 * `durable` calls are recorded and reused; `effectful` calls are re-run at
 * most once (an in-flight one becomes outcome-unknown).
 */
enum ToolEffect: string
{
    case EffectFree = 'effect_free';
    case Durable = 'durable';
    case Effectful = 'effectful';
}
