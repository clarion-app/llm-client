<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\Backend\ClarionPackageServiceProvider;

class McpPromptRegistry
{
    public function getPrompts(?string $cursor = null): array
    {
        $allPrompts = $this->collectAllPrompts();

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

    public function getPrompt(string $name, array $arguments = []): ?array
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

    private function collectAllPrompts(): array
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
                ];
            }
        }

        usort($prompts, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $prompts;
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
