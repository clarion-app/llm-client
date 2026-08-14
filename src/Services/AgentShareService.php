<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\AgentShareGrant;

/**
 * Write path for `agent_share_grants` (096-agent-sharing, data-model.md
 * §4). Mirrors AgentService's own owner/write-path role.
 *
 * grant() is owner-only: it resolves the target agent via the existing,
 * unmodified, owner-only AgentQuery::findAgent() — never
 * findAccessibleAgent() — so a recipient who only holds a `use` or
 * `use_and_edit` grant on an agent can never grant further access to
 * anyone else, regardless of their own permission level. A caller who
 * does not own the agent gets the identical rejection every other
 * validation failure here does (a plain \RuntimeException, left
 * undifferentiated by design — see AgentShareServiceTest's own docblock);
 * AgentShareController distinguishes "not owned" from the other rejection
 * reasons itself, by resolving ownership before ever calling grant(), so
 * this class does not need a bespoke exception hierarchy to communicate it.
 */
class AgentShareService
{
    private const ALLOWED_PERMISSIONS = ['use', 'use_and_edit'];

    public function __construct(
        private readonly AgentQuery $query,
    ) {}

    /**
     * Grants (or updates/restores) a recipient's access to an agent the
     * caller owns. Idempotent per (agent_id, recipient_user_id) pair — a
     * second call updates the existing row's permission in place rather
     * than inserting a second one; a call after a prior revoke() restores
     * the same lifetime row instead of creating a new one (research.md D7).
     *
     * @throws \RuntimeException when the caller does not own the target
     *   agent, the recipient does not exist, the recipient is the caller
     *   themself, or the permission value is outside {use, use_and_edit}.
     */
    public function grant(string $ownerUserId, string $agentId, string $recipientUserId, string $permission): AgentShareGrant
    {
        $agent = $this->query->findAgent($ownerUserId, $agentId);

        if ($agent === null) {
            throw new \RuntimeException('Agent not found or not owned by the caller.');
        }

        if ($recipientUserId === $ownerUserId) {
            throw new \RuntimeException('An agent cannot be shared with its own owner.');
        }

        if (User::find($recipientUserId) === null) {
            throw new \RuntimeException('The recipient user does not exist.');
        }

        if (!in_array($permission, self::ALLOWED_PERMISSIONS, true)) {
            throw new \RuntimeException('Permission must be one of: '.implode(', ', self::ALLOWED_PERMISSIONS).'.');
        }

        $grant = AgentShareGrant::withTrashed()->firstOrNew([
            'agent_id' => $agentId,
            'recipient_user_id' => $recipientUserId,
        ]);

        $grant->owner_user_id = $ownerUserId;
        $grant->permission = $permission;

        if ($grant->trashed()) {
            // restore() persists the row itself (sets deleted_at = null and
            // calls save()), already carrying the owner_user_id/permission
            // assignments above along with it — a separate save() call
            // afterward would be a redundant no-op, not a second write.
            $grant->restore();
        } else {
            $grant->save();
        }

        return $grant;
    }

    /**
     * Revokes a recipient's access to an agent the caller owns
     * (096-agent-sharing, data-model.md §4, Phase 5/US3). Same ownership
     * resolution as grant() — an owner-only action, never reachable by a
     * mere recipient regardless of their own permission level.
     *
     * Idempotent: soft-deletes the active (non-trashed) grant for the pair
     * if one exists and returns true; returns false, never throws, when no
     * active grant exists for the pair — mirroring
     * ConversationLifecycleService::end()'s own established idempotency
     * posture.
     *
     * @throws \RuntimeException when the caller does not own the target agent.
     */
    public function revoke(string $ownerUserId, string $agentId, string $recipientUserId): bool
    {
        $agent = $this->query->findAgent($ownerUserId, $agentId);

        if ($agent === null) {
            throw new \RuntimeException('Agent not found or not owned by the caller.');
        }

        $grant = AgentShareGrant::where('agent_id', $agentId)
            ->where('recipient_user_id', $recipientUserId)
            ->first();

        if ($grant === null) {
            return false;
        }

        $grant->delete();

        return true;
    }
}
