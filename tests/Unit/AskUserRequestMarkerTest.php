<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\ValueObjects\AskUserRequest;
use ClarionApp\LlmClient\ValueObjects\ParsedAnswer;
use ClarionApp\LlmClient\ValueObjects\UnansweredPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A validated `ask_user` call travels from the tool handler to the pause
 * site as a JSON marker, and the pause site rebuilds the request with
 * AskUserRequest::fromMarker() — never field by field, so no field the
 * agent chose (above all the policy) can be dropped on the way.
 *
 * A marker written before the policy existed (only question, choices and
 * allow_multiple) must rebuild as a stop question with no assumption: a
 * deploy in the middle of a run can never turn it into a guess.
 */
class AskUserRequestMarkerTest extends TestCase
{
    /** The marker exactly as the tool handler writes it and the pause site reads it back. */
    private function throughMarker(AskUserRequest $request): AskUserRequest
    {
        $json = json_encode(
            [AgentLoopService::REQUIRES_USER_INPUT => true] + $request->toArray(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        $this->assertIsString($json);

        $marker = json_decode($json, true);
        $this->assertIsArray($marker);

        return AskUserRequest::fromMarker($marker);
    }

    /** @return array<string, array{0: AskUserRequest}> */
    public static function requests(): array
    {
        $choices = ['PHPUnit', 'Pest', 'Codeception'];

        return [
            'stop, all defaults' => [new AskUserRequest('Which framework?', $choices, false)],
            'free question, stop' => [new AskUserRequest('What should it be called?')],
            'stop, consequential, requested period' => [new AskUserRequest(
                'Delete the old records?',
                ['Yes', 'No'],
                false,
                policy: UnansweredPolicy::Stop,
                assumedAnswer: null,
                consequential: true,
                requestedWaitMinutes: 30,
            )],
            'proceed on a single selection' => [new AskUserRequest(
                'Which framework?',
                $choices,
                false,
                policy: UnansweredPolicy::ProceedOnAssumption,
                assumedAnswer: new ParsedAnswer('1', true, [['number' => 1, 'text' => 'PHPUnit']]),
                consequential: false,
                requestedWaitMinutes: 120,
            )],
            'proceed on a multiple selection' => [new AskUserRequest(
                'Which parts?',
                $choices,
                true,
                policy: UnansweredPolicy::ProceedOnAssumption,
                assumedAnswer: new ParsedAnswer('1, 3', true, [
                    ['number' => 1, 'text' => 'PHPUnit'],
                    ['number' => 3, 'text' => 'Codeception'],
                ]),
                requestedWaitMinutes: null,
            )],
            'proceed on free text with unicode' => [new AskUserRequest(
                'What should the release be called?',
                [],
                false,
                policy: UnansweredPolicy::ProceedOnAssumption,
                assumedAnswer: new ParsedAnswer('Café — “2026.10” / beta', false, []),
                requestedWaitMinutes: -5,
            )],
            'negative and huge requested periods survive' => [new AskUserRequest(
                'Which framework?',
                $choices,
                false,
                requestedWaitMinutes: 1_000_000_000,
            )],
        ];
    }

    #[Test]
    #[DataProvider('requests')]
    public function every_field_survives_the_marker(AskUserRequest $request): void
    {
        $rebuilt = $this->throughMarker($request);

        $this->assertEquals($request, $rebuilt);
        $this->assertSame($request->question, $rebuilt->question);
        $this->assertSame($request->choices, $rebuilt->choices);
        $this->assertSame($request->allowMultiple, $rebuilt->allowMultiple);
        $this->assertSame($request->policy, $rebuilt->policy);
        $this->assertSame($request->consequential, $rebuilt->consequential);
        $this->assertSame($request->requestedWaitMinutes, $rebuilt->requestedWaitMinutes);
        $this->assertSame($request->assumedAnswer?->toArray(), $rebuilt->assumedAnswer?->toArray());
        $this->assertSame($request->toArray(), $rebuilt->toArray());
    }

    #[Test]
    public function the_defaults_are_a_stop_question_with_nothing_assumed(): void
    {
        $request = new AskUserRequest('Which framework?', ['PHPUnit', 'Pest'], false);

        $this->assertSame(UnansweredPolicy::Stop, $request->policy);
        $this->assertNull($request->assumedAnswer);
        $this->assertFalse($request->consequential);
        $this->assertNull($request->requestedWaitMinutes);
    }

    #[Test]
    public function the_marker_carries_exactly_these_keys(): void
    {
        $request = new AskUserRequest(
            'Which framework?',
            ['PHPUnit', 'Pest'],
            false,
            policy: UnansweredPolicy::ProceedOnAssumption,
            assumedAnswer: new ParsedAnswer('2', true, [['number' => 2, 'text' => 'Pest']]),
            consequential: false,
            requestedWaitMinutes: 90,
        );

        $this->assertSame([
            'question' => 'Which framework?',
            'choices' => ['PHPUnit', 'Pest'],
            'allow_multiple' => false,
            'if_unanswered' => 'proceed_on_assumption',
            'assumed_answer' => [
                'raw_reply' => '2',
                'is_selection' => true,
                'selected' => [['number' => 2, 'text' => 'Pest']],
            ],
            'consequential' => false,
            'wait_minutes' => 90,
        ], $request->toArray());
    }

    #[Test]
    public function a_stop_question_marks_no_assumption(): void
    {
        $array = (new AskUserRequest('Which framework?', ['PHPUnit', 'Pest'], false))->toArray();

        $this->assertSame(
            ['question', 'choices', 'allow_multiple', 'if_unanswered', 'assumed_answer', 'consequential', 'wait_minutes'],
            array_keys($array),
        );
        $this->assertSame('stop', $array['if_unanswered']);
        $this->assertNull($array['assumed_answer']);
        $this->assertFalse($array['consequential']);
        $this->assertNull($array['wait_minutes']);
    }

    #[Test]
    public function a_marker_written_before_the_policy_existed_rebuilds_as_a_stop_question(): void
    {
        $marker = json_decode(json_encode([
            AgentLoopService::REQUIRES_USER_INPUT => true,
            'question' => 'Which framework?',
            'choices' => ['PHPUnit', 'Pest'],
            'allow_multiple' => false,
        ]), true);

        $request = AskUserRequest::fromMarker($marker);

        $this->assertSame('Which framework?', $request->question);
        $this->assertSame(['PHPUnit', 'Pest'], $request->choices);
        $this->assertFalse($request->allowMultiple);
        $this->assertSame(UnansweredPolicy::Stop, $request->policy);
        $this->assertNull($request->assumedAnswer);
        $this->assertFalse($request->consequential);
        $this->assertNull($request->requestedWaitMinutes);
    }

    #[Test]
    public function a_marker_with_only_a_question_rebuilds_with_no_choices(): void
    {
        $request = AskUserRequest::fromMarker(['question' => 'What should it be called?']);

        $this->assertEquals(new AskUserRequest('What should it be called?'), $request);
    }
}
