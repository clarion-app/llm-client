<?php

namespace ClarionApp\LlmClient\Tests\Unit\ValueObjects;

use ClarionApp\LlmClient\ValueObjects\OperationGroupPattern;
use ClarionApp\LlmClient\ValueObjects\OperationGroupPatternKind;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OperationGroupPattern (data-model.md §2, research.md D8) is the single
 * "what does this pattern match" implementation shared by the parse-time
 * emptiness check (AgentDefinitionParser) and AgentDefinition's own
 * isOperationPermitted()/isConfirmationRequired() at call time. This test
 * covers the value object in isolation, with a hand-built catalog fixture
 * — never a real ApiManager call (this is a Unit test).
 */
class OperationGroupPatternTest extends TestCase
{
    #[Test]
    public function a_bare_uppercase_http_verb_is_kind_http_verb(): void
    {
        $pattern = new OperationGroupPattern('DELETE');

        $this->assertSame(OperationGroupPatternKind::HttpVerb, $pattern->kind);
    }

    #[Test]
    public function anything_else_is_kind_glob(): void
    {
        $this->assertSame(OperationGroupPatternKind::Glob, (new OperationGroupPattern('contacts.*'))->kind);
        $this->assertSame(OperationGroupPatternKind::Glob, (new OperationGroupPattern('weather.get_forecast'))->kind);
        // Lowercase does not count as the verb form.
        $this->assertSame(OperationGroupPatternKind::Glob, (new OperationGroupPattern('delete'))->kind);
    }

    #[Test]
    public function glob_pattern_matches_operation_id_via_fnmatch_and_ignores_method(): void
    {
        $pattern = new OperationGroupPattern('contacts.*');

        $this->assertTrue($pattern->matches('contacts.store', 'post'));
        $this->assertTrue($pattern->matches('contacts.destroy', 'delete'));
        $this->assertFalse($pattern->matches('weather.get_forecast', 'get'));

        // Method is ignored entirely for a Glob pattern.
        $this->assertTrue($pattern->matches('contacts.store', 'anything-at-all'));
    }

    #[Test]
    public function http_verb_pattern_matches_method_case_insensitively_and_ignores_operation_id(): void
    {
        $pattern = new OperationGroupPattern('DELETE');

        $this->assertTrue($pattern->matches('contacts.destroy', 'delete'));
        $this->assertTrue($pattern->matches('contacts.destroy', 'DELETE'));
        $this->assertFalse($pattern->matches('contacts.destroy', 'post'));

        // operationId is ignored entirely for an HttpVerb pattern.
        $this->assertTrue($pattern->matches('anything-at-all', 'delete'));
    }

    #[Test]
    public function resolve_returns_the_union_of_every_matching_catalog_entry_with_no_duplicates(): void
    {
        $catalog = [
            ['operationId' => 'contacts.store', 'method' => 'post'],
            ['operationId' => 'contacts.destroy', 'method' => 'delete'],
            ['operationId' => 'weather.get_forecast', 'method' => 'get'],
        ];

        // Both patterns match contacts.destroy — must appear once, not twice.
        $matched = OperationGroupPattern::resolve(['contacts.*', 'DELETE'], $catalog);

        sort($matched);
        $this->assertSame(['contacts.destroy', 'contacts.store'], $matched);
    }

    #[Test]
    public function resolve_with_no_patterns_returns_an_empty_array(): void
    {
        $catalog = [
            ['operationId' => 'contacts.store', 'method' => 'post'],
        ];

        $this->assertSame([], OperationGroupPattern::resolve([], $catalog));
    }

    #[Test]
    public function resolve_against_an_empty_catalog_returns_an_empty_array_regardless_of_pattern_content(): void
    {
        // The emptiness this feature's parser (T021) surfaces as
        // EmptyOperationPattern — not OperationGroupPattern's own concern
        // to reject.
        $this->assertSame([], OperationGroupPattern::resolve(['contacts.*', 'DELETE'], []));
    }
}
