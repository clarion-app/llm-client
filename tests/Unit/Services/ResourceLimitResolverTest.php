<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Services\ResourceLimitResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 124-command-limit-controls, US1, T008 (data-model.md §1's precedence
 * rule: `effective(limit) = project.<limit>_override ?? config(...)`).
 * Exercises ResourceLimitResolver::resolve(CodingProject $project): array
 * entirely against an unsaved, in-memory CodingProject instance -- no
 * database write is needed to prove a pure resolution function, mirroring
 * WorkspaceSearchServiceTest.php's own `new CodingProject([...])` (never
 * ::create()) convention for a service that only reads column values off
 * the model, never persists it.
 *
 * Written before ResourceLimitResolver exists -- expected to FAIL red
 * ("Class ... ResourceLimitResolver not found") until T013 creates it.
 */
class ResourceLimitResolverTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: mixed, 4: mixed}>
     *   [overrideColumn, resolvedKey, configKey, overrideValue, installationDefault]
     */
    public static function limitProvider(): array
    {
        return [
            'time' => ['time_limit_override_seconds', 'time_limit_seconds', 'llm-client.coding_agent.command_timeout_seconds', 300, 60],
            'memory' => ['memory_limit_override_mb', 'memory_limit_mb', 'llm-client.coding_agent.command_memory_limit_mb', 512, 256],
            'cpu' => ['cpu_limit_override', 'cpu_limit', 'llm-client.coding_agent.command_cpu_limit', '2.0', '1.0'],
            'pids' => ['pids_limit_override', 'pids_limit', 'llm-client.coding_agent.command_pids_limit', 256, 128],
            'disk' => ['disk_limit_override_mb', 'disk_limit_mb', 'llm-client.coding_agent.command_disk_limit_mb', 1024, 512],
            'output' => ['output_cap_override_bytes', 'output_cap_bytes', 'llm-client.coding_agent.command_output_cap_bytes', 524288, 262144],
        ];
    }

    private function resolver(): ResourceLimitResolver
    {
        return app(ResourceLimitResolver::class);
    }

    // -----------------------------------------------------------------
    // Override-vs-default precedence, per limit, independently.
    // -----------------------------------------------------------------

    #[Test]
    #[DataProvider('limitProvider')]
    public function a_project_with_the_override_column_set_resolves_to_that_value(
        string $overrideColumn,
        string $resolvedKey,
        string $configKey,
        mixed $overrideValue,
        mixed $installationDefault,
    ): void {
        config([$configKey => $installationDefault]);

        $project = new CodingProject([$overrideColumn => $overrideValue]);

        $resolved = $this->resolver()->resolve($project);

        $this->assertSame($overrideValue, $resolved[$resolvedKey], "{$resolvedKey} must resolve to the workspace's own override value, not the installation default");
    }

    #[Test]
    #[DataProvider('limitProvider')]
    public function a_project_with_the_override_column_null_resolves_to_the_current_config_default(
        string $overrideColumn,
        string $resolvedKey,
        string $configKey,
        mixed $overrideValue,
        mixed $installationDefault,
    ): void {
        config([$configKey => $installationDefault]);

        $project = new CodingProject([$overrideColumn => null]);

        $resolved = $this->resolver()->resolve($project);

        $this->assertSame($installationDefault, $resolved[$resolvedKey], "{$resolvedKey} must fall back to the installation-wide config default when no override is set");
    }

    // -----------------------------------------------------------------
    // Per-limit independence (FR-001: "each... independently", not an
    // all-or-nothing switch): overriding ONE limit must leave the other
    // five resolved at their installation defaults.
    // -----------------------------------------------------------------

    #[Test]
    public function overriding_only_the_time_limit_leaves_every_other_limit_at_its_installation_default(): void
    {
        config([
            'llm-client.coding_agent.command_timeout_seconds' => 60,
            'llm-client.coding_agent.command_memory_limit_mb' => 256,
            'llm-client.coding_agent.command_cpu_limit' => '1.0',
            'llm-client.coding_agent.command_pids_limit' => 128,
            'llm-client.coding_agent.command_disk_limit_mb' => 512,
            'llm-client.coding_agent.command_output_cap_bytes' => 262144,
        ]);

        $project = new CodingProject(['time_limit_override_seconds' => 900]);

        $resolved = $this->resolver()->resolve($project);

        $this->assertSame(900, $resolved['time_limit_seconds'], 'the one overridden limit must take effect');
        $this->assertSame(256, $resolved['memory_limit_mb'], 'memory must stay at its installation default -- overriding time alone must not touch it');
        $this->assertSame('1.0', $resolved['cpu_limit'], 'cpu must stay at its installation default');
        $this->assertSame(128, $resolved['pids_limit'], 'pids must stay at its installation default');
        $this->assertSame(512, $resolved['disk_limit_mb'], 'disk must stay at its installation default');
        $this->assertSame(262144, $resolved['output_cap_bytes'], 'output cap must stay at its installation default');
    }

    #[Test]
    public function overriding_only_the_pids_limit_leaves_every_other_limit_at_its_installation_default(): void
    {
        config([
            'llm-client.coding_agent.command_timeout_seconds' => 60,
            'llm-client.coding_agent.command_memory_limit_mb' => 256,
            'llm-client.coding_agent.command_cpu_limit' => '1.0',
            'llm-client.coding_agent.command_pids_limit' => 128,
            'llm-client.coding_agent.command_disk_limit_mb' => 512,
            'llm-client.coding_agent.command_output_cap_bytes' => 262144,
        ]);

        $project = new CodingProject(['pids_limit_override' => 4]);

        $resolved = $this->resolver()->resolve($project);

        $this->assertSame(60, $resolved['time_limit_seconds']);
        $this->assertSame(256, $resolved['memory_limit_mb']);
        $this->assertSame('1.0', $resolved['cpu_limit']);
        $this->assertSame(4, $resolved['pids_limit'], 'the one overridden limit must take effect');
        $this->assertSame(512, $resolved['disk_limit_mb']);
        $this->assertSame(262144, $resolved['output_cap_bytes']);
    }

    // -----------------------------------------------------------------
    // cpu_limit_override resolves as a STRING, never cast -- Docker's
    // --cpus accepts fractional values an int/float cast would corrupt.
    // -----------------------------------------------------------------

    #[Test]
    public function cpu_limit_resolves_as_a_string_whether_from_an_override_or_the_config_default(): void
    {
        config(['llm-client.coding_agent.command_cpu_limit' => '1.0']);

        $overridden = new CodingProject(['cpu_limit_override' => '0.5']);
        $resolvedOverridden = $this->resolver()->resolve($overridden);
        $this->assertIsString($resolvedOverridden['cpu_limit']);
        $this->assertSame('0.5', $resolvedOverridden['cpu_limit']);

        $defaulted = new CodingProject(['cpu_limit_override' => null]);
        $resolvedDefaulted = $this->resolver()->resolve($defaulted);
        $this->assertIsString($resolvedDefaulted['cpu_limit']);
        $this->assertSame('1.0', $resolvedDefaulted['cpu_limit']);
    }

    // -----------------------------------------------------------------
    // Read-fresh discipline (data-model.md §1, research.md R1): the
    // config default must be read fresh on every resolve() call, never
    // cached from an earlier call within the same process.
    // -----------------------------------------------------------------

    #[Test]
    public function the_config_default_is_read_fresh_on_every_call_not_cached_across_calls(): void
    {
        $project = new CodingProject(['memory_limit_override_mb' => null]);

        config(['llm-client.coding_agent.command_memory_limit_mb' => 256]);
        $first = $this->resolver()->resolve($project);
        $this->assertSame(256, $first['memory_limit_mb']);

        config(['llm-client.coding_agent.command_memory_limit_mb' => 1024]);
        $second = $this->resolver()->resolve($project);
        $this->assertSame(1024, $second['memory_limit_mb'], 'a config value changed between two resolve() calls on the SAME project must be reflected on the second call -- caching the first call\'s result would leave this at the stale 256');
    }

    #[Test]
    public function every_resolved_key_is_present_in_the_returned_array(): void
    {
        $project = new CodingProject([]);

        $resolved = $this->resolver()->resolve($project);

        $this->assertArrayHasKey('time_limit_seconds', $resolved);
        $this->assertArrayHasKey('memory_limit_mb', $resolved);
        $this->assertArrayHasKey('cpu_limit', $resolved);
        $this->assertArrayHasKey('pids_limit', $resolved);
        $this->assertArrayHasKey('disk_limit_mb', $resolved);
        $this->assertArrayHasKey('output_cap_bytes', $resolved);
    }
}
