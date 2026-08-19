<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\CodingProject;

/**
 * 124-command-limit-controls, US1 (data-model.md §1, contracts/
 * resource-limits.md §2). Resolves a workspace's six effective resource
 * limits: a non-null override column always wins; a null column falls
 * back to the current installation-wide config default. Each of the six
 * is resolved entirely independently -- overriding one has no effect on
 * how the other five resolve (FR-001's "each... independently", not an
 * all-or-nothing switch).
 *
 * Every config default is read fresh, at the exact moment resolve() is
 * called -- never cached across calls, matching network_enabled's own
 * "read exactly once, immediately before flag construction" discipline
 * (research.md R1), extended here to six values. Called exactly once per
 * runCommand() invocation, by CodingWorkspaceController::runCommand(),
 * immediately before DockerCommandExecutor::run().
 */
class ResourceLimitResolver
{
    /**
     * @return array{
     *     time_limit_seconds: int,
     *     memory_limit_mb: int,
     *     cpu_limit: string,
     *     pids_limit: int,
     *     disk_limit_mb: int,
     *     output_cap_bytes: int,
     * }
     */
    public function resolve(CodingProject $project): array
    {
        return [
            'time_limit_seconds' => $project->time_limit_override_seconds
                ?? (int) config('llm-client.coding_agent.command_timeout_seconds', 60),
            'memory_limit_mb' => $project->memory_limit_override_mb
                ?? (int) config('llm-client.coding_agent.command_memory_limit_mb', 256),
            // Deliberately never cast to int/float -- Docker's --cpus
            // accepts fractional values ("0.5") a numeric cast would
            // corrupt or round, matching command_cpu_limit's own (string)
            // read in DockerCommandExecutor::run().
            'cpu_limit' => $project->cpu_limit_override
                ?? (string) config('llm-client.coding_agent.command_cpu_limit', '1.0'),
            'pids_limit' => $project->pids_limit_override
                ?? (int) config('llm-client.coding_agent.command_pids_limit', 128),
            'disk_limit_mb' => $project->disk_limit_override_mb
                ?? (int) config('llm-client.coding_agent.command_disk_limit_mb', 512),
            'output_cap_bytes' => $project->output_cap_override_bytes
                ?? (int) config('llm-client.coding_agent.command_output_cap_bytes', 262144),
        ];
    }
}
