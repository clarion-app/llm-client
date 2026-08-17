<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use Dedoc\Scramble\Generator;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 112-coding-agent, US1 (P1), T022 (contracts §1.2, quickstart row 1).
 *
 * Mirrors ResearchAgentDefinitionTest's own shape: the coding agent's
 * permission/confirmation guarantees are enforced by the existing,
 * unmodified AgentDefinition::isOperationPermitted()/
 * isConfirmationRequired() primitives against the real coding.yaml
 * template — nothing here is mocked out of the definition itself. The
 * second half of this file asserts the template's instruction-only
 * guarantees directly against its instructions text, mirroring the same
 * "template requires X" assertion pattern spec 111 established.
 */
class CodingAgentDefinitionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolate every assertion to the coding agent's own tools.allow —
        // the installation ceiling (api_denylist/confirm_methods) is not
        // this phase's concern.
        $this->app['config']->set('llm-client.confirm_methods', []);
        $this->app['config']->set('llm-client.api_denylist', []);
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function definition(): \ClarionApp\LlmClient\ValueObjects\AgentDefinition
    {
        return (new AgentDefinitionParser())->parse(
            (string) file_get_contents(__DIR__.'/../../src/Templates/coding.yaml'),
        );
    }

    private function seedCatalog(): void
    {
        $this->seedOperationCatalog([
            'clarionApp.llmClient.codingWorkspace.listFiles' => ['path' => '/api/coding-project/{project}/files', 'method' => 'get'],
            'clarionApp.llmClient.codingWorkspace.readFile' => ['path' => '/api/coding-project/{project}/file', 'method' => 'get'],
            'clarionApp.llmClient.codingWorkspace.gitStatus' => ['path' => '/api/coding-project/{project}/git-status', 'method' => 'get'],
            'clarionApp.llmClient.codingWorkspace.gitDiff' => ['path' => '/api/coding-project/{project}/git-diff', 'method' => 'get'],
            'clarionApp.llmClient.codingWorkspace.writeFile' => ['path' => '/api/coding-project/{project}/file', 'method' => 'post'],
            'clarionApp.llmClient.codingWorkspace.deleteFile' => ['path' => '/api/coding-project/{project}/file', 'method' => 'delete'],
            'clarionApp.llmClient.codingWorkspace.runTests' => ['path' => '/api/coding-project/{project}/run-tests', 'method' => 'post'],
            'clarionApp.llmClient.codingProject.store' => ['path' => '/api/coding-project', 'method' => 'post'],
            'clarionApp.llmClient.codingProject.index' => ['path' => '/api/coding-project', 'method' => 'get'],
            'clarionApp.llmClient.codingProject.destroy' => ['path' => '/api/coding-project/{id}', 'method' => 'delete'],
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post'],
        ]);
    }

    private function seedOperationCatalog(array $operations = []): void
    {
        $paths = [];
        foreach ($operations as $operationId => $entry) {
            $paths[$entry['path']][$entry['method']] = [
                'operationId' => $operationId,
                'summary' => $operationId,
            ];
        }
        $doc = ['paths' => $paths];

        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);

        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->andReturn($doc);
        $this->app->instance(Generator::class, $generator);
    }

    private function clearOperationCatalog(): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    // ---------------------------------------------------------------
    // Permission contract (contracts §1.2)
    // ---------------------------------------------------------------

    #[Test]
    public function permits_reads_and_the_three_coding_workspace_mutations(): void
    {
        $this->seedCatalog();

        $definition = $this->definition();

        $this->assertTrue(
            $definition->isOperationPermitted('clarionApp.llmClient.codingWorkspace.listFiles'),
            'a representative GET coding-workspace operation must be permitted',
        );
        $this->assertTrue(
            $definition->isOperationPermitted('clarionApp.llmClient.codingWorkspace.writeFile'),
            'writeFile must be permitted',
        );
        $this->assertTrue(
            $definition->isOperationPermitted('clarionApp.llmClient.codingWorkspace.deleteFile'),
            'deleteFile must be permitted',
        );
        $this->assertTrue(
            $definition->isOperationPermitted('clarionApp.llmClient.codingWorkspace.runTests'),
            'runTests must be permitted',
        );
    }

    #[Test]
    public function never_permits_project_registration_or_deletion(): void
    {
        $this->seedCatalog();

        $definition = $this->definition();

        $this->assertFalse(
            $definition->isOperationPermitted('clarionApp.llmClient.codingProject.store'),
            'codingProject.store is human-driven only, never agent-callable',
        );
        $this->assertFalse(
            $definition->isOperationPermitted('clarionApp.llmClient.codingProject.destroy'),
            'codingProject.destroy is human-driven only, never agent-callable',
        );
    }

    #[Test]
    public function never_permits_an_operation_outside_either_prefix(): void
    {
        $this->seedCatalog();

        $definition = $this->definition();

        $this->assertFalse(
            $definition->isOperationPermitted('contacts.store'),
            'an arbitrary operation outside both the coding-workspace and coding-project prefixes must never be permitted',
        );
    }

    #[Test]
    public function requires_confirmation_only_for_write_and_delete(): void
    {
        $this->seedCatalog();

        $definition = $this->definition();

        $this->assertTrue(
            $definition->isConfirmationRequired('clarionApp.llmClient.codingWorkspace.writeFile'),
            'writeFile must require confirmation (FR-009)',
        );
        $this->assertTrue(
            $definition->isConfirmationRequired('clarionApp.llmClient.codingWorkspace.deleteFile'),
            'deleteFile must require confirmation (FR-009)',
        );
        $this->assertFalse(
            $definition->isConfirmationRequired('clarionApp.llmClient.codingWorkspace.runTests'),
            'runTests never mutates a file itself (research.md D3) — no confirmation',
        );
        $this->assertFalse(
            $definition->isConfirmationRequired('clarionApp.llmClient.codingWorkspace.listFiles'),
            'a read must never require confirmation',
        );
        $this->assertFalse(
            $definition->isConfirmationRequired('clarionApp.llmClient.codingWorkspace.readFile'),
            'a read must never require confirmation',
        );
        $this->assertFalse(
            $definition->isConfirmationRequired('clarionApp.llmClient.codingWorkspace.gitStatus'),
            'a read must never require confirmation',
        );
        $this->assertFalse(
            $definition->isConfirmationRequired('clarionApp.llmClient.codingWorkspace.gitDiff'),
            'a read must never require confirmation',
        );
    }

    // ---------------------------------------------------------------
    // Instruction-only guarantees (contracts §1) — asserted directly
    // against the template's own instructions text, mirroring spec 111's
    // "template requires X" mutation-testing pattern.
    // ---------------------------------------------------------------

    #[Test]
    public function instructions_require_stating_test_outcome_from_structured_fields_not_stdout(): void
    {
        $this->seedCatalog();

        $instructions = $this->definition()->instructions;

        $this->assertStringContainsString(
            'Never characterize a run as "passed" from',
            $instructions,
            'the template must forbid narrating a pass from the model\'s own reading of output text',
        );
        $this->assertStringContainsString(
            'read the structured fields, not the log.',
            $instructions,
            'the template must require reading status/passed/exit_code, not the log',
        );
    }

    #[Test]
    public function instructions_require_calling_change_status_before_naming_changed_files(): void
    {
        $this->seedCatalog();

        $instructions = $this->definition()->instructions;

        $this->assertStringContainsString(
            'call the change-status operation and name exactly the',
            $instructions,
            'the template must require calling the change-status operation before naming changed files',
        );
        $this->assertStringContainsString(
            'Do not report a file as changed from memory alone.',
            $instructions,
            'the template must forbid naming a changed file from memory alone',
        );
    }

    #[Test]
    public function instructions_require_reading_a_file_before_writing_to_it(): void
    {
        $this->seedCatalog();

        $instructions = $this->definition()->instructions;

        $this->assertStringContainsString(
            'Before changing a file, read the relevant existing code with the file-read',
            $instructions,
            'the template must require reading a file via the read operation before writing to it (FR-002/US1 AS3)',
        );
    }

    #[Test]
    public function instructions_require_declining_a_publish_or_push_request(): void
    {
        $this->seedCatalog();

        $instructions = $this->definition()->instructions;

        $this->assertStringContainsString(
            'You do not publish, push, or otherwise send changes anywhere outside this',
            $instructions,
            'the template must require declining a publish/push/remote-transmission request and stating a reason (FR-015)',
        );
    }

    #[Test]
    public function instructions_require_declining_a_toolchain_management_request(): void
    {
        $this->seedCatalog();

        $instructions = $this->definition()->instructions;

        $this->assertStringContainsString(
            "You do not install, upgrade, or otherwise manage this project's language",
            $instructions,
            'the template must require declining a toolchain-install/upgrade/manage request and stating a reason (FR-016)',
        );
    }

    #[Test]
    public function instructions_require_enumerating_partial_work_and_forbid_calling_it_done(): void
    {
        $this->seedCatalog();

        $instructions = $this->definition()->instructions;

        $this->assertStringContainsString(
            'Enumerate exactly what was and was not done.',
            $instructions,
            'the template must require enumerating exactly what was and was not completed when work is partial (FR-012)',
        );
        $this->assertStringContainsString(
            'describe partial work as complete because most of it succeeded.',
            $instructions,
            'the template must forbid describing partial work as done because most of it succeeded (FR-012)',
        );
    }

    #[Test]
    public function instructions_require_stating_a_cross_project_request_is_out_of_bounds(): void
    {
        $this->seedCatalog();

        $instructions = $this->definition()->instructions;

        $this->assertStringContainsString(
            'request to touch a different project, or a path outside this one, is refused',
            $instructions,
            'the template must state plainly that a request naming a different project is out of bounds (US4 AS3)',
        );
        $this->assertStringContainsString(
            'say plainly that it is outside the project',
            $instructions,
            'the template must require stating the request is outside the bound project, not attempting it',
        );
    }
}
