<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\Backend\ClarionPackageServiceProvider;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Services\McpPromptRegistry;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 127-command-packs, Phase 3 (US1), T013 (tasks.md).
 *
 * Extends McpPromptRegistry's existing coverage (McpPromptRegistryTest.php,
 * untouched by this feature -- confirmed still green below) with the new
 * project-defined-command merge: the built-in-only behavior stays additive
 * (a new `source` field on every entry, nothing removed), and a workspace's
 * own commands are discovered live off disk (CommandPackLoader, research.md
 * D6) and merged in, ownership-checked (research.md D6/D7), with argument
 * substitution always delimited (research.md D5).
 *
 * Mirrors McpPromptRegistryTest.php's own mockPackages() fixture for
 * built-ins, plus a real-temp-directory-backed CodingProject fixture
 * (persisted via CodingProject::create(), not the in-memory-only
 * `new CodingProject([...])` CommandPackLoaderTest uses, since the
 * ownership check this feature adds needs a real row to find) for project
 * commands (Grounding note 8's convention).
 *
 * Every case below is expected to fail against the current McpPromptRegistry
 * (Phase 3 has not implemented anything yet): getPrompts()/getPrompt() do
 * not yet accept $codingProjectId/$userId at all, so passing those named
 * arguments is itself already a hard TypeError/Error before any assertion
 * runs -- this is the correct "genuinely red" state.
 */
class McpPromptRegistryCommandPacksTest extends TestCase
{
    /** @var list<string> temp directories created by this test, removed in tearDown */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            if (is_dir($dir)) {
                $this->removeDirectory($dir);
            }
        }

        restore_error_handler();
        restore_exception_handler();
        Mockery::close();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixture helpers
    // -----------------------------------------------------------------

    private function mockPackages(array $packagePrompts): void
    {
        $descriptions = [];
        foreach (array_keys($packagePrompts) as $pkg) {
            $descriptions[$pkg] = ['description' => "Description for {$pkg}"];
        }

        $reflection = new \ReflectionClass(ClarionPackageServiceProvider::class);

        $descProp = $reflection->getProperty('packageDescriptions');
        $descProp->setAccessible(true);
        $descProp->setValue(null, $descriptions);

        $promptsProp = $reflection->getProperty('customPrompts');
        $promptsProp->setAccessible(true);
        $promptsProp->setValue(null, $packagePrompts);
    }

    private function makeProjectDir(): string
    {
        $dir = sys_get_temp_dir().'/mcp_prompt_registry_cp_'.uniqid('', true);
        mkdir($dir, 0777, true);
        $this->tmpDirs[] = $dir;

        return $dir;
    }

    private function write(string $projectDir, string $relativePath, string $content): void
    {
        $full = $projectDir.'/'.$relativePath;
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, $content);
    }

    private function makeProject(?string $rootPath = null, ?string $userId = null): CodingProject
    {
        return CodingProject::create([
            'user_id' => $userId ?? (string) Str::uuid(),
            'name' => 'test project',
            'root_path' => $rootPath ?? $this->makeProjectDir(),
            'test_command' => null,
        ]);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    // -----------------------------------------------------------------
    // (a) Merged list: one project command + every built-in, `source` on
    // every entry, `codingProjectId` on project entries, no `collidesWith`
    // anywhere in a no-collision scenario.
    // -----------------------------------------------------------------

    #[Test]
    public function getPrompts_merges_project_commands_with_builtins_and_tags_every_entry_with_source(): void
    {
        $this->mockPackages([
            '@clarion-app/wizlights' => [
                'listOperations' => 'To adjust the lighting...',
            ],
        ]);

        $projectDir = $this->makeProjectDir();
        $this->write($projectDir, '.claude/commands/summarize.md', <<<'MD'
---
description: Summarize the given input.
---
Please summarize the following: $ARGUMENTS
MD);

        $project = $this->makeProject($projectDir);

        $registry = new McpPromptRegistry();
        $result = $registry->getPrompts(cursor: null, codingProjectId: $project->id, userId: $project->user_id);

        $this->assertArrayHasKey('prompts', $result);
        $this->assertCount(2, $result['prompts'], 'expected the one project command plus the one built-in');

        $byName = [];
        foreach ($result['prompts'] as $prompt) {
            $this->assertArrayHasKey('source', $prompt, 'every entry, project or built-in, must carry a source field');
            $byName[$prompt['name']] = $prompt;
        }

        $this->assertArrayHasKey('summarize', $byName);
        $this->assertSame('project', $byName['summarize']['source']);
        $this->assertArrayHasKey('codingProjectId', $byName['summarize']);
        $this->assertSame($project->id, $byName['summarize']['codingProjectId']);
        $this->assertArrayNotHasKey('collidesWith', $byName['summarize'], 'no collision in this fixture');

        $this->assertArrayHasKey('wizlights_listOperations', $byName);
        $this->assertSame('builtin', $byName['wizlights_listOperations']['source']);
        $this->assertArrayNotHasKey('codingProjectId', $byName['wizlights_listOperations']);
        $this->assertArrayNotHasKey('collidesWith', $byName['wizlights_listOperations']);
    }

    // -----------------------------------------------------------------
    // (b) codingProjectId omitted / foreign-owned / soft-deleted -- all
    // collapse to built-ins only, additive-only shape.
    // -----------------------------------------------------------------

    #[Test]
    public function getPrompts_with_no_coding_project_id_returns_builtins_only_with_source_field_added(): void
    {
        $this->mockPackages([
            '@clarion-app/wizlights' => [
                'listOperations' => 'To adjust the lighting...',
            ],
        ]);

        $registry = new McpPromptRegistry();
        $result = $registry->getPrompts();

        $this->assertCount(1, $result['prompts']);
        $this->assertSame('wizlights_listOperations', $result['prompts'][0]['name']);
        $this->assertSame('builtin', $result['prompts'][0]['source']);
        $this->assertArrayNotHasKey('codingProjectId', $result['prompts'][0]);
        $this->assertArrayNotHasKey('collidesWith', $result['prompts'][0]);
    }

    #[Test]
    public function getPrompts_for_a_project_the_given_user_does_not_own_returns_builtins_only(): void
    {
        $this->mockPackages([
            '@clarion-app/wizlights' => [
                'listOperations' => 'To adjust the lighting...',
            ],
        ]);

        $projectDir = $this->makeProjectDir();
        $this->write($projectDir, '.claude/commands/summarize.md', self::validBodyWithArguments());
        $project = $this->makeProject($projectDir);

        $registry = new McpPromptRegistry();
        $result = $registry->getPrompts(cursor: null, codingProjectId: $project->id, userId: (string) Str::uuid());

        $this->assertCount(1, $result['prompts']);
        $this->assertSame('wizlights_listOperations', $result['prompts'][0]['name']);
        $this->assertSame('builtin', $result['prompts'][0]['source']);
    }

    #[Test]
    public function getPrompts_for_a_soft_deleted_project_returns_builtins_only(): void
    {
        $this->mockPackages([
            '@clarion-app/wizlights' => [
                'listOperations' => 'To adjust the lighting...',
            ],
        ]);

        $projectDir = $this->makeProjectDir();
        $this->write($projectDir, '.claude/commands/summarize.md', self::validBodyWithArguments());
        $project = $this->makeProject($projectDir);
        $userId = $project->user_id;
        $projectId = $project->id;
        $project->delete();

        $registry = new McpPromptRegistry();
        $result = $registry->getPrompts(cursor: null, codingProjectId: $projectId, userId: $userId);

        $this->assertCount(1, $result['prompts']);
        $this->assertSame('wizlights_listOperations', $result['prompts'][0]['name']);
        $this->assertSame('builtin', $result['prompts'][0]['source']);
    }

    #[Test]
    public function existing_mcpPromptRegistryTest_assertions_still_hold_unmodified(): void
    {
        // Sanity re-assertion of McpPromptRegistryTest.php's own core claim
        // (that file is not modified by this feature) -- getPrompts() with
        // no workspace params still returns only the keys that test checks
        // for, plus the new additive `source` field.
        $this->mockPackages([
            '@clarion-app/wizlights' => [
                'listOperations' => 'To adjust the lighting...',
                'executeOperation' => 'When adjusting the lighting...',
            ],
        ]);

        $registry = new McpPromptRegistry();
        $result = $registry->getPrompts();

        $this->assertCount(2, $result['prompts']);
        foreach ($result['prompts'] as $prompt) {
            $this->assertArrayHasKey('name', $prompt);
            $this->assertArrayHasKey('description', $prompt);
            $this->assertArrayHasKey('arguments', $prompt);
        }
    }

    // -----------------------------------------------------------------
    // (c)/(d) Argument substitution: always delimited, always resolves
    // even with no argument text supplied.
    // -----------------------------------------------------------------

    #[Test]
    public function getPrompt_substitutes_arguments_into_a_delimited_block_alongside_the_templates_own_instructions(): void
    {
        $projectDir = $this->makeProjectDir();
        $this->write($projectDir, '.claude/commands/summarize.md', self::validBodyWithArguments());
        $project = $this->makeProject($projectDir);

        $registry = new McpPromptRegistry();
        $result = $registry->getPrompt(
            'summarize',
            ['command' => 'The quick brown fox...'],
            codingProjectId: $project->id,
            userId: $project->user_id,
        );

        $this->assertNotNull($result);
        $text = $result['messages'][0]['content']['text'];

        $this->assertStringContainsString('Please summarize the following:', $text, 'the template\'s own instructions text must survive');
        $this->assertStringContainsString('--- BEGIN ARGUMENT TEXT ---', $text);
        $this->assertStringContainsString('The quick brown fox...', $text);
        $this->assertStringContainsString('--- END ARGUMENT TEXT ---', $text);

        // The argument text must appear strictly between the delimiters,
        // not as a bare, undelimited append (research.md D5).
        $beginPos = strpos($text, '--- BEGIN ARGUMENT TEXT ---');
        $endPos = strpos($text, '--- END ARGUMENT TEXT ---');
        $argPos = strpos($text, 'The quick brown fox...');
        $this->assertGreaterThan($beginPos, $argPos);
        $this->assertLessThan($endPos, $argPos);
    }

    #[Test]
    public function getPrompt_with_empty_arguments_still_resolves_with_an_empty_but_present_delimited_block(): void
    {
        $projectDir = $this->makeProjectDir();
        $this->write($projectDir, '.claude/commands/summarize.md', self::validBodyWithArguments());
        $project = $this->makeProject($projectDir);

        $registry = new McpPromptRegistry();
        $result = $registry->getPrompt(
            'summarize',
            [],
            codingProjectId: $project->id,
            userId: $project->user_id,
        );

        $this->assertNotNull($result, 'an empty-argument invocation must still resolve, never null/error (FR-006)');
        $text = $result['messages'][0]['content']['text'];

        $this->assertStringContainsString('--- BEGIN ARGUMENT TEXT ---', $text);
        $this->assertStringContainsString('--- END ARGUMENT TEXT ---', $text);

        $beginPos = strpos($text, '--- BEGIN ARGUMENT TEXT ---') + strlen('--- BEGIN ARGUMENT TEXT ---');
        $endPos = strpos($text, '--- END ARGUMENT TEXT ---');
        $this->assertSame('', trim(substr($text, $beginPos, $endPos - $beginPos)), 'the delimited block must be empty, not omitted');
    }

    // -----------------------------------------------------------------
    // No $ARGUMENTS token in the body -- supplied argument text silently
    // unused, body passed through byte-for-byte.
    // -----------------------------------------------------------------

    #[Test]
    public function getPrompt_for_a_template_with_no_arguments_token_leaves_supplied_text_unused_and_body_untouched(): void
    {
        $projectDir = $this->makeProjectDir();
        $body = "This command needs no user input at all.\n";
        $this->write($projectDir, '.claude/commands/noargs.md', $body);
        $project = $this->makeProject($projectDir);

        $registry = new McpPromptRegistry();
        $result = $registry->getPrompt(
            'noargs',
            ['command' => 'this text has nowhere to go'],
            codingProjectId: $project->id,
            userId: $project->user_id,
        );

        $this->assertNotNull($result);
        $text = $result['messages'][0]['content']['text'];

        $this->assertSame(trim($body), $text, 'body must pass through byte-for-byte when there is no $ARGUMENTS token');
        $this->assertStringNotContainsString('this text has nowhere to go', $text);
        $this->assertStringNotContainsString('BEGIN ARGUMENT TEXT', $text);
    }

    // -----------------------------------------------------------------
    // Missing description -- synthesized default naming the relative path.
    // -----------------------------------------------------------------

    #[Test]
    public function a_project_command_with_no_frontmatter_description_gets_a_synthesized_default(): void
    {
        $projectDir = $this->makeProjectDir();
        $this->write($projectDir, '.claude/commands/noargs.md', "This command needs no user input at all.\n");
        $project = $this->makeProject($projectDir);

        $registry = new McpPromptRegistry();
        $result = $registry->getPrompts(cursor: null, codingProjectId: $project->id, userId: $project->user_id);

        $byName = [];
        foreach ($result['prompts'] as $prompt) {
            $byName[$prompt['name']] = $prompt;
        }

        $this->assertArrayHasKey('noargs', $byName);
        $this->assertSame(
            'Project-defined command from .claude/commands/noargs.md',
            $byName['noargs']['description'],
        );
    }

    // -----------------------------------------------------------------
    // Not found -- neither a project command nor a built-in -- unchanged
    // -32602 shape (null return from the registry).
    // -----------------------------------------------------------------

    #[Test]
    public function getPrompt_for_an_unknown_name_returns_null(): void
    {
        $this->mockPackages([
            '@clarion-app/wizlights' => [
                'listOperations' => 'To adjust the lighting...',
            ],
        ]);

        $projectDir = $this->makeProjectDir();
        $this->write($projectDir, '.claude/commands/summarize.md', self::validBodyWithArguments());
        $project = $this->makeProject($projectDir);

        $registry = new McpPromptRegistry();
        $result = $registry->getPrompt(
            'does-not-exist-anywhere',
            [],
            codingProjectId: $project->id,
            userId: $project->user_id,
        );

        $this->assertNull($result);
    }

    private static function validBodyWithArguments(): string
    {
        return <<<'MD'
---
description: Summarize the given input.
---
Please summarize the following: $ARGUMENTS
MD;
    }

    // -----------------------------------------------------------------
    // Phase 5 (US3), T024 -- collision visibility and resolution.
    //
    // Every case below is expected to fail against the current
    // McpPromptRegistry (Phase 5 has not implemented the collision-
    // resolution pass yet): collectAllPrompts() merges project and
    // built-in entries with no grouping-by-effective-name step at all, so
    // neither `collidesWith` nor a synthesized `builtin:`/`copilot-agent:`
    // alternate name is ever produced, and getPrompt() has no prefix-
    // stripping branch. This is the correct "genuinely red" state.
    //
    // The project-vs-builtin fixture deliberately uses the real built-in
    // name shape `{shortName}_listOperations` (quickstart.md Scenario 3),
    // e.g. `llm-client_listOperations` for the `llm-client` package
    // itself -- note the mixed-case `Operations` inherited verbatim from
    // the built-in prompt key. CommandTemplateParser::deriveName() (D8)
    // always lowercases a project template's own name, so the project
    // file here derives to `llm-client_listoperations` internally; the
    // winning entry's *displayed* `name` in getPrompts() must still read
    // `llm-client_listOperations` (matching the built-in's original
    // spelling) per quickstart.md/contracts/mcp-prompts-list.md's worked
    // example -- collision resolution must canonicalize the shared bare
    // name to the built-in's own casing when a built-in is one of the
    // colliding occupants, not just reuse the project template's own
    // (always-lowercased) name verbatim.
    // -----------------------------------------------------------------

    private const LLM_CLIENT_BUILTIN_LIST_OPERATIONS_TEXT = 'Guidance for discovering and selecting llm-client tools via the MCP interface.';

    private const PROJECT_LLM_CLIENT_LIST_OPERATIONS_BODY = "This is the PROJECT's own guidance for llm-client operations, distinct from the built-in guidance.";

    /**
     * @return array{0: CodingProject, 1: string} [$project, $projectDir]
     */
    private function makeProjectVsBuiltinCollisionFixture(): array
    {
        $this->mockPackages([
            '@clarion-app/llm-client' => [
                'listOperations' => self::LLM_CLIENT_BUILTIN_LIST_OPERATIONS_TEXT,
            ],
            '@clarion-app/wizlights' => [
                'listOperations' => 'To adjust the lighting...',
            ],
        ]);

        $projectDir = $this->makeProjectDir();
        $this->write(
            $projectDir,
            '.claude/commands/llm-client_listOperations.md',
            self::PROJECT_LLM_CLIENT_LIST_OPERATIONS_BODY."\n",
        );

        $project = $this->makeProject($projectDir);

        return [$project, $projectDir];
    }

    #[Test]
    public function getPrompts_marks_a_project_vs_builtin_collision_visible_with_collideswith_and_alternate_name(): void
    {
        [$project] = $this->makeProjectVsBuiltinCollisionFixture();

        $registry = new McpPromptRegistry();
        $result = $registry->getPrompts(cursor: null, codingProjectId: $project->id, userId: $project->user_id);

        $this->assertCount(3, $result['prompts'], 'expected the colliding project entry, the shadowed builtin entry, and one non-colliding builtin');

        $byName = [];
        foreach ($result['prompts'] as $prompt) {
            $byName[$prompt['name']] = $prompt;
        }

        $this->assertArrayHasKey('llm-client_listOperations', $byName, 'the project entry must win the bare, builtin-cased name');
        $this->assertSame('project', $byName['llm-client_listOperations']['source']);
        $this->assertSame($project->id, $byName['llm-client_listOperations']['codingProjectId']);
        $this->assertSame('builtin:llm-client_listOperations', $byName['llm-client_listOperations']['collidesWith']);

        $this->assertArrayHasKey('builtin:llm-client_listOperations', $byName, 'the shadowed builtin must remain reachable under its prefixed alternate name');
        $this->assertSame('builtin', $byName['builtin:llm-client_listOperations']['source']);
        $this->assertSame('llm-client_listOperations', $byName['builtin:llm-client_listOperations']['collidesWith']);

        $this->assertArrayHasKey('wizlights_listOperations', $byName, 'a non-colliding builtin must be listed unchanged');
        $this->assertSame('builtin', $byName['wizlights_listOperations']['source']);
        $this->assertArrayNotHasKey('collidesWith', $byName['wizlights_listOperations'], 'a non-colliding entry must carry no collidesWith key at all, not merely null');
    }

    #[Test]
    public function getPrompt_resolves_the_bare_colliding_name_to_the_project_command_consistently_across_repeated_calls(): void
    {
        [$project] = $this->makeProjectVsBuiltinCollisionFixture();

        $registry = new McpPromptRegistry();

        $expectedText = trim(self::PROJECT_LLM_CLIENT_LIST_OPERATIONS_BODY);
        $observed = [];

        for ($i = 0; $i < 5; $i++) {
            $result = $registry->getPrompt(
                'llm-client_listOperations',
                [],
                codingProjectId: $project->id,
                userId: $project->user_id,
            );

            $this->assertNotNull($result, "iteration {$i}: bare-name lookup must resolve (project wins the collision)");
            $observed[] = $result['messages'][0]['content']['text'];
        }

        foreach ($observed as $i => $text) {
            $this->assertSame($expectedText, $text, "iteration {$i}: must resolve to the project file's content, not the builtin's");
        }

        $this->assertCount(1, array_unique($observed), 'all five resolutions must be byte-identical, not merely equivalent');
    }

    #[Test]
    public function getPrompt_resolves_the_builtin_prefixed_name_to_the_original_builtin_content_bypassing_precedence(): void
    {
        [$project] = $this->makeProjectVsBuiltinCollisionFixture();

        $registry = new McpPromptRegistry();
        $result = $registry->getPrompt(
            'builtin:llm-client_listOperations',
            [],
            codingProjectId: $project->id,
            userId: $project->user_id,
        );

        $this->assertNotNull($result, 'the builtin: prefix must resolve directly against the builtin, bypassing the project-wins precedence rule');
        $this->assertSame(
            self::LLM_CLIENT_BUILTIN_LIST_OPERATIONS_TEXT,
            $result['messages'][0]['content']['text'],
            'must be the original, unmodified builtin guidance text',
        );
    }

    #[Test]
    public function getPrompts_and_getPrompt_resolve_an_intra_workspace_convention_tie_deterministically(): void
    {
        $this->mockPackages([]);

        $projectDir = $this->makeProjectDir();
        $claudeBody = 'This is the CLAUDE-CONVENTION review guidance.';
        $copilotBody = 'This is the COPILOT-AGENT review guidance.';

        $this->write($projectDir, '.claude/commands/review.md', $claudeBody."\n");
        $this->write($projectDir, '.github/agents/review.agent.md', $copilotBody."\n");

        $project = $this->makeProject($projectDir);

        $registry = new McpPromptRegistry();
        $result = $registry->getPrompts(cursor: null, codingProjectId: $project->id, userId: $project->user_id);

        $reviewEntries = array_values(array_filter(
            $result['prompts'],
            fn ($p) => $p['name'] === 'review',
        ));
        $copilotAgentEntries = array_values(array_filter(
            $result['prompts'],
            fn ($p) => $p['name'] === 'copilot-agent:review',
        ));

        $this->assertCount(1, $reviewEntries, 'exactly one entry must occupy the bare "review" name -- the claude_command-convention winner');
        $this->assertSame('project', $reviewEntries[0]['source']);
        $this->assertSame('copilot-agent:review', $reviewEntries[0]['collidesWith']);

        $this->assertCount(1, $copilotAgentEntries, 'the copilot_agent-convention loser must remain reachable under its hyphenated-tag alternate name');
        $this->assertSame('project', $copilotAgentEntries[0]['source']);
        $this->assertSame('review', $copilotAgentEntries[0]['collidesWith']);

        $claudeResult = $registry->getPrompt('review', [], codingProjectId: $project->id, userId: $project->user_id);
        $this->assertNotNull($claudeResult);
        $this->assertSame($claudeBody, $claudeResult['messages'][0]['content']['text']);

        $copilotResult = $registry->getPrompt('copilot-agent:review', [], codingProjectId: $project->id, userId: $project->user_id);
        $this->assertNotNull($copilotResult, 'the copilot-agent: prefix must resolve directly to the intra-workspace loser');
        $this->assertSame($copilotBody, $copilotResult['messages'][0]['content']['text']);
    }

    // -----------------------------------------------------------------
    // Phase 7 (US5), T032 -- per-file isolation proven one layer up,
    // through the actual McpPromptRegistry merge (Phase 3's iteration
    // over $result->commands), not only at the CommandPackLoader layer
    // in isolation (already proven there by CommandPackLoaderTest's own
    // per-file-isolation case). A zero-byte file, and separately a file
    // with an unterminated/malformed frontmatter block, must both be
    // silently absent from getPrompts() -- genuinely absent, not listed
    // with an error marker -- while every valid sibling file in the
    // same directory stays listed and invocable, unaffected.
    // -----------------------------------------------------------------

    #[Test]
    public function a_zero_byte_file_and_a_malformed_frontmatter_file_are_silently_absent_from_getprompts_while_valid_siblings_stay_listed_and_invocable(): void
    {
        $this->mockPackages([]);

        $projectDir = $this->makeProjectDir();
        $this->write($projectDir, '.claude/commands/a.md', <<<'MD'
---
description: Command A.
---
This is command A.
MD);
        $this->write($projectDir, '.claude/commands/b.md', <<<'MD'
---
description: Command B.
---
This is command B.
MD);
        $this->write($projectDir, '.claude/commands/c.md', '');

        $project = $this->makeProject($projectDir);
        $registry = new McpPromptRegistry();

        $result = $registry->getPrompts(cursor: null, codingProjectId: $project->id, userId: $project->user_id);
        $names = array_column($result['prompts'], 'name');

        $this->assertContains('a', $names);
        $this->assertContains('b', $names);
        $this->assertNotContains('c', $names, 'a zero-byte file must never produce a listed entry, error-marked or otherwise');

        $promptA = $registry->getPrompt('a', [], codingProjectId: $project->id, userId: $project->user_id);
        $promptB = $registry->getPrompt('b', [], codingProjectId: $project->id, userId: $project->user_id);
        $this->assertNotNull($promptA, '"a" must remain invocable despite the sibling zero-byte file');
        $this->assertNotNull($promptB, '"b" must remain invocable despite the sibling zero-byte file');
        $this->assertStringContainsString('This is command A.', $promptA['messages'][0]['content']['text']);
        $this->assertStringContainsString('This is command B.', $promptB['messages'][0]['content']['text']);

        $this->assertNull(
            $registry->getPrompt('c', [], codingProjectId: $project->id, userId: $project->user_id),
            'a zero-byte file must never resolve as invocable either'
        );

        // Add a second, differently-broken file (unterminated/malformed
        // frontmatter block) -- must not affect a or b, and must not
        // produce an entry of its own.
        $this->write($projectDir, '.claude/commands/d.md', "---\nnot: [valid, yaml:\n---\nbody");

        $result = $registry->getPrompts(cursor: null, codingProjectId: $project->id, userId: $project->user_id);
        $names = array_column($result['prompts'], 'name');

        $this->assertContains('a', $names, '"a" must remain listed after adding the malformed-frontmatter sibling');
        $this->assertContains('b', $names, '"b" must remain listed after adding the malformed-frontmatter sibling');
        $this->assertNotContains('c', $names);
        $this->assertNotContains('d', $names, 'a malformed-frontmatter file must never produce a listed entry, error-marked or otherwise');

        $promptA = $registry->getPrompt('a', [], codingProjectId: $project->id, userId: $project->user_id);
        $promptB = $registry->getPrompt('b', [], codingProjectId: $project->id, userId: $project->user_id);
        $this->assertNotNull($promptA, '"a" must remain invocable after adding the malformed-frontmatter sibling');
        $this->assertNotNull($promptB, '"b" must remain invocable after adding the malformed-frontmatter sibling');
        $this->assertStringContainsString('This is command A.', $promptA['messages'][0]['content']['text']);
        $this->assertStringContainsString('This is command B.', $promptB['messages'][0]['content']['text']);

        $this->assertNull(
            $registry->getPrompt('d', [], codingProjectId: $project->id, userId: $project->user_id),
            'a malformed-frontmatter file must never resolve as invocable'
        );
    }
}
