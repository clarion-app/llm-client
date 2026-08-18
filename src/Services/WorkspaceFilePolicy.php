<?php

namespace ClarionApp\LlmClient\Services;

/**
 * The single, shared definition of "oversized" and "binary" for
 * workspace file content, used by both CodingWorkspaceController::readFile()
 * and WorkspaceSearchService's content-search path, so a file is never
 * treated as fine in one path and oversized in the other.
 *
 * isBinary() is a deliberately independent implementation of the same
 * two-part heuristic ToolResultCondenser::isBinaryContent() already uses
 * (null byte OR a high non-printable-byte ratio) — it never calls into
 * that class, which is a separate, downstream, whole-tool-result layer
 * with its own config namespace and its own callers.
 */
class WorkspaceFilePolicy
{
    /**
     * The number of leading bytes considered when sniffing a sample for
     * binary content.
     */
    private const SNIFF_SAMPLE_BYTES = 8192;

    /**
     * The non-printable-byte ratio above which a sample is classified as
     * binary, even without a null byte present.
     */
    private const NON_PRINTABLE_RATIO_THRESHOLD = 0.10;

    /**
     * True when the file at $absolutePath exceeds the configured size
     * threshold. A stat()-only check (filesize()) — the file's content is
     * never read merely to answer this question.
     */
    public function isOversized(string $absolutePath): bool
    {
        $threshold = (int) config('llm-client.coding_agent.file_size_threshold_bytes');

        $size = filesize($absolutePath);

        if ($size === false) {
            return false;
        }

        return $size > $threshold;
    }

    /**
     * True when $sample looks like binary content: a literal null byte, or
     * a non-printable-byte ratio over 10%. Content-only — takes a raw byte
     * sample and nothing else, so classification is never extension- or
     * path-based.
     */
    public function isBinary(string $sample): bool
    {
        if (str_contains($sample, "\x00")) {
            return true;
        }

        $length = strlen($sample);

        if ($length === 0) {
            return false;
        }

        $sniffed = substr($sample, 0, self::SNIFF_SAMPLE_BYTES);
        $sniffedLength = strlen($sniffed);

        $nonPrintable = 0;
        for ($i = 0; $i < $sniffedLength; $i++) {
            $byte = ord($sniffed[$i]);

            // Printable ASCII (0x20-0x7E) and common whitespace
            // (tab/newline/carriage-return) are never counted as
            // non-printable. Bytes with the high bit set (>= 0x80) are
            // treated as potential multi-byte UTF-8 and not counted here
            // either, since ordinary UTF-8 text legitimately uses them.
            if ($byte === 9 || $byte === 10 || $byte === 13) {
                continue;
            }

            if ($byte >= 0x20 && $byte <= 0x7E) {
                continue;
            }

            if ($byte >= 0x80) {
                continue;
            }

            $nonPrintable++;
        }

        return ($nonPrintable / $sniffedLength) > self::NON_PRINTABLE_RATIO_THRESHOLD;
    }
}
