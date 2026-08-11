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

        // System-initiated work brought under the gate by this feature.
        // Condensation, the role test, and title generation go through
        // RunTraceRecorder::traceSystemRun(), whose first act is the gate.
        'Services/ConversationCondenser.php',
        'Services/RoleTestRunner.php',
        'OpenAIGenerateConversationTitleRequest.php',

        // Embedding calls BudgetGate::admit() DIRECTLY rather than going
        // through traceSystemRun(). Almost every embedding here is a query
        // embedded while a live turn assembles its context, so wrapping it
        // would open and close a nested run on every turn — measurably over
        // spec 062's own per-step overhead budget, and one embedding run per
        // turn in the run listing. admit() writes its own refusal record, so
        // a refused embedding is exactly as visible to an operator either
        // way. The partition below classifies it as gating itself, which it
        // does; only the funnel differs.
        'Services/EmbeddingService.php',

        // Rubric judging calls BudgetGate::admit() DIRECTLY, the same
        // reason RoleTestRunner's null-user branch does rather than going
        // through traceSystemRun(): the judge's user id is always null
        // (system-initiated work), and traceSystemRun()'s own $userId
        // parameter is non-nullable. admit() writes its own refusal
        // record on a stop, and RubricJudge converts that refusal into an
        // explicit unjudged result rather than propagating it — a refusal
        // is exactly as visible to an operator either way.
        'Services/RubricJudge.php',

        // Dead legacy classes. They are on the list because they genuinely
        // contain the constructs and the assertion is an equality — not
        // because they are gated. The separate assertion below is what keeps
        // them harmless.
        'OpenAIConversationRequest.php',
        'OpenAIConversationStreamRequest.php',
    ];

    /**
     * The members of the allowlist that must carry a gate of their **own**.
     *
     * The equality assertion above is a guard in one direction only: it
     * notices a *new* file that reaches a model, and it notices one that
     * stops reaching a model. It cannot see whether an allowlisted file is
     * still gated, because unwrapping a call site does not change which file
     * contains `->chat(`. Left at that, quietly removing
     * ConversationCondenser's traceSystemRun() wrapper — so condensation
     * calls a model with no ceiling consulted at all — passes the whole
     * suite, which is how this list came to exist.
     *
     * "A gate of their own" means the file itself contains one of the two
     * funnels: a BudgetGate::admit() call, or a traceSystemRun() call whose
     * first act is that same admit().
     */
    private const MUST_GATE_THEMSELVES = [
        'Services/AgentLoopService.php',
        'Services/ConversationCondenser.php',
        'Services/EmbeddingService.php',
        'Services/RoleTestRunner.php',
        'Services/RubricJudge.php',
        'Jobs/GenerateEpisodicMemoryJob.php',
        'Jobs/PreWarmChunkSummaryJob.php',
        'OpenAIGenerateConversationTitleRequest.php',
    ];

    /**
     * The rest of the allowlist: files that reach a model from *inside* a
     * unit of work something else already admitted, plus the two dead
     * legacy classes nothing constructs.
     *
     * Enumerated rather than derived so that the two lists together are an
     * exact partition of the allowlist — a new entry has to be classified
     * deliberately as one or the other, and cannot arrive unclassified.
     */
    private const GATED_BY_THEIR_CALLER = [
        // Reached only within an already-admitted turn; re-checking mid-turn
        // would truncate a response already in flight.
        'Services/ToolResultCondenser.php',

        // Self-recursion inside one provider call.
        'Providers/LlamaCppProvider.php',

        // Dead legacy classes, instantiated nowhere.
        'OpenAIConversationRequest.php',
        'OpenAIConversationStreamRequest.php',
    ];

    /**
     * What counts as this file gating itself.
     *
     * Matched against the file's code with comments stripped. Every one of
     * these files *discusses* gating at length in its docblocks, so a raw
     * text scan would be satisfied by the prose explaining a gate that had
     * just been deleted.
     */
    private const GATING_CONSTRUCTS = [
        '->admit(',
        '->traceSystemRun(',
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
     * The other direction: every allowlisted file that is not reached from
     * inside somebody else's admitted work still gates itself.
     *
     * This is what makes "unwrapped" visible. The equality assertion above
     * would go on passing with condensation, title generation, embedding, or
     * the role test calling a model directly, because unwrapping a call does
     * not change which file contains it.
     */
    #[Test]
    public function every_allowlisted_file_is_either_gated_by_itself_or_by_its_caller(): void
    {
        // The two lists are an exact partition of the allowlist, so a new
        // entry cannot arrive unclassified and skip this check entirely.
        $classified = array_merge(self::MUST_GATE_THEMSELVES, self::GATED_BY_THEIR_CALLER);
        sort($classified);
        $allowlist = self::ALLOWLIST;
        sort($allowlist);

        $this->assertSame(
            $allowlist,
            $classified,
            'Every allowlisted file must be classified as gating itself or as being gated by its caller'
        );

        $files = $this->phpFiles();
        $ungated = [];

        foreach (self::MUST_GATE_THEMSELVES as $relativePath) {
            $content = $files[$relativePath] ?? null;

            $this->assertNotNull($content, "Allowlisted file is missing from src/: {$relativePath}");

            $code = self::withoutComments($content);
            $gated = false;

            foreach (self::GATING_CONSTRUCTS as $construct) {
                if (str_contains($code, $construct)) {
                    $gated = true;
                    break;
                }
            }

            if (!$gated) {
                $ungated[] = $relativePath;
            }
        }

        $this->assertSame(
            [],
            $ungated,
            "A model-invoking path has lost its gate:\n".implode("\n", $ungated)
            ."\nEach of these must call BudgetGate::admit() or RunTraceRecorder::traceSystemRun() itself, "
            .'or move to GATED_BY_THEIR_CALLER with the reason it is reached only from admitted work.'
        );
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
    /**
     * The file's code with every comment and docblock removed.
     */
    private static function withoutComments(string $content): string
    {
        $code = '';

        foreach (token_get_all($content) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

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
