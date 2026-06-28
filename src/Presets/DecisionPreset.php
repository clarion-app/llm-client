<?php

namespace ClarionApp\LlmClient\Presets;

use ClarionApp\LlmClient\Services\StructuredOutputPreset;

class DecisionPreset extends StructuredOutputPreset
{
    public function __construct()
    {
        parent::__construct(
            'decision',
            'A yes/no decision with supporting reasoning',
            [
                'type' => 'object',
                'properties' => [
                    'decision' => [
                        'type' => 'boolean',
                        'description' => 'The final decision: true for yes/approve, false for no/reject',
                    ],
                    'reasoning' => [
                        'type' => 'string',
                        'description' => 'A concise explanation of the reasoning behind the decision',
                    ],
                ],
                'required' => ['decision', 'reasoning'],
            ],
            "You are making a decision. Respond with a clear yes/no decision and brief reasoning. "
            . "Always output valid JSON matching the expected schema with 'decision' (boolean) and 'reasoning' (string) fields."
        );
    }
}
