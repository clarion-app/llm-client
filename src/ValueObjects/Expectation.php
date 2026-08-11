<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * One entry of a case's `expectations` array — the one place FR-004,
 * FR-009, and FR-010's shape rules for a single expectation live. No
 * separate, looser rule set exists anywhere else in this feature:
 * EvalCaseService (authoring) and EvalSuiteImporter (import) both call
 * validate() directly, so a case an operator could create by hand is
 * exactly the set of cases an import can recreate (data-model.md §4).
 *
 * Rejection follows the SpendingCeilingService::validated() ordering this
 * package already established elsewhere: the first violation found throws
 * \InvalidArgumentException with a specific, one-sentence reason, and
 * nothing is constructed on a rejected shape.
 */
final class Expectation
{
    private function __construct(
        public readonly ExpectationKind $kind,
        public readonly ?string $expectedText = null,
        public readonly ?string $expectedInfo = null,
        public readonly ?string $action = null,
        public readonly ?string $note = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws \InvalidArgumentException when the shape is invalid; nothing
     *   is constructed in that case.
     */
    public static function fromArray(array $data): self
    {
        self::validate($data);

        $kind = ExpectationKind::from($data['kind']);

        return match ($kind) {
            ExpectationKind::TextMatch => new self(
                kind: $kind,
                expectedText: trim((string) $data['expected_text']),
            ),
            ExpectationKind::InformationPresent => new self(
                kind: $kind,
                expectedInfo: trim((string) $data['expected_info']),
            ),
            ExpectationKind::ActionTaken, ExpectationKind::ActionNotTaken => new self(
                kind: $kind,
                action: trim((string) $data['action']),
            ),
            ExpectationKind::HumanJudgment => new self(
                kind: $kind,
                note: array_key_exists('note', $data) && $data['note'] !== null
                    ? (string) $data['note']
                    : null,
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return match ($this->kind) {
            ExpectationKind::TextMatch => [
                'kind' => $this->kind->value,
                'expected_text' => $this->expectedText,
            ],
            ExpectationKind::InformationPresent => [
                'kind' => $this->kind->value,
                'expected_info' => $this->expectedInfo,
            ],
            ExpectationKind::ActionTaken, ExpectationKind::ActionNotTaken => [
                'kind' => $this->kind->value,
                'action' => $this->action,
            ],
            ExpectationKind::HumanJudgment => $this->note === null
                ? ['kind' => $this->kind->value]
                : ['kind' => $this->kind->value, 'note' => $this->note],
        };
    }

    /**
     * Validate a raw expectation array without constructing anything.
     * Called directly by EvalCaseService (authoring) and
     * EvalSuiteImporter (import) — the identical rule, both callers.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws \InvalidArgumentException on the first violation found.
     */
    public static function validate(array $data): void
    {
        if (!isset($data['kind']) || !is_string($data['kind'])) {
            throw new \InvalidArgumentException('An expectation must have a "kind".');
        }

        $kind = ExpectationKind::tryFrom($data['kind']);

        if ($kind === null) {
            throw new \InvalidArgumentException(sprintf(
                'Unrecognized expectation kind "%s".',
                $data['kind'],
            ));
        }

        match ($kind) {
            ExpectationKind::TextMatch => self::requireNonEmptyField($data, 'expected_text'),
            ExpectationKind::InformationPresent => self::requireNonEmptyField($data, 'expected_info'),
            ExpectationKind::ActionTaken, ExpectationKind::ActionNotTaken => self::requireNonEmptyField($data, 'action'),
            ExpectationKind::HumanJudgment => self::validateOptionalField($data, 'note'),
        };
    }

    private static function requireNonEmptyField(array $data, string $field): void
    {
        if (!isset($data[$field]) || !is_string($data[$field]) || trim($data[$field]) === '') {
            throw new \InvalidArgumentException(sprintf(
                'An expectation of kind "%s" requires a non-empty "%s".',
                $data['kind'],
                $field,
            ));
        }

        self::assertWithinMaxLength($data[$field], $field);
    }

    /**
     * human_judgment's "note" is the only genuinely optional field in this
     * shape — it may be omitted or empty, but if present it is still
     * length-bounded like every other free-text field.
     */
    private static function validateOptionalField(array $data, string $field): void
    {
        if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
            return;
        }

        if (!is_string($data[$field])) {
            throw new \InvalidArgumentException(sprintf('"%s" must be a string when present.', $field));
        }

        self::assertWithinMaxLength($data[$field], $field);
    }

    private static function assertWithinMaxLength(string $value, string $field): void
    {
        $maxLength = (int) config('llm-client.eval_suites.max_text_length', 10000);

        if (mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" must not exceed %d characters.',
                $field,
                $maxLength,
            ));
        }
    }
}
