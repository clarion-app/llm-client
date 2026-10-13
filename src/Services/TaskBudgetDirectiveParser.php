<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\ValueObjects\StatedCeilings;

/**
 * Reads a "/budget steps=N spend=X" line at the start of a message. Only the
 * first line counts, the command and keys in any case, the keys in any order
 * and each at most once. A bare "/budget" asks for the task's standing; a
 * line with keys and nothing after it only changes the ceilings; a line with
 * keys followed by a request states the ceilings for that request. Anything
 * else starting with "/budget" is malformed and left untouched.
 */
class TaskBudgetDirectiveParser
{
    /** @return array{stated: ?StatedCeilings, remaining: string, directiveOnly: bool, viewOnly: bool, malformed: bool} */
    public function parse(string $content): array
    {
        $parts = preg_split('/\r?\n/', $content, 2);
        $first = trim($parts[0]);
        $rest = trim($parts[1] ?? '');
        $tokens = $first === '' ? [] : preg_split('/\s+/', $first);
        if ($tokens === [] || strtolower($tokens[0]) !== '/budget') {
            return $this->result(null, $content);
        }
        $pairs = CeilingDecisionParser::keyValuePairs(array_slice($tokens, 1));
        if ($pairs === []) {
            return $rest === '' ? $this->result(null, '', viewOnly: true) : $this->result(null, $content, malformed: true);
        }
        $allowance = $pairs === null ? null : CeilingDecisionParser::allowance($pairs);
        if ($allowance === null || $allowance['problem'] !== null) {
            return $this->result(null, $content, malformed: true);
        }
        $steps = $allowance['steps'] === null ? null : (int) $allowance['steps'];

        return $this->result(new StatedCeilings($steps, $allowance['spend']), $rest, directiveOnly: $rest === '');
    }

    private function result(?StatedCeilings $stated, string $remaining, bool $directiveOnly = false, bool $viewOnly = false, bool $malformed = false): array
    {
        return ['stated' => $stated, 'remaining' => $remaining, 'directiveOnly' => $directiveOnly, 'viewOnly' => $viewOnly, 'malformed' => $malformed];
    }
}
