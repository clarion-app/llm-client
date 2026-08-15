<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
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
 * 102-router-pattern, Phase 3 (US1, T017).
 *
 * Written before RouterService exists at all (contracts/routing-mechanism.md
 * §1, research.md D1/D4). Every test in this file is expected to FAIL with a
 * fatal "Class ... RouterService not found" error until Phase 3's own
 * Implementation task (T021) creates it.
 *
 * Mirrors AgentQueryTest.php's own seedOperationCatalog()/service()/user()
 * fixture conventions — every candidate here is a real Agent + AgentVersion
 * row created through AgentService::create(), never a hand-rolled
 * DB::table() insert, so each fixture's declared name/instructions are
 * resolvable through whatever read path RouterService itself ends up using
 * (research.md D1's token-overlap scorer reads name + instructions).
 */
class RouterServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function router(): RouterService
    {
        return new RouterService();
    }

    private function service(): AgentService
    {
        return new AgentService(new AgentDefinitionParser(), new GitDefinitionFileReader());
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    /**
     * Seeds both of ApiManager's live-catalog seams — required before any
     * *valid* AgentDefinitionParser::parse() call (AgentServiceTest's own
     * established convention, reused verbatim by AgentQueryTest.php).
     */
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

    /**
     * Forces a fixture's created_at to a specific instant — a direct
     * attribute assignment + save(), not mass assignment (created_at is not
     * fillable), so D4's created_at tie-break can be tested deterministically
     * rather than relying on real wall-clock ordering between two fixture
     * creations a few microseconds apart.
     */
    private function setCreatedAt(Agent $agent, \DateTimeInterface $when): void
    {
        $agent->created_at = $when;
        $agent->save();
    }

    // ---------------------------------------------------------------
    // (a) zero candidates
    // ---------------------------------------------------------------

    #[Test]
    public function zero_candidates_for_the_caller_returns_a_none_decision(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();

        $decision = $this->router()->route($user->id, 'Anything at all.');

        $this->assertNull($decision->agentId);
        $this->assertNull($decision->agentVersionId);
        $this->assertSame('none', $decision->reason);
        $this->assertFalse($decision->hasAgent());
    }

    // ---------------------------------------------------------------
    // (b) exactly one active candidate — FR-016 short-circuit
    // ---------------------------------------------------------------

    #[Test]
    public function exactly_one_active_candidate_wins_immediately_regardless_of_match(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();
        $agent = $this->service()->create(
            $user->id,
            "name: lone-agent\ninstructions: Talks exclusively about tropical fish care.",
        );

        $decision = $this->router()->route($user->id, 'I need help launching a rocket to Mars.');

        $this->assertSame(
            $agent->id,
            $decision->agentId,
            'the sole candidate must win even though nothing in the trigger text matches its instructions (FR-016 short-circuit)',
        );
        $this->assertSame($agent->current_version_id, $decision->agentVersionId);
        $this->assertSame('automatic', $decision->reason);
    }

    // ---------------------------------------------------------------
    // (c) overlapping candidate wins over a non-overlapping one
    // ---------------------------------------------------------------

    #[Test]
    public function the_overlapping_candidate_wins_over_a_non_overlapping_one(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();
        $billingAgent = $this->service()->create(
            $user->id,
            "name: billing-agent\ninstructions: Handles billing invoice and payment matters for customers.",
        );
        $weatherAgent = $this->service()->create(
            $user->id,
            "name: weather-agent\ninstructions: Talks about weather forecasts and cloud cover.",
        );

        $decision = $this->router()->route($user->id, 'I have a billing invoice and a payment problem.');

        $this->assertSame($billingAgent->id, $decision->agentId);
        $this->assertNotSame($weatherAgent->id, $decision->agentId);
        $this->assertSame('automatic', $decision->reason);
    }

    // ---------------------------------------------------------------
    // (d) equal-score tie broken by earlier created_at, deterministically
    // ---------------------------------------------------------------

    #[Test]
    public function a_tie_on_score_is_broken_by_earlier_created_at_deterministically(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();

        $earlier = $this->service()->create(
            $user->id,
            "name: agent-earlier\ninstructions: billing invoice payment specialist.",
        );
        $later = $this->service()->create(
            $user->id,
            "name: agent-later\ninstructions: invoice payment billing helper.",
        );

        $this->setCreatedAt($earlier, now()->subMinutes(10));
        $this->setCreatedAt($later, now());

        $router = $this->router();
        $trigger = 'I have a billing invoice and a payment problem.';

        $first = $router->route($user->id, $trigger);
        $second = $router->route($user->id, $trigger);

        $this->assertSame(
            $earlier->id,
            $first->agentId,
            'the earlier-created candidate must win an equal, positive-overlap score tie (D4)',
        );
        $this->assertSame(
            $earlier->id,
            $second->agentId,
            'repeated calls against the same fixture must always return the same winner (FR-013)',
        );
    }

    // ---------------------------------------------------------------
    // (e) identical created_at — final tie-break on id
    // ---------------------------------------------------------------

    #[Test]
    public function an_identical_created_at_tie_is_broken_by_the_lexicographically_earlier_id(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();

        $agentX = $this->service()->create(
            $user->id,
            "name: agent-x\ninstructions: billing invoice payment specialist.",
        );
        $agentY = $this->service()->create(
            $user->id,
            "name: agent-y\ninstructions: invoice payment billing helper.",
        );

        $sameInstant = now();
        $this->setCreatedAt($agentX, $sameInstant);
        $this->setCreatedAt($agentY, $sameInstant);

        $expectedWinnerId = strcmp($agentX->id, $agentY->id) <= 0 ? $agentX->id : $agentY->id;

        $decision = $this->router()->route($user->id, 'I have a billing invoice and a payment problem.');

        $this->assertSame(
            $expectedWinnerId,
            $decision->agentId,
            'when created_at is identical, the lexicographically/numerically earlier id must win the final tie-break (D4)',
        );
    }

    // ---------------------------------------------------------------
    // (f) no match at all — 'none' with no default configured, 'default'
    // once Phase 6 (US4) wires step 5 in
    // ---------------------------------------------------------------

    #[Test]
    public function no_candidate_matching_the_trigger_text_returns_none_when_no_default_handler_is_configured(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();

        $this->service()->create(
            $user->id,
            "name: billing-agent\ninstructions: Handles billing invoice and payment matters.",
        );
        $this->service()->create(
            $user->id,
            "name: weather-agent\ninstructions: Talks about weather forecasts and cloud cover.",
        );

        // No default handler configured for this caller — step 5 (Phase 6,
        // 102-router-pattern) has nothing to fall back to, so this is the
        // original, pre-Phase-6 degrade.
        $decision = $this->router()->route($user->id, 'What time does the movie start tonight?');

        $this->assertNull($decision->agentId);
        $this->assertNull($decision->agentVersionId);
        $this->assertSame('none', $decision->reason);
    }

    #[Test]
    public function no_candidate_matching_the_trigger_text_falls_back_to_the_configured_default_handler(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();

        $this->service()->create(
            $user->id,
            "name: billing-agent\ninstructions: Handles billing invoice and payment matters.",
        );
        $weatherAgent = $this->service()->create(
            $user->id,
            "name: weather-agent\ninstructions: Talks about weather forecasts and cloud cover.",
        );

        // Phase 6 (US4, contracts §1 step 5): once a default handler is
        // configured, a trigger message matching no candidate's declared
        // focus falls back to it, reason 'default', rather than 'none'.
        DB::table('agents')->where('id', $weatherAgent->id)->update(['is_default_handler' => true]);

        $decision = $this->router()->route($user->id, 'What time does the movie start tonight?');

        $this->assertSame($weatherAgent->id, $decision->agentId);
        $this->assertSame($weatherAgent->current_version_id, $decision->agentVersionId);
        $this->assertSame('default', $decision->reason);
    }

    // ---------------------------------------------------------------
    // Reconciliation (102-router-pattern): the "never throws" contract
    // (contracts §1) is documented but was never exercised by a test that
    // actually forces a candidate's scoring to fail — every existing
    // fixture here is created through AgentService::create(), which
    // validates parseability at write time, so scoreCandidate()'s own
    // catch blocks were never hit by any prior test in this file.
    // ---------------------------------------------------------------

    #[Test]
    public function a_candidate_with_an_unresolvable_current_version_degrades_to_name_only_scoring_rather_than_throwing(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();

        $orphaned = $this->service()->create(
            $user->id,
            "name: orphan-candidate\ninstructions: billing invoice payment specialist.",
        );
        // Point current_version_id at a row that does not exist — the
        // "AgentVersion relation is null" malformed-candidate scenario:
        // $candidate->currentVersion resolves to null via the belongsTo
        // relation, never an exception, but scoreCandidate() must still
        // handle it without crashing the whole call for every other
        // candidate.
        DB::table('agents')->where('id', $orphaned->id)->update([
            'current_version_id' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $healthy = $this->service()->create(
            $user->id,
            "name: healthy-agent\ninstructions: billing invoice payment specialist.",
        );

        $decision = $this->router()->route($user->id, 'I have a billing invoice and a payment problem.');

        $this->assertNotNull($decision, 'route() must return a RouterDecision, never throw, when a candidate\'s currentVersion relation is unresolvable');
        $this->assertSame(
            $healthy->id,
            $decision->agentId,
            'the orphaned candidate must degrade to name-only scoring (0, since "orphan-candidate" shares no tokens with the trigger text) rather than aborting scoring for the healthy candidate',
        );
    }

    #[Test]
    public function a_candidate_whose_raw_definition_fails_to_parse_degrades_to_name_only_scoring_rather_than_throwing(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();

        $malformed = $this->service()->create(
            $user->id,
            "name: malformed-candidate\ninstructions: billing invoice payment specialist.",
        );
        // Overwrite the stored raw_definition directly at the DB level
        // with genuinely unparseable YAML (bypassing AgentService::
        // create()'s own write-time validation, which never lets a
        // malformed document land in the first place) — the second
        // "malformed candidate" scenario contracts §1's "never throws"
        // clause exists to guard against: AgentDefinitionParser::parse()
        // throws AgentDefinitionParseException for this input, which
        // scoreCandidate() explicitly catches.
        DB::table('agent_versions')->where('id', $malformed->current_version_id)->update([
            'raw_definition' => "name: [this is not valid yaml\n  - broken: :::",
        ]);

        $healthy = $this->service()->create(
            $user->id,
            "name: healthy-agent\ninstructions: billing invoice payment specialist.",
        );

        $decision = $this->router()->route($user->id, 'I have a billing invoice and a payment problem.');

        $this->assertNotNull($decision, 'route() must return a RouterDecision, never throw, when a candidate\'s raw_definition fails to parse');
        $this->assertSame(
            $healthy->id,
            $decision->agentId,
            'the malformed candidate must degrade to name-only scoring (0, since "malformed-candidate" shares no tokens with the trigger text) rather than aborting scoring for the healthy candidate',
        );
    }

    // ---------------------------------------------------------------
    // (g) $excludeAgentIds — an excluded candidate is never selectable
    // ---------------------------------------------------------------

    #[Test]
    public function an_excluded_candidate_is_never_selectable_even_if_it_would_otherwise_win(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();

        $strongMatch = $this->service()->create(
            $user->id,
            "name: strong-match\ninstructions: Handles billing invoice payment matters directly.",
        );
        $weakMatch = $this->service()->create(
            $user->id,
            "name: weak-match\ninstructions: Occasionally mentions payment in passing.",
        );
        $this->service()->create(
            $user->id,
            "name: no-match\ninstructions: Talks exclusively about tropical fish care.",
        );

        $trigger = 'I have a billing invoice and a payment problem.';

        $unexcluded = $this->router()->route($user->id, $trigger);
        $this->assertSame(
            $strongMatch->id,
            $unexcluded->agentId,
            'fixture sanity: without exclusion, the strong match must win',
        );

        $excluded = $this->router()->route($user->id, $trigger, [$strongMatch->id]);

        $this->assertNotSame(
            $strongMatch->id,
            $excluded->agentId,
            'the excluded candidate must never be selectable, even though it would otherwise win',
        );
        $this->assertSame(
            $weakMatch->id,
            $excluded->agentId,
            'the next-best remaining candidate must win once the top scorer is excluded',
        );
    }
}
