<?php

namespace ClarionApp\LlmClient\Commands;

use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Services\CommandPackLoader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 128-project-command-indexing, Phase 2 (Foundational), T009.
 *
 * contracts/reindex-project-commands-command.md. Realizes the freshness
 * window (research.md D3) by rebuilding every type = 'project_command' row
 * in operation_search_index from the current on-disk template state of
 * every non-deleted CodingProject, scoped to that type only -- the
 * pre-existing 'operation'/'prompt' rows ReindexOperationsJob owns are
 * never touched.
 *
 * No scheduling registration here (US3, a later phase) and no per-workspace
 * try/catch isolation yet (US4, also later) -- both are added on top of
 * this same command in their own phases. This phase's own contract only
 * requires the transactional delete-then-repopulate shape and --dry-run.
 */
class ReindexProjectCommandsCommand extends Command
{
    protected $signature = 'llm-client:reindex-project-commands
                            {--dry-run : Report counts without writing any row}';

    protected $description = 'Rebuild the project-command slice of the operations search index from every workspace\'s current templates';

    public function handle(CommandPackLoader $loader): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry-run mode — no changes will be made');
        }

        $workspacesScanned = 0;
        $commandsIndexed = 0;

        DB::transaction(function () use ($loader, $dryRun, &$workspacesScanned, &$commandsIndexed) {
            if (!$dryRun) {
                DB::table('operation_search_index')->where('type', 'project_command')->delete();
            }

            foreach (CodingProject::query()->cursor() as $project) {
                $workspacesScanned++;

                $result = $loader->discover($project);

                foreach ($result->commands as $command) {
                    $commandsIndexed++;

                    if ($dryRun) {
                        continue;
                    }

                    $summary = $command->description ?? "Project-defined command from {$command->relativePath}";
                    $searchableText = "{$command->name} {$command->description} {$command->instructions}";

                    DB::table('operation_search_index')->insert([
                        'operation_id' => "{$project->id}:{$command->name}",
                        'package_name' => null,
                        'type' => 'project_command',
                        'coding_project_id' => $project->id,
                        'summary' => $summary,
                        'method' => null,
                        'path' => null,
                        'searchable_text' => $searchableText,
                        'param_schema' => null,
                        'prompt_content' => $command->instructions,
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        $verb = $dryRun ? 'would be indexed' : 'indexed';
        $this->info("Workspaces scanned: {$workspacesScanned}");
        $this->info("Commands {$verb}: {$commandsIndexed}");

        if ($dryRun) {
            $this->comment('Dry-run complete — no changes were made');
        }

        return self::SUCCESS;
    }
}
