<?php

namespace Tests\RealDatabase\Support;

use Illuminate\Support\Facades\DB;

/**
 * T021: Known set of rows for operation_search_index, seeded directly.
 *
 * Every term a query depends on is ≥ 3 characters and not an InnoDB stopword.
 * Expected orderings are separated by clear relevance margins.
 */
class OperationIndexFixture
{
    /**
     * Operation entries to seed. Each entry has the columns that
     * operation_search_index expects.
     */
    public static function entries(): array
    {
        return [
            [
                'type' => 'operation',
                'operation_id' => 'wizlights.toggle_power',
                'package_name' => '@clarion-app/wizlights',
                'summary' => 'Toggle the power state of a smart light',
                'method' => 'POST',
                'path' => '/api/wizlights/toggle',
                'searchable_text' => 'Toggle the power state of a smart light POST /api/wizlights/toggle deviceId light brightness',
                'param_schema' => json_encode([
                    'path' => ['deviceId' => ['type' => 'string', 'in' => 'path', 'required' => true]],
                ]),
                'prompt_content' => null,
            ],
            [
                'type' => 'operation',
                'operation_id' => 'wizlights.set_brightness',
                'package_name' => '@clarion-app/wizlights',
                'summary' => 'Set the brightness level of a smart light',
                'method' => 'PUT',
                'path' => '/api/wizlights/brightness',
                'searchable_text' => 'Set the brightness level of a smart light PUT /api/wizlights/brightness deviceId brightness percentage',
                'param_schema' => json_encode([
                    'path' => ['deviceId' => ['type' => 'string', 'in' => 'path', 'required' => true]],
                    'query' => ['brightness' => ['type' => 'integer', 'in' => 'query', 'required' => true]],
                ]),
                'prompt_content' => null,
            ],
            [
                'type' => 'operation',
                'operation_id' => 'contacts.store',
                'package_name' => '@clarion-app/contacts',
                'summary' => 'Create a new contact with name and phone number',
                'method' => 'POST',
                'path' => '/api/contacts',
                'searchable_text' => 'Create a new contact with name and phone number POST /api/contacts name phone number email address',
                'param_schema' => json_encode([
                    'body' => [
                        'name' => ['type' => 'string', 'in' => 'body', 'required' => true],
                        'phone' => ['type' => 'string', 'in' => 'body', 'required' => false],
                    ],
                ]),
                'prompt_content' => null,
            ],
            [
                'type' => 'operation',
                'operation_id' => 'contacts.search',
                'package_name' => '@clarion-app/contacts',
                'summary' => 'Search contacts by name or phone number',
                'method' => 'GET',
                'path' => '/api/contacts/search',
                'searchable_text' => 'Search contacts by name or phone number GET /api/contacts/search name phone query page limit',
                'param_schema' => json_encode([
                    'query' => [
                        'query' => ['type' => 'string', 'in' => 'query', 'required' => true],
                        'page' => ['type' => 'integer', 'in' => 'query', 'required' => false],
                    ],
                ]),
                'prompt_content' => null,
            ],
            [
                'type' => 'prompt',
                'operation_id' => 'wizlights_scene_evening',
                'package_name' => '@clarion-app/wizlights',
                'summary' => 'wizlights: evening scene',
                'method' => null,
                'path' => null,
                'searchable_text' => 'Dim all lights to a warm evening atmosphere with soft amber colours and reduced brightness levels for relaxation',
                'param_schema' => null,
                'prompt_content' => 'Dim all lights to a warm evening atmosphere with soft amber colours and reduced brightness levels for relaxation',
            ],
        ];
    }

    /**
     * Queries mapping query string → expected operation_id order (best first).
     *
     * These are designed so that the expected order is separated by clear
     * relevance margins rather than by ties.
     */
    public static function queries(): array
    {
        return [
            // "toggle light power" — toggle_power has all three terms in searchable_text
            // set_brightness has "light" but not "toggle" or "power"
            'toggle light power' => [
                'wizlights.toggle_power',
                'wizlights.set_brightness',
            ],
            // "create contact" — contacts.store has both "create" and "contact"
            // contacts.search has "contacts" but not "create" — won't match
            'create contact' => [
                'contacts.store',
            ],
            // "set brightness" — set_brightness has "Set" and "brightness",
            // toggle_power has "brightness" in searchable_text,
            // wizlights_scene_evening mentions "brightness" ("reduced brightness levels")
            'set brightness' => [
                'wizlights.set_brightness',
                'wizlights.toggle_power',
                'wizlights_scene_evening',
            ],
            // "search contacts" — contacts.search has both "search" and "contacts"
            // contacts.store has "contacts" but not "search"
            'search contacts' => [
                'contacts.search',
                'contacts.store',
            ],
            // "evening" — only the prompt entry has "evening"
            'evening atmosphere' => [
                'wizlights_scene_evening',
            ],
            // "prompt" — queries the type column; the prompt entry has type = 'prompt'
            'prompt' => [
                'wizlights_scene_evening',
            ],
        ];
    }

    /**
     * A query that matches nothing in the fixture.
     * "plumbing" is not present in any searchable_text or type column.
     */
    public static function nonMatchingQuery(): string
    {
        return 'plumbing repair service';
    }

    /**
     * Revision of the contacts.store entry for the re-index scenario (T029).
     * Changes the searchable_text to include "email" prominently.
     */
    public static function revision(): array
    {
        return [
            'operation_id' => 'contacts.store',
            'summary' => 'Create a new contact with name, phone number, and email',
            'searchable_text' => 'Create a new contact with name, phone number, and email address POST /api/contacts name phone number email address postal zip code',
            'param_schema' => json_encode([
                'body' => [
                    'name' => ['type' => 'string', 'in' => 'body', 'required' => true],
                    'phone' => ['type' => 'string', 'in' => 'body', 'required' => false],
                    'email' => ['type' => 'string', 'in' => 'body', 'required' => false],
                ],
            ]),
        ];
    }

    /**
     * Seed all entries into the operation_search_index table.
     * Uses updateOrInsert so a re-seed is idempotent.
     */
    public static function seed(): void
    {
        foreach (self::entries() as $entry) {
            DB::table('operation_search_index')->updateOrInsert(
                ['operation_id' => $entry['operation_id']],
                array_merge($entry, ['updated_at' => now()])
            );
        }
    }

    /**
     * Apply the revision to a single entry (for T029 re-index scenario).
     */
    public static function applyRevision(): void
    {
        DB::table('operation_search_index')
            ->where('operation_id', self::revision()['operation_id'])
            ->update(array_merge(self::revision(), ['updated_at' => now()]));
    }
}
