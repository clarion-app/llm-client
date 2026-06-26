<?php

namespace ClarionApp\LlmClient\Tests\Feature\Contracts;

use Tests\TestCase;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;

use PHPUnit\Framework\Attributes\Test;

/**
 * Contract validation tests for LlmProvider interface.
 *
 * These tests verify that the contract is complete and usable.
 * TODO: Add implementation-specific contract tests as providers are added.
 */
class LlmProviderContractTest extends TestCase
{
    #[Test]
    public function contracts_namespace_is_loadable()
    {
        $this->assertTrue(interface_exists(LlmProvider::class));
        $this->assertTrue(enum_exists(ProviderType::class));
    }
}
