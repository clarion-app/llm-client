<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\GitDefinitionFileReader;
use ClarionApp\LlmClient\Services\RouterService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 102-router-pattern, Phase 3 (US1, T018).
 *
 * SC-003/FR-012, research.md D1/D12: RouterService::route() is a pure,
 * in-process, single-indexed-query function — no model call, no network
 * I/O — so its own per-call cost must be a tiny sliver of SC-003's 1-second
 * budget. Mirrors tests/Unit/LatencyOverheadBenchmark.php's own
 * hrtime()-based, median-of-N-iterations pattern.
 *
 * Written before RouterService exists at all — expected to FAIL with a
 * fatal "Class ... RouterService not found" error until Phase 3's
 * Implementation task (T021) creates it.
 */
class RouterServiceLatencyTest extends TestCase
{
    private const ITERATIONS = 10;

    /**
     * A tight, generous-headroom bound (research.md D12's own "low tens of
     * milliseconds" framing) — nowhere near SC-003's 1-second ceiling, since
     * this measures ONLY route()'s own in-process cost against a realistic
     * ~10-candidate roster, not a full turn.
     */
    private const BOUND_MS = 50.0;

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function service(): AgentService
    {
        return new AgentService(new AgentDefinitionParser(), new GitDefinitionFileReader());
    }

    private function seedOperationCatalog(array $operations = []): void
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

    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        if ($count % 2 === 0) {
            return ($values[$mid - 1] + $values[$mid]) / 2;
        }

        return $values[$mid];
    }

    #[Test]
    public function route_completes_within_a_tight_generous_headroom_bound_against_ten_realistic_candidates(): void
    {
        $this->seedOperationCatalog();
        $user = User::factory()->create();

        // Ten active agents with realistic name/instructions lengths — a
        // representative candidate count for "a small number of specialists
        // per installation" (research.md D1's own scale assumption).
        for ($i = 0; $i < 10; $i++) {
            $this->service()->create(
                $user->id,
                "name: specialist-{$i}\ninstructions: "
                    . "Handles a range of domain-specific matters for area {$i}, including "
                    . "coordination, troubleshooting, escalation, and customer support across "
                    . "several related topics that come up during a typical working day.",
            );
        }

        $router = new RouterService();
        $trigger = 'I need help with a fairly detailed, realistic customer request describing a specific problem in some depth, spanning a couple of sentences worth of context.';

        $times = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $start = hrtime(true);
            $router->route($user->id, $trigger);
            $times[] = (hrtime(true) - $start) / 1e6;
        }

        $medianMs = $this->median($times);

        $this->assertLessThan(
            self::BOUND_MS,
            $medianMs,
            "RouterService::route() median latency ({$medianMs}ms) exceeds the generous-headroom bound of "
                . self::BOUND_MS . 'ms (research.md D1/D12 — no model call, no network I/O expected)',
        );
    }
}
