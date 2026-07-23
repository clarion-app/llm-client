<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\ApiCallValidator;
use ClarionApp\Backend\ApiManager;

use PHPUnit\Framework\Attributes\Test;

class ApiCallValidatorTest extends TestCase
{
    // T023 — valid operationId allowed
    #[Test]
    public function allows_valid_operation_id()
    {
        // Seed the real ApiManager so getOperationDetails('listContacts') resolves.
        $this->seedOperations([
            'listContacts' => ['/api/clarion-app/contacts/contact', 'get'],
        ]);

        $result = ApiCallValidator::validate('listContacts', 'GET', '/api/clarion-app/contacts/contact');
        $this->assertEquals(ApiCallValidator::STATUS_ALLOW, $result['status']);
    }

    // T023 — unknown operationId rejected

    #[Test]
    public function rejects_unknown_operation_id()
    {
        // Seed a non-empty (but non-matching) catalogue so getOperationDetails
        // returns (object)[] for the unknown op rather than falling through to
        // the real DocumentationService.
        $this->seedOperations([
            'listContacts' => ['/api/clarion-app/contacts/contact', 'get'],
        ]);

        $result = ApiCallValidator::validate('fakeOperation', 'GET', '/api/some/path');
        $this->assertEquals(ApiCallValidator::STATUS_REJECT, $result['status']);
        $this->assertStringContainsString('Unknown operationId', $result['reason']);
    }

    // T023 — denylisted path rejected

    #[Test]
    public function rejects_denylisted_path()
    {
        $this->seedOperations([
            'listConversations' => ['/api/clarion-app/llm-client/conversation', 'get'],
        ]);

        $result = ApiCallValidator::validate('listConversations', 'GET', '/api/clarion-app/llm-client/conversation');
        $this->assertEquals(ApiCallValidator::STATUS_REJECT, $result['status']);
        $this->assertStringContainsString('denylisted', $result['reason']);
    }

    // T023 — PUT/PATCH/DELETE flagged for confirmation

    #[Test]
    public function flags_destructive_methods_for_confirmation()
    {
        $this->seedOperations([
            'deleteContact' => ['/api/clarion-app/contacts/contact/{id}', 'delete'],
        ]);

        foreach (['DELETE'] as $method) {
            $result = ApiCallValidator::validate('deleteContact', $method, '/api/clarion-app/contacts/contact/123');
            $this->assertEquals(ApiCallValidator::STATUS_CONFIRM, $result['status']);
        }
    }

    // T023 — GET/POST allowed without confirmation

    #[Test]
    public function allows_get_and_post_without_confirmation()
    {
        $this->seedOperations([
            'listContacts' => ['/api/clarion-app/contacts/contact', 'get'],
        ]);

        foreach (['GET', 'POST'] as $method) {
            $result = ApiCallValidator::validate('listContacts', $method, '/api/clarion-app/contacts/contact');
            $this->assertEquals(ApiCallValidator::STATUS_ALLOW, $result['status']);
        }
    }

    // T023 — path traversal attempts rejected

    #[Test]
    public function rejects_path_traversal()
    {
        $result = ApiCallValidator::validate('someOp', 'GET', '/api/../etc/passwd');
        $this->assertEquals(ApiCallValidator::STATUS_REJECT, $result['status']);
        $this->assertStringContainsString('traversal', $result['reason']);
    }

    // T023 — encoded path traversal attempts rejected

    #[Test]
    public function rejects_encoded_path_traversal()
    {
        $result = ApiCallValidator::validate('someOp', 'GET', '/api/..%2F..%2Fetc/passwd');
        $this->assertEquals(ApiCallValidator::STATUS_REJECT, $result['status']);
        $this->assertStringContainsString('traversal', $result['reason']);
    }

    /**
     * Seed ApiManager's real static $apiDocsCache with an OpenAPI-shaped doc so
     * the *real* ApiManager::getOperationDetails() resolves the given operations
     * ({opId => [path, method]}) and returns (object)[] for anything else.
     *
     * This replaces a Mockery `alias:` mock, which fails with "class already
     * exists" once the real ApiManager has been autoloaded earlier in the same
     * process (e.g. by the Integration suite). ApiCallValidator only uses
     * getOperationDetails() for an existence check, so path/method keys are
     * enough; getOperationDetails() matches on details['operationId'].
     */
    private function seedOperations(array $operations): void
    {
        $paths = [];
        foreach ($operations as $opId => [$path, $method]) {
            $paths[$path][$method] = ['operationId' => $opId];
        }
        $this->seedApiDocsCache(['paths' => $paths]);
    }

    private function seedApiDocsCache(?array $doc): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);
    }

    protected function tearDown(): void
    {
        // Clear the seeded catalogue so it cannot leak into later tests.
        $this->seedApiDocsCache(null);
        parent::tearDown();
    }
}
