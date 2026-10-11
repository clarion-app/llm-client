<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\PausedTurnWriter;
use ClarionApp\LlmClient\ValueObjects\PauseKind;
use ClarionApp\LlmClient\ValueObjects\PausedTurnContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PausedTurnWriter is the one place a paused model turn is written into, and
 * consumed out of, the paused assistant message's tool_data — for both
 * confirmation and question pauses.
 *
 * Contract exercised here:
 *  - The pause site has already stored the turn's full `tool_calls` batch on
 *    the message; pause() adds the pause keys around it.
 *  - pause() records which call paused (`paused_tool_call_id`), the kind
 *    (`pause_kind`), the results produced before the pause plus a
 *    synthesized not-executed result for every call after it
 *    (`pending_tool_results`, in tool_calls order), and the kind's pending
 *    key (`pending_confirmation` unchanged, or `pending_question`).
 *  - consume() answers the paused call, merges everything into
 *    `tool_results` in tool_calls order, and clears the pending keys.
 *  - A row written before this shape existed (no `paused_tool_call_id`, no
 *    `pause_kind`) is a confirmation whose paused call is tool_calls[0].
 */
class PausedTurnWriterTest extends TestCase
{
    private const QUESTION_NOT_EXECUTED = 'Not executed: this turn paused to ask the user a question. Re-issue this call after the answer if it is still needed.';

    protected function tearDown(): void
    {
        DB::table('messages')->delete();
        DB::table('conversations')->delete();

        parent::tearDown();
    }

    private function toolCall(string $id, string $name): array
    {
        return ['id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => '{}']];
    }

    /** @return list<array<string, mixed>> */
    private function batch(): array
    {
        return [
            $this->toolCall('call_1', 'record_note'),
            $this->toolCall('call_2', 'ask_user'),
            $this->toolCall('call_3', 'record_tag'),
            $this->toolCall('call_4', 'record_other'),
        ];
    }

    private function messageWithToolCalls(array $toolCalls, array $extra = []): Message
    {
        $conversation = Conversation::factory()->create();

        return Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => 'Let me check with you first.',
            'responseTime' => 0,
            'tool_data' => ['tool_calls' => $toolCalls] + $extra,
        ]);
    }

    private function context(string $pausedId = 'call_2', array $pending = []): PausedTurnContext
    {
        return new PausedTurnContext(
            pausedToolCallId: $pausedId,
            pendingToolResults: $pending ?: [['tool_call_id' => 'call_1', 'content' => '{"recorded":"note"}']],
            iteration: 3,
            runId: 'run-1',
            stepId: 'step-1',
            actionId: 'action-1',
        );
    }

    private function writer(): PausedTurnWriter
    {
        return app(PausedTurnWriter::class);
    }

    #[Test]
    public function question_pause_writes_the_generalized_shape(): void
    {
        $message = $this->messageWithToolCalls($this->batch());

        $this->writer()->pause($message, PauseKind::Question, ['question_id' => 'q-1'], $this->context());

        $data = $message->fresh()->tool_data;

        $this->assertSame($this->batch(), $data['tool_calls'], 'the batch itself is never rewritten');
        $this->assertNull($data['tool_results'], 'nothing is consumed until the pause resolves');
        $this->assertSame('question', $data['pause_kind']);
        $this->assertSame('call_2', $data['paused_tool_call_id']);
        $this->assertSame(['question_id' => 'q-1'], $data['pending_question']);
        $this->assertNull($data['pending_confirmation'] ?? null);
        $this->assertSame(3, $data['iteration']);
        $this->assertSame('run-1', $data['run_id']);
        $this->assertSame('step-1', $data['step_id']);
        $this->assertSame('action-1', $data['action_id']);
        $this->assertNotEmpty($data['paused_at']);

        $this->assertSame(
            [
                ['tool_call_id' => 'call_1', 'content' => '{"recorded":"note"}'],
                ['tool_call_id' => 'call_3', 'content' => self::QUESTION_NOT_EXECUTED],
                ['tool_call_id' => 'call_4', 'content' => self::QUESTION_NOT_EXECUTED],
            ],
            $data['pending_tool_results'],
            'earlier results are kept and every later call gets the question-specific not-executed result, in tool_calls order',
        );
    }

    #[Test]
    public function confirmation_pause_keeps_the_pending_confirmation_keys_unchanged(): void
    {
        $message = $this->messageWithToolCalls($this->batch());
        $pendingConfirmation = [
            'tool_name' => 'execute_operation',
            'confirmation_type' => 'api_call',
            'operationId' => 'destroyContact',
            'method' => 'DELETE',
            'path' => '/api/contacts/{id}',
            'arguments' => ['path' => ['id' => '42']],
            'expires_at' => '2026-10-11T12:05:00+00:00',
        ];

        $this->writer()->pause($message, PauseKind::Confirmation, $pendingConfirmation, $this->context());

        $data = $message->fresh()->tool_data;

        $this->assertSame('confirmation', $data['pause_kind']);
        $this->assertSame('call_2', $data['paused_tool_call_id']);
        $this->assertSame($pendingConfirmation, $data['pending_confirmation']);
        $this->assertNull($data['pending_question'] ?? null);
        $this->assertNull($data['tool_results']);

        $this->assertSame('call_1', $data['pending_tool_results'][0]['tool_call_id']);
        foreach ([1 => 'call_3', 2 => 'call_4'] as $index => $id) {
            $entry = $data['pending_tool_results'][$index];
            $this->assertSame($id, $entry['tool_call_id']);
            $this->assertStringStartsWith('Not executed', $entry['content']);
            $this->assertStringContainsStringIgnoringCase('confirm', $entry['content'], 'a confirmation pause names confirmation, not a question');
            $this->assertNotSame(self::QUESTION_NOT_EXECUTED, $entry['content']);
        }
    }

    #[Test]
    public function pause_on_the_last_call_synthesizes_nothing(): void
    {
        $message = $this->messageWithToolCalls([
            $this->toolCall('call_1', 'record_note'),
            $this->toolCall('call_2', 'ask_user'),
        ]);

        $this->writer()->pause($message, PauseKind::Question, ['question_id' => 'q-1'], $this->context());

        $this->assertSame(
            [['tool_call_id' => 'call_1', 'content' => '{"recorded":"note"}']],
            $message->fresh()->tool_data['pending_tool_results'],
        );
    }

    #[Test]
    public function consume_merges_results_in_tool_calls_order_and_clears_pending_keys(): void
    {
        $message = $this->messageWithToolCalls($this->batch());
        $this->writer()->pause($message, PauseKind::Question, ['question_id' => 'q-1'], $this->context());

        $this->writer()->consume($message->fresh(), '{"outcome":"answered"}');

        $data = $message->fresh()->tool_data;

        $this->assertSame(
            [
                ['tool_call_id' => 'call_1', 'content' => '{"recorded":"note"}'],
                ['tool_call_id' => 'call_2', 'content' => '{"outcome":"answered"}'],
                ['tool_call_id' => 'call_3', 'content' => self::QUESTION_NOT_EXECUTED],
                ['tool_call_id' => 'call_4', 'content' => self::QUESTION_NOT_EXECUTED],
            ],
            $data['tool_results'],
        );
        $this->assertNull($data['pending_tool_results']);
        $this->assertNull($data['pending_question']);
        $this->assertSame($this->batch(), $data['tool_calls']);
    }

    #[Test]
    public function consume_orders_by_tool_calls_even_when_pending_results_arrive_out_of_order(): void
    {
        $message = $this->messageWithToolCalls([
            $this->toolCall('call_1', 'record_note'),
            $this->toolCall('call_2', 'record_tag'),
            $this->toolCall('call_3', 'execute_operation'),
        ]);

        $this->writer()->pause($message, PauseKind::Confirmation, ['expires_at' => '2026-10-11T12:05:00+00:00'], $this->context('call_3', [
            ['tool_call_id' => 'call_2', 'content' => 'two'],
            ['tool_call_id' => 'call_1', 'content' => 'one'],
        ]));

        $this->writer()->consume($message->fresh(), 'three');

        $data = $message->fresh()->tool_data;
        $this->assertSame(
            ['call_1', 'call_2', 'call_3'],
            array_column($data['tool_results'], 'tool_call_id'),
        );
        $this->assertSame(['one', 'two', 'three'], array_column($data['tool_results'], 'content'));
        $this->assertNull($data['pending_confirmation']);
        $this->assertNull($data['pending_tool_results']);
    }

    #[Test]
    public function legacy_row_without_pause_markers_is_a_confirmation_on_the_first_call(): void
    {
        $message = $this->messageWithToolCalls(
            [$this->toolCall('call_legacy', 'contacts.destroy'), $this->toolCall('call_other', 'contacts.show')],
            [
                'tool_results' => null,
                'iteration' => 1,
                'pending_confirmation' => [
                    'tool_name' => 'contacts.destroy',
                    'method' => 'DELETE',
                    'expires_at' => '2026-10-11T12:05:00+00:00',
                ],
            ],
        );

        $this->writer()->consume($message, 'User cancelled this operation.');

        $data = $message->fresh()->tool_data;
        $this->assertSame(
            [['tool_call_id' => 'call_legacy', 'content' => 'User cancelled this operation.']],
            $data['tool_results'],
            'a legacy row answers tool_calls[0] exactly as before',
        );
        $this->assertNull($data['pending_confirmation']);
    }
}
