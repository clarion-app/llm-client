<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\EvalCase;
use ClarionApp\LlmClient\Models\EvalCaseVersion;
use ClarionApp\LlmClient\Models\EvalSuite;
use ClarionApp\LlmClient\ValueObjects\Expectation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only place `eval_cases`/`eval_case_versions` rows are ever written
 * (the SpendingCeilingService/ModelPriceService "sole write path" idiom).
 *
 * addCase() is this file's User Story 1 surface — editCase()/archive()/
 * versionsFor() are User Story 2's concern, added later.
 *
 * Every attribute is validated in full, before any write, following
 * SpendingCeilingService::validated()'s ordering: the first violation
 * found throws \InvalidArgumentException with a specific, one-sentence
 * reason, and a rejected call leaves the table byte for byte as it was.
 */
class EvalCaseService
{
    /**
     * Add a case to a suite: inserts exactly one eval_case_versions row
     * (version_number = 1) and one eval_cases row pointing at it, in one
     * transaction (C3).
     *
     * @param  array<int, array<string, mixed>>  $expectations
     *
     * @throws \InvalidArgumentException when given/expected_behavior or
     *   any expectation is invalid, including an empty $expectations
     *   array (FR-009) or an action_taken/action_not_taken with no named
     *   action (FR-010, via Expectation::validate()); no row is written
     *   in that case.
     */
    public function addCase(EvalSuite $suite, string $given, string $expectedBehavior, array $expectations): EvalCase
    {
        $given = $this->validatedText($given, 'given');
        $expectedBehavior = $this->validatedText($expectedBehavior, 'expected_behavior');
        $expectations = $this->validatedExpectations($expectations);

        return DB::transaction(function () use ($suite, $given, $expectedBehavior, $expectations) {
            $case = EvalCase::create([
                'suite_id' => $suite->id,
                'current_version_id' => null,
            ]);

            $version = EvalCaseVersion::create([
                'case_id' => $case->id,
                'version_number' => 1,
                'given' => $given,
                'expected_behavior' => $expectedBehavior,
                'expectations' => $expectations,
            ]);

            $case->current_version_id = $version->id;
            $case->save();

            return $case->fresh();
        });
    }

    /**
     * Edit a case: inserts exactly one *new* eval_case_versions row
     * (version_number = previous max + 1) and repoints
     * eval_cases.current_version_id at it, in one transaction (C4). This
     * method never issues an UPDATE or DELETE against any existing
     * eval_case_versions row — every previously written version stays
     * byte-identical, forever, which is the property FR-011/FR-012 exist
     * to keep true. The next version_number is derived from
     * MAX(version_number), never COUNT(*), so a version sequence with a
     * gap can never collide with or renumber an existing row.
     *
     * @param  array<int, array<string, mixed>>  $expectations
     *
     * @throws \InvalidArgumentException when given/expected_behavior or
     *   any expectation is invalid, identically to addCase(); no row is
     *   written and the case's current version is left unchanged in that
     *   case.
     */
    public function editCase(EvalCase $case, string $given, string $expectedBehavior, array $expectations): EvalCase
    {
        $given = $this->validatedText($given, 'given');
        $expectedBehavior = $this->validatedText($expectedBehavior, 'expected_behavior');
        $expectations = $this->validatedExpectations($expectations);

        return DB::transaction(function () use ($case, $given, $expectedBehavior, $expectations) {
            $nextVersionNumber = (int) EvalCaseVersion::where('case_id', $case->id)->max('version_number') + 1;

            $version = EvalCaseVersion::create([
                'case_id' => $case->id,
                'version_number' => $nextVersionNumber,
                'given' => $given,
                'expected_behavior' => $expectedBehavior,
                'expectations' => $expectations,
            ]);

            $case->current_version_id = $version->id;
            $case->save();

            return $case->fresh();
        });
    }

    /**
     * Archive (soft delete) a case identity row only — never cascades to
     * any eval_case_versions row it ever produced (C6, research.md D3/D6).
     * Every version remains resolvable by id afterward.
     */
    public function archive(EvalCase $case): void
    {
        $case->delete();
    }

    /**
     * Every version this case has ever had, oldest first — including
     * versions written before the case was archived. Accepts an
     * already-held in-memory EvalCase instance as well as a freshly
     * fetched one, since only the case's id is used.
     *
     * @return Collection<int, EvalCaseVersion>
     */
    public function versionsFor(EvalCase $case): Collection
    {
        return EvalCaseVersion::query()
            ->where('case_id', $case->id)
            ->orderBy('version_number')
            ->get();
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function validatedText(string $value, string $field): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new \InvalidArgumentException("A case requires a non-empty {$field}.");
        }

        $maxLength = (int) config('llm-client.eval_suites.max_text_length', 10000);

        if (mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException(
                "{$field} must not exceed {$maxLength} characters."
            );
        }

        return $value;
    }

    /**
     * Validates every entry (rejecting an empty array first, FR-009) via
     * the identical Expectation::validate() rule authoring and import both
     * use, and returns the array in the exact order submitted — no
     * reordering, no deduplication.
     *
     * @param  array<int, array<string, mixed>>  $expectations
     * @return array<int, array<string, mixed>>
     *
     * @throws \InvalidArgumentException
     */
    private function validatedExpectations(array $expectations): array
    {
        if (count($expectations) === 0) {
            throw new \InvalidArgumentException('A case requires at least one expectation.');
        }

        foreach ($expectations as $expectation) {
            if (!is_array($expectation)) {
                throw new \InvalidArgumentException('Each expectation must be an object.');
            }

            Expectation::validate($expectation);
        }

        return $expectations;
    }
}
