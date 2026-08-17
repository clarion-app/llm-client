<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Services\AgentStartingPointCatalog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Static guard: none of the registered starting-point templates may carry
 * a credential-shaped key, scanning each one's raw YAML text so a future
 * template addition is covered automatically without editing this test.
 *
 * Written before AgentStartingPointCatalog exists -- expected to fail
 * with a "class not found"-style error until it is created. That is the
 * intended RED state, not a mistake. This is a standing guard, not a
 * fix -- the four templates are already clean.
 */
class NoCredentialsInTemplatesTest extends TestCase
{
    private const CREDENTIAL_KEY_PATTERNS = [
        '/api_key\s*:/i',
        '/password\s*:/i',
        '/secret\s*:/i',
        '/token\s*:/i',
    ];

    #[Test]
    public function no_registered_starting_point_template_contains_a_credential_shaped_key(): void
    {
        $catalog = $this->app->make(AgentStartingPointCatalog::class);

        foreach (['research', 'coding', 'data', 'scheduler'] as $slug) {
            $rawYaml = $catalog->rawYamlFor($slug);

            foreach (self::CREDENTIAL_KEY_PATTERNS as $pattern) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $rawYaml,
                    "Starting point \"{$slug}\"'s template contains a credential-shaped key matching {$pattern}.",
                );
            }
        }
    }
}
