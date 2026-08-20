<?php

namespace ClarionApp\LlmClient\Commands;

use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Services\CommandPackLoader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
 * Scheduling registration (US3, Phase 5) and per-workspace try/catch fault
 * isolation (US4, Phase 6, research.md D4) are both added on top of this
 * same command, in LlmClientServiceProvider and handle() respectively.
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

                // Defense-in-depth wrap (research.md D4): CommandPackLoader::discover()
                // already guarantees per-file isolation by construction (a malformed
                // template becomes a TemplateDiscoveryProblem, never a thrown exception),
                // but an unanticipated failure -- e.g. a transient filesystem/permission
                // error discover()'s own code doesn't itself guard against -- must not
                // abort indexing for every other workspace in the same run (FR-008). One
                // workspace's failure is logged and skipped; the loop continues.
                try {
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
                } catch (\Throwable $e) {
                    Log::warning('project command reindex failed for workspace', [
                        'coding_project_id' => (string) $project->id,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
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
