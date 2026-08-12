<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A closed-set guard over every call site of DegradationGate::evaluate()
 * (quickstart.md mutation-testing row 1, research.md D1).
 *
 * DegradationGate::evaluate() must be called from exactly one place:
 * AgentLoopService::admitInteractiveWork(). It must never be called from
 * any of ConversationWorkGate's four mid-loop sites (those are deliberately
 * mid-response — reduction has to be decided at the response boundary,
 * never while a tool-call batch is already underway), never from a new
 * call site inside a tool-call loop, and never from resume()'s
 * "confirmation has expired" branch (which clears is_processing and
 * returns without ever re-entering admitInteractiveWork() at all).
 *
 * This is a pure source-text guard — no database, no application
 * container, no dependency on DegradationGate actually existing yet. Right
 * now (before T034/T038 land) it fails because zero call sites exist at
 * all: the "must appear inside admitInteractiveWork()" assertion sees an
 * empty set where it expects exactly one occurrence.
 */
class DegradationCallSiteGuardTest extends TestCase
{
    private const NEEDLE = 'DegradationGate::class)->evaluate(';

    private string $agentLoopServicePath;
    private string $streamHandlerPath;
    private string $srcDir;

    protected function setUp(): void
    {
        $this->srcDir = dirname(__DIR__, 2).'/src';
        $this->agentLoopServicePath = $this->srcDir.'/Services/AgentLoopService.php';
        $this->streamHandlerPath = $this->srcDir.'/AgentLoopStreamHandler.php';
    }

    #[Test]
    public function every_call_site_of_degradation_gate_evaluate_across_src_lives_inside_admit_interactive_work(): void
    {
        $totalOccurrences = 0;
        $files = [];

        foreach ($this->phpFiles() as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $count = substr_count($content, self::NEEDLE);
            if ($count > 0) {
                $totalOccurrences += $count;
                $files[str_replace($this->srcDir.'/', '', $file)] = $count;
            }
        }

        $agentLoopContent = file_get_contents($this->agentLoopServicePath);
        $this->assertNotFalse($agentLoopContent);

        $admitBody = $this->extractMethodBody($agentLoopContent, 'admitInteractiveWork');
        $this->assertNotNull(
            $admitBody,
            'AgentLoopService::admitInteractiveWork() must exist to be the one funnel DegradationGate::evaluate() is called from'
        );

        $withinAdmit = substr_count($admitBody, self::NEEDLE);

        $this->assertSame(
            1,
            $withinAdmit,
            'DegradationGate::evaluate() must be called exactly once, from inside admitInteractiveWork() — '
            ."found {$withinAdmit} occurrence(s) there. All src/ occurrences: ".json_encode($files)
        );

        $this->assertSame(
            $withinAdmit,
            $totalOccurrences,
            'DegradationGate::evaluate() must never be called from anywhere outside admitInteractiveWork() — '
            .'occurrences found across src/: '.json_encode($files)
        );
    }

    #[Test]
    public function conversation_work_gates_four_mid_loop_sites_never_call_degradation_gate_evaluate(): void
    {
        $agentLoopContent = file_get_contents($this->agentLoopServicePath);
        $this->assertNotFalse($agentLoopContent);

        // run()'s tool-call loop and its schema-retry branch, plus
        // resumeSync()'s own tool-call loop — the three ConversationWorkGate
        // sites inside AgentLoopService.php.
        foreach (['run', 'resumeSync'] as $method) {
            $body = $this->extractMethodBody($agentLoopContent, $method);
            $this->assertNotNull($body, "AgentLoopService::{$method}() must exist");

            $this->assertStringNotContainsString(
                self::NEEDLE,
                $body,
                "AgentLoopService::{$method}() must never call DegradationGate::evaluate() directly — "
                .'the withheld-tool interception (Phase 3) reads forRun(), not a fresh evaluate()'
            );
        }

        $streamContent = file_get_contents($this->streamHandlerPath);
        $this->assertNotFalse($streamContent);

        $handleToolCallsBody = $this->extractMethodBody($streamContent, 'handleToolCalls');
        $this->assertNotNull($handleToolCallsBody, 'AgentLoopStreamHandler::handleToolCalls() must exist');

        $this->assertStringNotContainsString(
            self::NEEDLE,
            $handleToolCallsBody,
            'AgentLoopStreamHandler::handleToolCalls() must never call DegradationGate::evaluate() directly'
        );
    }

    #[Test]
    public function resumes_expired_confirmation_branch_never_calls_degradation_gate_evaluate(): void
    {
        $agentLoopContent = file_get_contents($this->agentLoopServicePath);
        $this->assertNotFalse($agentLoopContent);

        $resumeBody = $this->extractMethodBody($agentLoopContent, 'resume');
        $this->assertNotNull($resumeBody, 'AgentLoopService::resume() must exist');

        $expiredPos = strpos($resumeBody, 'Confirmation has expired');
        $this->assertNotFalse($expiredPos, "resume()'s expired-confirmation branch must exist");

        // The expired-confirmation branch is a short block right around the
        // "Confirmation has expired" throw — check a generous window
        // around it rather than the whole method, since resume() as a
        // whole legitimately reaches admitInteractiveWork() via
        // admitResumedWork() earlier in the method.
        $window = substr($resumeBody, max(0, $expiredPos - 400), 800);

        $this->assertStringNotContainsString(
            self::NEEDLE,
            $window,
            "resume()'s expired-confirmation branch clears is_processing and returns without re-entering "
            .'admitInteractiveWork() — it must never call DegradationGate::evaluate() of its own accord'
        );
    }

    /**
     * @return string[] absolute paths of every .php file under src/
     */
    private function phpFiles(): array
    {
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->srcDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach (new \RegexIterator($iterator, '/\.php$/') as $file) {
            $found[] = $file->getPathname();
        }

        sort($found);

        return $found;
    }

    /**
     * Extract the full body (including its own braces) of the first
     * method named $methodName found in $content, by brace-counting from
     * its opening `{` to the matching closing `}`. Returns null if no such
     * method is found.
     */
    private function extractMethodBody(string $content, string $methodName): ?string
    {
        if (!preg_match(
            '/function\s+'.preg_quote($methodName, '/').'\s*\([^)]*\)[^{]*\{/',
            $content,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            return null;
        }

        $openBracePos = $matches[0][1] + strlen($matches[0][0]) - 1;
        $depth = 0;
        $length = strlen($content);

        for ($i = $openBracePos; $i < $length; $i++) {
            if ($content[$i] === '{') {
                $depth++;
            } elseif ($content[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $openBracePos, $i - $openBracePos + 1);
                }
            }
        }

        return null;
    }
}
