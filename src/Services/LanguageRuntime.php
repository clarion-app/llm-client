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

        // 125-language-runtime-execution, US1 (discovered live against a
        // real container, tests/RealDocker/LanguageExecutionTest.php's
        // timeout case): Python's stdout is fully buffered rather than
        // line-buffered whenever it is not attached to a TTY -- exactly
        // the case here, since the container is never started with -t.
        // Without this, output already printed before a timeout-kill sits
        // in the interpreter's own unflushed buffer and is lost with the
        // process, silently violating FR-008's "output already produced
        // is never discarded" guarantee. PYTHONUNBUFFERED is exported
        // unconditionally (harmless for a language whose own runtime does
        // not recognize it) rather than branching per binary, so this
        // stays one shared code path for every recognized language.
        return "command -v {$binary} >/dev/null 2>&1 || { echo '".self::LANGUAGE_UNAVAILABLE_SENTINEL."' >&2; exit 127; }; export PYTHONUNBUFFERED=1; cat > /tmp/snippet.{$extension} && {$binary} /tmp/snippet.{$extension}";
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
     * and blank lines -- and, discovered only against a real container
     * (tests/RealDocker/LanguageAvailabilityProbeTest.php; no hand-written
     * stdout fixture reproduces this), tolerant of a leading, colon-free
     * line too: on success `command -v <binary>` itself prints the
     * resolved binary path to stdout before the `&&`-chained echo runs,
     * so a real probe's stdout is two lines per available language, not
     * one. Any line without a `name:status` shape is simply not a probe
     * result and is skipped rather than parsed.
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

            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$name, $status] = $parts;
            $result[$name] = trim($status) === 'available';
        }

        return $result;
    }
}
