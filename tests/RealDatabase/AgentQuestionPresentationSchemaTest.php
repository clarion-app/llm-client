<?php

namespace Tests\RealDatabase;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The columns a question's presentation and answer method are recorded in,
 * on the engine production runs (SQLite enforces no length, so the
 * in-memory suite cannot tell). The preamble is the assistant text that
 * accompanied the question; the conversation message holding the same text
 * is a LONGTEXT, so the question row must hold any preamble that message
 * holds, or opening the question would fail.
 */
#[Group('real-db')]
class AgentQuestionPresentationSchemaTest extends RealDatabaseTestCase
{
    protected array $seedTables = ['agent_questions'];

    /** @return array<string, object> the column metadata of agent_questions, by name */
    private function columns(): array
    {
        $rows = DB::select(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS '
            ."WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_questions'"
        );

        $columns = [];
        foreach ($rows as $row) {
            $columns[$row->COLUMN_NAME] = $row;
        }

        return $columns;
    }

    #[Test]
    public function the_presentation_columns_hold_every_value_they_are_given(): void
    {
        $this->assertReady();
        $columns = $this->columns();

        $this->assertSame('longtext', $columns['preamble']->COLUMN_TYPE, 'any preamble its display message can hold');
        $this->assertSame('varchar(16)', $columns['presentation']->COLUMN_TYPE);
        $this->assertSame('varchar(32)', $columns['presentation_fallback_reason']->COLUMN_TYPE);
        $this->assertSame('varchar(24)', $columns['answer_method']->COLUMN_TYPE);
        foreach (['preamble', 'presentation', 'presentation_fallback_reason', 'answer_method'] as $name) {
            $this->assertSame('YES', $columns[$name]->IS_NULLABLE, "{$name} is nullable");
        }
    }

    #[Test]
    public function a_question_with_a_long_preamble_can_be_stored(): void
    {
        $this->assertReady();
        $preamble = str_repeat('Here is what I found so far. ', 3000); // ~87 KB

        DB::table('agent_questions')->insert([
            'id' => (string) Str::uuid(),
            'conversation_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'paused_message_id' => (string) Str::uuid(),
            'tool_call_id' => 'call_ask',
            'channel' => 'telegram',
            'resume_mode' => 'sync',
            'question' => 'Which part should I change first?',
            'choices' => json_encode(['Backend', 'Frontend']),
            'preamble' => $preamble,
            'presentation' => 'plain_text',
            'presentation_fallback_reason' => 'channel_lacks_options',
            'answer_method' => 'messaging_option',
            'asked_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        $this->assertSame($preamble, DB::table('agent_questions')->value('preamble'));
    }
}
