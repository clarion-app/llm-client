<?php

namespace ClarionApp\LlmClient\Presets;

use ClarionApp\LlmClient\Services\StructuredOutputPreset;

class SummaryPreset extends StructuredOutputPreset
{
    public function __construct()
    {
        parent::__construct(
            'summary',
            'A concise summary with key points extracted from text',
            [
                'type' => 'object',
                'properties' => [
                    'summary' => [
                        'type' => 'string',
                        'description' => 'A concise summary of the content',
                    ],
                    'key_points' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                        ],
                        'description' => 'Key points extracted from the content',
                    ],
                ],
                'required' => ['summary', 'key_points'],
            ],
            "You are summarizing content. Provide a concise summary and extract the key points. "
            . "Always output valid JSON matching the expected schema with 'summary' (string) and 'key_points' (array of strings) fields."
        );
    }
}
