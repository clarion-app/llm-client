<?php

namespace ClarionApp\LlmClient\Presets;

use ClarionApp\LlmClient\Services\StructuredOutputPreset;

class CondensationPreset extends StructuredOutputPreset
{
    public function __construct()
    {
        parent::__construct(
            'condensation',
            'Structured condensation of a conversation chunk preserving decisions, constraints, and obligations',
            [
                'type' => 'object',
                'properties' => [
                    'decisions' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Key decisions made or agreed upon in this chunk',
                    ],
                    'constraints' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Constraints, limitations, or requirements identified',
                    ],
                    'open_questions' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Questions that remain unresolved after this chunk',
                    ],
                    'facts' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Factual information established in this chunk',
                    ],
                    'commitments' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Promises, action items, or commitments made',
                    ],
                    'context' => [
                        'type' => 'string',
                        'description' => 'Optional brief context or background for this chunk',
                    ],
                ],
                'required' => ['decisions', 'constraints', 'open_questions', 'facts', 'commitments'],
            ],
            "You are condensing a segment of a conversation. Extract the \"what and why\" of decisions and constraints, "
            . "any still-unresolved questions, factual information, and commitments made. "
            . "Omit conversational filler. Stay concise and structured — do not narrate. "
            . "Output valid JSON matching the expected schema with 'decisions', 'constraints', 'open_questions', "
            . "'facts', and 'commitments' (arrays of strings) fields, plus an optional 'context' string."
        );
    }
}
