<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Services\LanguageRuntime;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for LanguageRuntime (125-language-runtime-execution,
 * data-model.md §4) — the sole place the recognized-language →
 * binary/extension mapping lives, plus the fused availability-guard +
 * stdin-to-file + invoke shell fragment (research.md D2), the
 * newline-joined availability probe command (research.md D4), and its
 * corresponding output parser.
 *
 * Pure and stateless — no Docker, no database.
 */
class LanguageRuntimeTest extends TestCase
{
    #[Test]
    public function recognized_languages_maps_exactly_python_and_javascript_to_their_binary_and_extension(): void
    {
        $this->assertSame(
            [
                'python' => ['binary' => 'python3', 'extension' => 'py'],
                'javascript' => ['binary' => 'node', 'extension' => 'js'],
            ],
            LanguageRuntime::RECOGNIZED_LANGUAGES
        );
    }

    #[Test]
    public function is_recognized_is_true_for_python_and_javascript(): void
    {
        $runtime = new LanguageRuntime();

        $this->assertTrue($runtime->isRecognized('python'));
        $this->assertTrue($runtime->isRecognized('javascript'));
    }

    #[Test]
    public function is_recognized_is_false_for_an_unrecognized_language_name(): void
    {
        $runtime = new LanguageRuntime();

        $this->assertFalse($runtime->isRecognized('ruby'));
    }

    #[Test]
    public function is_recognized_is_false_for_a_garbage_or_empty_string(): void
    {
        $runtime = new LanguageRuntime();

        $this->assertFalse($runtime->isRecognized(''));
        $this->assertFalse($runtime->isRecognized('   '));
        $this->assertFalse($runtime->isRecognized("Python\0; rm -rf /"));
    }

    #[Test]
    public function build_execution_command_for_python_produces_the_exact_fused_guard_stdin_and_invoke_fragment(): void
    {
        $runtime = new LanguageRuntime();

        $expected = "command -v python3 >/dev/null 2>&1 || { echo '".LanguageRuntime::LANGUAGE_UNAVAILABLE_SENTINEL."' >&2; exit 127; }; cat > /tmp/snippet.py && python3 /tmp/snippet.py";

        $this->assertSame($expected, $runtime->buildExecutionCommand('python'));
    }

    #[Test]
    public function build_execution_command_for_javascript_produces_the_equivalent_fragment_for_node(): void
    {
        $runtime = new LanguageRuntime();

        $expected = "command -v node >/dev/null 2>&1 || { echo '".LanguageRuntime::LANGUAGE_UNAVAILABLE_SENTINEL."' >&2; exit 127; }; cat > /tmp/snippet.js && node /tmp/snippet.js";

        $this->assertSame($expected, $runtime->buildExecutionCommand('javascript'));
    }

    #[Test]
    public function language_unavailable_sentinel_is_the_exact_string_embedded_in_the_execution_command(): void
    {
        $this->assertSame('__CLARION_LANGUAGE_UNAVAILABLE__', LanguageRuntime::LANGUAGE_UNAVAILABLE_SENTINEL);

        $runtime = new LanguageRuntime();

        // Asserted by substring against the constant itself, not duplicated
        // as a second hardcoded literal, so the constant is the single
        // source of truth for both the sentinel's value and its embedding.
        $this->assertStringContainsString(
            LanguageRuntime::LANGUAGE_UNAVAILABLE_SENTINEL,
            $runtime->buildExecutionCommand('python')
        );
        $this->assertStringContainsString(
            LanguageRuntime::LANGUAGE_UNAVAILABLE_SENTINEL,
            $runtime->buildExecutionCommand('javascript')
        );
    }

    #[Test]
    public function build_availability_probe_command_produces_one_stable_line_per_recognized_language(): void
    {
        $runtime = new LanguageRuntime();

        $expected = "command -v python3 && echo 'python:available' || echo 'python:unavailable'\n"
            ."command -v node && echo 'javascript:available' || echo 'javascript:unavailable'";

        $this->assertSame($expected, $runtime->buildAvailabilityProbeCommand());
    }

    #[Test]
    public function build_availability_probe_command_is_stable_across_repeated_calls(): void
    {
        $runtime = new LanguageRuntime();

        $this->assertSame(
            $runtime->buildAvailabilityProbeCommand(),
            $runtime->buildAvailabilityProbeCommand()
        );
    }

    #[Test]
    public function parse_availability_output_correctly_parses_a_mixed_result(): void
    {
        $runtime = new LanguageRuntime();

        $this->assertSame(
            ['python' => true, 'javascript' => false],
            $runtime->parseAvailabilityOutput("python:available\njavascript:unavailable")
        );
    }

    #[Test]
    public function parse_availability_output_is_resilient_to_trailing_whitespace_and_blank_lines(): void
    {
        $runtime = new LanguageRuntime();

        $this->assertSame(
            ['python' => true, 'javascript' => false],
            $runtime->parseAvailabilityOutput("python:available  \n\njavascript:unavailable\n\n  \n")
        );
    }

    #[Test]
    public function parse_availability_output_reports_all_available_when_every_binary_is_present(): void
    {
        $runtime = new LanguageRuntime();

        $this->assertSame(
            ['python' => true, 'javascript' => true],
            $runtime->parseAvailabilityOutput("python:available\njavascript:available\n")
        );
    }

    #[Test]
    public function parse_availability_output_reports_all_unavailable_when_no_binary_is_present(): void
    {
        $runtime = new LanguageRuntime();

        $this->assertSame(
            ['python' => false, 'javascript' => false],
            $runtime->parseAvailabilityOutput("python:unavailable\njavascript:unavailable\n")
        );
    }
}
