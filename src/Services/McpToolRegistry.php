<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\ClarionPackageServiceProvider;

class McpToolRegistry
{
    public function getTools(?string $cursor = null, ?string $package = null): array
    {
        $allTools = $this->collectAllTools($package);

        $offset = 0;
        if ($cursor) {
            $decoded = base64_decode($cursor, true);
            if ($decoded !== false) {
                $cursorData = json_decode($decoded, true);
                $offset = $cursorData['offset'] ?? 0;
            }
        }

        $pageSize = config('llm-client.mcp.page_size', 50);
        $page = array_slice($allTools, $offset, $pageSize);

        $nextOffset = $offset + $pageSize;
        $nextCursor = null;
        if ($nextOffset < count($allTools)) {
            $nextCursor = base64_encode(json_encode(['offset' => $nextOffset]));
        }

        return [
            'tools' => $page,
            'nextCursor' => $nextCursor,
        ];
    }

    public function findTool(string $toolName): ?array
    {
        $allTools = $this->collectAllTools();
        foreach ($allTools as $tool) {
            if ($tool['name'] === $toolName) {
                return $tool;
            }
        }
        return null;
    }

    private function collectAllTools(?string $packageFilter = null): array
    {
        $packages = ClarionPackageServiceProvider::getPackageDescriptions();
        $tools = [];

        foreach ($packages as $packageName => $meta) {
            $shortName = $this->getShortName($packageName);

            if ($packageFilter !== null && $shortName !== $packageFilter) {
                continue;
            }

            $operations = ClarionPackageServiceProvider::getPackageOperations($packageName);

            foreach ($operations as $operation) {
                $operationId = $operation['operationId'] ?? null;
                if (!$operationId) {
                    continue;
                }

                $details = ApiManager::getOperationDetails($operationId);
                if (empty((array) $details)) {
                    continue;
                }

                $tool = $this->convertToMcpTool($shortName, $operationId, $operation, $details);
                if ($tool) {
                    $tools[] = $tool;
                }
            }
        }

        // Sort for deterministic ordering
        usort($tools, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $tools;
    }

    private function convertToMcpTool(string $packageShortName, string $operationId, array $operation, $details): ?array
    {
        if (!is_array($details) || !isset($details['details'])) {
            return null;
        }

        $opDetails = $details['details'];
        $method = strtoupper($details['method'] ?? 'GET');
        $path = $details['path'] ?? '';

        $inputSchema = $this->buildInputSchema($opDetails);
        $annotations = $this->buildAnnotations($method, $operation['summary'] ?? $operationId);

        return [
            'name' => "{$packageShortName}_{$operationId}",
            'description' => $operation['summary'] ?? $operationId,
            'inputSchema' => $inputSchema,
            'annotations' => $annotations,
            '_meta' => [
                'operationId' => $operationId,
                'method' => $method,
                'path' => $path,
            ],
        ];
    }

    private function buildInputSchema(array $opDetails): array
    {
        $properties = [];
        $required = [];

        // Path and query parameters
        $parameters = $opDetails['parameters'] ?? [];
        foreach ($parameters as $param) {
            $paramName = $param['name'] ?? null;
            if (!$paramName) {
                continue;
            }

            $in = $param['in'] ?? 'query';
            $prefix = $in === 'path' ? 'path_' : 'query_';
            $flatName = $prefix . $paramName;

            $properties[$flatName] = $param['schema'] ?? ['type' => 'string'];
            if (!empty($param['description'])) {
                $properties[$flatName]['description'] = $param['description'];
            }

            if (!empty($param['required'])) {
                $required[] = $flatName;
            }
        }

        // Request body properties
        $requestBody = $opDetails['requestBody'] ?? null;
        if ($requestBody) {
            $content = $requestBody['content'] ?? [];
            $jsonSchema = $content['application/json']['schema'] ?? null;

            if ($jsonSchema && isset($jsonSchema['properties'])) {
                foreach ($jsonSchema['properties'] as $propName => $propSchema) {
                    $flatName = "body_{$propName}";
                    $properties[$flatName] = $propSchema;
                }

                $bodyRequired = $jsonSchema['required'] ?? [];
                foreach ($bodyRequired as $reqName) {
                    $required[] = "body_{$reqName}";
                }
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => empty($properties) ? new \stdClass() : $properties,
        ];

        if (!empty($required)) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    private function getShortName(string $packageName): string
    {
        // Strip @clarion-app/ prefix
        $shortName = $packageName;
        if (str_starts_with($shortName, '@clarion-app/')) {
            $shortName = substr($shortName, strlen('@clarion-app/'));
        }
        return $shortName;
    }

    private function buildAnnotations(string $method, string $summary): array
    {
        $map = [
            'GET' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
            ],
            'POST' => [
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => false,
            ],
            'PUT' => [
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => true,
            ],
            'PATCH' => [
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => false,
            ],
            'DELETE' => [
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => true,
            ],
        ];

        $hints = $map[$method] ?? $map['GET'];

        return array_merge($hints, [
            'openWorldHint' => false,
            'title' => $summary,
        ]);
    }
}
