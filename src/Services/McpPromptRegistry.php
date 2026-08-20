<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\Backend\ClarionPackageServiceProvider;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\ValueObjects\CommandTemplate;

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
        $project = $this->resolveOwnedProject($codingProjectId, $userId);

        if ($project !== null) {
            $result = $this->commandPackLoader->discover($project);

            foreach ($result->commands as $template) {
                if (mb_strtolower($template->name) === mb_strtolower($name)) {
                    return $this->buildProjectPromptResult($template, $arguments);
                }
            }
        }

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

    private function collectAllPrompts(?string $codingProjectId = null, ?string $userId = null): array
    {
        $packages = ClarionPackageServiceProvider::getPackageDescriptions();
        $prompts = [];

        foreach ($packages as $packageName => $meta) {
            $shortName = $this->getShortName($packageName);
            $customPrompts = ClarionPackageServiceProvider::getCustomPrompts($packageName);

            foreach ($customPrompts as $promptKey => $promptContent) {
                $prompts[] = [
                    'name' => "{$shortName}_{$promptKey}",
                    'description' => $this->buildDescription($shortName, $promptKey),
                    'arguments' => [
                        [
                            'name' => 'command',
                            'description' => "The user's natural language command for context",
                            'required' => false,
                        ],
                    ],
                    'source' => 'builtin',
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
                ];
            }
        }

        usort($prompts, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $prompts;
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
