<?php

namespace ClarionApp\LlmClient\Services;

/**
 * The single place the recognized-language -> binary/extension mapping
 * lives (data-model.md §4). Pure and stateless — no Docker, no database,
 * no framework dependencies beyond plain PHP.
 *
 * Owns:
 *  - the recognized-language table itself (RECOGNIZED_LANGUAGES);
 *  - the fused availability-guard + stdin-to-file + invoke shell fragment
 *    (research.md D2) used to actually run a submitted snippet;
 *  - the newline-joined availability probe command (research.md D4) used
 *    to answer "which languages are usable in this workspace right now";
 *  - the parser for that probe's stdout.
 *
 * LANGUAGE_UNAVAILABLE_SENTINEL is defined exactly once here and embedded
 * into buildExecutionCommand()'s output, so the caller (CodingWorkspaceController)
 * can recognize the same string on stderr without duplicating it.
 */
class LanguageRuntime
{
    public const RECOGNIZED_LANGUAGES = [
        'python' => ['binary' => 'python3', 'extension' => 'py'],
        'javascript' => ['binary' => 'node', 'extension' => 'js'],
    ];

    public const LANGUAGE_UNAVAILABLE_SENTINEL = '__CLARION_LANGUAGE_UNAVAILABLE__';

    public function isRecognized(string $language): bool
    {
        return array_key_exists($language, self::RECOGNIZED_LANGUAGES);
    }

    /**
     * The fused availability-guard + stdin-to-file + invoke shell fragment
     * for a recognized language. Callers must check isRecognized() first;
     * an unrecognized language name is a caller-side error, not something
     * this method reports on (data-model.md §2: an unrecognized language
     * never reaches this far).
     */
    public function buildExecutionCommand(string $language): string
    {
        $binary = self::RECOGNIZED_LANGUAGES[$language]['binary'];
        $extension = self::RECOGNIZED_LANGUAGES[$language]['extension'];

        return "command -v {$binary} >/dev/null 2>&1 || { echo '".self::LANGUAGE_UNAVAILABLE_SENTINEL."' >&2; exit 127; }; cat > /tmp/snippet.{$extension} && {$binary} /tmp/snippet.{$extension}";
    }

    /**
     * One `command -v <binary> && echo '<name>:available' || echo
     * '<name>:unavailable'` line per recognized language, newline-joined,
     * in RECOGNIZED_LANGUAGES's own stable iteration order.
     */
    public function buildAvailabilityProbeCommand(): string
    {
        $lines = [];

        foreach (self::RECOGNIZED_LANGUAGES as $name => $spec) {
            $binary = $spec['binary'];
            $lines[] = "command -v {$binary} && echo '{$name}:available' || echo '{$name}:unavailable'";
        }

        return implode("\n", $lines);
    }

    /**
     * Parses buildAvailabilityProbeCommand()'s stdout back into
     * {python: bool, javascript: bool}, tolerant of trailing whitespace
     * and blank lines.
     *
     * @return array<string, bool>
     */
    public function parseAvailabilityOutput(string $stdout): array
    {
        $result = [];

        foreach (explode("\n", $stdout) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            [$name, $status] = explode(':', $line, 2);
            $result[$name] = trim($status) === 'available';
        }

        return $result;
    }
}
