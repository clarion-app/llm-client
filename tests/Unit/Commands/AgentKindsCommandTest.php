<?php

namespace ClarionApp\LlmClient\Tests\Unit\Commands;

use ClarionApp\LlmClient\Services\AgentKindRegistry;
use ClarionApp\LlmClient\ValueObjects\AgentKind;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for the `agent:kinds` Artisan command (089-agent-scaffolding-cli,
 * Phase 5/US2, T027, contracts §2, FR-005).
 *
 * Written before `agent:kinds` is registered, and before AgentKindRegistry
 * exists — every test in this file is expected to fail (class not found /
 * command not found) until Phase 5's own Implementation tasks (T031-T033)
 * land. That is the intended RED state, not a mistake.
 *
 * Rather than depending on the not-yet-built config-driven boot-time
 * registration (LlmClientServiceProvider::registerAgentKinds(), T032 — a
 * runtime config() change made inside a test would not retroactively
 * affect a registry already populated during boot), each test binds a
 * real AgentKindRegistry instance into the container itself, populated
 * exactly the way that eventual wiring would populate it from
 * config('llm-client.agent_definitions.kinds.enabled'). This exercises
 * the command's own behavior in isolation from the service provider's
 * wiring, which is covered separately by AgentScaffoldingJourneyTest.php's
 * container-resolved assertions.
 */
class AgentKindsCommandTest extends TestCase
{
    /**
     * @param string[] $enabledSlugs
     */
    private function bindRegistry(array $enabledSlugs): void
    {
        $registry = new AgentKindRegistry();

        $kinds = [
            'research' => fn () => AgentKind::research(),
            'coding' => fn () => AgentKind::coding(),
        ];

        foreach ($kinds as $slug => $factory) {
            if (in_array($slug, $enabledSlugs, true)) {
                $registry->register($factory());
            }
        }

        $this->app->instance(AgentKindRegistry::class, $registry);
    }

    #[Test]
    public function lists_every_registered_kind_with_its_description(): void
    {
        $this->bindRegistry(['research', 'coding']);

        $exitCode = Artisan::call('agent:kinds');

        $this->assertSame(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('research', $output);
        $this->assertStringContainsString(AgentKind::research()->getDescription(), $output);
        $this->assertStringContainsString('coding', $output);
        $this->assertStringContainsString(AgentKind::coding()->getDescription(), $output);
    }

    #[Test]
    public function reports_a_plain_message_when_no_kinds_are_available(): void
    {
        config(['llm-client.agent_definitions.kinds.enabled' => []]);
        $this->bindRegistry([]);

        $exitCode = Artisan::call('agent:kinds');

        $this->assertSame(0, $exitCode);
        $this->assertSame(
            'No ready-made agent kinds are available on this installation.',
            trim(Artisan::output())
        );
    }
}
