<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Services\ApiCallValidator;
use ClarionApp\LlmClient\Services\CallValidatorInterface;
use ClarionApp\LlmClient\Services\McpClientCallValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * McpClientCallValidator -- the CallValidatorInterface implementation for
 * a synthesized external-tool path. Its own decision reads only the
 * $method/$path arguments this test drives it with, exactly the same
 * fnmatch()-over-config('llm-client.api_denylist') check
 * ApiCallValidatorTest already proves for a built-in route's own path
 * (mirrors that file's own denylist/confirm assertions).
 *
 * Written before McpClientCallValidator exists -- expected to FAIL red
 * (class not found) until it is created.
 */
class McpClientCallValidatorTest extends TestCase
{
    #[Test]
    public function it_implements_the_call_validator_interface(): void
    {
        $this->assertInstanceOf(CallValidatorInterface::class, new McpClientCallValidator());
    }

    #[Test]
    public function every_non_denied_external_call_confirms_unconditionally(): void
    {
        config(['llm-client.api_denylist' => []]);

        $result = (new McpClientCallValidator())->validate(
            'mcp:3fae2e11-0000-0000-0000-000000000000:search',
            'MCP_EXTERNAL',
            '/mcp-client/3fae2e11-0000-0000-0000-000000000000/search',
        );

        $this->assertSame(ApiCallValidator::STATUS_CONFIRM, $result['status']);
    }

    #[Test]
    public function a_denylisted_path_is_rejected_outright_never_confirmed(): void
    {
        config(['llm-client.api_denylist' => ['/mcp-client/*/delete_*']]);

        $result = (new McpClientCallValidator())->validate(
            'mcp:3fae2e11-0000-0000-0000-000000000000:delete_file',
            'MCP_EXTERNAL',
            '/mcp-client/3fae2e11-0000-0000-0000-000000000000/delete_file',
        );

        $this->assertSame(ApiCallValidator::STATUS_REJECT, $result['status']);
    }

    #[Test]
    public function a_denylist_entry_for_a_different_pattern_does_not_reject_an_unrelated_tool(): void
    {
        config(['llm-client.api_denylist' => ['/mcp-client/*/delete_*']]);

        $result = (new McpClientCallValidator())->validate(
            'mcp:3fae2e11-0000-0000-0000-000000000000:search',
            'MCP_EXTERNAL',
            '/mcp-client/3fae2e11-0000-0000-0000-000000000000/search',
        );

        $this->assertSame(ApiCallValidator::STATUS_CONFIRM, $result['status']);
    }

    #[Test]
    public function it_reuses_the_same_denylist_config_array_a_built_in_route_is_checked_against(): void
    {
        // A pattern shaped for a real built-in route must have no bearing
        // on a synthesized external-tool path -- proves the same array is
        // read, not a parallel one, without the two namespaces colliding.
        config(['llm-client.api_denylist' => ['/api/clarion-app/llm-client/*']]);

        $result = (new McpClientCallValidator())->validate(
            'mcp:3fae2e11-0000-0000-0000-000000000000:search',
            'MCP_EXTERNAL',
            '/mcp-client/3fae2e11-0000-0000-0000-000000000000/search',
        );

        $this->assertSame(ApiCallValidator::STATUS_CONFIRM, $result['status']);
    }

    #[Test]
    public function a_denylist_pattern_matching_only_the_operation_id_still_rejects(): void
    {
        // The path candidate here does not match this pattern at all --
        // only the durable operationId does -- proving a rule can be
        // anchored to the id form alone.
        config(['llm-client.api_denylist' => ['mcp:3fae2e11-0000-0000-0000-000000000000:9c1e0000-0000-0000-0000-000000000001']]);

        $result = (new McpClientCallValidator())->validate(
            'mcp:3fae2e11-0000-0000-0000-000000000000:9c1e0000-0000-0000-0000-000000000001',
            'MCP_EXTERNAL',
            '/mcp-client/3fae2e11-0000-0000-0000-000000000000/renamed_tool',
        );

        $this->assertSame(ApiCallValidator::STATUS_REJECT, $result['status']);
    }

    #[Test]
    public function a_denylist_pattern_matching_only_the_legacy_path_still_rejects(): void
    {
        // The operationId here is already the id-based durable form and
        // does not match this glob at all -- only the legacy name-based
        // path candidate does -- a backward-compatibility regression
        // check for every rule written before the durable id existed.
        config(['llm-client.api_denylist' => ['/mcp-client/*/delete_*']]);

        $result = (new McpClientCallValidator())->validate(
            'mcp:3fae2e11-0000-0000-0000-000000000000:9c1e0000-0000-0000-0000-000000000001',
            'MCP_EXTERNAL',
            '/mcp-client/3fae2e11-0000-0000-0000-000000000000/delete_file',
        );

        $this->assertSame(ApiCallValidator::STATUS_REJECT, $result['status']);
    }

    #[Test]
    public function validate_never_returns_allow_across_a_representative_matrix_of_inputs(): void
    {
        $matrix = [
            ['denylist' => [], 'operationId' => 'mcp:server-a:tool-a', 'path' => '/mcp-client/server-a/tool-a'],
            ['denylist' => ['/mcp-client/*/delete_*'], 'operationId' => 'mcp:server-a:delete_file', 'path' => '/mcp-client/server-a/delete_file'],
            ['denylist' => ['mcp:server-a:tool-a'], 'operationId' => 'mcp:server-a:tool-a', 'path' => '/mcp-client/server-a/tool-a'],
            ['denylist' => ['/unrelated/*'], 'operationId' => 'mcp:server-b:tool-b', 'path' => '/mcp-client/server-b/tool-b'],
            ['denylist' => ['mcp:server-c:tool-c'], 'operationId' => 'mcp:server-c:tool-c', 'path' => '/mcp-client/server-c/renamed'],
            ['denylist' => ['/mcp-client/server-d/*'], 'operationId' => 'mcp:server-d:tool-d', 'path' => '/mcp-client/server-d/tool-d'],
        ];

        foreach ($matrix as $case) {
            config(['llm-client.api_denylist' => $case['denylist']]);

            $result = (new McpClientCallValidator())->validate($case['operationId'], 'MCP_EXTERNAL', $case['path']);

            $this->assertContains(
                $result['status'],
                [ApiCallValidator::STATUS_REJECT, ApiCallValidator::STATUS_CONFIRM],
                "Unexpected status '{$result['status']}' for operationId '{$case['operationId']}'",
            );
            $this->assertNotSame(ApiCallValidator::STATUS_ALLOW, $result['status']);
        }
    }

    #[Test]
    public function validate_takes_no_mcp_client_tool_argument_at_all(): void
    {
        // Structural proof: there is no parameter this class could even
        // read tool-supplied name/description/annotations through, so no
        // future edit to this method's body could smuggle one in without
        // also changing its own signature.
        $method = new \ReflectionMethod(McpClientCallValidator::class, 'validate');

        $this->assertCount(3, $method->getParameters());
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $this->assertNotNull($type);
            $this->assertSame('string', (string) $type);
        }
    }
}
