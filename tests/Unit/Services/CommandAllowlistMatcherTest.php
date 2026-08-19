<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Services\CommandAllowlistMatcher;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 123-sandboxed-shell-execution, US2 (data-model.md §2 exact rule,
 * FR-006, spec.md Edge Case). Exercises CommandAllowlistMatcher's pure
 * matching rule in isolation -- no HTTP, no confirmation flow, no
 * database. Genuine confirmation-bypass wiring is proven separately by
 * tests/Feature/CommandExecutionConfirmationJourneyTest.php.
 *
 * Written before CommandAllowlistMatcher exists -- expected to FAIL red
 * (class not found) until T026 creates it.
 */
class CommandAllowlistMatcherTest extends TestCase
{
    private function matcher(): CommandAllowlistMatcher
    {
        return new CommandAllowlistMatcher();
    }

    #[Test]
    public function an_exact_match_after_whitespace_normalization_matches(): void
    {
        $this->assertTrue($this->matcher()->matches(['git status'], 'git  status'));
    }

    #[Test]
    public function leading_and_trailing_whitespace_is_trimmed_before_comparison(): void
    {
        $this->assertTrue($this->matcher()->matches(['git status'], '  git status  '));
    }

    #[Test]
    public function a_command_with_extra_arguments_does_not_match_a_bare_exact_pattern(): void
    {
        $this->assertFalse($this->matcher()->matches(['git status'], 'git status --porcelain'));
    }

    #[Test]
    public function a_superficially_similar_command_does_not_match(): void
    {
        $this->assertFalse($this->matcher()->matches(['git status'], 'git statuses'));
    }

    /**
     * spec.md's own named Edge Case example: a pattern of "git st" must
     * never match "git status" -- no trailing-wildcard form was used, so
     * this is compared for exact equality, which fails. This is the exact
     * "prefix match that also matches an unrelated, more dangerous
     * command" failure mode FR-006 exists to close.
     */
    #[Test]
    public function the_edge_case_prefix_git_st_does_not_match_git_status(): void
    {
        $this->assertFalse($this->matcher()->matches(['git st'], 'git status'));
    }

    #[Test]
    public function a_trailing_space_asterisk_wildcard_matches_the_literal_prefix_plus_anything(): void
    {
        $this->assertTrue($this->matcher()->matches(['npm test *'], 'npm test --watch'));
    }

    #[Test]
    public function a_trailing_wildcard_pattern_does_not_match_an_unrelated_command_sharing_only_a_short_prefix(): void
    {
        $this->assertFalse($this->matcher()->matches(['npm test *'], 'npm testing'));
    }

    /**
     * The wildcard form is opt-in -- a pattern with no trailing " *" is
     * never treated as a prefix, only ever as an exact match.
     */
    #[Test]
    public function a_pattern_of_exactly_npm_test_with_no_trailing_wildcard_does_not_match_npm_test_watch(): void
    {
        $this->assertFalse($this->matcher()->matches(['npm test'], 'npm test --watch'));
    }

    /**
     * No other wildcard position or character is interpreted -- a `*`
     * anywhere else in a pattern is a literal character, deliberately, so
     * a user cannot accidentally create a broader match than the one
     * explicit, documented form (a trailing " *").
     */
    #[Test]
    public function a_mid_string_asterisk_is_a_literal_character_not_a_wildcard(): void
    {
        $this->assertFalse($this->matcher()->matches(['git *status'], 'git anything status'));
        $this->assertTrue($this->matcher()->matches(['git *status'], 'git *status'));
    }

    #[Test]
    public function a_question_mark_is_never_interpreted_as_a_wildcard(): void
    {
        $this->assertFalse($this->matcher()->matches(['git statu?'], 'git status'));
    }

    #[Test]
    public function a_leading_asterisk_is_a_literal_character_not_a_wildcard(): void
    {
        $this->assertFalse($this->matcher()->matches(['*git status'], 'anything git status'));
    }

    #[Test]
    public function an_empty_allowlist_never_matches_anything(): void
    {
        $this->assertFalse($this->matcher()->matches([], 'git status'));
    }

    /**
     * data-model.md §2: reading a null column value is treated identically
     * to an empty array everywhere it is consulted -- CommandAllowlistMatcher
     * never special-cases null vs. [].
     */
    #[Test]
    public function a_null_allowlist_is_treated_identically_to_an_empty_one(): void
    {
        $this->assertFalse($this->matcher()->matches(null, 'git status'));
    }

    #[Test]
    public function a_match_anywhere_in_a_multi_pattern_allowlist_is_sufficient(): void
    {
        $this->assertTrue($this->matcher()->matches(['git diff', 'git status', 'phpunit'], 'git status'));
    }

    #[Test]
    public function no_pattern_in_a_multi_pattern_allowlist_matching_returns_false(): void
    {
        $this->assertFalse($this->matcher()->matches(['git diff', 'phpunit'], 'git status'));
    }
}
