<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Services\RunTraceQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * US4 (P2) — contradictory sources are reported as contradictions (FR-010).
 *
 * When consulted sources disagree on a fact, the answer reports the
 * disagreement explicitly — presenting both sides and naming both sources —
 * rather than silently picking one. This is a confirm-or-fix phase: the
 * template (T005) already carries the contradiction-reporting instruction and
 * the `contradiction` outcome, and the run-trace manifest already lists every
 * distinct fetched source (US1, T014), so these tests prove the guarantee
 * holds by construction and would go red if the wording or the mechanism
 * regressed.
 */
class ResearchContradictionTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('agent_run_actions')->delete();
        DB::table('agent_run_steps')->delete();
        DB::table('agent_runs')->delete();
        DB::table('users')->delete();
        parent::tearDown();
    }

    private function templateInstructions(): string
    {
        $path = __DIR__ . '/../../src/Templates/research.yaml';

        return (string) (Yaml::parseFile($path)['instructions'] ?? '');
    }

    #[Test]
    public function the_template_instructs_reporting_a_disagreement_with_both_sides_named(): void
    {
        $instructions = $this->templateInstructions();

        // The agent must report the disagreement explicitly, present both
        // sides, and name the source for each — not silently pick one.
        $this->assertMatchesRegularExpression(
            '/disagree.*?report the disagreement/is',
            $instructions,
            'the instruction must require reporting the disagreement',
        );
        $this->assertMatchesRegularExpression(
            '/present both sides.*?name the source/is',
            $instructions,
            'the instruction must require presenting both sides and naming the source for each',
        );
        $this->assertMatchesRegularExpression(
            '/do not silently\s+pick one/is',
            $instructions,
            'the instruction must forbid silently picking one side',
        );
    }

    #[Test]
    public function contradiction_is_a_named_outcome_with_its_own_trigger(): void
    {
        $instructions = $this->templateInstructions();

        // The closed vocabulary names the outcome and its trigger: at least
        // two sources disagree; present both sides and name both sources.
        $this->assertStringContainsString('contradiction', $instructions);
        $this->assertMatchesRegularExpression(
            '/contradiction:.*?(two sources disagree|present both sides)/is',
            $instructions,
            'the contradiction outcome must be triggered by two or more sources disagreeing',
        );
    }

    #[Test]
    public function two_disagreeing_fetched_sources_are_both_available_to_name(): void
    {
        $user = User::factory()->create();

        $runId = (string) Str::uuid();
        DB::table('agent_runs')->insert([
            'id' => $runId,
            'kind' => 'interactive',
            'user_id' => $user->id,
            'conversation_id' => null,
            'started_at' => '2026-01-01 09:59:00.000000',
        ]);

        $stepId = (string) Str::uuid();
        DB::table('agent_run_steps')->insert([
            'id' => $stepId,
            'run_id' => $runId,
            'position' => 1,
            'started_at' => '2026-01-01 10:00:00.000000',
        ]);

        // Two distinct sources that disagree on a fact — the agent must be
        // able to name both. The run-trace manifest lists every distinct
        // fetched source, so both sides are available to the answer.
        $this->insertPageTextAction($runId, $stepId, 'https://a.example/claim-is-true', '2026-01-01 10:00:00.000000');
        $this->insertPageTextAction($runId, $stepId, 'https://b.example/claim-is-false', '2026-01-01 10:01:00.000000');

        $manifest = (new RunTraceQuery())->consultedSourcesForRun($user->id, $runId);

        $this->assertSame(
            ['https://a.example/claim-is-true', 'https://b.example/claim-is-false'],
            $manifest,
            'both disagreeing sources must be available for the agent to name',
        );
    }

    private function insertPageTextAction(string $runId, string $stepId, string $url, string $startedAt): void
    {
        $envelope = [
            'source' => ['url' => $url, 'title' => null],
            'content' => "--- BEGIN RESPONSE UNDER EVALUATION ---\nbody text\n--- END RESPONSE UNDER EVALUATION ---",
            'reference_id' => null,
        ];

        DB::table('agent_run_actions')->insert([
            'id' => (string) Str::uuid(),
            'run_id' => $runId,
            'step_id' => $stepId,
            'action_type' => 'tool_invocation',
            'target' => 'execute_operation',
            'outcome' => 'success',
            'content' => json_encode($envelope),
            'started_at' => $startedAt,
        ]);
    }
}
