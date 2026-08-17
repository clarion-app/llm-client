<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\LlmClient\Exceptions\AgentStartingPointNotFoundException;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentDefinitionValidator;
use ClarionApp\LlmClient\Services\AgentStartingPointCatalog;
use ClarionApp\LlmClient\ValueObjects\AgentStartingPoint;
use ClarionApp\LlmClient\ValueObjects\AgentStartingPointSummary;
use Dedoc\Scramble\Generator;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for AgentStartingPointCatalog's register()/find()/has()/list()
 * mechanics, mirroring AgentKindRegistryTest's own structure. Exercised
 * against small fixture YAML files, never the real templates under
 * src/Templates/, so these tests describe the registry's own mechanics
 * rather than any one starting point's content.
 *
 * Written before AgentStartingPointCatalog exists -- every test in this
 * file is expected to fail with a "class not found"-style error until the
 * class is created. That is the intended RED state, not a mistake.
 */
class AgentStartingPointCatalogTest extends TestCase
{
    private string $satisfiedFixturePath;
    private string $unsatisfiedFixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->satisfiedFixturePath = sys_get_temp_dir().'/agent-starting-point-test-satisfied-'.uniqid().'.yaml';
        file_put_contents($this->satisfiedFixturePath, "name: alpha-agent\n");

        $this->unsatisfiedFixturePath = sys_get_temp_dir().'/agent-starting-point-test-unsatisfied-'.uniqid().'.yaml';
        file_put_contents($this->unsatisfiedFixturePath, <<<'YAML'
        name: beta-agent
        tools:
          allow:
            - nowhere.unresolvable.*
        YAML);
    }

    protected function tearDown(): void
    {
        @unlink($this->satisfiedFixturePath);
        @unlink($this->unsatisfiedFixturePath);
        $this->clearOperationCatalog();
        Mockery::close();
        parent::tearDown();
    }

    private function catalog(): AgentStartingPointCatalog
    {
        return new AgentStartingPointCatalog(new AgentDefinitionValidator(new AgentDefinitionParser()));
    }

    private function seedOperationCatalog(array $operations = []): void
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

    #[Test]
    public function register_adds_starting_point_to_the_catalog(): void
    {
        $catalog = $this->catalog();
        $catalog->register(new AgentStartingPoint('alpha', 'An alpha starting point.', $this->satisfiedFixturePath));

        $this->assertTrue($catalog->has('alpha'));
    }

    #[Test]
    public function has_returns_false_for_unregistered_slug(): void
    {
        $this->assertFalse($this->catalog()->has('nonexistent'));
    }

    #[Test]
    public function find_returns_the_registered_starting_point_by_slug(): void
    {
        $catalog = $this->catalog();
        $catalog->register(new AgentStartingPoint('alpha', 'An alpha starting point.', $this->satisfiedFixturePath));

        $found = $catalog->find('alpha');

        $this->assertSame('alpha', $found->slug);
        $this->assertSame('An alpha starting point.', $found->description);
        $this->assertSame($this->satisfiedFixturePath, $found->templatePath);
    }

    #[Test]
    public function find_throws_for_unregistered_slug_naming_it_and_listing_available(): void
    {
        $catalog = $this->catalog();
        $catalog->register(new AgentStartingPoint('alpha', 'An alpha starting point.', $this->satisfiedFixturePath));
        $catalog->register(new AgentStartingPoint('beta', 'A beta starting point.', $this->unsatisfiedFixturePath));

        try {
            $catalog->find('gamma');
            $this->fail('Expected AgentStartingPointNotFoundException');
        } catch (AgentStartingPointNotFoundException $e) {
            $this->assertSame('gamma', $e->getSlug());
            $this->assertContains('alpha', $e->getAvailableSlugs());
            $this->assertContains('beta', $e->getAvailableSlugs());
            $this->assertStringContainsString('gamma', $e->getMessage());
            $this->assertStringContainsString('alpha', $e->getMessage());
            $this->assertStringContainsString('beta', $e->getMessage());
        }
    }

    #[Test]
    public function list_returns_a_non_empty_description_for_every_entry(): void
    {
        $this->seedOperationCatalog();

        $catalog = $this->catalog();
        $catalog->register(new AgentStartingPoint('alpha', 'An alpha starting point.', $this->satisfiedFixturePath));

        $summaries = $catalog->list();

        $this->assertCount(1, $summaries);
        $this->assertInstanceOf(AgentStartingPointSummary::class, $summaries[0]);
        $this->assertNotEmpty($summaries[0]->description);
        $this->assertSame('An alpha starting point.', $summaries[0]->description);
    }

    #[Test]
    public function list_computes_requirements_satisfied_via_a_real_validator_check_call(): void
    {
        $this->seedOperationCatalog();

        $catalog = $this->catalog();
        $catalog->register(new AgentStartingPoint('alpha', 'Satisfies its own requirements.', $this->satisfiedFixturePath));
        $catalog->register(new AgentStartingPoint('beta', 'Does not satisfy its own requirements.', $this->unsatisfiedFixturePath));

        $summaries = $catalog->list();
        $bySlug = [];
        foreach ($summaries as $summary) {
            $bySlug[$summary->slug] = $summary;
        }

        $this->assertTrue($bySlug['alpha']->requirementsSatisfied);
        $this->assertSame([], $bySlug['alpha']->problems);

        $this->assertFalse($bySlug['beta']->requirementsSatisfied);
        $this->assertNotEmpty($bySlug['beta']->problems);
    }

    #[Test]
    public function list_returns_empty_array_when_nothing_registered(): void
    {
        $this->assertSame([], $this->catalog()->list());
    }
}
