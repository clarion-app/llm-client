<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\McpPromptRegistry;
use ClarionApp\Backend\ClarionPackageServiceProvider;
use Mockery;

use PHPUnit\Framework\Attributes\Test;

class McpPromptRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function getPrompts_returns_all_prompts_from_registered_packages_with_correct_name_format()
    {
        $this->mockPackages([
            '@clarion-app/wizlights' => [
                'listOperations' => 'To adjust the lighting...',
                'executeOperation' => 'When adjusting the lighting...',
            ],
            '@clarion-app/weather' => [
                'listOperations' => 'To retrieve the weather...',
                'executeOperation' => 'Remember to replace...',
            ],
        ]);

        $registry = new McpPromptRegistry();
        $result = $registry->getPrompts();

        $this->assertArrayHasKey('prompts', $result);
        $this->assertCount(4, $result['prompts']);

        $names = array_map(fn($p) => $p['name'], $result['prompts']);
        $this->assertContains('wizlights_listOperations', $names);
        $this->assertContains('wizlights_executeOperation', $names);
        $this->assertContains('weather_listOperations', $names);
        $this->assertContains('weather_executeOperation', $names);

        foreach ($result['prompts'] as $prompt) {
            $this->assertArrayHasKey('name', $prompt);
            $this->assertArrayHasKey('description', $prompt);
            $this->assertArrayHasKey('arguments', $prompt);
        }
    }

    #[Test]
    public function getPrompts_with_cursor_returns_paginated_results_using_base64_cursor_pattern()
    {
        $prompts = [];
        for ($i = 0; $i < 60; $i++) {
            $prompts["prompt{$i}"] = "Prompt content {$i}";
        }

        $this->mockPackages([
            '@clarion-app/test-package' => $prompts,
        ]);

        $registry = new McpPromptRegistry();

        // First page
        $result = $registry->getPrompts();
        $this->assertCount(50, $result['prompts']);
        $this->assertNotNull($result['nextCursor']);

        // Decode cursor to verify format
        $decoded = json_decode(base64_decode($result['nextCursor']), true);
        $this->assertArrayHasKey('offset', $decoded);

        // Second page
        $result2 = $registry->getPrompts($result['nextCursor']);
        $this->assertCount(10, $result2['prompts']);
        $this->assertNull($result2['nextCursor']);
    }

    #[Test]
    public function getPrompt_returns_structured_prompt_with_description_and_messages_for_valid_name()
    {
        $this->mockPackages([
            '@clarion-app/wizlights' => [
                'listOperations' => 'To adjust the lighting of a room...',
            ],
        ]);

        $registry = new McpPromptRegistry();
        $result = $registry->getPrompt('wizlights_listOperations');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('description', $result);
        $this->assertArrayHasKey('messages', $result);
        $this->assertCount(1, $result['messages']);
        $this->assertEquals('user', $result['messages'][0]['role']);
        $this->assertEquals('text', $result['messages'][0]['content']['type']);
        $this->assertEquals('To adjust the lighting of a room...', $result['messages'][0]['content']['text']);
    }

    #[Test]
    public function getPrompt_returns_null_for_nonexistent_prompt_name()
    {
        $this->mockPackages([
            '@clarion-app/wizlights' => [
                'listOperations' => 'To adjust the lighting...',
            ],
        ]);

        $registry = new McpPromptRegistry();
        $result = $registry->getPrompt('nonexistent_prompt');

        $this->assertNull($result);
    }

    #[Test]
    public function getPrompt_with_arguments_appends_user_command_to_prompt_message_content()
    {
        $this->mockPackages([
            '@clarion-app/wizlights' => [
                'listOperations' => 'To adjust the lighting of a room...',
            ],
        ]);

        $registry = new McpPromptRegistry();
        $result = $registry->getPrompt('wizlights_listOperations', ['command' => 'turn on the living room lights']);

        $this->assertNotNull($result);
        $text = $result['messages'][0]['content']['text'];
        $this->assertStringContainsString('To adjust the lighting of a room...', $text);
        $this->assertStringContainsString('turn on the living room lights', $text);
        $this->assertStringContainsString('User command:', $text);
    }

    #[Test]
    public function packages_with_empty_customPrompts_are_skipped_without_error()
    {
        $this->mockPackages([
            '@clarion-app/empty-package' => [],
            '@clarion-app/wizlights' => [
                'listOperations' => 'To adjust the lighting...',
            ],
        ]);

        $registry = new McpPromptRegistry();
        $result = $registry->getPrompts();

        $this->assertCount(1, $result['prompts']);
        $this->assertEquals('wizlights_listOperations', $result['prompts'][0]['name']);
    }

    private function mockPackages(array $packagePrompts): void
    {
        $descriptions = [];
        foreach (array_keys($packagePrompts) as $pkg) {
            $descriptions[$pkg] = ['description' => "Description for {$pkg}"];
        }

        // Directly manipulate static properties instead of mocking the abstract class
        $reflection = new \ReflectionClass(ClarionPackageServiceProvider::class);

        $descProp = $reflection->getProperty('packageDescriptions');
        $descProp->setAccessible(true);
        $descProp->setValue(null, $descriptions);

        $promptsProp = $reflection->getProperty('customPrompts');
        $promptsProp->setAccessible(true);
        $promptsProp->setValue(null, $packagePrompts);
    }
}
