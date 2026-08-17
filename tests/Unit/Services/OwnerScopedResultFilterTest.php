<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Services\OwnerScopedResultFilter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for OwnerScopedResultFilter::apply() (Foundational,
 * security-critical, FR-010/FR-011/FR-012) — the full adversarial decision
 * table: a mismatched user_id in a JSON list (dropped, re-indexed), a
 * mismatched user_id in a single JSON object (replaced with a generic
 * not-found shape, never a differently-worded ownership message), a
 * mismatched user_id nested under a top-level "data" envelope key (the
 * same two rules applied to the nested value, re-wrapped, sibling keys
 * left untouched), and every passthrough case (matching user_id kept, no
 * user_id key at all passed through unchanged, non-JSON/scalar/null
 * passed through unchanged).
 *
 * Written entirely against the not-yet-created class — every case here
 * must fail with a "class not found" error until OwnerScopedResultFilter
 * exists.
 */
class OwnerScopedResultFilterTest extends TestCase
{
    private const REQUESTING_USER = 'user-a';

    private const OTHER_USER = 'user-b';

    private function filter(): OwnerScopedResultFilter
    {
        return new OwnerScopedResultFilter();
    }

    // ---------------------------------------------------------------
    // List of objects: drop mismatched rows, keep rows with no user_id,
    // re-index
    // ---------------------------------------------------------------

    #[Test]
    public function a_list_drops_rows_owned_by_a_different_user_and_reindexes(): void
    {
        $decoded = [
            ['id' => 1, 'user_id' => self::REQUESTING_USER, 'name' => 'mine'],
            ['id' => 2, 'user_id' => self::OTHER_USER, 'name' => 'not mine'],
            ['id' => 3, 'user_id' => self::REQUESTING_USER, 'name' => 'also mine'],
        ];

        $result = $this->filter()->apply($decoded, self::REQUESTING_USER);

        $this->assertSame([
            ['id' => 1, 'user_id' => self::REQUESTING_USER, 'name' => 'mine'],
            ['id' => 3, 'user_id' => self::REQUESTING_USER, 'name' => 'also mine'],
        ], $result, 'the foreign-owned row must be dropped and the list re-indexed');

        $this->assertSame([0, 1], array_keys($result), 'the result must be array_values()-re-indexed, not sparse');
    }

    #[Test]
    public function a_list_row_with_no_user_id_key_is_kept_unchanged(): void
    {
        $decoded = [
            ['id' => 1, 'user_id' => self::OTHER_USER, 'name' => 'not mine'],
            ['id' => 2, 'name' => 'shared, no owner concept'],
        ];

        $result = $this->filter()->apply($decoded, self::REQUESTING_USER);

        $this->assertSame([
            ['id' => 2, 'name' => 'shared, no owner concept'],
        ], $result);
    }

    // ---------------------------------------------------------------
    // Single object: mismatched user_id -> generic not-found shape
    // ---------------------------------------------------------------

    #[Test]
    public function a_single_object_with_a_mismatched_user_id_is_replaced_with_a_generic_not_found_shape(): void
    {
        $decoded = ['id' => 42, 'user_id' => self::OTHER_USER, 'name' => 'not mine', 'balance' => 1000];

        $result = $this->filter()->apply($decoded, self::REQUESTING_USER);

        $this->assertSame(['error' => 'Not found.'], $result);
    }

    #[Test]
    public function the_not_found_replacement_never_reveals_existence_via_a_differently_worded_or_ownership_message(): void
    {
        $decoded = ['id' => 42, 'user_id' => self::OTHER_USER, 'name' => 'not mine'];

        $result = $this->filter()->apply($decoded, self::REQUESTING_USER);

        $this->assertArrayNotHasKey('name', $result);
        $this->assertArrayNotHasKey('id', $result);
        $encoded = json_encode($result);
        $this->assertStringNotContainsStringIgnoringCase('forbidden', $encoded);
        $this->assertStringNotContainsStringIgnoringCase('permission', $encoded);
        $this->assertStringNotContainsStringIgnoringCase('owner', $encoded);
        $this->assertStringNotContainsStringIgnoringCase('403', $encoded);
    }

    // ---------------------------------------------------------------
    // Single object: matching user_id, or no user_id at all -> unchanged
    // ---------------------------------------------------------------

    #[Test]
    public function a_single_object_with_a_matching_user_id_is_left_completely_unchanged(): void
    {
        $decoded = ['id' => 42, 'user_id' => self::REQUESTING_USER, 'name' => 'mine', 'balance' => 1000];

        $result = $this->filter()->apply($decoded, self::REQUESTING_USER);

        $this->assertSame($decoded, $result);
    }

    #[Test]
    public function a_single_object_with_no_user_id_key_at_all_is_left_completely_unchanged(): void
    {
        $decoded = ['id' => 7, 'title' => 'shared list', 'items' => ['a', 'b']];

        $result = $this->filter()->apply($decoded, self::REQUESTING_USER);

        $this->assertSame($decoded, $result);
    }

    // ---------------------------------------------------------------
    // data-wrapper envelope: apply the same two rules to the nested
    // value, re-wrap, sibling keys untouched
    // ---------------------------------------------------------------

    #[Test]
    public function a_data_wrapped_list_has_the_list_rule_applied_to_the_nested_value_with_siblings_untouched(): void
    {
        $decoded = [
            'data' => [
                ['id' => 1, 'user_id' => self::REQUESTING_USER],
                ['id' => 2, 'user_id' => self::OTHER_USER],
            ],
            'links' => ['self' => '/api/contacts?page=1'],
            'meta' => ['total' => 2],
        ];

        $result = $this->filter()->apply($decoded, self::REQUESTING_USER);

        $this->assertSame([
            ['id' => 1, 'user_id' => self::REQUESTING_USER],
        ], $result['data']);
        $this->assertSame(['self' => '/api/contacts?page=1'], $result['links'], 'sibling keys must be left untouched');
        $this->assertSame(['total' => 2], $result['meta'], 'sibling keys must be left untouched');
    }

    #[Test]
    public function a_data_wrapped_single_object_has_the_object_rule_applied_to_the_nested_value_with_siblings_untouched(): void
    {
        $decoded = [
            'data' => ['id' => 42, 'user_id' => self::OTHER_USER, 'name' => 'not mine'],
            'meta' => ['requested_at' => '2026-08-17'],
        ];

        $result = $this->filter()->apply($decoded, self::REQUESTING_USER);

        $this->assertSame(['error' => 'Not found.'], $result['data']);
        $this->assertSame(['requested_at' => '2026-08-17'], $result['meta']);
    }

    // ---------------------------------------------------------------
    // Passthrough cases
    // ---------------------------------------------------------------

    #[Test]
    public function null_passes_through_unchanged(): void
    {
        $this->assertNull($this->filter()->apply(null, self::REQUESTING_USER));
    }

    #[Test]
    public function a_bare_scalar_passes_through_unchanged(): void
    {
        $this->assertSame('just a string', $this->filter()->apply('just a string', self::REQUESTING_USER));
        $this->assertSame(42, $this->filter()->apply(42, self::REQUESTING_USER));
        $this->assertSame(true, $this->filter()->apply(true, self::REQUESTING_USER));
    }

    #[Test]
    public function a_list_of_scalars_passes_through_unchanged(): void
    {
        $decoded = [1, 2, 3, 'four'];

        $this->assertSame($decoded, $this->filter()->apply($decoded, self::REQUESTING_USER));
    }

    #[Test]
    public function an_associative_array_with_neither_user_id_nor_data_reachable_passes_through_unchanged(): void
    {
        $decoded = ['title' => 'gtd project', 'items' => ['step 1', 'step 2']];

        $this->assertSame($decoded, $this->filter()->apply($decoded, self::REQUESTING_USER));
    }

    #[Test]
    public function it_never_throws_on_input_it_cannot_interpret(): void
    {
        $this->assertSame([], $this->filter()->apply([], self::REQUESTING_USER));

        // A nested "data" key whose value is itself a scalar -- not a
        // list/object -- must not blow up the nested-rule application.
        $decoded = ['data' => 'not a list or object'];
        $this->assertSame($decoded, $this->filter()->apply($decoded, self::REQUESTING_USER));
    }
}
