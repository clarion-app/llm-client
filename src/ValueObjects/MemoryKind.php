<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The vocabulary an Agent Definition File Format document (086-agent-yaml-schema)
 * uses to state which kinds of memory an agent may use at all.
 *
 * The first three values are identical to
 * ClarionApp\LlmClient\Contracts\MemoryScope's own three cases
 * (scratch, short_term, long_term) — this is a distinct, superset
 * vocabulary, not a replacement. MemoryScope continues to govern the
 * memory_* meta-tools' own `scope` parameter unchanged; MemoryKind exists
 * only for "which memory kinds this format may grant," a broader concept
 * MemoryScope alone cannot express (it has no notion of episodic/declarative,
 * research.md D6).
 */
enum MemoryKind: string
{
    case Scratch = 'scratch';
    case ShortTerm = 'short_term';
    case LongTerm = 'long_term';
    case Episodic = 'episodic';
    case Declarative = 'declarative';
}
