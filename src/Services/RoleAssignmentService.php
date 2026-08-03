<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\ValueObjects\ModelRole;

final class RoleAssignmentService
{
    public function __construct(
        private readonly RoleResolver $resolver,
    ) {}

    public function set(ModelRole $role, string $ownerId, string $serverId, string $model): RoleAssignment
    {
        $assignment = RoleAssignment::withTrashed()
            ->where('role', $role->value)
            ->where('user_id', $ownerId)
            ->first();

        if ($assignment) {
            if ($assignment->trashed()) {
                $assignment->restore();
            }
            $assignment->update(['server_id' => $serverId, 'model' => $model]);
        } else {
            $assignment = RoleAssignment::create([
                'role' => $role->value,
                'user_id' => $ownerId,
                'server_id' => $serverId,
                'model' => $model,
            ]);
        }

        return $assignment;
    }

    public function clear(ModelRole $role, string $ownerId): void
    {
        RoleAssignment::where('role', $role->value)
            ->where('user_id', $ownerId)
            ->first()?->delete();
    }

    /**
     * @return array<string, array{
     *   role: string,
     *   effective: array{status: string, scope: ?string, server: ?array, model: ?string, reason: ?string},
     *   user_assignment: ?array{server_id: string, model: string},
     *   installation_assignment: ?array{server_id: string, model: string},
     * }>
     */
    public function describeAllRoles(string $userId): array
    {
        $result = [];

        foreach (ModelRole::cases() as $role) {
            $resolution = $this->resolver->resolve($role, $userId);

            $userAssignment = RoleAssignment::where('role', $role->value)
                ->where('user_id', $userId)
                ->first();

            $installationAssignment = RoleAssignment::where('role', $role->value)
                ->where('user_id', RoleAssignment::INSTALLATION_SCOPE_ID)
                ->first();

            $result[$role->value] = [
                'role' => $role->value,
                'effective' => [
                    'status' => $resolution->status->value,
                    'scope' => $resolution->scope,
                    'server' => $resolution->server ? [
                        'id' => $resolution->server->id,
                        'name' => $resolution->server->name,
                    ] : null,
                    'model' => $resolution->model,
                    'reason' => $resolution->brokenReason,
                ],
                'user_assignment' => $userAssignment ? [
                    'server_id' => $userAssignment->server_id,
                    'model' => $userAssignment->model,
                ] : null,
                'installation_assignment' => $installationAssignment ? [
                    'server_id' => $installationAssignment->server_id,
                    'model' => $installationAssignment->model,
                ] : null,
            ];
        }

        return $result;
    }
}
