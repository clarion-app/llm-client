<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The per-file problems CommandTemplateParser::parse()/CommandPackLoader::
 * discover() can find while turning one command template file into a
 * CommandTemplate (127-command-packs, data-model.md §3, contracts/
 * command-template-parser.md §1).
 */
enum TemplateDiscoveryProblemKind
{
    // The body (everything after any frontmatter block, or the entire
    // content when there was none) is empty or whitespace-only after
    // trim() — a zero-byte file collapses here too (research.md D2).
    case EmptyInstructions;

    // is_readable() was false — reported by CommandPackLoader itself,
    // never by CommandTemplateParser (contracts/command-template-parser.md
    // §1's own "UnreadableFile is reported by the caller" guarantee).
    case UnreadableFile;

    // Frontmatter delimiters ("---") are present but the block between
    // them either fails Symfony\Component\Yaml\Yaml::parse() or parses to
    // something other than a mapping (a bare scalar or a list).
    case MalformedFrontmatter;
}
