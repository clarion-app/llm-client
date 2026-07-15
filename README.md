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

#### Learned Patterns and Confidence

The store holds both user-stated entries and patterns learned on the user's behalf, in a single table (no parallel model). A learned pattern (`source = agent_learned`) carries a `confidence_level` — a nullable integer from 0 to 100 reflecting how much consistent evidence supports it:

- **`confidence_level` is `NULL` for user-stated entries** and set to 0–100 for learned patterns. Values outside 0–100 are rejected at the service layer.
- **User-stated always wins** — when a learned pattern semantically conflicts with a user-stated entry, the user-stated entry is never superseded. A higher-confidence learned pattern may supersede an older learned pattern; a lower-confidence one does not.
- **Editing a learned entry converts it** to `source = user_stated` and clears `confidence_level` to `NULL`.
- **Confidence is visible everywhere** — surfaced on recall, in every API response, and carried in the `ConfirmationRequiredException` payload so the confirmation prompt can show it.

`applyAgentWrite()` accepts an optional `$confidenceLevel` parameter; the confirmation gate still throws before any DB access when the write is not confirmed.

See `specs/041-declarative-memory-store/quickstart.md` for base API usage and `specs/046-learned-patterns-store/quickstart.md` for learned-pattern behavior verification.
