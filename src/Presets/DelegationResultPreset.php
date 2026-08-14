<?php

namespace ClarionApp\LlmClient\Presets;

use ClarionApp\LlmClient\Services\StructuredOutputPreset;

class DelegationResultPreset extends StructuredOutputPreset
{
    public function __construct()
    {
        parent::__construct(
            'delegation_result',
            'The structured outcome a delegated helper must return (099-result-aggregation)',
            [
                'type' => 'object',
                'properties' => [
                    'status' => [
                        'type' => 'string',
                        'enum' => ['success', 'partial', 'failure'],
                    ],
                    'summary' => [
                        'type' => 'string',
                        'description' => 'What was accomplished.',
                    ],
                    'output' => [
                        'type' => 'object',
                        'description' => 'The concrete result produced, as named fields. Empty object if the task legitimately produced nothing (e.g. a verification-only task).',
                    ],
                    'undone' => [
                        'type' => 'string',
                        'description' => "What remains, if anything. Empty string ('') if nothing is left undone — never omit this field.",
                    ],
                ],
                'required' => ['status', 'summary', 'output', 'undone'],
            ],
            "You are completing a delegated task. When you have your final answer, "
            . "respond with JSON matching: status ('success' if you finished everything, "
            . "'partial' if you finished some but not all of it, 'failure' if you could not "
            . "complete it), summary (what you accomplished), output (an object of the "
            . "concrete results you produced — {} if the task legitimately produced nothing "
            . "to report), and undone (what is left, as an empty string '' if nothing is). "
            . "If you are missing information you need, set status to 'failure' and say so "
            . "plainly in summary rather than guessing."
        );
    }
}
