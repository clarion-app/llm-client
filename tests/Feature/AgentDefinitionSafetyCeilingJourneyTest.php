<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use Dedoc\Scramble\Generator;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AgentDefinition::isOperationPermitted()/isConfirmationRequired() — the
 * installation-ceiling union (086-agent-yaml-schema, spec.md US3 Acceptance
 * Scenarios 1-3, quickstart.md steps 7-9, mutation-checklist rows 1-2).
 *
 * Phase 3 (US1) built both methods to check only the definition's own
 * toolsAllow/toolsDeny/safetyConfirmationRequired/safetyDenylist — neither
 * method yet consults config('llm-client.api_denylist') or
 * config('llm-client.confirm_methods') (tasks.md Grounding note 3). Every
 * scenario below is expected to be genuinely RED until Phase 4/US3's T026/
 * T027 add that union.
 */
class AgentDefinitionSafetyCeilingJourneyTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function an_installation_denylist_cannot_be_widened_by_an_explicit_tools_allow(): void
    {
        $this->seedOperationCatalog([
            'contacts.destroy' => ['path' => '/api/contacts/{id}', 'method' => 'delete', 'summary' => 'Delete a contact'],
        ]);

        // The installation's own denylist matches this operation's
        // resolved path, exactly the same fnmatch() normalization
        // ApiCallValidator::validate() applies (path-pattern, not
        // operationId-pattern — tasks.md Grounding note 1).
        $this->app['config']->set('llm-client.api_denylist', ['/api/contacts/*']);

        $raw = <<<YAML
name: ceiling-agent
tools:
  allow:
    - contacts.destroy
YAML;

        $definition = (new AgentDefinitionParser())->parse($raw);

        // No safety.denylist entry at all in the definition itself — only
        // the installation's api_denylist stands between this explicit
        // allow and permission. isOperationPermitted() must still be
        // false regardless of the definition's own tools.allow.
        $this->assertFalse($definition->isOperationPermitted('contacts.destroy'));
    }

    #[Test]
    public function an_installation_confirm_method_cannot_be_waived_by_an_explicitly_empty_confirmation_required(): void
    {
        $this->seedOperationCatalog([
            'contacts.destroy' => ['path' => '/api/contacts/{id}', 'method' => 'delete', 'summary' => 'Delete a contact'],
        ]);

        $this->app['config']->set('llm-client.confirm_methods', ['DELETE']);

        $raw = <<<YAML
name: ceiling-agent
tools:
  allow:
    - contacts.destroy
safety:
  confirmation_required: []
YAML;

        $definition = (new AgentDefinitionParser())->parse($raw);

        // safety.confirmation_required is explicitly empty (not merely
        // omitted) — an author cannot opt out of the installation's own
        // confirm_methods floor by stating an empty list.
        $this->assertSame([], $definition->safetyConfirmationRequired);
        $this->assertTrue($definition->isConfirmationRequired('contacts.destroy'));
    }

    #[Test]
    public function a_definitions_own_stricter_confirmation_requirement_governs_and_does_not_leak_to_other_operations(): void
    {
        $this->seedOperationCatalog([
            'contacts.destroy' => ['path' => '/api/contacts/{id}', 'method' => 'delete', 'summary' => 'Delete a contact'],
            'weather.get_forecast' => ['path' => '/api/weather', 'method' => 'get', 'summary' => 'Get forecast'],
        ]);

        // The installation requires nothing at all.
        $this->app['config']->set('llm-client.confirm_methods', []);

        $raw = <<<YAML
name: ceiling-agent
tools:
  allow:
    - "*"
safety:
  confirmation_required:
    - contacts.destroy
YAML;

        $definition = (new AgentDefinitionParser())->parse($raw);

        $this->assertTrue($definition->isConfirmationRequired('contacts.destroy'));
        $this->assertFalse($definition->isConfirmationRequired('weather.get_forecast'));
    }

    /**
     * Seeds both of ApiManager's live-catalog seams with the identical
     * OpenAPI-shaped document: the static $apiDocsCache property
     * getOperationDetails() reads directly (reflection, matching
     * AgentDefinitionFullJourneyTest's own established convention), and a
     * fake Dedoc\Scramble\Generator bound into the container for
     * getOperations() — which never reads that cache, it always resolves a
     * fresh DocumentationService.
     *
     * @param array<string, array{path: string, method: string, summary: string}> $operations
     */
    private function seedOperationCatalog(array $operations): void
    {
        $paths = [];
        foreach ($operations as $operationId => $entry) {
            $paths[$entry['path']][$entry['method']] = [
                'operationId' => $operationId,
                'summary' => $entry['summary'],
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
}
