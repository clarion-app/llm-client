<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\RoleTestResult;
use Throwable;

/**
 * Exercises the effective model for a role with a single bounded provider
 * call, read-only: it never mutates a RoleAssignment or a ServerStatus row.
 */
final class RoleTestRunner
{
    private const TIMEOUT_MS = 20000;

    public function __construct(
        private readonly RoleResolver $resolver,
        private readonly ProviderRegistry $providers,
    ) {}

    public function run(ModelRole $role, ?string $userId): RoleTestResult
    {
        $startedAt = microtime(true);
        $resolution = $this->resolver->resolve($role, $userId);

        $model = $resolution->model;
        $server = $resolution->server ? [
            'id' => $resolution->server->id,
            'name' => $resolution->server->name,
        ] : null;

        if ($role === ModelRole::Image) {
            return new RoleTestResult(
                $role,
                'not_testable',
                $model,
                $server,
                'Nothing currently consumes the image role yet — there is nothing to test.',
                $this->elapsedMs($startedAt),
            );
        }

        if (!$resolution->hasEffectiveModel()) {
            return new RoleTestResult(
                $role,
                'no_effective_model',
                $model,
                $server,
                "No effective model is assigned for {$role->value}.",
                $this->elapsedMs($startedAt),
            );
        }

        $provider = $this->providers->resolve($resolution->server);

        try {
            $message = match ($role) {
                ModelRole::Inference => $this->exerciseInference($provider),
                ModelRole::Embedding => $this->exerciseEmbedding($provider),
                ModelRole::Image => throw new \LogicException('unreachable'),
            };
        } catch (Throwable $e) {
            return new RoleTestResult(
                $role,
                'fail',
                $model,
                $server,
                $e->getMessage(),
                $this->elapsedMs($startedAt),
            );
        }

        return new RoleTestResult(
            $role,
            'pass',
            $model,
            $server,
            $message,
            $this->elapsedMs($startedAt),
        );
    }

    private function exerciseInference(\ClarionApp\LlmClient\Contracts\LlmProvider $provider): string
    {
        $result = $provider->chat(
            [['role' => 'user', 'content' => 'ping']],
            [],
            ['max_tokens' => 1, 'timeout_ms' => self::TIMEOUT_MS],
        );

        if (empty($result['choices'])) {
            throw new \RuntimeException('Provider returned no choices.');
        }

        return 'Chat completion succeeded.';
    }

    private function exerciseEmbedding(\ClarionApp\LlmClient\Contracts\LlmProvider $provider): string
    {
        $result = $provider->embed(
            ['clarion role test'],
            ['timeout_ms' => self::TIMEOUT_MS],
        );

        if (empty($result['embeddings'][0])) {
            throw new \RuntimeException('Provider returned no embedding.');
        }

        return 'Embedding request succeeded.';
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
