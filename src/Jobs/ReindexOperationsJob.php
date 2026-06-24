<?php

namespace ClarionApp\LlmClient\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\ClarionPackageServiceProvider;

class ReindexOperationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // Truncate existing index
        DB::table('operation_search_index')->truncate();

        // Get all package descriptions
        $packages = ClarionPackageServiceProvider::getPackageDescriptions();

        foreach ($packages as $packageName => $meta) {
            $packageDescription = $meta['description'] ?? '';

            // Get short name for this package (e.g., "wizlights" from "@clarion-app/wizlights")
            $shortName = $this->getShortName($packageName);

            // Index custom prompts for this package
            $this->indexCustomPrompts($shortName, $packageDescription, $packageDescription);

            // Get operations for this package
            $operations = ClarionPackageServiceProvider::getPackageOperations($packageName);

            foreach ($operations as $op) {
                $operationId = $op['operationId'] ?? null;
                if (empty($operationId)) {
                    Log::warning("Skipping operation with missing operationId in package {$packageName}");
                    continue;
                }

                // Get full operation details
                $details = ApiManager::getOperationDetails($operationId);
                if (empty((array) $details)) {
                    Log::warning("Skipping operation {$operationId} - no details found");
                    continue;
                }

                $opDetails = $details['details'] ?? [];

                // Extract searchable fields
                $summary = $op['summary'] ?? $opDetails['summary'] ?? '';
                $method = strtoupper($details['method'] ?? 'GET');
                $path = $details['path'] ?? '';

                // Extract parameter names for searchable text
                $paramNames = [];
                $paramSchema = [];

                // Path/query parameters
                $pathParams = [];
                $queryParams = [];
                foreach ($opDetails['parameters'] ?? [] as $param) {
                    $name = $param['name'] ?? null;
                    if (!$name) continue;
                    $in = $param['in'] ?? 'query';
                    $paramInfo = [
                        'type' => $param['schema']['type'] ?? 'string',
                        'in' => $in,
                        'description' => $param['description'] ?? '',
                        'required' => !empty($param['required']),
                    ];
                    if ($in === 'path') {
                        $pathParams[$name] = $paramInfo;
                    } else {
                        $queryParams[$name] = $paramInfo;
                    }
                    $paramNames[] = $name;
                }
                if (!empty($pathParams)) {
                    $paramSchema['path'] = $pathParams;
                }
                if (!empty($queryParams)) {
                    $paramSchema['query'] = $queryParams;
                }

                // Body parameters
                $requestBody = $opDetails['requestBody'] ?? null;
                if ($requestBody) {
                    $content = $requestBody['content'] ?? [];
                    $jsonSchema = $content['application/json']['schema'] ?? null;
                    if ($jsonSchema && isset($jsonSchema['properties'])) {
                        $bodyRequired = $jsonSchema['required'] ?? [];
                        $bodyParams = [];
                        foreach ($jsonSchema['properties'] as $propName => $propSchema) {
                            $paramNames[] = $propName;
                            $bodyParams[$propName] = [
                                'type' => $propSchema['type'] ?? 'string',
                                'in' => 'body',
                                'description' => $propSchema['description'] ?? '',
                                'required' => in_array($propName, $bodyRequired),
                            ];
                        }
                        $paramSchema['body'] = $bodyParams;
                    }
                }

                // Build searchable text: summary + method + path + param names + package description
                $searchableParts = array_filter(array_merge(
                    [$summary, $method, $path],
                    $paramNames,
                    [$packageDescription]
                ), fn($p) => !empty($p));

                $searchableText = implode(' ', $searchableParts);

                // Fallback: if searchable_text is empty, use operationId + method + path
                if (empty(trim($searchableText))) {
                    $searchableText = trim("{$operationId} {$method} {$path}");
                    if (empty($searchableText)) {
                        Log::warning("Skipping operation {$operationId} - searchable_text would be empty");
                        continue;
                    }
                }

                // Insert into index
                DB::table('operation_search_index')->updateOrInsert(
                    ['operation_id' => $operationId],
                    [
                        'package_name' => $packageName,
                        'summary' => $summary ?: null,
                        'method' => $method,
                        'path' => $path,
                        'searchable_text' => $searchableText,
                        'param_schema' => empty($paramSchema) ? null : json_encode($paramSchema),
                        'updated_at' => now(),
                    ]
                );
            }
        }

        Log::info('Operations search index rebuilt successfully');
    }

    /**
     * Index custom prompts from all packages into the search index.
     */
    private function indexCustomPrompts(): void
    {
        $packages = ClarionPackageServiceProvider::getPackageDescriptions();

        foreach ($packages as $packageName => $meta) {
            $packageDescription = $meta['description'] ?? '';
            $shortName = $this->getShortName($packageName);
            $customPrompts = ClarionPackageServiceProvider::getCustomPrompts($packageName);

            if (empty($customPrompts)) {
                continue;
            }

            foreach ($customPrompts as $promptKey => $promptContent) {
                $operationId = "{$shortName}_{$promptKey}";

                // Build searchable text from prompt content + package description
                $searchableText = trim("{$promptContent} {$packageDescription}");

                if (empty($searchableText)) {
                    Log::warning("Skipping prompt {$operationId} - searchable_text would be empty");
                    continue;
                }

                DB::table('operation_search_index')->updateOrInsert(
                    ['operation_id' => $operationId],
                    [
                        'package_name' => $packageName,
                        'type' => 'prompt',
                        'summary' => trim("{$shortName}: {$promptKey}"),
                        'method' => null,
                        'path' => null,
                        'searchable_text' => $searchableText,
                        'param_schema' => null,
                        'prompt_content' => $promptContent,
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    /**
     * Extract short name from package name.
     * E.g., "@clarion-app/wizlights" -> "wizlights"
     */
    private function getShortName(string $packageName): string
    {
        $parts = explode('/', $packageName);
        return end($parts);
    }
}
