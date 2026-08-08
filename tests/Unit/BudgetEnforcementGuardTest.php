<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * The closed-set guard over every model-invoking call site in src/.
 *
 * "No way of starting model-consuming work skips the spending evaluation"
 * is only true on the day it ships unless something keeps it true. This
 * test is that something: it scans every .php file under src/ for the four
 * constructs that actually reach a model and asserts the set of files
 * containing them is *equal* to a fixed allowlist, every member of which is
 * either one of the two funnels or is reached only through one.
 *
 * Equality, not containment, on purpose. A subset assertion notices a new
 * ungated call site but says nothing when a call site disappears — and a
 * disappearing member is exactly what happens when one of the newly
 * funnelled paths is quietly unwrapped or deleted, at which point the
 * allowlist has become a lie the build no longer checks.
 *
 * `->embed(` is in the construct list because embedding calls cost real
 * money. Without it the scan sees neither EmbeddingService nor
 * RoleTestRunner::exerciseEmbedding(), and a future ungated embedding call
 * site would pass the build in silence — the precise failure this guard
 * exists to prevent.
 *
 * Two traps that make an *assumed* allowlist fail outright, both verified
 * against the tree rather than reasoned about:
 *
 *  - AgentLoopStreamHandler.php contains **none** of the four constructs.
 *    It re-enters AgentLoopService::start() and calls no provider itself,
 *    so listing it breaks the equality assertion.
 *  - EmbeddingService.php appears only once `->embed(` is scanned for; a
 *    chat-only construct list would leave it off and the list would then be
 *    wrong in the other direction.
 *
 * This test needs no database and no application container.
 */
class BudgetEnforcementGuardTest extends TestCase
{
    /**
     * The four constructs that actually reach a model. Anything in src/
     * containing one of these either is a funnel or is reached only through
     * one; there is no third category.
     */
    private const MODEL_INVOKING_CONSTRUCTS = [
        '->chat(',
        '->embed(',
        'SendHttpStreamRequest::dispatch(',
        'SendHttpRequest::dispatch(',
    ];

    /**
     * Every file in src/ permitted to contain a model-invoking construct,
     * each with the reason it is allowed. Paths are relative to src/.
     */
    private const ALLOWLIST = [
        // The interactive funnel itself: the synchronous chat call and the
        // streamed dispatch both live here, and admitInteractiveWork() gates
        // run()/resume()/resumeSync() plus start() when it mints a new run.
        'Services/AgentLoopService.php',

        // Reached only *within* an already-admitted turn. Deliberately not
        // re-gated: re-checking mid-turn would truncate a response that is
        // already in flight.
        'Services/ToolResultCondenser.php',

        // Self-recursion inside one provider call, so it is inside whatever
        // admitted that call.
        'Providers/LlamaCppProvider.php',

        // Deferred work, gated at dequeue by the traceSystemRun() call
        // inside its own handle().
        'Jobs/GenerateEpisodicMemoryJob.php',
        'Jobs/PreWarmChunkSummaryJob.php',

        // System-initiated work brought under traceSystemRun() by this
        // feature.
        'Services/ConversationCondenser.php',
        'Services/EmbeddingService.php',
        'Services/RoleTestRunner.php',
        'OpenAIGenerateConversationTitleRequest.php',

        // Dead legacy classes. They are on the list because they genuinely
        // contain the constructs and the assertion is an equality — not
        // because they are gated. The separate assertion below is what keeps
        // them harmless.
        'OpenAIConversationRequest.php',
        'OpenAIConversationStreamRequest.php',
    ];

    /**
     * Classes that still contain a model-invoking construct but must never
     * be constructed anywhere in src/ again.
     */
    private const DEAD_LEGACY_CLASSES = [
        'OpenAIConversationRequest',
        'OpenAIConversationStreamRequest',
    ];

    private string $srcDir;

    protected function setUp(): void
    {
        $this->srcDir = dirname(__DIR__, 2).'/src';
    }

    #[Test]
    public function the_set_of_model_invoking_files_equals_the_allowlist(): void
    {
        $found = $this->filesContainingAnyConstruct();

        $unexpected = array_values(array_diff($found, self::ALLOWLIST));
        $missing = array_values(array_diff(self::ALLOWLIST, $found));

        $this->assertSame(
            [],
            $unexpected,
            "New model-invoking call site(s) in src/ that no funnel covers:\n"
            .implode("\n", $unexpected)
            ."\nEither route the call through AgentLoopService::admitInteractiveWork() "
            .'or RunTraceRecorder::traceSystemRun(), or add the file to this allowlist '
            .'with the reason it is already covered.'
        );

        $this->assertSame(
            [],
            $missing,
            "Allowlisted file(s) that no longer contain any model-invoking construct:\n"
            .implode("\n", $missing)
            ."\nA member that has stopped calling a model is a stale allowlist entry — "
            .'remove it, or find out why the call site disappeared.'
        );

        // Belt and braces: the two lists are the same set, in the same
        // normalised order, so neither of the diffs above can pass vacuously
        // on an empty scan.
        $expected = self::ALLOWLIST;
        sort($expected);
        sort($found);
        $this->assertSame($expected, $found);
    }

    /**
     * Embedding spend is real spend. If this construct ever leaves the scan
     * list, the guard silently stops seeing two of the paths this feature
     * brought under a funnel.
     */
    #[Test]
    public function the_construct_list_covers_embedding_calls_as_well_as_chat_calls(): void
    {
        $this->assertContains('->embed(', self::MODEL_INVOKING_CONSTRUCTS);

        $embedFiles = $this->filesContainingConstruct('->embed(');

        $this->assertContains('Services/EmbeddingService.php', $embedFiles);
        $this->assertContains('Services/RoleTestRunner.php', $embedFiles);
    }

    /**
     * AgentLoopStreamHandler re-enters AgentLoopService::start() and calls no
     * provider of its own. Stated as its own case because "surely the stream
     * handler calls a model" is the single most natural wrong assumption to
     * make about this allowlist.
     */
    #[Test]
    public function the_stream_handler_contains_no_model_invoking_construct(): void
    {
        $this->assertNotContains('AgentLoopStreamHandler.php', $this->filesContainingAnyConstruct());
    }

    #[Test]
    public function the_dead_legacy_request_classes_are_instantiated_nowhere_in_src(): void
    {
        $violations = [];

        foreach (self::DEAD_LEGACY_CLASSES as $class) {
            foreach ($this->phpFiles() as $relativePath => $content) {
                if (str_ends_with($relativePath, $class.'.php')) {
                    continue;
                }

                foreach (explode("\n", $content) as $index => $line) {
                    if (preg_match('/\bnew\s+'.preg_quote($class, '/').'\s*\(/', $line)) {
                        $violations[] = $relativePath.':'.($index + 1).': '.trim($line);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "A dead legacy request class is being constructed again:\n".implode("\n", $violations)
            ."\nBoth classes contain an ungated model-invoking construct; reviving one "
            .'reopens a path around the ceiling.'
        );
    }

    /**
     * @return string[] Relative paths, sorted, of every src/ file containing
     *                  at least one model-invoking construct.
     */
    private function filesContainingAnyConstruct(): array
    {
        $found = [];

        foreach ($this->phpFiles() as $relativePath => $content) {
            foreach (self::MODEL_INVOKING_CONSTRUCTS as $construct) {
                if (str_contains($content, $construct)) {
                    $found[$relativePath] = true;
                    break;
                }
            }
        }

        $found = array_keys($found);
        sort($found);

        return $found;
    }

    /**
     * @return string[] Relative paths, sorted, of every src/ file containing
     *                  one specific construct.
     */
    private function filesContainingConstruct(string $construct): array
    {
        $found = [];

        foreach ($this->phpFiles() as $relativePath => $content) {
            if (str_contains($content, $construct)) {
                $found[] = $relativePath;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * @return array<string, string> relative path => file contents
     */
    private function phpFiles(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->srcDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $files = [];

        foreach (new RegexIterator($iterator, '/\.php$/') as $file) {
            $path = $file->getPathname();
            $content = file_get_contents($path);

            if ($content === false) {
                continue;
            }

            $files[str_replace($this->srcDir.'/', '', $path)] = $content;
        }

        return $cache = $files;
    }
}
