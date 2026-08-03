<?php

namespace ClarionApp\LlmClient\ValueObjects;

use ClarionApp\LlmClient\Models\Server;

final class RoleResolution
{
    private function __construct(
        public readonly ModelRole $role,
        public readonly RoleResolutionStatus $status,
        public readonly ?string $scope,
        public readonly ?Server $server = null,
        public readonly ?string $model = null,
        public readonly ?string $brokenReason = null,
    ) {}

    public static function resolved(ModelRole $role, string $scope, Server $server, string $model): self
    {
        return new self($role, RoleResolutionStatus::Resolved, $scope, $server, $model);
    }

    public static function unassigned(ModelRole $role): self
    {
        return new self($role, RoleResolutionStatus::Unassigned, null);
    }

    public static function broken(ModelRole $role, string $scope, string $model, string $reason): self
    {
        return new self($role, RoleResolutionStatus::Broken, $scope, null, $model, $reason);
    }

    public function hasEffectiveModel(): bool
    {
        return $this->status === RoleResolutionStatus::Resolved;
    }
}
