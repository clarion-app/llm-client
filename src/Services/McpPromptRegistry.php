<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\Backend\ClarionPackageServiceProvider;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\ValueObjects\CommandTemplate;
use ClarionApp\LlmClient\ValueObjects\CommandTemplateConvention;

class McpPromptRegistry
{
    public function __construct(
        private readonly CommandPackLoader $commandPackLoader = new CommandPackLoader(new CommandTemplateParser()),
    ) {
    }

    public function getPrompts(?string $cursor = null, ?string $codingProjectId = null, ?string $userId = null): array
    {
        $allPrompts = $this->collectAllPrompts($codingProjectId, $userId);

        $offset = 0;
        if ($cursor) {
            $decoded = base64_decode($cursor, true);
            if ($decoded !== false) {
                $cursorData = json_decode($decoded, true);
                $offset = $cursorData['offset'] ?? 0;
            }
        }

        $pageSize = config('llm-client.mcp.page_size', 50);
        $page = array_slice($allPrompts, $offset, $pageSize);

        $nextOffset = $offset + $pageSize;
        $nextCursor = null;
        if ($nextOffset < count($allPrompts)) {
            $nextCursor = base64_encode(json_encode(['offset' => $nextOffset]));
        }

        return [
            'prompts' => $page,
            'nextCursor' => $nextCursor,
        ];
    }

    public function getPrompt(string $name, array $arguments = [], ?string $codingProjectId = null, ?string $userId = null): ?array
    {
        // A collision-alternate name resolves directly against the named
        // source, bypassing the project-vs-builtin precedence check
        // entirely (127-command-packs, research.md D4) -- this is
        // unconditional, so the distinguishing form itself can never be
        // shadowed.
        if (str_starts_with($name, 'builtin:')) {
            return $this->resolveBuiltinPrompt(substr($name, strlen('builtin:')), $arguments);
        }

        if (str_starts_with($name, 'copilot-agent:')) {
            return $this->resolveProjectPromptByConvention(
                substr($name, strlen('copilot-agent:')),
                CommandTemplateConvention::CopilotAgent,
                $arguments,
                $codingProjectId,
                $userId,
            );
        }

        $project = $this->resolveOwnedProject($codingProjectId, $userId);

        if ($project !== null) {
            $result = $this->commandPackLoader->discover($project);
            $winner = $this->pickProjectCollisionWinner($result->commands, $name);

            if ($winner !== null) {
                return $this->buildProjectPromptResult($winner, $arguments);
            }
        }

        return $this->resolveBuiltinPrompt($name, $arguments);
    }

    /**
     * Resolves a bare name against every project-defined command matching
     * it (case-folded), returning the one that wins the intra-workspace
     * convention tie-break when more than one convention derived the same
     * name in the same workspace (127-command-packs, research.md D4,
     * Grounding note 13) -- ClaudeCommand beats CopilotAgent, via a small
     * private comparison local to this class.
     *
     * @param list<CommandTemplate> $commands
     */
    private function pickProjectCollisionWinner(array $commands, string $name): ?CommandTemplate
    {
        $matches = array_values(array_filter(
            $commands,
            fn (CommandTemplate $template) => mb_strtolower($template->name) === mb_strtolower($name),
        ));

        if ($matches === []) {
            return null;
        }

        usort($matches, fn (CommandTemplate $a, CommandTemplate $b) => $this->conventionPriority($a->convention) <=> $this->conventionPriority($b->convention));

        return $matches[0];
    }

    private function conventionPriority(CommandTemplateConvention $convention): int
    {
        return $convention === CommandTemplateConvention::ClaudeCommand ? 0 : 1;
    }

    private function resolveBuiltinPrompt(string $name, array $arguments): ?array
    {
        $packages = ClarionPackageServiceProvider::getPackageDescriptions();

        foreach ($packages as $packageName => $meta) {
            $shortName = $this->getShortName($packageName);
            $customPrompts = ClarionPackageServiceProvider::getCustomPrompts($packageName);

            foreach ($customPrompts as $promptKey => $promptContent) {
                $promptName = "{$shortName}_{$promptKey}";

                if ($promptName === $name) {
                    $text = $promptContent;

                    if (!empty($arguments['command'])) {
                        $text .= "\n\nUser command: " . $arguments['command'];
                    }

                    return [
                        'description' => $this->buildDescription($shortName, $promptKey),
                        'messages' => [
                            [
                                'role' => 'user',
                                'content' => [
                                    'type' => 'text',
                                    'text' => $text,
                                ],
                            ],
                        ],
                    ];
                }
            }
        }

        return null;
    }

    private function resolveProjectPromptByConvention(
        string $name,
        CommandTemplateConvention $convention,
        array $arguments,
        ?string $codingProjectId,
        ?string $userId,
    ): ?array {
        $project = $this->resolveOwnedProject($codingProjectId, $userId);

        if ($project === null) {
            return null;
        }

        $result = $this->commandPackLoader->discover($project);

        foreach ($result->commands as $template) {
            if ($template->convention === $convention && mb_strtolower($template->name) === mb_strtolower($name)) {
                return $this->buildProjectPromptResult($template, $arguments);
            }
        }

        return null;
    }

    private function collectAllPrompts(?string $codingProjectId = null, ?string $userId = null): array
    {
        $packages = ClarionPackageServiceProvider::getPackageDescriptions();
        $prompts = [];

        foreach ($packages as $packageName => $meta) {
            $shortName = $this->getShortName($packageName);
            $customPrompts = ClarionPackageServiceProvider::getCustomPrompts($packageName);

            foreach ($customPrompts as $promptKey => $promptContent) {
                $name = "{$shortName}_{$promptKey}";
                $prompts[] = [
                    'name' => $name,
                    'description' => $this->buildDescription($shortName, $promptKey),
                    'arguments' => [
                        [
                            'name' => 'command',
                            'description' => "The user's natural language command for context",
                            'required' => false,
                        ],
                    ],
                    'source' => 'builtin',
                    '_effectiveName' => mb_strtolower($name),
                    '_convention' => null,
                ];
            }
        }

        $project = $this->resolveOwnedProject($codingProjectId, $userId);

        if ($project !== null) {
            $result = $this->commandPackLoader->discover($project);

            foreach ($result->commands as $template) {
                $prompts[] = [
                    'name' => $template->name,
                    'description' => $this->resolveTemplateDescription($template),
                    'arguments' => [
                        [
                            'name' => 'command',
                            'description' => "The user's natural language command for context",
                            'required' => false,
                        ],
                    ],
                    'source' => 'project',
                    'codingProjectId' => $template->codingProjectId,
                    '_effectiveName' => mb_strtolower($template->name),
                    '_convention' => $template->convention,
                ];
            }
        }

        $prompts = $this->resolveCollisions($prompts);

        usort($prompts, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $prompts;
    }

    /**
     * Groups the merged, pre-sort entry set by an effective, case-folded
     * name and resolves any group with more than one occupant
     * (127-command-packs, research.md D4, Grounding note 13): a project
     * entry always beats a built-in, and among multiple project occupants
     * for the same name, CommandTemplateConvention::ClaudeCommand beats
     * CopilotAgent. The winner keeps the bare name -- canonicalized to a
     * participating built-in's own original-cased spelling, since project
     * template names are unconditionally lowercased (D8) and would
     * otherwise never even group with the built-in they collide with. Each
     * loser is additionally listed under a synthesized alternate name
     * (`builtin:<name>` for a shadowed built-in, `copilot-agent:<name>` for
     * a shadowed same-workspace loser), and both occupants of a collision
     * gain `collidesWith` naming the other's own listed name. A
     * non-colliding entry is left with no `collidesWith` key at all.
     *
     * @param list<array<string, mixed>> $prompts
     * @return list<array<string, mixed>>
     */
    private function resolveCollisions(array $prompts): array
    {
        $groups = [];
        foreach ($prompts as $index => $entry) {
            $groups[$entry['_effectiveName']][] = $index;
        }

        foreach ($groups as $indices) {
            if (count($indices) < 2) {
                continue;
            }

            usort($indices, fn (int $a, int $b) => $this->collisionPriority($prompts[$a]) <=> $this->collisionPriority($prompts[$b]));

            $winnerIdx = $indices[0];
            $canonicalName = $prompts[$winnerIdx]['name'];

            foreach ($indices as $idx) {
                if ($prompts[$idx]['source'] === 'builtin') {
                    $canonicalName = $prompts[$idx]['name'];
                    break;
                }
            }

            $prompts[$winnerIdx]['name'] = $canonicalName;

            $losers = array_slice($indices, 1);
            foreach ($losers as $loserIdx) {
                $prefix = $prompts[$loserIdx]['source'] === 'builtin' ? 'builtin:' : 'copilot-agent:';
                $prompts[$loserIdx]['name'] = $prefix.$canonicalName;
                $prompts[$loserIdx]['collidesWith'] = $canonicalName;
            }

            $prompts[$winnerIdx]['collidesWith'] = $prompts[$losers[0]]['name'];
        }

        foreach ($prompts as $index => $entry) {
            unset($prompts[$index]['_effectiveName'], $prompts[$index]['_convention']);
        }

        return array_values($prompts);
    }

    private function collisionPriority(array $entry): int
    {
        if ($entry['source'] === 'builtin') {
            return 2;
        }

        return $entry['_convention'] === CommandTemplateConvention::ClaudeCommand ? 0 : 1;
    }

    /**
     * Resolves and returns the given coding project only when it is owned
     * by $userId (127-command-packs, research.md D6/D7). A miss -- absent
     * id, foreign-owned, soft-deleted, or either id simply null -- yields
     * null with no distinct error, so callers fall back to built-ins only,
     * matching a pre-127 call exactly.
     */
    private function resolveOwnedProject(?string $codingProjectId, ?string $userId): ?CodingProject
    {
        if ($codingProjectId === null || $userId === null) {
            return null;
        }

        return CodingProject::where('id', $codingProjectId)->where('user_id', $userId)->first();
    }

    private function resolveTemplateDescription(CommandTemplate $template): string
    {
        return $template->description ?? "Project-defined command from {$template->relativePath}";
    }

    private function buildProjectPromptResult(CommandTemplate $template, array $arguments): array
    {
        $argumentText = (string) ($arguments['command'] ?? '');

        $text = $template->hasArgumentPlaceholder
            ? str_replace('$ARGUMENTS', (new CommandArgumentPromptBuilder())->untrustedArgumentBlock($argumentText), $template->instructions)
            : $template->instructions;

        return [
            'description' => $this->resolveTemplateDescription($template),
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => $text,
                    ],
                ],
            ],
        ];
    }

    private function getShortName(string $packageName): string
    {
        $shortName = $packageName;
        if (str_starts_with($shortName, '@clarion-app/')) {
            $shortName = substr($shortName, strlen('@clarion-app/'));
        }
        return $shortName;
    }

    private function buildDescription(string $shortName, string $promptKey): string
    {
        $action = $promptKey === 'listOperations'
            ? 'discovering and selecting'
            : 'executing';

        return "Guidance for {$action} {$shortName} tools";
    }
}
