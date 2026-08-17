<?php

namespace ClarionApp\LlmClient\Services;

/**
 * Drops or replaces any element of a decoded execute_operation GET result
 * whose own `user_id` field does not match the requesting user
 * (Foundational, security-critical, FR-010/FR-011/FR-012).
 *
 * Applied to every execute_operation GET result, for every agent bound to
 * the conversation -- not gated by template name or operationId. It is a
 * no-op for any resource that already scopes itself correctly (no foreign
 * `user_id` ever appears in the response) and for any resource with no
 * per-user ownership concept in its schema at all (no `user_id` key
 * anywhere for the filter to ever match against) -- both are left exactly
 * as they arrive.
 *
 * `UrlValidator`/`PathContainment`-shaped: stateless, never throws,
 * degrades to returning the input unchanged for anything it cannot
 * interpret.
 */
final class OwnerScopedResultFilter
{
    /**
     * @param  mixed  $decoded  The already json_decode(..., true)'d body of
     *   an execute_operation GET result.
     * @param  string  $requestingUserId  The conversation's owning user id --
     *   the same identity executeApiCall() already resolves to mint the
     *   call's own bearer token.
     * @return mixed The same shape, with foreign-owned elements dropped
     *   (list) or replaced with a generic not-found shape (single object).
     */
    public function apply(mixed $decoded, string $requestingUserId): mixed
    {
        if (!is_array($decoded)) {
            return $decoded;
        }

        if (self::isList($decoded)) {
            return self::filterList($decoded, $requestingUserId);
        }

        // A "data"-wrapper envelope (the common Laravel API-resource
        // collection/single-resource shape): apply the same two rules to
        // the nested value only, one level deep, then reassign it back
        // under `data`. Sibling keys (links, meta, ...) are left untouched.
        if (array_key_exists('data', $decoded)) {
            $nested = $decoded['data'];
            if (is_array($nested)) {
                $decoded['data'] = self::isList($nested)
                    ? self::filterList($nested, $requestingUserId)
                    : self::filterObject($nested, $requestingUserId);
            }

            return $decoded;
        }

        return self::filterObject($decoded, $requestingUserId);
    }

    /**
     * @param  array<int, mixed>  $list
     * @return array<int, mixed>
     */
    private static function filterList(array $list, string $requestingUserId): array
    {
        $kept = [];
        foreach ($list as $element) {
            if (!is_array($element)) {
                $kept[] = $element;

                continue;
            }

            if (array_key_exists('user_id', $element) && (string) $element['user_id'] !== $requestingUserId) {
                continue;
            }

            $kept[] = $element;
        }

        return array_values($kept);
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private static function filterObject(array $object, string $requestingUserId): array
    {
        if (array_key_exists('user_id', $object) && (string) $object['user_id'] !== $requestingUserId) {
            // Replaced entirely with a generic, existence-revealing-nothing
            // shape -- structurally identical to how a genuinely-absent
            // resource is already reported elsewhere in this codebase.
            // Never a 403-shaped or ownership-worded message, which would
            // itself be the leak FR-011 forbids.
            return ['error' => 'Not found.'];
        }

        return $object;
    }

    /**
     * A "list" is a sequential, zero-indexed array -- the shape
     * json_decode(..., true) produces for a JSON array. An associative
     * array (a JSON object) is anything else.
     */
    private static function isList(array $value): bool
    {
        return array_is_list($value);
    }
}
