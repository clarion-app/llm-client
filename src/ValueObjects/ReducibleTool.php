<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The closed set of tool names a ReductionStep.withheld_tools column may
 * ever contain (data-model.md §6, research.md D6).
 *
 * Deliberately no case for `list_applications`, `execute_operation`, or
 * `search_operations` — the structural FR-008 guarantee: an agent with no
 * way to discover or execute an operation cannot function in any reduced
 * form at all, so withholding one of those three is not a smaller
 * capability, it is no capability. Because ReductionLadderService's write
 * path validates every withheld_tools entry against this enum, those three
 * names can never reach the column, at the type level, independent of any
 * runtime check.
 */
enum ReducibleTool: string
{
    case MemoryCreate = 'memory_create';
    case MemoryRead = 'memory_read';
    case MemorySearch = 'memory_search';
    case MemoryDelete = 'memory_delete';
    case ProposeDeclarativeMemory = 'propose_declarative_memory';
}
