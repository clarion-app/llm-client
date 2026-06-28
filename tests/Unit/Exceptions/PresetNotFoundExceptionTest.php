<?php

namespace ClarionApp\LlmClient\Tests\Unit\Exceptions;

use Tests\TestCase;
use ClarionApp\LlmClient\Exceptions\PresetNotFoundException;
use PHPUnit\Framework\Attributes\Test;

class PresetNotFoundExceptionTest extends TestCase
{
    #[Test]
    public function exception_message_includes_preset_name(): void
    {
        $exception = new PresetNotFoundException('decision', ['summary', 'extraction']);
        $this->assertStringContainsString('decision', $exception->getMessage());
    }

    #[Test]
    public function exception_message_includes_available_presets(): void
    {
        $exception = new PresetNotFoundException('decision', ['summary', 'extraction']);
        $this->assertStringContainsString('summary', $exception->getMessage());
        $this->assertStringContainsString('extraction', $exception->getMessage());
    }

    #[Test]
    public function exception_exposes_preset_name(): void
    {
        $exception = new PresetNotFoundException('my-preset', ['other']);
        $this->assertEquals('my-preset', $exception->getPresetName());
    }

    #[Test]
    public function exception_exposes_available_presets(): void
    {
        $available = ['summary', 'extraction', 'decision'];
        $exception = new PresetNotFoundException('missing', $available);
        $this->assertEquals($available, $exception->getAvailablePresets());
    }

    #[Test]
    public function exception_extends_runtime_exception(): void
    {
        $exception = new PresetNotFoundException('missing', []);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }
}
