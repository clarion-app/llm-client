<?php

namespace ClarionApp\LlmClient\Exceptions;

class PresetNotFoundException extends \RuntimeException
{
    private string $presetName;
    private array $availablePresets;

    public function __construct(string $presetName, array $availablePresets = [])
    {
        $this->presetName = $presetName;
        $this->availablePresets = $availablePresets;

        $message = sprintf('Preset "%s" not found.', $presetName);
        if (!empty($availablePresets)) {
            $message .= sprintf(' Available presets: %s.', implode(', ', $availablePresets));
        }

        parent::__construct($message);
    }

    public function getPresetName(): string
    {
        return $this->presetName;
    }

    public function getAvailablePresets(): array
    {
        return $this->availablePresets;
    }
}
