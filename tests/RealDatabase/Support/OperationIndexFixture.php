<?php

namespace Tests\RealDatabase\Support;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\ClarionPackageServiceProvider;
use ClarionApp\LlmClient\Jobs\ReindexOperationsJob;
use ReflectionClass;

/**
 * T021: A known catalogue of operations and prompts, indexed through the
 * product's own indexing path.
 *
 * FR-001/FR-004 put index *population* under test, not just querying, so this
 * fixture does not write rows into `operation_search_index`. It seeds the
 * catalogue `ReindexOperationsJob` reads — package descriptions, package
 * operations, custom prompts, and the OpenAPI document — and then runs the job.
 * Every column the search then matches on, including the `searchable_text` the
 * job composes and the `type` it assigns, is the product's own work.
 *
 * Every term a query depends on is ≥ 3 characters and not an InnoDB stopword,
 * and the expected orderings are separated by clear relevance margins: no query
 * term appears in a package description, so descriptions (which the job appends
 * to every row of a package) cannot tilt an ordering.
 */
class OperationIndexFixture
{
    private const WIZLIGHTS = '@clarion-app/wizlights';
    private const CONTACTS  = '@clarion-app/contacts';

    /** Catalogue state as it was before seed(), restored by reset(). */
    private static ?array $saved = null;

    /**
     * Package descriptions the job appends to every row it writes.
     *
     * Deliberately share no token with any query in queries(), so that a term
     * every row carries can never decide an ordering.
     */
    public static function packageDescriptions(): array
    {
        return [
            self::WIZLIGHTS => ['description' => 'Household illumination fixtures over local network'],
            self::CONTACTS  => ['description' => 'Personal directory of people and organisations'],
        ];
    }

    /**
     * The rows the job is expected to produce, best identified by their
     * operation_id and type. The job composes everything else.
     */
    public static function entries(): array
    {
        return [
            ['operation_id' => 'wizlights.toggle_power',    'type' => 'operation'],
            ['operation_id' => 'wizlights.set_brightness',  'type' => 'operation'],
            ['operation_id' => 'contacts.store',            'type' => 'operation'],
            ['operation_id' => 'contacts.search',           'type' => 'operation'],
            ['operation_id' => 'wizlights_scene_evening',   'type' => 'prompt'],
        ];
    }

    /**
     * Queries mapping query string → expected operation_id order (best first).
     *
     * Each expectation is derived from term overlap, not read off a run:
     *
     * - `toggle light power` — toggle_power carries all three ("toggle" twice,
     *   in summary and path); set_brightness carries only "light".
     * - `create contact` — only contacts.store carries "create", and only it
     *   carries the singular "contact"; contacts.search has "contacts".
     * - `set brightness` — set_brightness carries "set" plus "brightness" three
     *   times (summary, path, parameter); toggle_power carries "brightness"
     *   twice; the evening prompt carries it once.
     * - `search contacts` — contacts.search carries both terms twice;
     *   contacts.store carries "contacts" once, from its path.
     * - `evening atmosphere` — only the prompt's content carries either term.
     * - `prompt` — no searchable_text carries it; it matches the `type` column.
     */
    public static function queries(): array
    {
        return [
            'toggle light power' => [
                'wizlights.toggle_power',
                'wizlights.set_brightness',
            ],
            'create contact' => [
                'contacts.store',
            ],
            'set brightness' => [
                'wizlights.set_brightness',
                'wizlights.toggle_power',
                'wizlights_scene_evening',
            ],
            'search contacts' => [
                'contacts.search',
                'contacts.store',
            ],
            'evening atmosphere' => [
                'wizlights_scene_evening',
            ],
            'prompt' => [
                'wizlights_scene_evening',
            ],
        ];
    }

    /**
     * A query that matches nothing in the fixture.
     * None of these terms appears in any summary, path, parameter name,
     * prompt content, package description, or type value.
     */
    public static function nonMatchingQuery(): string
    {
        return 'plumbing repair service';
    }

    /**
     * Seed the catalogue and run the product's indexer over it.
     */
    public static function seed(): void
    {
        self::seedCatalogue(self::operations(), self::customPrompts());
        (new ReindexOperationsJob())->handle();
    }

    /**
     * Re-index after a content change (FR-007).
     *
     * `contacts.store` is re-described: it gains "postal zip code" and loses
     * "create" and the singular "contact", so a correct re-index both reflects
     * the new content and stops reflecting the superseded content. The index is
     * rebuilt by the same job that built it, not patched in place.
     */
    public static function applyRevision(): void
    {
        self::seedCatalogue(self::revisedOperations(), self::customPrompts());
        (new ReindexOperationsJob())->handle();
    }

    /**
     * Restore the catalogue statics this fixture overwrote.
     *
     * They are process-global, so leaving them set would leak into any later
     * test that reads the package catalogue.
     */
    public static function reset(): void
    {
        if (self::$saved === null) {
            return;
        }

        self::writeStatic(ClarionPackageServiceProvider::class, 'packageDescriptions', self::$saved['descriptions']);
        self::writeStatic(ClarionPackageServiceProvider::class, 'packageOperations', self::$saved['operations']);
        self::writeStatic(ClarionPackageServiceProvider::class, 'customPrompts', self::$saved['prompts']);
        self::writeStatic(ApiManager::class, 'apiDocsCache', self::$saved['apiDocs']);

        self::$saved = null;
    }

    /**
     * Write the catalogue the indexer reads: what packages exist, which
     * operations they publish, their custom prompts, and the OpenAPI document
     * the operation details come from.
     */
    private static function seedCatalogue(array $operations, array $prompts): void
    {
        if (self::$saved === null) {
            self::$saved = [
                'descriptions' => self::readStatic(ClarionPackageServiceProvider::class, 'packageDescriptions'),
                'operations'   => self::readStatic(ClarionPackageServiceProvider::class, 'packageOperations'),
                'prompts'      => self::readStatic(ClarionPackageServiceProvider::class, 'customPrompts'),
                'apiDocs'      => self::readStatic(ApiManager::class, 'apiDocsCache'),
            ];
        }

        $summaries = [];
        $paths = [];

        foreach ($operations as $operation) {
            $summaries[$operation['package']][] = [
                'operationId' => $operation['operationId'],
                'summary'     => $operation['summary'],
            ];

            $paths[$operation['path']][$operation['method']] = array_filter([
                'operationId' => $operation['operationId'],
                'summary'     => $operation['summary'],
                'parameters'  => $operation['parameters'] ?? null,
                'requestBody' => $operation['requestBody'] ?? null,
            ], fn ($value) => $value !== null);
        }

        self::writeStatic(ClarionPackageServiceProvider::class, 'packageDescriptions', self::packageDescriptions());
        self::writeStatic(ClarionPackageServiceProvider::class, 'packageOperations', $summaries);
        self::writeStatic(ClarionPackageServiceProvider::class, 'customPrompts', $prompts);
        self::writeStatic(ApiManager::class, 'apiDocsCache', ['paths' => $paths]);
    }

    /**
     * The operations the catalogue publishes, in OpenAPI terms.
     *
     * The indexer composes searchable_text from summary, method, path,
     * parameter names, and the package description — so these values, not a
     * hand-written searchable_text, are what the orderings above rest on.
     */
    private static function operations(): array
    {
        return [
            [
                'package'     => self::WIZLIGHTS,
                'operationId' => 'wizlights.toggle_power',
                'method'      => 'post',
                'path'        => '/api/wizlights/toggle',
                'summary'     => 'Toggle the power state of a smart light and restore its brightness',
                'parameters'  => [
                    ['name' => 'deviceId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ['name' => 'brightness', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                ],
            ],
            [
                'package'     => self::WIZLIGHTS,
                'operationId' => 'wizlights.set_brightness',
                'method'      => 'put',
                'path'        => '/api/wizlights/brightness',
                'summary'     => 'Set the brightness level of a smart light',
                'parameters'  => [
                    ['name' => 'deviceId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ['name' => 'brightness', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']],
                    ['name' => 'percentage', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                ],
            ],
            [
                'package'     => self::CONTACTS,
                'operationId' => 'contacts.store',
                'method'      => 'post',
                'path'        => '/api/contacts',
                'summary'     => 'Create a new contact with name and phone number',
                'requestBody' => self::jsonBody(['name'], ['name', 'phone', 'email', 'address']),
            ],
            [
                'package'     => self::CONTACTS,
                'operationId' => 'contacts.search',
                'method'      => 'get',
                'path'        => '/api/contacts/search',
                'summary'     => 'Search contacts by name or phone number',
                'parameters'  => [
                    ['name' => 'query', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
                    ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                    ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                ],
            ],
        ];
    }

    /**
     * The same catalogue with `contacts.store` re-described (FR-007).
     */
    private static function revisedOperations(): array
    {
        $operations = self::operations();

        foreach ($operations as $index => $operation) {
            if ($operation['operationId'] !== 'contacts.store') {
                continue;
            }

            $operations[$index]['summary'] = 'Register a new address book record with postal zip code and mailbox';
            $operations[$index]['requestBody'] = self::jsonBody(
                ['name'],
                ['name', 'phone', 'email', 'postal', 'zip']
            );
        }

        return $operations;
    }

    private static function customPrompts(): array
    {
        return [
            self::WIZLIGHTS => [
                'scene_evening' => 'Dim all lights to a warm evening atmosphere with soft amber colours '
                    . 'and reduced brightness levels for relaxation',
            ],
            self::CONTACTS => [],
        ];
    }

    private static function jsonBody(array $required, array $properties): array
    {
        return [
            'content' => [
                'application/json' => [
                    'schema' => [
                        'type'       => 'object',
                        'required'   => $required,
                        'properties' => array_combine(
                            $properties,
                            array_map(fn () => ['type' => 'string'], $properties)
                        ),
                    ],
                ],
            ],
        ];
    }

    private static function readStatic(string $class, string $property): mixed
    {
        $reflected = (new ReflectionClass($class))->getProperty($property);
        $reflected->setAccessible(true);

        return $reflected->getValue();
    }

    private static function writeStatic(string $class, string $property, mixed $value): void
    {
        $reflected = (new ReflectionClass($class))->getProperty($property);
        $reflected->setAccessible(true);
        $reflected->setValue(null, $value);
    }
}
