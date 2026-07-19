<?php

namespace Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Integration\Harness\ResponseScript;

class ResponseScriptTest extends TestCase
{
    /* ------------------------------------------------------------------ */
    /*  T006: Steps are served in order                                    */
    /* ------------------------------------------------------------------ */

    public function test_steps_are_served_in_order()
    {
        $script = new ResponseScript();
        $script->toolRequest('search', ['query' => 'test'])
            ->finalAnswer('Results found');

        $first = $script->serve();
        $second = $script->serve();

        // First step should be the tool request
        $this->assertArrayHasKey('choices', $first);
        $this->assertEquals('tool_calls', $first['choices'][0]['finish_reason']);
        $this->assertEquals('search', $first['choices'][0]['message']['tool_calls'][0]['function']['name']);

        // Second step should be the final answer
        $this->assertEquals('stop', $second['choices'][0]['finish_reason']);
        $this->assertEquals('Results found', $second['choices'][0]['message']['content']);
    }

    public function test_serving_past_end_throws_with_request_info()
    {
        $script = new ResponseScript();
        $script->finalAnswer('Done');

        $script->serve(); // consume the only step

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/message.*count/i');

        // Should throw with request info rendered
        $script->serve([
            'message_count' => 5,
            'entry_path' => 'sync',
            'iteration' => 3,
        ]);
    }

    public function test_thrown_error_includes_unserved_request_details()
    {
        $script = new ResponseScript();
        $script->toolRequest('get_weather', ['city' => 'London']);

        $script->serve(); // consume the step

        try {
            $script->serve([
                'message_count' => 10,
                'tool_names' => ['get_weather', 'search'],
                'entry_path' => 'stream',
                'iteration' => 2,
            ]);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            // Should contain some of the request info
            $this->assertStringContainsString('10', $message);
            $this->assertStringContainsString('stream', $message);
        }
    }

    public function test_unconsumed_steps_are_detectable()
    {
        $script = new ResponseScript();
        $script->toolRequest('search', ['q' => 'a'])
            ->toolRequest('search', ['q' => 'b'])
            ->finalAnswer('Done');

        $this->assertTrue($script->hasUnconsumedSteps());
        $this->assertEquals(3, $script->unconsumedSteps());

        $script->serve(); // consume first
        $this->assertEquals(2, $script->unconsumedSteps());

        $script->serve(); // consume second
        $this->assertEquals(1, $script->unconsumedSteps());

        $script->serve(); // consume last
        $this->assertFalse($script->hasUnconsumedSteps());
        $this->assertEquals(0, $script->unconsumedSteps());
    }

    public function test_empty_script_has_no_unconsumed_steps()
    {
        $script = new ResponseScript();
        $this->assertFalse($script->hasUnconsumedSteps());
        $this->assertEquals(0, $script->unconsumedSteps());
    }

    /* ------------------------------------------------------------------ */
    /*  Builder method tests                                               */
    /* ------------------------------------------------------------------ */

    public function test_toolRequest_produces_openai_format()
    {
        $script = new ResponseScript();
        $script->toolRequest('get_weather', ['city' => 'London', 'units' => 'celsius']);

        $step = $script->serve();

        $this->assertArrayHasKey('choices', $step);
        $choice = $step['choices'][0];
        $this->assertEquals('tool_calls', $choice['finish_reason']);
        $this->assertEquals('assistant', $choice['message']['role']);
        $this->assertArrayHasKey('tool_calls', $choice['message']);

        $toolCall = $choice['message']['tool_calls'][0];
        $this->assertStringStartsWith('call_', $toolCall['id']);
        $this->assertEquals('function', $toolCall['type']);
        $this->assertEquals('get_weather', $toolCall['function']['name']);

        $args = json_decode($toolCall['function']['arguments'], true);
        $this->assertEquals('London', $args['city']);
        $this->assertEquals('celsius', $args['units']);
    }

    public function test_finalAnswer_produces_openai_format()
    {
        $script = new ResponseScript();
        $script->finalAnswer('The weather in London is 20 degrees celsius.');

        $step = $script->serve();

        $this->assertArrayHasKey('choices', $step);
        $choice = $step['choices'][0];
        $this->assertEquals('stop', $choice['finish_reason']);
        $this->assertEquals('assistant', $choice['message']['role']);
        $this->assertEquals('The weather in London is 20 degrees celsius.', $choice['message']['content']);
    }

    public function test_embedding_produces_openai_format()
    {
        $script = new ResponseScript();
        $script->embedding(['Hello world']);

        $step = $script->serve();

        $this->assertArrayHasKey('data', $step);
        $this->assertCount(1, $step['data']);
        $this->assertIsArray($step['data'][0]['embedding']);
        $this->assertEquals(0, $step['data'][0]['index']);
    }

    public function test_usage_adds_usage_info_to_last_step()
    {
        $script = new ResponseScript();
        $script->finalAnswer('Hello')->usage(10, 5);

        $step = $script->serve();

        $this->assertArrayHasKey('usage', $step);
        $this->assertEquals(10, $step['usage']['prompt_tokens']);
        $this->assertEquals(5, $step['usage']['completion_tokens']);
    }

    public function test_method_chaining()
    {
        $script = new ResponseScript();
        $result = $script
            ->toolRequest('search', ['q' => 'a'])
            ->toolRequest('search', ['q' => 'b'])
            ->finalAnswer('Done')
            ->usage(100, 50);

        $this->assertSame($script, $result);
        $this->assertEquals(3, $script->unconsumedSteps());
    }

    public function test_multiple_tool_requests_in_sequence()
    {
        $script = new ResponseScript();
        $script->toolRequest('search', ['q' => 'first'])
            ->toolRequest('search', ['q' => 'second'])
            ->toolRequest('search', ['q' => 'third'])
            ->finalAnswer('All done');

        $steps = [];
        for ($i = 0; $i < 4; $i++) {
            $steps[] = $script->serve();
        }

        // First three steps are tool calls
        for ($i = 0; $i < 3; $i++) {
            $this->assertEquals('tool_calls', $steps[$i]['choices'][0]['finish_reason']);
        }

        // Last step is final answer
        $this->assertEquals('stop', $steps[3]['choices'][0]['finish_reason']);
    }

    public function test_never_returns_empty_or_default_response()
    {
        $script = new ResponseScript();
        $script->finalAnswer('Specific answer');

        $step = $script->serve();

        // Should not be empty
        $this->assertNotEmpty($step);
        // Should not have empty content
        $this->assertNotEquals('', $step['choices'][0]['message']['content']);
        // Should match what we scripted
        $this->assertEquals('Specific answer', $step['choices'][0]['message']['content']);
    }

    public function test_never_repeats_last_response()
    {
        $script = new ResponseScript();
        $script->finalAnswer('First answer')
            ->finalAnswer('Second answer');

        $first = $script->serve();
        $second = $script->serve();

        $this->assertEquals('First answer', $first['choices'][0]['message']['content']);
        $this->assertEquals('Second answer', $second['choices'][0]['message']['content']);
        $this->assertNotEquals($first, $second);
    }
}
