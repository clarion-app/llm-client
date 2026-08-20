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
}
