<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Services\ToolResponseSimulator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * research.md D4: ToolResponseSimulator::simulate() returns a plausible,
 * schema-shaped, JSON-encodable skeleton — never a real network call,
 * never an empty array (mutation-checklist row 5).
 *
 * simulate()'s primary, task-required contract is schema-only:
 * simulate(array $inputSchema): array. A second, optional
 * $submittedArguments parameter is exercised separately below for the
 * "echoes back the caller's own submitted value" plausibility behaviour
 * D4's rationale describes — a purely additive affordance the schema-only
 * calls never need to supply.
 */
class ToolResponseSimulatorTest extends TestCase
{
    private function simulator(): ToolResponseSimulator
    {
        return app(ToolResponseSimulator::class);
    }

    // ---------------------------------------------------------------
    // Always a non-empty, success-shaped envelope (mutation-checklist
    // row 5 — must not regress to [])
    // ---------------------------------------------------------------

    #[Test]
    public function it_never_returns_an_empty_array_and_always_reports_success_at_the_top_level(): void
    {
        $result = $this->simulator()->simulate([
            'properties' => ['body' => ['properties' => ['name' => ['type' => 'string']]]],
        ]);

        $this->assertNotSame([], $result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
    }

    #[Test]
    public function an_empty_skeleton_falls_back_to_just_success_true_when_neither_body_nor_query_exist(): void
    {
        $this->assertSame(['success' => true], $this->simulator()->simulate(['properties' => []]));
        $this->assertSame(['success' => true], $this->simulator()->simulate([]));
    }

    // ---------------------------------------------------------------
    // Type-appropriate placeholders per declared property type
    // ---------------------------------------------------------------

    #[Test]
    public function each_declared_body_property_type_gets_a_type_appropriate_placeholder(): void
    {
        $schema = [
            'properties' => [
                'body' => [
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'age' => ['type' => 'integer'],
                        'price' => ['type' => 'number'],
                        'active' => ['type' => 'boolean'],
                        'tags' => ['type' => 'array'],
                        'meta' => ['type' => 'object'],
                        'anything' => [],
                    ],
                ],
            ],
        ];

        $result = $this->simulator()->simulate($schema);

        $this->assertIsString($result['name']);
        $this->assertNotSame('', $result['name'], 'string placeholder must be a short synthetic marker, not empty');

        $this->assertIsInt($result['age']);

        $this->assertIsNumeric($result['price']);

        $this->assertTrue($result['active']);

        $this->assertSame([], $result['tags']);

        // object/unknown -> an empty *object*, not an empty array: must
        // JSON-encode to {} so an agent inspecting the synthetic response
        // sees the correct JSON shape.
        $this->assertJsonStringEqualsJsonString('{}', json_encode($result['meta']));
        $this->assertJsonStringEqualsJsonString('{}', json_encode($result['anything']));
    }

    #[Test]
    public function a_string_property_echoes_the_callers_own_submitted_value_when_supplied(): void
    {
        $schema = ['properties' => ['body' => ['properties' => ['name' => ['type' => 'string']]]]];

        $result = $this->simulator()->simulate($schema, ['name' => 'Alice']);

        $this->assertSame(
            'Alice',
            $result['name'],
            'echoing back the caller-submitted value reads as a more believable acknowledgment (research.md D4)',
        );
    }

    // ---------------------------------------------------------------
    // Falls back to query when no body is present; body wins when both
    // are present (walks body first, never merges the two)
    // ---------------------------------------------------------------

    #[Test]
    public function it_falls_back_to_query_properties_when_no_body_is_declared(): void
    {
        $schema = [
            'properties' => [
                'query' => ['properties' => ['q' => ['type' => 'string']]],
            ],
        ];

        $result = $this->simulator()->simulate($schema);

        $this->assertArrayHasKey('q', $result);
        $this->assertIsString($result['q']);
    }

    #[Test]
    public function body_takes_precedence_over_query_when_both_are_declared(): void
    {
        $schema = [
            'properties' => [
                'body' => ['properties' => ['fromBody' => ['type' => 'string']]],
                'query' => ['properties' => ['fromQuery' => ['type' => 'string']]],
            ],
        ];

        $result = $this->simulator()->simulate($schema);

        $this->assertArrayHasKey('fromBody', $result);
        $this->assertArrayNotHasKey('fromQuery', $result);
    }

    // ---------------------------------------------------------------
    // Always JSON-encodable (no PHP-only types)
    // ---------------------------------------------------------------

    #[Test]
    public function the_result_is_always_json_encodable(): void
    {
        $schema = [
            'properties' => [
                'body' => [
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'meta' => ['type' => 'object'],
                        'tags' => ['type' => 'array'],
                    ],
                ],
            ],
        ];

        $encoded = json_encode($this->simulator()->simulate($schema), JSON_THROW_ON_ERROR);

        $this->assertNotFalse($encoded);
        $this->assertIsArray(json_decode($encoded, true));
    }
}
