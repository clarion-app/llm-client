<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\Backend\ApiManager;
use ClarionApp\LlmClient\Services\AgentStartingPointCatalog;
use ClarionApp\LlmClient\ValueObjects\AgentStartingPointSummary;
use Dedoc\Scramble\Generator;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Confirms the container wires all four default starting points into the
 * AgentStartingPointCatalog singleton at boot, matching
 * agent_definitions.starting_points.enabled's default value -- a
 * regression guard mirroring the registration test the sibling
 * AgentKindRegistry mechanism already has for its own boot-time wiring.
 *
 * Written before registerAgentStartingPoints() exists -- every test in
 * this file is expected to fail until it is added to
 * LlmClientServiceProvider::boot(). That is the intended RED state, not a
 * mistake.
 */
class AgentStartingPointRegistrationTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();
        parent::tearDown();
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
    public function the_container_bound_catalog_registers_all_four_default_starting_points_in_order(): void
    {
        $this->seedOperationCatalog();

        $catalog = $this->app->make(AgentStartingPointCatalog::class);

        $this->assertTrue($catalog->has('research'));
        $this->assertTrue($catalog->has('coding'));
        $this->assertTrue($catalog->has('data'));
        $this->assertTrue($catalog->has('scheduler'));

        $slugs = array_map(fn (AgentStartingPointSummary $summary): string => $summary->slug, $catalog->list());
        $this->assertSame(['research', 'coding', 'data', 'scheduler'], $slugs);
    }
}
