<?php

namespace ClarionApp\LlmClient\Commands;

use ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;
use ClarionApp\LlmClient\Exceptions\AgentKindNotFoundException;
use ClarionApp\LlmClient\Exceptions\AgentScaffoldCollisionException;
use ClarionApp\LlmClient\Exceptions\AgentScaffoldDestinationException;
use ClarionApp\LlmClient\Services\AgentDefinitionScaffolder;
use ClarionApp\LlmClient\Services\AgentDefinitionScaffoldWriter;
use ClarionApp\LlmClient\Services\AgentKindRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * `agent:create {name} {--path=} {--kind=}` — generates one complete,
 * immediately valid agent definition file and writes it to disk
 * (089-agent-scaffolding-cli, contracts §1 rows 1-3/8). Phase 4 adds the
 * catch around AgentScaffoldCollisionException/AgentScaffoldDestinationException
 * so a collision or an unusable destination fails cleanly (exit 1, clear
 * message) instead of an uncaught exception propagating out of the
 * command. Phase 5 adds `--kind`, resolved as the very first action of
 * handle() — before name validation, before path computation, before any
 * filesystem interaction (research.md D9) — so an unavailable kind fails
 * clearly with nothing ever written to disk.
 */
class AgentCreateCommand extends Command
{
    protected $signature = 'agent:create {name} {--path=} {--kind=}';

    protected $description = 'Generate a complete, immediately-runnable agent definition file';

    public function handle(AgentDefinitionScaffolder $scaffolder, AgentDefinitionScaffoldWriter $writer, AgentKindRegistry $kindRegistry): int
    {
        $kind = null;
        if ($kindSlug = $this->option('kind')) {
            try {
                $kind = $kindRegistry->find($kindSlug);
            } catch (AgentKindNotFoundException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $path = $this->option('path') ?? getcwd();

        try {
            $scaffold = $scaffolder->generate($this->argument('name'), $kind);
        } catch (AgentDefinitionParseException|AgentDefinitionResolutionException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (Str::slug($scaffold->name) === '') {
            $this->error(sprintf('The name "%s" cannot be turned into a valid file name.', $scaffold->name));

            return self::FAILURE;
        }

        try {
            $absolutePath = $writer->write($path, $scaffold->filename, $scaffold->content);
        } catch (AgentScaffoldCollisionException $e) {
            $destination = rtrim($path, '/').'/'.$scaffold->filename;
            $this->error(sprintf('An agent definition already exists at %s. Choose a different name, or remove it first if you intend to replace it.', $destination));

            return self::FAILURE;
        } catch (AgentScaffoldDestinationException $e) {
            $this->error(match ($e->getReason()) {
                'not_found' => sprintf('Destination directory does not exist: %s.', $path),
                'not_writable' => sprintf('Destination directory is not writable: %s.', $path),
                'write_failed' => $e->getMessage(),
            });

            return self::FAILURE;
        }

        $this->info("Agent definition written to {$absolutePath}.");

        return self::SUCCESS;
    }
}
