<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\RoleResolution;
use ClarionApp\LlmClient\ValueObjects\RoleResolutionStatus;

final class RoleResolver
{
    public function __construct(
        private readonly ?ProviderRegistry $providers = null,
    ) {}

    public function resolve(ModelRole $role, ?string $userId): RoleResolution
    {
        $assignment = null;
        $scope = null;

        if ($userId !== null) {
            $assignment = RoleAssignment::where('role', $role->value)
                ->where('user_id', $userId)
                ->first();

            if ($assignment) {
                $scope = 'user';
            }
        }

        if (!$assignment) {
            $assignment = RoleAssignment::where('role', $role->value)
                ->where('user_id', RoleAssignment::INSTALLATION_SCOPE_ID)
                ->first();

            if ($assignment) {
                $scope = 'installation';
            }
        }

        if (!$assignment) {
            return RoleResolution::unassigned($role);
        }

        $brokenReason = $this->isBroken($assignment);
        if ($brokenReason) {
            return RoleResolution::broken($role, $scope ?? 'installation', $assignment->model, $brokenReason);
        }

        $server = Server::find($assignment->server_id);

        return RoleResolution::resolved(
            $role,
            $scope ?? 'installation',
            $server,
            $assignment->model,
        );
    }

    /**
     * Check if an assignment is broken. Returns a reason string if broken, null if not.
     */
    private function isBroken(RoleAssignment $assignment): ?string
    {
        $server = Server::withTrashed()->find($assignment->server_id);
        if ($server === null || $server->trashed()) {
            return 'server deleted';
        }

        $languageModel = LanguageModel::withTrashed()
            ->where('server_id', $assignment->server_id)
            ->where('name', $assignment->model)
            ->first();

        if ($languageModel !== null && $languageModel->trashed()) {
            return 'model removed';
        }

        return null;
    }
}
