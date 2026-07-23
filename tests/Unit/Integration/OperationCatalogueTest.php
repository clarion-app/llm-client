<?php

namespace Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Integration\Harness\OperationCatalogue;
use ClarionApp\Backend\ApiManager;
use ReflectionClass;

/**
 * T006: OperationCatalogue — seed/reset against ApiManager::$apiDocsCache.
 *
 * - seed() writes an OpenAPI-shaped doc into ApiManager::$apiDocsCache by reflection
 * - reset() sets it back to null
 * - no leak across tests when reset() runs unconditionally in tearDown
 */
class OperationCatalogueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Skip gracefully if any test earlier in this process has replaced
        // ApiManager with a Mockery `alias:`/`overload:` double: the double has
        // no $apiDocsCache property, so the seam this test exercises is gone.
        // No test does that today (ApiCallValidatorTest/McpToolRegistryTest,
        // which once did, now seed $apiDocsCache directly), so this never fires
        // — it is a regression safety-net. Skipping is honest — there would be
        // nothing real left to assert against — and avoids a confusing
        // `ReflectionException: Property ... does not exist` masquerading as a
        // failure of this test.
        if (! (new ReflectionClass(ApiManager::class))->hasProperty('apiDocsCache')) {
            $this->markTestSkipped(
                'ApiManager has been replaced by a Mockery alias/overload mock '
                . 'by an earlier test in this process; its $apiDocsCache seam is '
                . 'unavailable. Runs cleanly under the canonical `phpunit tests/` order.'
            );
        }

        // Ensure clean state
        $this->resetApiDocsCache();
    }

    protected function tearDown(): void
    {
        $this->resetApiDocsCache();
        parent::tearDown();
    }

    private function resetApiDocsCache(): void
    {
        $ref = new ReflectionClass(ApiManager::class);
        if (! $ref->hasProperty('apiDocsCache')) {
            return;
        }
        $prop = $ref->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    public function test_seed_writes_to_apiDocsCache(): void
    {
        $catalogue = new OperationCatalogue();
        $openApiDoc = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Test API', 'version' => '1.0'],
            'paths' => [
                '/api/contacts' => [
                    'get' => [
                        'operationId' => 'listContacts',
                        'summary' => 'List contacts',
                    ],
                ],
            ],
        ];

        $catalogue->seed($openApiDoc);

        // Verify it's in the cache
        $ref = new ReflectionClass(ApiManager::class);
        $prop = $ref->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $cached = $prop->getValue(null);

        $this->assertIsArray($cached);
        $this->assertArrayHasKey('paths', $cached);
        $this->assertArrayHasKey('/api/contacts', $cached['paths']);
    }

    public function test_reset_clears_apiDocsCache(): void
    {
        $catalogue = new OperationCatalogue();
        $openApiDoc = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Test API', 'version' => '1.0'],
            'paths' => [
                '/api/contacts' => [
                    'get' => [
                        'operationId' => 'listContacts',
                        'summary' => 'List contacts',
                    ],
                ],
            ],
        ];

        $catalogue->seed($openApiDoc);
        $catalogue->reset();

        // Verify cache is null
        $ref = new ReflectionClass(ApiManager::class);
        $prop = $ref->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $cached = $prop->getValue(null);

        $this->assertNull($cached);
    }

    public function test_no_leak_across_tests(): void
    {
        // This test verifies that reset() in tearDown prevents leaks.
        // We seed, then reset, and verify the cache is null.
        $catalogue = new OperationCatalogue();
        $catalogue->seed([
            'openapi' => '3.0.0',
            'paths' => [],
        ]);
        $catalogue->reset();

        $ref = new ReflectionClass(ApiManager::class);
        $prop = $ref->getProperty('apiDocsCache');
        $prop->setAccessible(true);

        $this->assertNull($prop->getValue(null));
    }

    public function test_seed_then_reset_cycle(): void
    {
        $catalogue = new OperationCatalogue();

        // First cycle
        $catalogue->seed(['openapi' => '3.0.0', 'paths' => ['/a' => []]]);
        $this->assertNotNull($this->getCache());
        $catalogue->reset();
        $this->assertNull($this->getCache());

        // Second cycle
        $catalogue->seed(['openapi' => '3.0.0', 'paths' => ['/b' => []]]);
        $this->assertNotNull($this->getCache());
        $catalogue->reset();
        $this->assertNull($this->getCache());
    }

    private function getCache(): ?array
    {
        $ref = new ReflectionClass(ApiManager::class);
        $prop = $ref->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        return $prop->getValue(null);
    }
}
