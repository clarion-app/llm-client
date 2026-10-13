<?php

namespace Tests\Unit\Resume;

use ClarionApp\LlmClient\Contracts\FaultInjector;
use ClarionApp\LlmClient\Services\NullFaultInjector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InjectedFault;
use Tests\Support\SnapshotFaultInjector;
use Tests\TestCase;

/**
 * The fault-injection seam's test support (research.md D21). The
 * SnapshotFaultInjector arms one (point, occurrence) pair, snapshots the
 * listed tables at the Nth reach of that point, throws the InjectedFault,
 * and restore() returns the tables to exactly the snapshot — the in-memory
 * stand-in for a SIGKILLed worker. Red now: neither the contract, the
 * NullFaultInjector nor the test support exists yet.
 */
class SnapshotFaultInjectorTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('conversations')->delete();

        parent::tearDown();
    }

    /** @return list<string> */
    private function conversationIds(): array
    {
        return DB::table('conversations')->orderBy('id')->pluck('id')->all();
    }

    private function seedConversations(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('conversations')->insert([
                'id' => (string) Str::uuid(),
                'title' => "fault-injection fixture {$i}",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    #[Test]
    public function the_contract_names_every_fault_point(): void
    {
        $this->assertSame([
            'after_model_recorded', 'before_tool_started', 'after_tool_started',
            'after_tool_effect', 'after_tool_recorded', 'before_step_message',
            'after_step_message', 'mid_stream', 'after_claim',
            'during_pause_resume', 'after_delegation_row', 'during_join_wait',
        ], FaultInjector::POINTS, 'the twelve D21 fault points, nothing else');
    }

    #[Test]
    public function the_null_injector_reaches_every_point_and_does_nothing(): void
    {
        $injector = new NullFaultInjector();
        $this->assertInstanceOf(FaultInjector::class, $injector);

        foreach (FaultInjector::POINTS as $point) {
            $injector->reach($point);
        }
        $this->addToAssertionCount(1, 'every point passed without throwing');
    }

    #[Test]
    public function the_injector_fires_only_on_its_armed_point_and_occurrence(): void
    {
        $this->seedConversations(2);

        $injector = new SnapshotFaultInjector();
        $injector->arm('after_tool_effect', 2, ['conversations']);

        $injector->reach('before_tool_started'); // a different point passes
        $injector->reach('after_tool_effect');  // the first occurrence passes
        $this->addToAssertionCount(1, 'the first occurrence passed without throwing');

        try {
            $injector->reach('after_tool_effect'); // the armed occurrence
            $this->fail('the armed occurrence must throw the InjectedFault');
        } catch (InjectedFault $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }
    }

    #[Test]
    public function restore_returns_the_tables_to_exactly_the_snapshot(): void
    {
        $this->seedConversations(2);
        $snapshotIds = $this->conversationIds();
        $this->assertCount(2, $snapshotIds, 'fixture sanity');
        // The UUID order is non-deterministic, so capture the first
        // (smallest-UUID) conversation's original title rather than assuming
        // which fixture title it carries.
        $firstId = $snapshotIds[0];
        $originalTitle = DB::table('conversations')->where('id', $firstId)->value('title');

        $injector = new SnapshotFaultInjector();
        $injector->arm('after_tool_effect', 1, ['conversations']);

        try {
            $injector->reach('after_tool_effect');
            $this->fail('the armed occurrence must throw the InjectedFault');
        } catch (InjectedFault) {
            // the "kill": everything after this point is lost
        }

        // The aftermath: work that ran between the fault and the restore.
        DB::table('conversations')->where('id', $firstId)->update(['title' => 'mutated after the fault']);
        DB::table('conversations')->insert([
            'id' => (string) Str::uuid(),
            'title' => 'inserted after the fault',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertNotSame($snapshotIds, $this->conversationIds(), 'fixture sanity: the aftermath changed the table');

        $injector->restore();

        $this->assertSame($snapshotIds, $this->conversationIds(), 'restore() truncates and re-inserts the snapshot exactly');
        $this->assertSame($originalTitle, DB::table('conversations')->where('id', $firstId)->value('title'), 'the mutation is undone');
    }
}
