<?php

namespace Tests\Unit\Resume;

use Carbon\Carbon;
use ClarionApp\LlmClient\Exceptions\CheckpointWriteFailed;
use ClarionApp\LlmClient\Exceptions\LeaseLost;
use ClarionApp\LlmClient\ValueObjects\CallDisposition;
use ClarionApp\LlmClient\ValueObjects\ClaimResult;
use ClarionApp\LlmClient\ValueObjects\PlanKind;
use ClarionApp\LlmClient\ValueObjects\ProviderFailureKind;
use ClarionApp\LlmClient\ValueObjects\ResumeDriver;
use ClarionApp\LlmClient\ValueObjects\ResumeMode;
use ClarionApp\LlmClient\ValueObjects\ResumeState;
use ClarionApp\LlmClient\ValueObjects\RetryDecision;
use ClarionApp\LlmClient\ValueObjects\StepRef;
use ClarionApp\LlmClient\ValueObjects\ToolEffect;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The value objects of the resumable-task feature, pinned to data-model.md
 * and the lease contract. Red now: none of the classes exist yet.
 */
class ResumeValueObjectsTest extends TestCase
{
    #[Test]
    public function resume_mode_backing_values_match_the_data_model(): void
    {
        $this->assertSame(
            ['sync', 'stream'],
            array_map(fn (ResumeMode $mode) => $mode->value, ResumeMode::cases()),
            'ResumeMode: sync | stream'
        );
    }

    #[Test]
    public function resume_driver_backing_values_match_the_data_model(): void
    {
        $this->assertSame(
            ['root', 'parent', 'batch_member', 'sequence_stage', 'consensus', 'none'],
            array_map(fn (ResumeDriver $driver) => $driver->value, ResumeDriver::cases()),
            'ResumeDriver: root | parent | batch_member | sequence_stage | consensus | none'
        );
    }

    #[Test]
    public function resume_state_backing_values_match_the_data_model(): void
    {
        $this->assertSame(
            ['working', 'paused', 'waiting_provider_retry', 'interrupted', 'resuming'],
            array_map(fn (ResumeState $state) => $state->value, ResumeState::cases()),
            'ResumeState: working | paused | waiting_provider_retry | interrupted | resuming'
        );
    }

    #[Test]
    public function tool_effect_backing_values_match_the_data_model(): void
    {
        $this->assertSame(
            ['effect_free', 'durable', 'effectful'],
            array_map(fn (ToolEffect $effect) => $effect->value, ToolEffect::cases()),
            'ToolEffect: effect_free | durable | effectful'
        );
    }

    #[Test]
    public function provider_failure_kind_values_and_transientness(): void
    {
        $this->assertSame(
            ['rate_limited', 'unavailable', 'connection', 'timeout', 'incomplete_stream', 'rejected'],
            array_map(fn (ProviderFailureKind $kind) => $kind->value, ProviderFailureKind::cases()),
            'ProviderFailureKind backing values'
        );

        foreach (ProviderFailureKind::cases() as $kind) {
            $expected = $kind->value !== 'rejected';
            $this->assertSame(
                $expected,
                $kind->isTransient(),
                "{$kind->value}: isTransient() is false only for rejected"
            );
        }
    }

    #[Test]
    public function plan_kind_cases_are_the_four_resume_plans(): void
    {
        $this->assertSame(
            ['NextIteration', 'RepeatModelCall', 'FinishToolBatch', 'CloseCommittedStep'],
            array_map(fn (PlanKind $kind) => $kind->name, PlanKind::cases()),
            'PlanKind cases'
        );
    }

    #[Test]
    public function call_disposition_kinds_and_reuse_carrying_its_result(): void
    {
        $ref = new \ReflectionClass(CallDisposition::class);
        $this->assertTrue($ref->isFinal(), 'CallDisposition is final');

        $kindType = $ref->getProperty('kind')->getType();
        $this->assertFalse($kindType->isBuiltin(), 'the kind is an enum');
        $kindEnum = $kindType->getName();

        $this->assertSame(
            ['Reuse', 'Execute', 'Rerun', 'OutcomeUnknown', 'ResolveDurable'],
            array_map(fn ($case) => $case->name, $kindEnum::cases()),
            'the five disposition kinds'
        );

        $result = ['tool_call_id' => 'call_a', 'content' => 'done', 'method' => 'passthrough'];
        $reuse = new CallDisposition($kindEnum::Reuse, $result);
        $this->assertSame($result, $reuse->result, 'Reuse carries its result entry');

        $execute = new CallDisposition($kindEnum::Execute);
        $this->assertNull($execute->result, 'a non-Reuse disposition carries no result');
    }

    #[Test]
    public function claim_result_won_and_lost_shapes(): void
    {
        $won = ClaimResult::won('nonce-1', true, true);
        $this->assertTrue($won->won);
        $this->assertSame('nonce-1', $won->nonce);
        $this->assertTrue($won->takeover);
        $this->assertTrue($won->detectedHere, 'detectedHere: the claim itself replaced an expired, still-held lease');

        $clean = ClaimResult::won('nonce-2', false, false);
        $this->assertFalse($clean->takeover);
        $this->assertFalse($clean->detectedHere);

        foreach (['taken', 'stale_dispatch', 'terminal', 'cancelled'] as $reason) {
            $lost = ClaimResult::lost($reason);
            $this->assertFalse($lost->won, "{$reason} is a lost claim");
            $this->assertSame($reason, $lost->reason);
        }
        $this->assertSame(
            ['taken', 'stale_dispatch', 'terminal', 'cancelled'],
            ClaimResult::LOST_REASONS,
            'the four lost reasons, nothing else'
        );
    }

    #[Test]
    public function retry_decision_factories(): void
    {
        $fail = RetryDecision::fail();
        $this->assertNull($fail->deferredUntil(), 'fail defers nothing');
        $this->assertNull($fail->inlineDelaySeconds(), 'fail retries nothing inline');

        $at = Carbon::parse('2026-10-15 10:00:00');
        $deferred = RetryDecision::deferred($at);
        $this->assertSame($at, $deferred->deferredUntil(), 'deferred names its due time');
        $this->assertNull($deferred->inlineDelaySeconds(), 'deferred does not retry inline');

        $inline = RetryDecision::inline(30);
        $this->assertSame(30, $inline->inlineDelaySeconds(), 'inline names its delay');
        $this->assertNull($inline->deferredUntil(), 'inline defers nothing');
    }

    #[Test]
    public function step_ref_carries_run_step_and_iteration(): void
    {
        $ref = new StepRef('run-1', 'step-1', 8);
        $this->assertSame('run-1', $ref->runId);
        $this->assertSame('step-1', $ref->stepId);
        $this->assertSame(8, $ref->iteration);
    }

    #[Test]
    public function lease_lost_is_a_checkpoint_write_failed(): void
    {
        $this->assertTrue(is_subclass_of(CheckpointWriteFailed::class, \RuntimeException::class));
        $this->assertTrue(is_subclass_of(LeaseLost::class, CheckpointWriteFailed::class));
    }
}
