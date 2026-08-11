<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\EvalSuite;
use Illuminate\Support\Collection;

/**
 * The only place an `eval_suites` row is ever written — create, list, and
 * find alike. Nothing else in this package creates, updates, or deletes
 * one (the SpendingCeilingService/ModelPriceService "sole write path"
 * idiom).
 *
 * Uniqueness of (agent_identifier, name) among live suites is a property
 * of this class alone, upheld in code rather than by a database
 * constraint: `eval_suites` carries only a plain index on the pair, for
 * the identical SoftDeletes-vs-unique-constraint reason
 * spending_ceilings/model_prices use a plain index (research.md D7).
 *
 * Invalid input is rejected with \InvalidArgumentException, in full,
 * before any row is touched — the SpendingCeilingService::validated()
 * ordering. Mapping a rejection to an HTTP 422 is the controller's job,
 * not this class's.
 */
class EvalSuiteService
{
    /**
     * Create a new suite. Rejects a (agent_identifier, name) pair already
     * held by a live suite before writing anything (C1, research.md D7).
     *
     * @throws \InvalidArgumentException when name/agent_identifier are
     *   invalid, or the pair collides with a live suite; no row is
     *   written in that case.
     */
    public function create(string $name, string $agentIdentifier): EvalSuite
    {
        $name = $this->validatedName($name);
        $agentIdentifier = $this->validatedAgentIdentifier($agentIdentifier);

        $this->assertPairAvailable($agentIdentifier, $name);

        return EvalSuite::create([
            'name' => $name,
            'agent_identifier' => $agentIdentifier,
        ]);
    }

    /**
     * Every live suite.
     *
     * @return Collection<int, EvalSuite>
     */
    public function list(): Collection
    {
        return EvalSuite::query()->orderBy('created_at')->get();
    }

    /**
     * A live suite by id, or null for an unknown or archived one — an
     * archived suite is "not found" through this method (contracts §5).
     */
    public function find(string $id): ?EvalSuite
    {
        return EvalSuite::query()->find($id);
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function validatedName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new \InvalidArgumentException('A suite requires a non-empty name.');
        }

        $maxLength = (int) config('llm-client.eval_suites.max_identifier_length', 255);

        if (mb_strlen($name) > $maxLength) {
            throw new \InvalidArgumentException(
                "name must not exceed {$maxLength} characters."
            );
        }

        return $name;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function validatedAgentIdentifier(string $agentIdentifier): string
    {
        $agentIdentifier = trim($agentIdentifier);

        if ($agentIdentifier === '') {
            throw new \InvalidArgumentException('A suite requires a non-empty agent_identifier.');
        }

        $maxLength = (int) config('llm-client.eval_suites.max_identifier_length', 255);

        if (mb_strlen($agentIdentifier) > $maxLength) {
            throw new \InvalidArgumentException(
                "agent_identifier must not exceed {$maxLength} characters."
            );
        }

        return $agentIdentifier;
    }

    /**
     * @throws \InvalidArgumentException when a live suite already occupies
     *   this (agent_identifier, name) pair.
     */
    private function assertPairAvailable(string $agentIdentifier, string $name, ?string $excludingId = null): void
    {
        $exists = EvalSuite::query()
            ->where('agent_identifier', $agentIdentifier)
            ->where('name', $name)
            ->when($excludingId !== null, fn ($query) => $query->where('id', '!=', $excludingId))
            ->exists();

        if ($exists) {
            throw new \InvalidArgumentException(
                "A suite named '{$name}' already exists for agent '{$agentIdentifier}'."
            );
        }
    }
}
