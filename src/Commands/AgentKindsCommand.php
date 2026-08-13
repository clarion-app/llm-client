<?php

namespace ClarionApp\LlmClient\Commands;

use ClarionApp\LlmClient\Services\AgentKindRegistry;
use Illuminate\Console\Command;

/**
 * `agent:kinds` — lists every registered ready-made agent kind's slug and
 * description (089-agent-scaffolding-cli, contracts §2, FR-005).
 */
class AgentKindsCommand extends Command
{
    protected $signature = 'agent:kinds';

    protected $description = 'List the ready-made agent kinds available for agent:create --kind=';

    public function handle(AgentKindRegistry $registry): int
    {
        $kinds = $registry->list();

        if ($kinds === []) {
            $this->line('No ready-made agent kinds are available on this installation.');

            return self::SUCCESS;
        }

        $columnWidth = max(array_map(static fn (array $kind) => strlen($kind['slug']), $kinds)) + 4;

        foreach ($kinds as $kind) {
            $this->line(str_pad($kind['slug'], $columnWidth).$kind['description']);
        }

        return self::SUCCESS;
    }
}
