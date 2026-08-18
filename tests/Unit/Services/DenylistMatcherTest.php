<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Services\DenylistMatcher;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DenylistMatcher -- the single shared fnmatch()-over-denylist matching
 * primitive both ApiCallValidator and McpClientCallValidator call.
 * Stateless and pure: every test here passes the denylist and candidates
 * as plain arguments, no config/env/DB involved.
 *
 * Written before DenylistMatcher exists -- expected to FAIL red (class
 * not found) until it is created.
 */
class DenylistMatcherTest extends TestCase
{
    #[Test]
    public function returns_null_when_no_pattern_matches_any_candidate(): void
    {
        $result = DenylistMatcher::matchesAny(
            ['/foo/*', 'mcp:server-a:unrelated'],
            '/bar/baz',
            'mcp:server-a:tool-a',
        );

        $this->assertNull($result);
    }

    #[Test]
    public function returns_the_matching_pattern_when_a_candidate_matches(): void
    {
        $result = DenylistMatcher::matchesAny(
            ['/mcp-client/*/delete_*'],
            '/mcp-client/server-a/delete_file',
        );

        $this->assertSame('/mcp-client/*/delete_*', $result);
    }

    #[Test]
    public function the_order_candidates_are_passed_in_does_not_affect_which_pattern_is_returned(): void
    {
        $denylist = ['/foo/*', 'mcp:server-a:tool-a'];

        $withPathFirst = DenylistMatcher::matchesAny($denylist, '/foo/bar', 'mcp:server-a:tool-a');
        $withOperationIdFirst = DenylistMatcher::matchesAny($denylist, 'mcp:server-a:tool-a', '/foo/bar');

        // Both candidates match a pattern here; either candidate order
        // must still surface the same, first-in-denylist-order pattern.
        $this->assertSame('/foo/*', $withPathFirst);
        $this->assertSame('/foo/*', $withOperationIdFirst);
    }

    #[Test]
    public function given_an_empty_denylist_it_always_returns_null_regardless_of_candidates(): void
    {
        $this->assertNull(DenylistMatcher::matchesAny([], '/anything', 'mcp:server-a:tool-a', 'literally-anything'));
    }

    #[Test]
    public function a_multi_candidate_call_matches_on_a_later_candidate_when_an_earlier_one_does_not_match(): void
    {
        $result = DenylistMatcher::matchesAny(
            ['mcp:server-a:tool-a'],
            '/mcp-client/server-a/renamed_tool',
            'mcp:server-a:tool-a',
        );

        $this->assertSame('mcp:server-a:tool-a', $result);
    }
}
