<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The return shape of CommandPackLoader::discover() (127-command-packs,
 * data-model.md §4). An empty $project->root_path (missing directory, or a
 * workspace with literally zero recognized files under either convention)
 * yields commands: [], problems: [] — never an error.
 */
final class CommandPackDiscoveryResult
{
    /**
     * @param list<CommandTemplate> $commands
     * @param list<TemplateDiscoveryProblem> $problems
     */
    public function __construct(
        public readonly array $commands,
        public readonly array $problems,
    ) {
    }
}
