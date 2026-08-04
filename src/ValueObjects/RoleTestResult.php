<?php

namespace ClarionApp\LlmClient\ValueObjects;

final class RoleTestResult
{
    public function __construct(
        public readonly ModelRole $role,
        public readonly string $outcome,
        public readonly ?string $model,
        public readonly ?array $server,
        public readonly ?string $message,
        public readonly int $duration_ms,
    ) {}

    /**
     * @return array{
     *     role: string,
     *     outcome: string,
     *     model: ?string,
     *     server: ?array{id: string, name: string},
     *     message: ?string,
     *     duration_ms: int,
     * }
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role->value,
            'outcome' => $this->outcome,
            'model' => $this->model,
            'server' => $this->server,
            'message' => $this->message,
            'duration_ms' => $this->duration_ms,
        ];
    }
}
