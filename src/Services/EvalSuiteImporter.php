<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\NameConflictException;
use ClarionApp\LlmClient\Models\EvalSuite;
use ClarionApp\LlmClient\ValueObjects\Expectation;
use Illuminate\Support\Facades\DB;

/**
 * Imports a previously exported document (data-model.md §6) as a brand new
 * suite, either on a clean installation or under an explicit
 * name_override/agent_identifier_override that resolves a naming conflict
 * (research.md D8).
 *
 * The entire document is validated structurally — schema_version, required
 * top-level keys, every case, every expectation (via the identical
 * Expectation::validate() authoring already uses, C8) and every bounded
 * count/length (research.md D9) — before EvalSuiteService::create() or
 * EvalCaseService::addCase() is called even once (C7). The writes
 * themselves are then wrapped in one DB::transaction() as a second line of
 * defense, matching SpendingCeilingService::upsert()'s "validated in full
 * before the transaction opens" discipline. On any validation failure, not
 * one row of eval_suites/eval_cases/eval_case_versions is written.
 *
 * A naming conflict against a live suite (checked against the effective
 * override pair, if given, else the document's own pair) is raised as a
 * distinct NameConflictException — never \InvalidArgumentException — so
 * the controller can tell "your file is malformed" apart from "your file
 * is fine, but that name is taken" (C9).
 */
class EvalSuiteImporter
{
    private EvalSuiteService $suiteService;
    private EvalCaseService $caseService;

    public function __construct()
    {
        $this->suiteService = new EvalSuiteService();
        $this->caseService = new EvalCaseService();
    }

    /**
     * @param  array<string, mixed>  $document
     *
     * @throws \InvalidArgumentException when the document is malformed or
     *   out of bounds; no row is written in that case.
     * @throws NameConflictException when the effective (agent_identifier,
     *   name) pair is already held by a live suite; no row is written in
     *   that case either.
     */
    public function import(array $document, ?string $nameOverride, ?string $agentIdentifierOverride): EvalSuite
    {
        $this->validateDocument($document);

        $effectiveName = $this->assertValidIdentifier($nameOverride ?? (string) $document['name'], 'name');
        $effectiveAgentIdentifier = $this->assertValidIdentifier(
            $agentIdentifierOverride ?? (string) $document['agent_identifier'],
            'agent_identifier',
        );

        $this->assertNameAvailable($effectiveAgentIdentifier, $effectiveName);

        return DB::transaction(function () use ($document, $effectiveName, $effectiveAgentIdentifier) {
            $suite = $this->suiteService->create($effectiveName, $effectiveAgentIdentifier);

            foreach ($document['cases'] as $caseData) {
                $this->caseService->addCase(
                    $suite,
                    (string) $caseData['given'],
                    (string) $caseData['expected_behavior'],
                    $caseData['expectations'],
                );
            }

            return $suite->fresh();
        });
    }

    /**
     * @throws \InvalidArgumentException on the first violation found.
     */
    private function validateDocument(array $document): void
    {
        $this->assertSupportedSchemaVersion($document);

        if (!isset($document['name']) || !is_string($document['name'])) {
            throw new \InvalidArgumentException('A document must have a string "name".');
        }
        $this->assertValidIdentifier($document['name'], 'name');

        if (!isset($document['agent_identifier']) || !is_string($document['agent_identifier'])) {
            throw new \InvalidArgumentException('A document must have a string "agent_identifier".');
        }
        $this->assertValidIdentifier($document['agent_identifier'], 'agent_identifier');

        if (!isset($document['cases']) || !is_array($document['cases'])) {
            throw new \InvalidArgumentException('A document must have a "cases" array.');
        }

        $maxCases = (int) config('llm-client.eval_suites.max_cases_per_suite', 200);

        if (count($document['cases']) > $maxCases) {
            throw new \InvalidArgumentException("cases must not exceed {$maxCases} entries.");
        }

        $maxExpectations = (int) config('llm-client.eval_suites.max_expectations_per_case', 20);

        foreach ($document['cases'] as $case) {
            $this->assertValidCase($case, $maxExpectations);
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function assertSupportedSchemaVersion(array $document): void
    {
        if (!array_key_exists('schema_version', $document)) {
            throw new \InvalidArgumentException('A document must have a "schema_version".');
        }

        $supported = (array) config('llm-client.eval_suites.supported_export_schema_versions', [1]);

        if (!in_array($document['schema_version'], $supported, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported schema_version "%s".',
                is_scalar($document['schema_version']) ? (string) $document['schema_version'] : gettype($document['schema_version']),
            ));
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function assertValidCase(mixed $case, int $maxExpectations): void
    {
        if (!is_array($case)) {
            throw new \InvalidArgumentException('Each case must be an object.');
        }

        $this->assertValidCaseText($case, 'given');
        $this->assertValidCaseText($case, 'expected_behavior');

        if (!isset($case['expectations']) || !is_array($case['expectations'])) {
            throw new \InvalidArgumentException('A case must have an "expectations" array.');
        }

        if (count($case['expectations']) === 0) {
            throw new \InvalidArgumentException('A case requires at least one expectation.');
        }

        if (count($case['expectations']) > $maxExpectations) {
            throw new \InvalidArgumentException("expectations must not exceed {$maxExpectations} entries.");
        }

        foreach ($case['expectations'] as $expectation) {
            if (!is_array($expectation)) {
                throw new \InvalidArgumentException('Each expectation must be an object.');
            }

            // The identical rule authoring-time EvalCaseService uses —
            // never a separate, looser import rule set (C8).
            Expectation::validate($expectation);
        }
    }

    /**
     * @param  array<string, mixed>  $case
     *
     * @throws \InvalidArgumentException
     */
    private function assertValidCaseText(array $case, string $field): void
    {
        if (!isset($case[$field]) || !is_string($case[$field]) || trim($case[$field]) === '') {
            throw new \InvalidArgumentException("A case requires a non-empty \"{$field}\".");
        }

        $maxLength = (int) config('llm-client.eval_suites.max_text_length', 10000);

        if (mb_strlen($case[$field]) > $maxLength) {
            throw new \InvalidArgumentException("{$field} must not exceed {$maxLength} characters.");
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function assertValidIdentifier(string $value, string $field): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new \InvalidArgumentException("A document requires a non-empty \"{$field}\".");
        }

        $maxLength = (int) config('llm-client.eval_suites.max_identifier_length', 255);

        if (mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException("{$field} must not exceed {$maxLength} characters.");
        }

        return $value;
    }

    /**
     * @throws NameConflictException when a live suite already occupies
     *   this (agent_identifier, name) pair.
     */
    private function assertNameAvailable(string $agentIdentifier, string $name): void
    {
        $exists = EvalSuite::query()
            ->where('agent_identifier', $agentIdentifier)
            ->where('name', $name)
            ->exists();

        if ($exists) {
            throw new NameConflictException($name, $agentIdentifier);
        }
    }
}
