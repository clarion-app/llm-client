<?php

namespace Tests\Integration\Harness;

use ClarionApp\Backend\ApiManager;
use Illuminate\Support\Facades\Http;
use ReflectionClass;

/**
 * T019: OperationCatalogue — Story 3 fixture seam (research R7).
 *
 * seed() writes an OpenAPI-shaped doc into ApiManager::$apiDocsCache by reflection.
 * reset() sets it back to null.
 * fakeHostApi() uses Http::fake() for APP_URL calls in McpToolExecutor::executeHttpCall.
 */
class OperationCatalogue
{
    /**
     * Whether the ApiManager seam this catalogue drives is still present —
     * i.e. the real class (with its `$apiDocsCache` property) has not been
     * replaced by a Mockery `alias:`/`overload:` double earlier in the same
     * process (as `ApiCallValidatorTest`/`McpToolRegistryTest` do). Scenarios
     * that seed the catalogue should skip gracefully when this is false; under
     * the canonical `phpunit tests/` order it is always true.
     */
    public function isSeamAvailable(): bool
    {
        return (new ReflectionClass(ApiManager::class))->hasProperty('apiDocsCache');
    }

    /**
     * Seed the API docs cache with an OpenAPI-shaped document.
     *
     * @param array $openApiDoc OpenAPI 3.0 document array.
     */
    public function seed(array $openApiDoc): void
    {
        $ref = new ReflectionClass(ApiManager::class);
        $prop = $ref->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $openApiDoc);
    }

    /**
     * Reset the API docs cache to null.
     *
     * Called unconditionally in tearDown to prevent leak across tests.
     *
     * Degrades gracefully when `ApiManager` no longer carries `$apiDocsCache`:
     * a prior test in the same process may have replaced the class with a
     * Mockery `alias:`/`overload:` double (as `ApiCallValidatorTest` and
     * `McpToolRegistryTest` do), which has no such property. Under the
     * canonical `phpunit tests/` order Integration runs before Unit so this
     * never happens, but a Unit-first ordering (explicit path list, or a
     * defects-first result cache) would otherwise crash every multi-turn
     * tearDown with `ReflectionException: Property ... does not exist`. There
     * is no real cache to clear in that case — the class the seam targets is
     * gone — so skipping the reset is both correct and safe.
     */
    public function reset(): void
    {
        $ref = new ReflectionClass(ApiManager::class);
        if (! $ref->hasProperty('apiDocsCache')) {
            return;
        }
        $prop = $ref->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    /**
     * Fake the host API calls made by McpToolExecutor::executeHttpCall.
     *
     * Safe to call more than once per test (e.g. to change routing between
     * two uses of the same operation, simulating the catalogue changing
     * mid-conversation): Http::fake() merges new stub callbacks onto
     * existing ones rather than replacing them, and PendingRequest resolves
     * the *first* matching stub in registration order
     * (Illuminate\Http\Client\PendingRequest::buildStubHandler()) — so an
     * earlier catch-all registration would otherwise always win and a
     * second call's routing would silently never be consulted. Resetting
     * the registered stubs first makes each call authoritative.
     *
     * @param array $responses Array of path => response mappings.
     */
    public function fakeHostApi(array $responses): void
    {
        $factory = Http::getFacadeRoot();
        $factoryRef = new ReflectionClass($factory);
        if ($factoryRef->hasProperty('stubCallbacks')) {
            $stubProp = $factoryRef->getProperty('stubCallbacks');
            $stubProp->setAccessible(true);
            $stubProp->setValue($factory, new \Illuminate\Support\Collection());
        }

        $callback = function ($request) use ($responses) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?? '';

            foreach ($responses as $pattern => $response) {
                if (str_contains($path, $pattern) || $pattern === '*') {
                    if (is_callable($response)) {
                        return $response($request);
                    }
                    return Http::response($response, 200, [
                        'Content-Type' => 'application/json',
                    ]);
                }
            }

            return Http::response(['error' => 'Not found'], 404);
        };

        Http::fake([
            config('app.url') . '/*' => $callback,
            '*' => $callback,
        ]);
    }
}
