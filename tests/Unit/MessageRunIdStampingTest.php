<?php

namespace Tests\Unit;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * contracts/trace-id-propagation.md §3 (M1-M4): Message's `creating` listener
 * stamps run_id from the ambient Context carrier. This is the mechanism US2's
 * reverse lookup ($message->run_id) depends on directly.
 */
class MessageRunIdStampingTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::forget('run_id');
        DB::table('messages')->delete();
        DB::table('conversations')->delete();

        parent::tearDown();
    }

    protected function baseAttributes(): array
    {
        $conversation = Conversation::factory()->create();

        return [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => 'Test content',
            'responseTime' => 0,
        ];
    }

    /** @test */
    public function creating_listener_stamps_run_id_from_context(): void
    {
        $runId = (string) Str::uuid();
        Context::add('run_id', $runId);

        $message = Message::create($this->baseAttributes());

        $this->assertSame($runId, $message->run_id);
    }

    /** @test */
    public function explicit_run_id_is_never_overwritten(): void
    {
        // Context carries a different value — the caller's own explicit
        // assignment (never mass-assigned; run_id is not in $fillable) must win.
        Context::add('run_id', (string) Str::uuid());
        $explicitRunId = (string) Str::uuid();

        $message = new Message($this->baseAttributes());
        $message->run_id = $explicitRunId;
        $message->save();

        $this->assertSame(
            $explicitRunId,
            $message->run_id,
            'M1: a caller-set run_id is never overwritten by the ambient Context value'
        );
    }

    /** @test */
    public function run_id_stays_null_when_context_empty(): void
    {
        Context::forget('run_id');

        $message = Message::create($this->baseAttributes());

        $this->assertNull(
            $message->run_id,
            'M2: no run open means no fallback identifier is fabricated'
        );
    }

    /** @test */
    public function creating_listener_issues_no_extra_query(): void
    {
        $conversation = Conversation::factory()->create();
        $attrsWithoutContext = array_merge($this->baseAttributes(), ['conversation_id' => $conversation->id]);
        $attrsWithContext = array_merge($this->baseAttributes(), ['conversation_id' => $conversation->id]);

        Context::forget('run_id');
        DB::enableQueryLog();
        Message::create($attrsWithoutContext);
        $withoutContext = count(DB::getQueryLog());
        DB::flushQueryLog();

        Context::add('run_id', (string) Str::uuid());
        Message::create($attrsWithContext);
        $withContext = count(DB::getQueryLog());
        DB::disableQueryLog();
        Context::forget('run_id');

        $this->assertSame(
            $withoutContext,
            $withContext,
            'M3: stamping run_id from Context must not add a query beyond the existing insert'
        );
    }
}
