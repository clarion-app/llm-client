<?php

namespace Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Integration\Harness\ConversationScript;
use Tests\Integration\Harness\RequestLane;
use Tests\Integration\Harness\Responses;

/**
 * T002: Construction rules for ConversationScript.
 *
 * - maxTurns must be > 0
 * - stopWhen without continuation is a construction error
 * - a script with neither stopWhen nor turns is a construction error
 */
class ConversationScriptTest extends TestCase
{
    public function test_maxTurns_must_be_positive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxTurns');

        ConversationScript::make()
            ->turn('Hello', fn ($r) => $r->finalAnswer('Hi'))
            ->maxTurns(0);
    }

    public function test_maxTurns_negative_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ConversationScript::make()
            ->turn('Hello', fn ($r) => $r->finalAnswer('Hi'))
            ->maxTurns(-5);
    }

    public function test_stopWhen_without_continuation_is_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('continuation');

        ConversationScript::make()
            ->turn('Hello', fn ($r) => $r->finalAnswer('Hi'))
            ->stopWhen(fn ($played) => true)
            ->maxTurns(40);
    }

    public function test_script_with_neither_stopWhen_nor_turns_is_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ConversationScript::make()
            ->maxTurns(40);
    }

    public function test_script_with_only_continuation_and_no_turns_is_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ConversationScript::make()
            ->filler(fn (int $n) => "Tell me about topic {$n}.")
            ->maxTurns(40);
    }

    public function test_valid_script_with_turns_and_maxTurns(): void
    {
        $script = ConversationScript::make()
            ->turn('Hello', fn ($r) => $r->finalAnswer('Hi'))
            ->maxTurns(10);

        $this->assertEquals(10, $script->maxTurns);
        $this->assertNull($script->stopWhenCondition);
        $this->assertCount(1, $script->turns);
    }

    public function test_valid_script_with_continuation_and_stopWhen(): void
    {
        $script = ConversationScript::make()
            ->turn('Start', fn ($r) => $r->finalAnswer('Ok'))
            ->filler(fn (int $n) => "Topic {$n}")
            ->stopWhen(fn ($played) => $played->turns !== [])
            ->maxTurns(40);

        $this->assertNotNull($script->continuation);
        $this->assertNotNull($script->stopWhenCondition);
        $this->assertEquals(40, $script->maxTurns);
    }

    public function test_untilContextManagementActed_sets_stopWhen(): void
    {
        $script = ConversationScript::make()
            ->turn('Hello', fn ($r) => $r->finalAnswer('Hi'))
            ->filler(fn (int $n) => "Topic {$n}")
            ->untilContextManagementActed()
            ->maxTurns(40);

        $this->assertNotNull($script->stopWhenCondition);
    }

    public function test_untilContextManagementActedAtLeast_sets_stopWhen(): void
    {
        $script = ConversationScript::make()
            ->turn('Hello', fn ($r) => $r->finalAnswer('Hi'))
            ->filler(fn (int $n) => "Topic {$n}")
            ->untilContextManagementActedAtLeast(3)
            ->maxTurns(40);

        $this->assertNotNull($script->stopWhenCondition);
    }

    public function test_rule_adds_lane_rule(): void
    {
        $script = ConversationScript::make()
            ->turn('Hello', fn ($r) => $r->finalAnswer('Hi'))
            ->rule(RequestLane::EpisodicSummary, fn () => Responses::summary())
            ->maxTurns(10);

        $this->assertCount(1, $script->rules);
    }

    public function test_requireRule_records_label(): void
    {
        $script = ConversationScript::make()
            ->turn('Hello', fn ($r) => $r->finalAnswer('Hi'))
            ->rule(RequestLane::AgentTurn, fn ($p, $t) => true, fn ($p, $t) => ['choices' => [[]]], 'known-op')
            ->requireRule('known-op')
            ->maxTurns(10);

        $this->assertContains('known-op', $script->requiredRules);
    }

    public function test_entryPath_defaults_to_sync(): void
    {
        $script = ConversationScript::make()
            ->turn('Hello', fn ($r) => $r->finalAnswer('Hi'))
            ->maxTurns(10);

        $this->assertEquals('sync', $script->entryPath);
    }

    public function test_entryPath_can_be_stream(): void
    {
        $script = ConversationScript::make()
            ->turn('Hello', fn ($r) => $r->finalAnswer('Hi'))
            ->stream()
            ->maxTurns(10);

        $this->assertEquals('stream', $script->entryPath);
    }
}
