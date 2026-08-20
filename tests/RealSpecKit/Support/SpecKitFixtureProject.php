<?php

namespace Tests\RealSpecKit\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * A disposable, filesystem-real Spec-Kit project directory (data-model.md
 * §1), produced by actually invoking the real `specify` CLI
 * (research.md D1). The constructor is private -- the only public entry
 * point is SpecKitCliFixtureBuilder::build() (T012), which performs the
 * real CLI invocation and the filesystem scan that populates
 * $agentCommandFiles; nothing here ever accepts a literal, hand-typed
 * command list (research.md D9).
 */
final readonly class SpecKitFixtureProject
{
    /**
     * @param  string  $rootPath  Absolute, sys_get_temp_dir()-rooted (FR-014 isolation).
     * @param  string  $aiTarget  'copilot' | 'claude' (research.md D2/D3).
     * @param  ?string  $integrationOptions  e.g. '--commands', or null for default mode.
     * @param  string  $cliVersionString  Literal `specify --version` output, never hardcoded (research.md D1).
     * @param  list<string>  $agentCommandFiles  Self-scanned glob() result at build time (research.md D9)
     *   -- relative paths, e.g. '.github/agents/speckit.plan.agent.md'.
     */
    private function __construct(
        public string $rootPath,
        public string $aiTarget,
        public ?string $integrationOptions,
        public string $cliVersionString,
        public array $agentCommandFiles,
    ) {
        $this->guardAgainstRepositoryContainment($rootPath);
    }

    /**
     * Derived command names, using 127's own D8 rule (strip the convention's
     * file suffix). Never a hand-maintained list -- computed from
     * $agentCommandFiles each time it's read (research.md D9).
     *
     * @return list<string>
     */
    public function expectedCommandNames(): array
    {
        return array_map(
            fn (string $relativePath): string => $this->deriveCommandName($relativePath),
            $this->agentCommandFiles
        );
    }

    /**
     * `.github/agents/speckit.plan.agent.md` -> `speckit.plan` (strip the
     * `.agent.md` suffix from the basename). `.claude/skills/speckit-plan/
     * SKILL.md` -> `speckit-plan` (the skill directory's own name).
     */
    private function deriveCommandName(string $relativePath): string
    {
        if (str_ends_with($relativePath, '.agent.md')) {
            return basename($relativePath, '.agent.md');
        }

        if (basename($relativePath) === 'SKILL.md') {
            return basename(dirname($relativePath));
        }

        throw new RuntimeException(
            "SpecKitFixtureProject: cannot derive a command name from unrecognized "
            . "agentCommandFiles entry '{$relativePath}' -- expected a "
            . "'.github/agents/*.agent.md' or '.claude/skills/*/SKILL.md' shape."
        );
    }

    /**
     * FR-014 isolation, enforced structurally rather than by convention: a
     * rootPath equal to, or nested inside, this repository's own package
     * root is refused outright. String-based (never realpath()), so a
     * rootPath that does not yet exist on disk -- the normal case for a
     * freshly-computed sys_get_temp_dir() subdirectory before `specify
     * init` has run -- is still validated correctly.
     */
    private function guardAgainstRepositoryContainment(string $rootPath): void
    {
        $packageRoot = rtrim(dirname(__DIR__, 3), '/');
        $normalizedRootPath = rtrim($rootPath, '/');

        if ($normalizedRootPath === $packageRoot || str_starts_with($normalizedRootPath, $packageRoot.'/')) {
            throw new InvalidArgumentException(
                "SpecKitFixtureProject: rootPath '{$rootPath}' is equal to, or inside, this "
                . "repository's own working tree ('{$packageRoot}') -- fixtures must live in a "
                . 'disposable sys_get_temp_dir() subdirectory instead (FR-014).'
            );
        }
    }
}
