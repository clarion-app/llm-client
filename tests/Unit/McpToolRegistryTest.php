<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\ClarionPackageServiceProvider;
use Mockery;

use PHPUnit\Framework\Attributes\Test;

class McpToolRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset every static this test seeds so nothing leaks into later tests
        // (the same isolation guarantee OperationCatalogue gives the harness).
        $this->seedApiDocsCache(null);
        $reflector = new \ReflectionClass(ClarionPackageServiceProvider::class);
        foreach (['packageDescriptions', 'packageOperations', 'customPrompts'] as $name) {
            $prop = $reflector->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue(null, []);
        }

        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function converts_single_openapi_operation_to_mcp_tool()
    {
        $this->mockApiManager([
            '@clarion-app/contacts' => [
                ['operationId' => 'listContacts', 'summary' => 'List all contacts'],
            ],
        ], [
            'listContacts' => [
                'path' => '/api/clarion-app/contacts/contact',
                'method' => 'get',
                'details' => [
                    'operationId' => 'listContacts',
                    'summary' => 'List all contacts',
                    'parameters' => [
                        [
                            'name' => 'page',
                            'in' => 'query',
                            'schema' => ['type' => 'integer'],
                            'description' => 'Page number',
                        ],
                    ],
                ],
            ],
        ]);

        $registry = new McpToolRegistry();
        $result = $registry->getTools();

        $this->assertCount(1, $result['tools']);

        $tool = $result['tools'][0];
        $this->assertEquals('contacts_listContacts', $tool['name']);
        $this->assertEquals('List all contacts', $tool['description']);
        $this->assertEquals('object', $tool['inputSchema']['type']);
        $this->assertArrayHasKey('query', $tool['inputSchema']['properties']);
        $this->assertArrayHasKey('page', $tool['inputSchema']['properties']['query']['properties']);
    }

    #[Test]
    public function namespaces_tool_names_as_package_dot_operation()
    {
        $this->mockApiManager([
            '@clarion-app/contacts' => [
                ['operationId' => 'store', 'summary' => 'Create contact'],
            ],
        ], [
            'store' => [
                'path' => '/api/clarion-app/contacts/contact',
                'method' => 'post',
                'details' => [
                    'operationId' => 'store',
                    'summary' => 'Create contact',
                ],
            ],
        ]);

        $registry = new McpToolRegistry();
        $result = $registry->getTools();

        $this->assertEquals('contacts_store', $result['tools'][0]['name']);
    }

    #[Test]
    public function generates_structured_input_schema_with_sub_objects()
    {
        $this->mockApiManager([
            '@clarion-app/contacts' => [
                ['operationId' => 'updateContact', 'summary' => 'Update a contact'],
            ],
        ], [
            'updateContact' => [
                'path' => '/api/clarion-app/contacts/contact/{contact}',
                'method' => 'put',
                'details' => [
                    'operationId' => 'updateContact',
                    'summary' => 'Update a contact',
                    'parameters' => [
                        [
                            'name' => 'contact',
                            'in' => 'path',
                            'schema' => ['type' => 'string'],
                            'description' => 'Contact ID',
                            'required' => true,
                        ],
                    ],
                    'requestBody' => [
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'name' => ['type' => 'string', 'description' => 'Contact name'],
                                        'email' => ['type' => 'string', 'description' => 'Email address'],
                                    ],
                                    'required' => ['name'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $registry = new McpToolRegistry();
        $result = $registry->getTools();
        $schema = $result['tools'][0]['inputSchema'];

        $this->assertArrayHasKey('path', $schema['properties']);
        $this->assertArrayHasKey('contact', $schema['properties']['path']['properties']);
        $this->assertArrayHasKey('body', $schema['properties']);
        $this->assertArrayHasKey('name', $schema['properties']['body']['properties']);
        $this->assertArrayHasKey('email', $schema['properties']['body']['properties']);
        $this->assertContains('path', $schema['required'] ?? []);
        $this->assertContains('body', $schema['required'] ?? []);
    }

    #[Test]
    public function handles_empty_operations()
    {
        $this->mockApiManager([
            '@clarion-app/empty-package' => [],
        ], []);

        $registry = new McpToolRegistry();
        $result = $registry->getTools();

        $this->assertEmpty($result['tools']);
        $this->assertNull($result['nextCursor']);
    }

    #[Test]
    public function handles_malformed_openapi_gracefully()
    {
        $this->mockApiManager([
            '@clarion-app/contacts' => [
                ['operationId' => 'broken', 'summary' => 'Broken op'],
            ],
        ], [
            'broken' => (object) [], // empty/malformed details
        ]);

        $registry = new McpToolRegistry();
        $result = $registry->getTools();

        // Should not crash, just produce an empty or safe result
        $this->assertIsArray($result['tools']);
    }

    #[Test]
    public function implements_cursor_pagination_with_default_page_size()
    {
        $operations = [];
        $operationDetails = [];
        for ($i = 0; $i < 60; $i++) {
            $opId = "op{$i}";
            $operations[] = ['operationId' => $opId, 'summary' => "Operation {$i}"];
            $operationDetails[$opId] = [
                'path' => "/api/clarion-app/contacts/{$opId}",
                'method' => 'get',
                'details' => [
                    'operationId' => $opId,
                    'summary' => "Operation {$i}",
                ],
            ];
        }

        $this->mockApiManager(['@clarion-app/contacts' => $operations], $operationDetails);

        $registry = new McpToolRegistry();

        // First page
        $result = $registry->getTools();
        $this->assertCount(50, $result['tools']);
        $this->assertNotNull($result['nextCursor']);

        // Second page
        $result2 = $registry->getTools($result['nextCursor']);
        $this->assertCount(10, $result2['tools']);
        $this->assertNull($result2['nextCursor']);
    }

    #[Test]
    public function next_cursor_absent_when_all_tools_fit_in_page()
    {
        $this->mockApiManager([
            '@clarion-app/contacts' => [
                ['operationId' => 'index', 'summary' => 'List contacts'],
            ],
        ], [
            'index' => [
                'path' => '/api/clarion-app/contacts/contact',
                'method' => 'get',
                'details' => [
                    'operationId' => 'index',
                    'summary' => 'List contacts',
                ],
            ],
        ]);

        $registry = new McpToolRegistry();
        $result = $registry->getTools();

        $this->assertNull($result['nextCursor']);
    }

    #[Test]
    public function package_filter_scopes_to_single_package()
    {
        $this->mockApiManager([
            '@clarion-app/contacts' => [
                ['operationId' => 'listContacts', 'summary' => 'List contacts'],
            ],
            '@clarion-app/lists' => [
                ['operationId' => 'listLists', 'summary' => 'List lists'],
            ],
        ], [
            'listContacts' => [
                'path' => '/api/clarion-app/contacts/contact',
                'method' => 'get',
                'details' => [
                    'operationId' => 'listContacts',
                    'summary' => 'List contacts',
                ],
            ],
            'listLists' => [
                'path' => '/api/clarion-app/lists/list',
                'method' => 'get',
                'details' => [
                    'operationId' => 'listLists',
                    'summary' => 'List lists',
                ],
            ],
        ]);

        $registry = new McpToolRegistry();
        $result = $registry->getTools(null, 'contacts');

        $this->assertCount(1, $result['tools']);
        $this->assertEquals('contacts_listContacts', $result['tools'][0]['name']);
    }

    #[Test]
    public function get_tool_has_readonly_annotations_for_get()
    {
        $this->mockApiManager([
            '@clarion-app/contacts' => [
                ['operationId' => 'listContacts', 'summary' => 'List all contacts'],
            ],
        ], [
            'listContacts' => [
                'path' => '/api/clarion-app/contacts/contact',
                'method' => 'get',
                'details' => [
                    'operationId' => 'listContacts',
                    'summary' => 'List all contacts',
                ],
            ],
        ]);

        $registry = new McpToolRegistry();
        $result = $registry->getTools();
        $annotations = $result['tools'][0]['annotations'];

        $this->assertTrue($annotations['readOnlyHint']);
        $this->assertFalse($annotations['destructiveHint']);
        $this->assertTrue($annotations['idempotentHint']);
        $this->assertFalse($annotations['openWorldHint']);
    }

    #[Test]
    public function post_tool_has_correct_annotations()
    {
        $this->mockApiManager([
            '@clarion-app/contacts' => [
                ['operationId' => 'createContact', 'summary' => 'Create a contact'],
            ],
        ], [
            'createContact' => [
                'path' => '/api/clarion-app/contacts/contact',
                'method' => 'post',
                'details' => [
                    'operationId' => 'createContact',
                    'summary' => 'Create a contact',
                ],
            ],
        ]);

        $registry = new McpToolRegistry();
        $result = $registry->getTools();
        $annotations = $result['tools'][0]['annotations'];

        $this->assertFalse($annotations['readOnlyHint']);
        $this->assertFalse($annotations['destructiveHint']);
        $this->assertFalse($annotations['idempotentHint']);
        $this->assertFalse($annotations['openWorldHint']);
    }

    #[Test]
    public function put_tool_has_idempotent_annotation()
    {
        $this->mockApiManager([
            '@clarion-app/contacts' => [
                ['operationId' => 'updateContact', 'summary' => 'Update a contact'],
            ],
        ], [
            'updateContact' => [
                'path' => '/api/clarion-app/contacts/contact/{contact}',
                'method' => 'put',
                'details' => [
                    'operationId' => 'updateContact',
                    'summary' => 'Update a contact',
                ],
            ],
        ]);

        $registry = new McpToolRegistry();
        $result = $registry->getTools();
        $annotations = $result['tools'][0]['annotations'];

        $this->assertFalse($annotations['readOnlyHint']);
        $this->assertFalse($annotations['destructiveHint']);
        $this->assertTrue($annotations['idempotentHint']);
    }

    #[Test]
    public function patch_tool_has_no_idempotent_annotation()
    {
        $this->mockApiManager([
            '@clarion-app/contacts' => [
                ['operationId' => 'patchContact', 'summary' => 'Patch a contact'],
            ],
        ], [
            'patchContact' => [
                'path' => '/api/clarion-app/contacts/contact/{contact}',
                'method' => 'patch',
                'details' => [
                    'operationId' => 'patchContact',
                    'summary' => 'Patch a contact',
                ],
            ],
        ]);

        $registry = new McpToolRegistry();
        $result = $registry->getTools();
        $annotations = $result['tools'][0]['annotations'];

        $this->assertFalse($annotations['readOnlyHint']);
        $this->assertFalse($annotations['destructiveHint']);
        $this->assertFalse($annotations['idempotentHint']);
    }

    #[Test]
    public function delete_tool_has_destructive_and_idempotent_annotations()
    {
        $this->mockApiManager([
            '@clarion-app/contacts' => [
                ['operationId' => 'deleteContact', 'summary' => 'Delete a contact'],
            ],
        ], [
            'deleteContact' => [
                'path' => '/api/clarion-app/contacts/contact/{contact}',
                'method' => 'delete',
                'details' => [
                    'operationId' => 'deleteContact',
                    'summary' => 'Delete a contact',
                ],
            ],
        ]);

        $registry = new McpToolRegistry();
        $result = $registry->getTools();
        $annotations = $result['tools'][0]['annotations'];

        $this->assertFalse($annotations['readOnlyHint']);
        $this->assertTrue($annotations['destructiveHint']);
        $this->assertTrue($annotations['idempotentHint']);
        $this->assertFalse($annotations['openWorldHint']);
    }

    #[Test]
    public function all_tools_have_open_world_hint_false()
    {
        $this->mockApiManager([
            '@clarion-app/contacts' => [
                ['operationId' => 'listContacts', 'summary' => 'List contacts'],
                ['operationId' => 'createContact', 'summary' => 'Create contact'],
            ],
        ], [
            'listContacts' => [
                'path' => '/api/clarion-app/contacts/contact',
                'method' => 'get',
                'details' => ['operationId' => 'listContacts', 'summary' => 'List contacts'],
            ],
            'createContact' => [
                'path' => '/api/clarion-app/contacts/contact',
                'method' => 'post',
                'details' => ['operationId' => 'createContact', 'summary' => 'Create contact'],
            ],
        ]);

        $registry = new McpToolRegistry();
        $result = $registry->getTools();

        foreach ($result['tools'] as $tool) {
            $this->assertFalse($tool['annotations']['openWorldHint']);
        }
    }

    #[Test]
    public function title_derived_from_openapi_summary()
    {
        $this->mockApiManager([
            '@clarion-app/contacts' => [
                ['operationId' => 'listContacts', 'summary' => 'List all contacts'],
            ],
        ], [
            'listContacts' => [
                'path' => '/api/clarion-app/contacts/contact',
                'method' => 'get',
                'details' => [
                    'operationId' => 'listContacts',
                    'summary' => 'List all contacts',
                ],
            ],
        ]);

        $registry = new McpToolRegistry();
        $result = $registry->getTools();
        $annotations = $result['tools'][0]['annotations'];

        $this->assertEquals('List all contacts', $annotations['title']);
    }

    // Tests for full raw schema reconstruction in McpToolRegistry::buildInputSchema()
    #[Test]
    public function build_input_schema_preserves_full_raw_schema_fields()
    {
        $this->mockApiManager([
            '@clarion-app/contacts' => [
                ['operationId' => 'createContact', 'summary' => 'Create a contact'],
            ],
        ], [
            'createContact' => [
                'path' => '/api/clarion-app/contacts/contact',
                'method' => 'post',
                'details' => [
                    'operationId' => 'createContact',
                    'summary' => 'Create a contact',
                    'parameters' => [
                        [
                            'name' => 'status',
                            'in' => 'query',
                            'schema' => [
                                'type' => 'string',
                                'enum' => ['active', 'inactive', 'pending'],
                                'default' => 'active',
                            ],
                            'description' => 'Filter by status',
                        ],
                    ],
                    'requestBody' => [
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'name' => [
                                            'type' => 'string',
                                            'description' => 'Contact name',
                                            'minLength' => 1,
                                            'maxLength' => 255,
                                        ],
                                        'tags' => [
                                            'type' => 'array',
                                            'description' => 'Contact tags',
                                            'items' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $registry = new McpToolRegistry();
        $result = $registry->getTools();
        $schema = $result['tools'][0]['inputSchema'];

        // Verify enum and default are preserved in query param schema
        $statusSchema = $schema['properties']['query']['properties']['status'];
        $this->assertEquals(['active', 'inactive', 'pending'], $statusSchema['enum']);
        $this->assertEquals('active', $statusSchema['default']);
        $this->assertEquals('string', $statusSchema['type']);

        // Verify minLength/maxLength are preserved in body param schema
        $nameSchema = $schema['properties']['body']['properties']['name'];
        $this->assertEquals(1, $nameSchema['minLength']);
        $this->assertEquals(255, $nameSchema['maxLength']);

        // Verify nested 'items' structure is preserved
        $tagsSchema = $schema['properties']['body']['properties']['tags'];
        $this->assertEquals('array', $tagsSchema['type']);
        $this->assertEquals(['type' => 'string'], $tagsSchema['items']);
    }

    // Tests for McpToolRegistry::findTool() returning full inputSchema
    #[Test]
    public function find_tool_returns_full_input_schema()
    {
        $this->mockApiManager([
            '@clarion-app/contacts' => [
                ['operationId' => 'updateContact', 'summary' => 'Update a contact'],
            ],
        ], [
            'updateContact' => [
                'path' => '/api/clarion-app/contacts/contact/{contact}',
                'method' => 'put',
                'details' => [
                    'operationId' => 'updateContact',
                    'summary' => 'Update a contact',
                    'parameters' => [
                        [
                            'name' => 'contact',
                            'in' => 'path',
                            'schema' => ['type' => 'string', 'format' => 'uuid'],
                            'description' => 'Contact ID',
                            'required' => true,
                        ],
                    ],
                    'requestBody' => [
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'name' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $registry = new McpToolRegistry();
        $tool = $registry->findTool('contacts_updateContact');

        $this->assertNotNull($tool);
        $this->assertArrayHasKey('inputSchema', $tool);
        // Verify format field is preserved
        $this->assertEquals('uuid', $tool['inputSchema']['properties']['path']['properties']['contact']['format']);
        // Verify body properties are preserved
        $this->assertArrayHasKey('name', $tool['inputSchema']['properties']['body']['properties']);
    }

    // Tests for full schema field preservation in McpToolRegistry::buildInputSchema()
    #[Test]
    public function build_input_schema_preserves_all_openapi_fields()
    {
        $this->mockApiManager([
            '@clarion-app/contacts' => [
                ['operationId' => 'searchContacts', 'summary' => 'Search contacts'],
            ],
        ], [
            'searchContacts' => [
                'path' => '/api/clarion-app/contacts/contact',
                'method' => 'get',
                'details' => [
                    'operationId' => 'searchContacts',
                    'summary' => 'Search contacts',
                    'parameters' => [
                        [
                            'name' => 'page',
                            'in' => 'query',
                            'schema' => [
                                'type' => 'integer',
                                'minimum' => 1,
                                'maximum' => 1000,
                                'default' => 1,
                            ],
                            'description' => 'Page number',
                        ],
                        [
                            'name' => 'sort',
                            'in' => 'query',
                            'schema' => [
                                'type' => 'string',
                                'enum' => ['name', 'created_at', 'updated_at'],
                                'default' => 'name',
                                'pattern' => '^(name|created_at|updated_at)$',
                            ],
                            'description' => 'Sort field',
                        ],
                    ],
                ],
            ],
        ]);

        $registry = new McpToolRegistry();
        $result = $registry->getTools();
        $schema = $result['tools'][0]['inputSchema'];

        // Verify all constraint fields are preserved for 'page'
        $pageSchema = $schema['properties']['query']['properties']['page'];
        $this->assertEquals('integer', $pageSchema['type']);
        $this->assertEquals(1, $pageSchema['minimum']);
        $this->assertEquals(1000, $pageSchema['maximum']);
        $this->assertEquals(1, $pageSchema['default']);
        $this->assertEquals('Page number', $pageSchema['description']);

        // Verify enum, default, and pattern are preserved for 'sort'
        $sortSchema = $schema['properties']['query']['properties']['sort'];
        $this->assertEquals(['name', 'created_at', 'updated_at'], $sortSchema['enum']);
        $this->assertEquals('name', $sortSchema['default']);
        $this->assertEquals('^(name|created_at|updated_at)$', $sortSchema['pattern']);
    }

    private function mockApiManager(array $packageOperations, array $operationDetailsMap): void
    {
        $descriptions = [];
        foreach (array_keys($packageOperations) as $pkg) {
            $descriptions[$pkg] = ['description' => "Description for {$pkg}"];
        }

        // Seed ClarionPackageServiceProvider statics by reflection (no mock).
        $reflector = new \ReflectionClass(ClarionPackageServiceProvider::class);

        $descProp = $reflector->getProperty('packageDescriptions');
        $descProp->setAccessible(true);
        $descProp->setValue(null, $descriptions);

        $opsProp = $reflector->getProperty('packageOperations');
        $opsProp->setAccessible(true);
        $opsProp->setValue(null, $packageOperations);

        $customPromptsProp = $reflector->getProperty('customPrompts');
        $customPromptsProp->setAccessible(true);
        $customPromptsProp->setValue(null, []);

        // Seed ApiManager's real static $apiDocsCache built from
        // $operationDetailsMap, so the *real* ApiManager::getOperationDetails()
        // returns each mapped {path, method, details} — and (object)[] for
        // anything absent, exactly as the old byDefault() mock did. This
        // replaces a Mockery `alias:` mock, which fails with "class already
        // exists" once the real ApiManager has been autoloaded earlier in the
        // process (e.g. by the Integration suite, whose OperationCatalogue
        // drives the same static). getOperationDetails() matches on
        // details['operationId'], so it is forced to $opId here.
        $paths = [];
        foreach ($operationDetailsMap as $opId => $entry) {
            if (! is_array($entry) || ! isset($entry['path'], $entry['method'])) {
                // Malformed/empty entry: leave it unmapped so the real method
                // returns (object)[] for this opId (the byDefault() case).
                continue;
            }
            $details = (array) ($entry['details'] ?? []);
            $details['operationId'] = $opId;
            $paths[$entry['path']][$entry['method']] = $details;
        }
        $this->seedApiDocsCache(['paths' => $paths]);
    }

    /**
     * Write (or clear, with null) ApiManager's static $apiDocsCache by
     * reflection — the same seam OperationCatalogue uses in the Integration
     * harness, chosen over a Mockery alias mock so these tests are independent
     * of whether the real ApiManager class was already autoloaded.
     */
    private function seedApiDocsCache(?array $doc): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);
    }
}
