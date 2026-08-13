<?php

namespace ClarionApp\LlmClient\Tests\Unit\Commands;

use ClarionApp\Backend\ApiManager;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for the `agent:create` Artisan command (089-agent-scaffolding-cli,
 * Phase 3/US1, contracts §1 rows 2/3/8 — rows 1 and 4-7 are Phase 4/5's own
 * additions and are deliberately not covered here).
 *
 * Written before `agent:create` is registered — every test in this file is
 * expected to fail (command not found / non-zero unexpected exit) until
 * Phase 3's own Implementation tasks (T017/T018) create and wire
 * AgentCreateCommand. That is the intended RED state, not a mistake.
 *
 * Calling convention matches EmbedMemoryCommandTest.php/
 * ResolveAbandonedRunsCommandTest.php's own established
 * Artisan::call()/Artisan::output() precedent in this package.
 */
class AgentCreateCommandTest extends TestCase
{
    /** @var string[] */
    private array $tempDirs = [];

    private string $originalCwd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalCwd = getcwd();
    }

    protected function tearDown(): void
    {
        // Unconditionally restore the process cwd — a failed assertion
        // mid-test must never leave the test process's cwd altered for a
        // later test.
        chdir($this->originalCwd);

        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
        $this->tempDirs = [];

        $this->clearOperationCatalog();
        Mockery::close();

        parent::tearDown();
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/agent_create_command_test_'.uniqid('', true);
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        @chmod($path, 0777);

        foreach (new \FilesystemIterator($path) as $item) {
            if ($item->isDir()) {
                $this->removeDirectory($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }

    /**
     * @return string[] non-"."/".." entries directly inside $dir
     */
    private function directoryEntries(string $dir): array
    {
        return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    }

    // ---------------------------------------------------------------
    // 1. Happy path (US1 AC1/AC2/AC3, contract §1 row 8)
    // ---------------------------------------------------------------

    #[Test]
    public function creates_a_definition_file_and_reports_success(): void
    {
        $this->seedOperationCatalog([]);
        $dir = $this->makeTempDir();

        $exitCode = Artisan::call('agent:create', [
            'name' => 'weather-agent',
            '--path' => $dir,
        ]);

        $this->assertSame(0, $exitCode);

        $output = Artisan::output();
        $expectedPath = $dir.'/weather-agent.yaml';
        $this->assertStringContainsString('Agent definition written to', $output);
        $this->assertStringContainsString($expectedPath, $output);
        $this->assertFileExists($expectedPath);
    }

    // ---------------------------------------------------------------
    // 2. --path defaults to the current working directory when omitted
    //    (research.md D6)
    // ---------------------------------------------------------------

    #[Test]
    public function path_defaults_to_the_current_working_directory_when_omitted(): void
    {
        $this->seedOperationCatalog([]);
        $dir = $this->makeTempDir();

        chdir($dir);

        $exitCode = Artisan::call('agent:create', ['name' => 'cwd-agent']);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($dir.'/cwd-agent.yaml');
    }

    // ---------------------------------------------------------------
    // 3. A blank/whitespace-only name is refused distinguishably
    //    (contract §1 row 2, research.md D10, quickstart step 9)
    // ---------------------------------------------------------------

    #[Test]
    public function a_blank_name_is_refused_with_the_exact_missing_name_wording(): void
    {
        $this->seedOperationCatalog([]);
        $dir = $this->makeTempDir();

        $exitCode = Artisan::call('agent:create', [
            'name' => '   ',
            '--path' => $dir,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'A definition must state a non-empty "name".',
            Artisan::output()
        );
        $this->assertSame([], $this->directoryEntries($dir), 'No file may be written for a refused name.');
    }

    // ---------------------------------------------------------------
    // 4. A name whose Str::slug() is empty is refused, distinctly from
    //    the blank-name case (contract §1 row 3, research.md D10)
    // ---------------------------------------------------------------

    #[Test]
    public function a_name_whose_slug_is_empty_is_refused_distinctly_from_a_blank_name(): void
    {
        $this->seedOperationCatalog([]);
        $dir = $this->makeTempDir();

        $exitCode = Artisan::call('agent:create', [
            'name' => '!!!',
            '--path' => $dir,
        ]);

        $this->assertSame(1, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('cannot be turned into a valid file name', $output);
        $this->assertStringNotContainsString('A definition must state a non-empty "name".', $output);
        $this->assertSame([], $this->directoryEntries($dir), 'No file may be written for a refused name.');
    }

    // ---------------------------------------------------------------
    // Operation catalog fixture (copied verbatim from
    // AgentDefinitionMinimalJourneyTest.php's own established pattern)
    // ---------------------------------------------------------------

    /**
     * @param array<string, array{path: string, method: string, summary: string}> $operations
     */
    private function seedOperationCatalog(array $operations): void
    {
        $paths = [];
        foreach ($operations as $operationId => $entry) {
            $paths[$entry['path']][$entry['method']] = [
                'operationId' => $operationId,
                'summary' => $entry['summary'],
            ];
        }
        $doc = ['paths' => $paths];

        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);

        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->andReturn($doc);
        $this->app->instance(Generator::class, $generator);
    }

    private function clearOperationCatalog(): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }
}
