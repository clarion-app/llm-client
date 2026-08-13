<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * Why a given AgentVersion row exists — observability/attribution context
 * only, never a branch point for AgentVersionResolver's resolution logic,
 * which treats every version identically regardless of how it was
 * produced (data-model.md §3).
 *
 * Created — the agent's first version, written by AgentService::create().
 * ProductEdit — any subsequent definition change made through the
 * product's own edit path, AgentService::update().
 * Restoration — AgentService::restore(), always a new version whose
 * raw_definition matches an earlier one's, never a repoint at the earlier
 * row itself.
 * FileSync — AgentService::link()'s initial import or
 * syncFromFile()'s explicit re-import.
 */
enum AgentChangeSource: string
{
    case Created = 'created';
    case ProductEdit = 'product_edit';
    case Restoration = 'restoration';
    case FileSync = 'file_sync';
}
