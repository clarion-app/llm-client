<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\OperationCache;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shared-storage guarantee (FR-001/FR-008) rests entirely on the container
 * handing OperationCache the store named by `llm-client.operation_cache.store`.
 *
 * Injecting the application default instead is silent: every test that uses a
 * single store still passes, while a real deployment whose default store is
 * per-worker (`array`, per-container `file`) reproduces the exact process-local
 * defect this feature exists to fix. These tests pin the wiring by giving the
 * configured and default stores different identities.
 */
class OperationCacheStoreResolutionTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Two distinct stores; the default is deliberately NOT the configured one.
        $app['config']->set('cache.stores.op_cache_probe', ['driver' => 'array']);
        $app['config']->set('cache.stores.decoy_default', ['driver' => 'array']);
        $app['config']->set('cache.default', 'decoy_default');
        $app['config']->set('llm-client.operation_cache.store', 'op_cache_probe');
    }

    #[Test]
    public function writes_go_to_the_configured_store_not_the_application_default()
    {
        app(OperationCache::class)->put('conv-1', 'op-1', [
            'operationId' => 'op-1',
            'summary'     => 'Create a contact',
            'method'      => 'POST',
            'path'        => '/contacts',
            'paramSchema' => null,
        ]);

        $key = 'llm-client:op_cache:conv-1';

        $this->assertNotNull(
            app('cache')->store('op_cache_probe')->get($key),
            'entries must land in the store named by operation_cache.store'
        );
        $this->assertNull(
            app('cache')->store('decoy_default')->get($key),
            'entries must not land in the application default store'
        );
    }

    #[Test]
    public function reads_come_back_from_the_configured_store_across_instances()
    {
        app(OperationCache::class)->put('conv-2', 'op-2', [
            'operationId' => 'op-2',
            'summary'     => 'List contacts',
            'method'      => 'GET',
            'path'        => '/contacts',
            'paramSchema' => null,
        ]);

        // A second instance stands in for another worker: no shared memo,
        // so this only passes if the entry round-tripped through the store.
        $other = new OperationCache(null, app('cache')->store('op_cache_probe'));

        $this->assertSame(['op-2 (GET /contacts)'], $other->getSummaries('conv-2'));
    }

    #[Test]
    public function null_store_config_falls_back_to_the_application_default()
    {
        config()->set('llm-client.operation_cache.store', null);
        app()->forgetInstance(OperationCache::class);

        app(OperationCache::class)->put('conv-3', 'op-3', [
            'operationId' => 'op-3',
            'summary'     => 'Default store',
            'method'      => 'GET',
            'path'        => '/default',
            'paramSchema' => null,
        ]);

        $this->assertNotNull(
            app('cache')->store('decoy_default')->get('llm-client:op_cache:conv-3'),
            'a null store name must resolve to the application default'
        );
    }
}
