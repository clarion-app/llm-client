<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\ClarionPackageServiceProvider;
use Mockery;

class McpToolRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    private function mockApiManager(array $packageOperations, array $operationDetailsMap): void
    {
        $descriptions = [];
        foreach (array_keys($packageOperations) as $pkg) {
            $descriptions[$pkg] = ['description' => "Description for {$pkg}"];
        }

        // Use reflection to set static properties instead of alias mock (class already exists)
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

        // Mock ApiManager with alias (concrete class, not abstract)
        $apiMock = Mockery::mock('alias:' . ApiManager::class);
        foreach ($operationDetailsMap as $opId => $details) {
            $apiMock->shouldReceive('getOperationDetails')
                ->with($opId)
                ->andReturn($details);
        }

        // For operations called without specific matching
        $apiMock->shouldReceive('getOperationDetails')
            ->andReturn((object) [])
            ->byDefault();
    }
}
