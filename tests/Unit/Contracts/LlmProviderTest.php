<?php

namespace ClarionApp\LlmClient\Tests\Unit\Contracts;

use Tests\TestCase;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use Generator;
use ReflectionClass;
use ReflectionMethod;

use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for LlmProvider interface contract.
 *
 * Verifies that the interface defines all required methods with correct signatures.
 */
class LlmProviderTest extends TestCase
{
    #[Test]
    public function interface_exists()
    {
        $this->assertTrue(interface_exists(LlmProvider::class));
    }

    #[Test]
    public function interface_has_four_required_methods()
    {
        $reflection = new ReflectionClass(LlmProvider::class);
        $methods = array_map(fn ($m) => $m->getName(), $reflection->getMethods());

        $requiredMethods = ['chat', 'stream', 'embed', 'countTokens'];

        foreach ($requiredMethods as $method) {
            $this->assertContains($method, $methods, "LlmProvider interface must define '{$method}' method");
        }
    }

    #[Test]
    public function chat_method_has_correct_signature()
    {
        $reflection = new ReflectionClass(LlmProvider::class);
        $method = $reflection->getMethod('chat');
        $parameters = $method->getParameters();

        // Should have 3 parameters: $messages, $tools, $options
        $this->assertEquals(3, count($parameters), 'chat() should have 3 parameters');

        // First parameter: $messages (array)
        $this->assertEquals('messages', $parameters[0]->getName());
        $this->assertTrue($parameters[0]->hasType());
        $this->assertEquals('array', (string) $parameters[0]->getType());

        // Second parameter: $tools (array, default [])
        $this->assertEquals('tools', $parameters[1]->getName());
        $this->assertTrue($parameters[1]->hasType());
        $this->assertEquals('array', (string) $parameters[1]->getType());
        $this->assertTrue($parameters[1]->isDefaultValueAvailable());
        $this->assertEquals([], $parameters[1]->getDefaultValue());

        // Third parameter: $options (array, default [])
        $this->assertEquals('options', $parameters[2]->getName());
        $this->assertTrue($parameters[2]->hasType());
        $this->assertEquals('array', (string) $parameters[2]->getType());
        $this->assertTrue($parameters[2]->isDefaultValueAvailable());
        $this->assertEquals([], $parameters[2]->getDefaultValue());

        // Return type: array
        $this->assertTrue($method->hasReturnType());
        $this->assertEquals('array', (string) $method->getReturnType());
    }

    #[Test]
    public function stream_method_has_correct_signature()
    {
        $reflection = new ReflectionClass(LlmProvider::class);
        $method = $reflection->getMethod('stream');
        $parameters = $method->getParameters();

        // Should have 3 parameters: $messages, $tools, $options
        $this->assertEquals(3, count($parameters), 'stream() should have 3 parameters');

        // First parameter: $messages (array)
        $this->assertEquals('messages', $parameters[0]->getName());
        $this->assertTrue($parameters[0]->hasType());
        $this->assertEquals('array', (string) $parameters[0]->getType());

        // Return type: Generator
        $this->assertTrue($method->hasReturnType());
        $this->assertEquals(Generator::class, (string) $method->getReturnType());
    }

    #[Test]
    public function embed_method_has_correct_signature()
    {
        $reflection = new ReflectionClass(LlmProvider::class);
        $method = $reflection->getMethod('embed');
        $parameters = $method->getParameters();

        // Should have 2 parameters: $inputs, $options
        $this->assertEquals(2, count($parameters), 'embed() should have 2 parameters');

        // First parameter: $inputs (array)
        $this->assertEquals('inputs', $parameters[0]->getName());
        $this->assertTrue($parameters[0]->hasType());
        $this->assertEquals('array', (string) $parameters[0]->getType());

        // Second parameter: $options (array, default [])
        $this->assertEquals('options', $parameters[1]->getName());
        $this->assertTrue($parameters[1]->hasType());
        $this->assertEquals('array', (string) $parameters[1]->getType());
        $this->assertTrue($parameters[1]->isDefaultValueAvailable());
        $this->assertEquals([], $parameters[1]->getDefaultValue());

        // Return type: array
        $this->assertTrue($method->hasReturnType());
        $this->assertEquals('array', (string) $method->getReturnType());
    }

    #[Test]
    public function countTokens_method_has_correct_signature()
    {
        $reflection = new ReflectionClass(LlmProvider::class);
        $method = $reflection->getMethod('countTokens');
        $parameters = $method->getParameters();

        // Should have 2 parameters: $text, $model
        $this->assertEquals(2, count($parameters), 'countTokens() should have 2 parameters');

        // First parameter: $text (string)
        $this->assertEquals('text', $parameters[0]->getName());
        $this->assertTrue($parameters[0]->hasType());
        $this->assertEquals('string', (string) $parameters[0]->getType());

        // Second parameter: $model (?string, default null)
        $this->assertEquals('model', $parameters[1]->getName());
        $this->assertTrue($parameters[1]->hasType());
        $this->assertTrue($parameters[1]->getType()->allowsNull());
        $this->assertEquals('string', $parameters[1]->getType()->getName());
        $this->assertTrue($parameters[1]->isDefaultValueAvailable());
        $this->assertNull($parameters[1]->getDefaultValue());

        // Return type: int
        $this->assertTrue($method->hasReturnType());
        $this->assertEquals('int', (string) $method->getReturnType());
    }

    #[Test]
    public function mock_implementation_can_be_instantiated()
    {
        $mock = new class implements LlmProvider {
            public function chat(array $messages, array $tools = [], array $options = []): array
            {
                return ['choices' => [['message' => ['role' => 'assistant', 'content' => 'test']]]];
            }

            public function stream(array $messages, array $tools = [], array $options = []): Generator
            {
                yield ['content' => 'test'];
            }

            public function embed(array $inputs, array $options = []): array
            {
                return ['embeddings' => [[0.1, 0.2, 0.3]]];
            }

            public function countTokens(string $text, ?string $model = null): int
            {
                return strlen($text) / 4;
            }
        };

        $this->assertInstanceOf(LlmProvider::class, $mock);

        // Verify chat returns expected shape
        $result = $mock->chat([['role' => 'user', 'content' => 'hello']]);
        $this->assertArrayHasKey('choices', $result);

        // Verify stream returns a generator
        $generator = $mock->stream([['role' => 'user', 'content' => 'hello']]);
        $this->assertInstanceOf(Generator::class, $generator);
        $chunks = iterator_to_array($generator);
        $this->assertCount(1, $chunks);

        // Verify embed returns expected shape
        $embeddings = $mock->embed(['test input']);
        $this->assertArrayHasKey('embeddings', $embeddings);

        // Verify countTokens returns int
        $tokens = $mock->countTokens('test input');
        $this->assertIsInt($tokens);
    }
}
