<?php

namespace ClarionApp\LlmClient\Commands;

use ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;
use ClarionApp\LlmClient\Services\AgentDefinitionScaffolder;
use ClarionApp\LlmClient\Services\AgentDefinitionScaffoldWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * `agent:create {name} {--path=}` — generates one complete, immediately
 * valid agent definition file and writes it to disk
 * (089-agent-scaffolding-cli, contracts §1 rows 2/3/8; rows 1 and 4-7 are
 * Phase 4/5's own additions, not this phase's scope). No `--kind` option
 * yet (Phase 5), and no catch around AgentScaffoldCollisionException/
 * AgentScaffoldDestinationException yet (Phase 4) — both deliberately left
 * for later phases per this feature's own tasks.md Ordering grounding note.
 */
class AgentCreateCommand extends Command
{
    protected $signature = 'agent:create {name} {--path=}';

    protected $description = 'Generate a complete, immediately-runnable agent definition file';

    public function handle(AgentDefinitionScaffolder $scaffolder, AgentDefinitionScaffoldWriter $writer): int
    {
        $path = $this->option('path') ?? getcwd();

        try {
            $scaffold = $scaffolder->generate($this->argument('name'));
        } catch (AgentDefinitionParseException|AgentDefinitionResolutionException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (Str::slug($scaffold->name) === '') {
            $this->error(sprintf('The name "%s" cannot be turned into a valid file name.', $scaffold->name));

            return self::FAILURE;
        }

        $absolutePath = $writer->write($path, $scaffold->filename, $scaffold->content);

        $this->info("Agent definition written to {$absolutePath}.");

        return self::SUCCESS;
    }
}
