<?php

namespace ClarionApp\LlmClient\Presets;

use ClarionApp\LlmClient\Services\StructuredOutputPreset;

class ExtractionPreset extends StructuredOutputPreset
{
    public function __construct()
    {
        parent::__construct(
            'extraction',
            'Parameterized field extraction from unstructured text',
            function (?array $params = null): array {
                $fields = $params['fields'] ?? [];
                $properties = [];
                $required = [];

                foreach ($fields as $name => $type) {
                    $properties[$name] = [
                        'type' => is_array($type) ? $type['type'] : $type,
                        'description' => is_array($type) ? ($type['description'] ?? 'Extracted field ' . $name) : ('Extracted field: ' . $name),
                    ];
                    $required[] = $name;
                }

                return [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $required,
                ];
            },
            "You are extracting specific fields from unstructured text. "
            . "Identify and extract each requested field, returning valid JSON with the extracted values."
        );
    }
}
