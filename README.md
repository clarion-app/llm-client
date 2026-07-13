# clarion-app/llm-client

## Memory Scopes

The package provides four memory scopes for agents:

| Scope | Table | Retention | Eviction | Entry Cap |
|-------|-------|-----------|----------|-----------|
| Scratch | — | Cleared per turn | N/A | N/A |
| Short-term | — | Cleared on conversation end | N/A | N/A |
| Long-term | `llm_memory_entries` | Permanent | LRU eviction | Configurable cap |
| Episodic | `episodic_memories` | Configurable (`retention_days`) | Time-based cleanup | None |
| **Declarative** | `declarative_memories` | **Permanent** | **None** | **None** |

### Declarative Memory (Permanent Facts, Preferences, Rules)

The declarative scope stores explicit user-created facts, preferences, and behavioral rules that must be reliably available in every conversation. Unlike long-term (LRU-evicted) and episodic (time-expiring) scopes, declarative entries are **permanent by design**:

- **No retention config** — no `retention_days`, no expiration
- **No eviction** — no LRU, no cleanup command, no scheduled task
- **No entry cap** — deliberately unbounded (user-managed, expected to stay small)
- **Strict per-user scoping** — no cross-user access, no admin override
- **Confirmation gate** — agent-sourced writes require explicit user confirmation before persistence
- **Semantic conflict detection** — reworded restatements supersede existing entries in place
- **Immediate edit/delete** — edits and deletes take effect in the same and all later conversations

Entries record a `type` (`fact` | `preference` | `rule`) and `source` (provenance: `user_stated` | `agent_learned`).

See `specs/041-declarative-memory-store/quickstart.md` for API usage and behavior verification.
