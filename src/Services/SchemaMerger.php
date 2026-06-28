<?php

namespace ClarionApp\LlmClient\Services;

class SchemaMerger
{
    /**
     * Deep merge $overrides onto $base with JSON Schema awareness.
     *
     * - `properties` keys are merged recursively
     * - `required` arrays are unioned
     * - Scalar keys are replaced by overrides
     * - `null` values in overrides remove the corresponding key from base
     *
     * @param array $base Base schema
     * @param array $overrides Override schema
     * @return array Merged schema
     */
    public function merge(array $base, array $overrides): array
    {
        // If overrides is empty, return base as-is
        if (empty($overrides)) {
            return $base;
        }

        $result = $base;

        // Collect property names removed via null sentinel in overrides['properties']
        $removedPropertyNames = [];
        if (isset($overrides['properties']) && is_array($overrides['properties'])) {
            foreach ($overrides['properties'] as $propName => $propValue) {
                if ($propValue === null) {
                    $removedPropertyNames[] = $propName;
                }
            }
        }

        foreach ($overrides as $key => $value) {
            // Null sentinel: remove the key from base
            if ($value === null) {
                unset($result[$key]);
            }
            // Both are arrays: recursive merge
            elseif (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
                // Special handling for 'required' array — union instead of recursive merge
                if ($key === 'required') {
                    $result['required'] = array_values(array_unique(
                        array_merge($result['required'], $value)
                    ));
                } else {
                    $result[$key] = $this->merge($result[$key], $value);
                }
            }
            // Scalar override: replace
            else {
                $result[$key] = $value;
            }
        }

        // Clean up `required` array: remove entries for properties deleted via null sentinel
        if (isset($result['required']) && is_array($result['required']) && !empty($removedPropertyNames)) {
            $result['required'] = array_values(array_filter($result['required'], function ($prop) use ($removedPropertyNames) {
                return !in_array($prop, $removedPropertyNames);
            }));
        }

        return $result;
    }
}
