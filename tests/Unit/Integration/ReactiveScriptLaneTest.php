<?php

namespace Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Integration\Harness\RequestLane;
use Tests\Integration\Harness\LaneRule;
use Tests\Integration\Harness\ResponseScript;
use Tests\Integration\Harness\ScriptedTransport;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\ScriptExhaustedError;

/**
 * T004: Contracts S1-S6 — Reactive Script Lane behavior.
 *
 * S1: Wire-body-only classification into four lanes
 * S2: Rule-then-ordered-step-then-fail evaluation order
 * S3: Rule purity (no counters/clock/randomness)
 * S4: Unanticipated-request failure rendering
 * S5: Backward compat — toolRequest/finalAnswer/serve fill agent_turn lane
 * S6: Leftover steps on any lane fail; requireRule failures
 */
class ReactiveScriptLaneTest extends TestCase
{
    /* ------------------------------------------------------------------ */
    /*  S1: Lane classification from wire body                             */
    /* ------------------------------------------------------------------ */

    public function test_classifies_embedding_by_path(): void
    {
        $this->assertEquals(RequestLane::Embedding, RequestLane::from('embedding'));
    }

    public function test_classifies_condensation_by_system_prefix(): void
    {
        $payload = new CapturedPayload(
            messages: [['role' => 'user', 'content' => 'test']],
            tools: [],
            system: 'You are condensing a segment of a conversation...',
            model: 'test',
            kind: 'chat',
            source: 'sync'
        );

        $lane = RequestLane::classify($payload);
        $this->assertEquals(RequestLane::Condensation, $lane);
    }

    public function test_classifies_episodicSummary_by_system_prefix(): void
    {
        $payload = new CapturedPayload(
            messages: [['role' => 'user', 'content' => 'test']],
            tools: [],
            system: 'You are a conversation summarizer...',
            model: 'test',
            kind: 'chat',
            source: 'sync'
        );

        $lane = RequestLane::classify($payload);
        $this->assertEquals(RequestLane::EpisodicSummary, $lane);
    }

    public function test_classifies_agentTurn_as_fallback(): void
    {
        $payload = new CapturedPayload(
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
            system: 'You are a helpful assistant.',
            model: 'test',
            kind: 'chat',
            source: 'sync'
        );

        $lane = RequestLane::classify($payload);
        $this->assertEquals(RequestLane::AgentTurn, $lane);
    }

    public function test_embedding_kind_is_embedding_lane(): void
    {
        $payload = new CapturedPayload(
            messages: [],
            tools: [],
            system: null,
            model: 'test',
            kind: 'embedding',
            source: 'sync'
        );

        $lane = RequestLane::classify($payload);
        $this->assertEquals(RequestLane::Embedding, $lane);
    }

    /* ------------------------------------------------------------------ */
    /*  S2: Rule-then-ordered-step evaluation order                        */
    /* ------------------------------------------------------------------ */

    public function test_rules_are_evaluated_before_ordered_steps(): void
    {
        $ruleFired = false;
        $rule = new LaneRule(
            lane: RequestLane::AgentTurn,
            predicate: fn (CapturedPayload $p, int $turn) => true,
            respond: fn (CapturedPayload $p, int $turn) => [
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'rule-response'], 'finish_reason' => 'stop']]
            ],
            label: 'always-match'
        );

        $script = new ResponseScript();
        $script->toolRequest('some_tool', ['arg' => 'val'])
            ->finalAnswer('ordered-answer');

        // Register the rule
        $script->addRule($rule);

        $payload = new CapturedPayload(
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
            system: 'You are helpful.',
            model: 'test',
            kind: 'chat',
            source: 'sync'
        );

        // serveFor should evaluate rules first, so the rule wins
        $response = $script->serveFor(RequestLane::AgentTurn, $payload, 1);
        $this->assertEquals('rule-response', $response['choices'][0]['message']['content']);

        // The ordered step should still be in the queue (not consumed)
        $this->assertEquals(2, $script->unconsumedSteps());
    }

    public function test_ordered_steps_used_when_no_rule_matches(): void
    {
        $rule = new LaneRule(
            lane: RequestLane::AgentTurn,
            predicate: fn (CapturedPayload $p, int $turn) => false,
            respond: fn () => [],
            label: 'never-match'
        );

        $script = new ResponseScript();
        $script->finalAnswer('ordered-answer');
        $script->addRule($rule);

        $payload = new CapturedPayload(
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
            system: 'You are helpful.',
            model: 'test',
            kind: 'chat',
            source: 'sync'
        );

        $response = $script->serveFor(RequestLane::AgentTurn, $payload, 1);
        $this->assertEquals('ordered-answer', $response['choices'][0]['message']['content']);
    }

    /* ------------------------------------------------------------------ */
    /*  S3: Rule purity                                                    */
    /* ------------------------------------------------------------------ */

    public function test_rule_purity_same_conversation_replays_identically(): void
    {
        $callCount = 0;
        $rule = new LaneRule(
            lane: RequestLane::AgentTurn,
            predicate: fn (CapturedPayload $p, int $turn) => str_contains($p->messages[0]['content'] ?? '', 'hello'),
            respond: fn (CapturedPayload $p, int $turn) => [
                'choices' => [['message' => ['role' => 'assistant', 'content' => "greeting-{$turn}"], 'finish_reason' => 'stop']]
            ],
            label: 'greeting'
        );

        $script = new ResponseScript();
        $script->addRule($rule);

        $payload = new CapturedPayload(
            messages: [['role' => 'user', 'content' => 'hello world']],
            tools: [],
            system: 'You are helpful.',
            model: 'test',
            kind: 'chat',
            source: 'sync'
        );

        // Same payload, different turn — rule should match both times
        $r1 = $script->serveFor(RequestLane::AgentTurn, $payload, 1);
        $r2 = $script->serveFor(RequestLane::AgentTurn, $payload, 2);

        $this->assertEquals('greeting-1', $r1['choices'][0]['message']['content']);
        $this->assertEquals('greeting-2', $r2['choices'][0]['message']['content']);
    }

    /* ------------------------------------------------------------------ */
    /*  S4: Unanticipated request failure rendering                        */
    /* ------------------------------------------------------------------ */

    public function test_unanticipated_request_fails_with_rendered_info(): void
    {
        $script = new ResponseScript();
        // No rules, no steps — everything is unanticipated

        $payload = new CapturedPayload(
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [['name' => 'search']],
            system: 'You are helpful.',
            model: 'test',
            kind: 'chat',
            source: 'sync'
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/[Rr]esponse script exhausted|[Uu]nanticipated|[Nn]o.*response/i');

        $script->serveFor(RequestLane::AgentTurn, $payload, 1);
    }

    /* ------------------------------------------------------------------ */
    /*  S5: Backward compatibility                                         */
    /* ------------------------------------------------------------------ */

    public function test_toolRequest_fills_agentTurn_lane(): void
    {
        $script = ResponseScript::make()
            ->toolRequest('search', ['q' => 'test'])
            ->finalAnswer('Found it');

        // serve() should still work as before (fills agent_turn lane)
        $step1 = $script->serve();
        $this->assertEquals('tool_calls', $step1['choices'][0]['finish_reason']);

        $step2 = $script->serve();
        $this->assertEquals('stop', $step2['choices'][0]['finish_reason']);
    }

    public function test_serve_uses_agentTurn_lane(): void
    {
        $script = ResponseScript::make()->finalAnswer('hello');

        // serve() delegates to agent_turn lane
        $response = $script->serve();
        $this->assertEquals('hello', $response['choices'][0]['message']['content']);
    }

    /* ------------------------------------------------------------------ */
    /*  S6: Leftover steps and requireRule failures                        */
    /* ------------------------------------------------------------------ */

    public function test_hasUnconsumedSteps_aggregates_across_lanes(): void
    {
        $script = new ResponseScript();
        $script->finalAnswer('agent answer'); // agent_turn lane

        // Add a step to condensation lane
        $script->pushStep(RequestLane::Condensation, [
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'condensed'], 'finish_reason' => 'stop']]
        ]);

        // Consume agent_turn step
        $script->serve();

        // condensation lane step is still unconsumed
        $this->assertTrue($script->hasUnconsumedSteps());
        $this->assertEquals(1, $script->unconsumedSteps());
    }

    public function test_unconsumedSteps_names_lane(): void
    {
        $script = new ResponseScript();
        $script->pushStep(RequestLane::Condensation, [
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'x'], 'finish_reason' => 'stop']]
        ]);

        $details = $script->unconsumedStepsDetail();
        $this->assertArrayHasKey('condensation', $details);
        $this->assertEquals(1, $details['condensation']);
    }
}
