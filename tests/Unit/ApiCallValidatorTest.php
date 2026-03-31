<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\ApiCallValidator;
use ClarionApp\Backend\ApiManager;
use Mockery;

class ApiCallValidatorTest extends TestCase
{
    /** @test T023 — valid operationId allowed */
    public function allows_valid_operation_id()
    {
        // Mock ApiManager to return a valid operation
        $mock = Mockery::mock('alias:' . ApiManager::class);
        $mock->shouldReceive('getOperationDetails')
            ->with('listContacts')
            ->andReturn([
                'path' => '/api/clarion-app/contacts/contact',
                'method' => 'get',
                'details' => ['operationId' => 'listContacts'],
            ]);

        $result = ApiCallValidator::validate('listContacts', 'GET', '/api/clarion-app/contacts/contact');
        $this->assertEquals(ApiCallValidator::STATUS_ALLOW, $result['status']);
    }

    /** @test T023 — unknown operationId rejected */
    public function rejects_unknown_operation_id()
    {
        $mock = Mockery::mock('alias:' . ApiManager::class);
        $mock->shouldReceive('getOperationDetails')
            ->with('fakeOperation')
            ->andReturn((object)[]);

        $result = ApiCallValidator::validate('fakeOperation', 'GET', '/api/some/path');
        $this->assertEquals(ApiCallValidator::STATUS_REJECT, $result['status']);
        $this->assertStringContainsString('Unknown operationId', $result['reason']);
    }

    /** @test T023 — denylisted path rejected */
    public function rejects_denylisted_path()
    {
        $mock = Mockery::mock('alias:' . ApiManager::class);
        $mock->shouldReceive('getOperationDetails')
            ->with('listConversations')
            ->andReturn([
                'path' => '/api/clarion-app/llm-client/conversation',
                'method' => 'get',
                'details' => ['operationId' => 'listConversations'],
            ]);

        $result = ApiCallValidator::validate('listConversations', 'GET', '/api/clarion-app/llm-client/conversation');
        $this->assertEquals(ApiCallValidator::STATUS_REJECT, $result['status']);
        $this->assertStringContainsString('denylisted', $result['reason']);
    }

    /** @test T023 — PUT/PATCH/DELETE flagged for confirmation */
    public function flags_destructive_methods_for_confirmation()
    {
        $mock = Mockery::mock('alias:' . ApiManager::class);
        $mock->shouldReceive('getOperationDetails')
            ->andReturn([
                'path' => '/api/clarion-app/contacts/contact/{id}',
                'method' => 'delete',
                'details' => ['operationId' => 'deleteContact'],
            ]);

        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            $result = ApiCallValidator::validate('deleteContact', $method, '/api/clarion-app/contacts/contact/123');
            $this->assertEquals(ApiCallValidator::STATUS_CONFIRM, $result['status']);
        }
    }

    /** @test T023 — GET/POST allowed without confirmation */
    public function allows_get_and_post_without_confirmation()
    {
        $mock = Mockery::mock('alias:' . ApiManager::class);
        $mock->shouldReceive('getOperationDetails')
            ->andReturn([
                'path' => '/api/clarion-app/contacts/contact',
                'method' => 'get',
                'details' => ['operationId' => 'listContacts'],
            ]);

        foreach (['GET', 'POST'] as $method) {
            $result = ApiCallValidator::validate('listContacts', $method, '/api/clarion-app/contacts/contact');
            $this->assertEquals(ApiCallValidator::STATUS_ALLOW, $result['status']);
        }
    }

    /** @test T023 — path traversal attempts rejected */
    public function rejects_path_traversal()
    {
        $result = ApiCallValidator::validate('someOp', 'GET', '/api/../etc/passwd');
        $this->assertEquals(ApiCallValidator::STATUS_REJECT, $result['status']);
        $this->assertStringContainsString('traversal', $result['reason']);
    }

    /** @test T023 — encoded path traversal attempts rejected */
    public function rejects_encoded_path_traversal()
    {
        $result = ApiCallValidator::validate('someOp', 'GET', '/api/..%2F..%2Fetc/passwd');
        $this->assertEquals(ApiCallValidator::STATUS_REJECT, $result['status']);
        $this->assertStringContainsString('traversal', $result['reason']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
