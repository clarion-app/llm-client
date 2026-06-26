<?php

namespace ClarionApp\LlmClient\Tests\Unit\Contracts;

use Tests\TestCase;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ReflectionClass;
use ReflectionMethod;

use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for unified type definitions in LlmProvider PHPDoc blocks.
 *
 * Verifies that PHPDoc type annotations for messages, tools, and results
 * are present and well-formed.
 */
class UnifiedTypesTest extends TestCase
{
    #[Test]
    public function message_shape_supports_all_four_roles()
    {
        $reflection = new ReflectionClass(LlmProvider::class);
        $method = $reflection->getMethod('chat');
        $docComment = $method->getDocComment();

        // Verify PHPDoc mentions all four roles
        $this->assertStringContainsString("'system'", $docComment, 'Message shape should support "system" role');
        $this->assertStringContainsString("'user'", $docComment, 'Message shape should support "user" role');
        $this->assertStringContainsString("'assistant'", $docComment, 'Message shape should support "assistant" role');
        $this->assertStringContainsString("'tool'", $docComment, 'Message shape should support "tool" role');
    }

    #[Test]
    public function message_shape_includes_content_and_optional_tool_data()
    {
        $reflection = new ReflectionClass(LlmProvider::class);
        $method = $reflection->getMethod('chat');
        $docComment = $method->getDocComment();

        // Verify message shape includes content
        $this->assertStringContainsString('content', $docComment, 'Message shape should include "content" field');

        // Verify optional tool_call_id for tool results
        $this->assertStringContainsString('tool_call_id', $docComment, 'Message shape should include optional "tool_call_id" field');

        // Verify optional tool_calls for assistant responses
        $this->assertStringContainsString('tool_calls', $docComment, 'Message shape should include optional "tool_calls" field');
    }

    #[Test]
    public function message_shape_includes_tool_call_structure()
    {
        $reflection = new ReflectionClass(LlmProvider::class);
        $method = $reflection->getMethod('chat');
        $docComment = $method->getDocComment();

        // Tool calls should have id, type, function.name, function.arguments
        $this->assertStringContainsString('id', $docComment, 'Tool call shape should include "id"');
        $this->assertStringContainsString('type', $docComment, 'Tool call shape should include "type"');
        $this->assertStringContainsString('function', $docComment, 'Tool call shape should include "function"');
        $this->assertStringContainsString('name', $docComment, 'Tool call function should include "name"');
        $this->assertStringContainsString('arguments', $docComment, 'Tool call function should include "arguments"');
    }

    #[Test]
    public function toolDefinition_shape_supports_name_description_and_parameters()
    {
        $reflection = new ReflectionClass(LlmProvider::class);
        $method = $reflection->getMethod('chat');
        $docComment = $method->getDocComment();

        // Tool definition should have name, description, parameters
        $this->assertStringContainsString('name', $docComment, 'ToolDefinition should include "name"');
        $this->assertStringContainsString('description', $docComment, 'ToolDefinition should include "description"');
        $this->assertStringContainsString('parameters', $docComment, 'ToolDefinition should include "parameters"');

        // Parameters should support JSON Schema
        $this->assertStringContainsString('properties', $docComment, 'Parameters should support JSON Schema "properties"');
    }

    #[Test]
    public function completionResult_shape_includes_choices_content_toolCalls()
    {
        $reflection = new ReflectionClass(LlmProvider::class);
        $method = $reflection->getMethod('chat');
        $docComment = $method->getDocComment();

        // Return shape should have choices, content, tool_calls, usage
        $this->assertStringContainsString('choices', $docComment, 'CompletionResult should include "choices"');
        $this->assertStringContainsString('content', $docComment, 'CompletionResult message should include "content"');
        $this->assertStringContainsString('tool_calls', $docComment, 'CompletionResult should support optional "tool_calls"');
        $this->assertStringContainsString('usage', $docComment, 'CompletionResult should support optional "usage"');
    }

    #[Test]
    public function completionResult_shape_includes_usage_metadata()
    {
        $reflection = new ReflectionClass(LlmProvider::class);
        $method = $reflection->getMethod('chat');
        $docComment = $method->getDocComment();

        // Usage should include token counts
        $this->assertStringContainsString('prompt_tokens', $docComment, 'Usage should include "prompt_tokens"');
        $this->assertStringContainsString('completion_tokens', $docComment, 'Usage should include "completion_tokens"');
        $this->assertStringContainsString('total_tokens', $docComment, 'Usage should include "total_tokens"');
    }

    #[Test]
    public function embeddingResult_shape_includes_embeddings_and_usage()
    {
        $reflection = new ReflectionClass(LlmProvider::class);
        $method = $reflection->getMethod('embed');
        $docComment = $method->getDocComment();

        // Embedding result should have embeddings array
        $this->assertStringContainsString('embeddings', $docComment, 'EmbeddingResult should include "embeddings"');

        // Should mention float arrays
        $this->assertStringContainsString('float', $docComment, 'Embeddings should be float arrays');

        // Should support optional usage
        $this->assertStringContainsString('usage', $docComment, 'EmbeddingResult should support optional "usage"');
    }

    #[Test]
    public function streaming_shape_includes_content_and_toolCalls()
    {
        $reflection = new ReflectionClass(LlmProvider::class);
        $method = $reflection->getMethod('stream');
        $docComment = $method->getDocComment();

        // Stream chunks should support content and tool_calls
        $this->assertStringContainsString('content', $docComment, 'Stream chunks should include optional "content"');
        $this->assertStringContainsString('tool_calls', $docComment, 'Stream chunks should support optional "tool_calls"');
        $this->assertStringContainsString('finish_reason', $docComment, 'Stream chunks should include optional "finish_reason"');
    }

    #[Test]
    public function types_are_reusable_across_all_methods()
    {
        $reflection = new ReflectionClass(LlmProvider::class);

        // Both chat and stream should accept the same message format
        $chatDoc = $reflection->getMethod('chat')->getDocComment();
        $streamDoc = $reflection->getMethod('stream')->getDocComment();

        // Both mention the same roles
        $this->assertStringContainsString("'system'|'user'|'assistant'|'tool'", $chatDoc);
        $this->assertStringContainsString("'system'|'user'|'assistant'|'tool'", $streamDoc);
    }
}
