<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Models\ManagedTaskPart;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 103-manager-agent, Phase 10 (Polish), tasks.md T074.
 *
 * Unit tests for `AgentLoopService::buildManagedTaskProgressSection(
 * ?string $managedTaskId): ?string` (research.md D9, data-model.md §4/§2)
 * -- a task-list gap flagged by Phase 3's own Progress Log (no task in
 * T001-T076 ever assigned this method to a phase, despite research.md D9
 * and the Grounding notes both describing it as a genuinely new,
 * additive mechanism). Implemented here in Phase 10's own reconciliation
 * pass (T074), mirroring `AgentLoopServiceCombinedResultsSectionTest`'s
 * exact "invoke the private builder via reflection, then confirm the
 * real buildMessagesPayload() entry point actually wires it in" shape.
 */
class AgentLoopServiceManagedTaskProgressSectionTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('managed_task_parts')->delete();
        DB::table('managed_tasks')->delete();

        parent::tearDown();
    }

    private function service(): AgentLoopService
    {
        return new AgentLoopService(
            app(McpToolRegistry::class),
            app(McpToolExecutor::class),
            app(OperationCache::class),
        );
    }

    /**
     * Invokes the (expected-private) buildManagedTaskProgressSection() via
     * reflection -- AgentLoopServiceCombinedResultsSectionTest's own
     * established precedent for a system-prompt section builder that is
     * not part of the class's public surface.
     */
    private function invoke(AgentLoopService $service, ?string $managedTaskId): ?string
    {
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildManagedTaskProgressSection');
        $method->setAccessible(true);

        return $method->invoke($service, $managedTaskId);
    }

    private function makeManagedTask(?string $conversationId = null): ManagedTask
    {
        return ManagedTask::create([
            'conversation_id' => $conversationId ?? (string) Str::uuid(),
            'owner_user_id' => (string) Str::uuid(),
            'manager_agent_id' => null,
            'original_request' => 'A multi-part task.',
            'status' => 'in_progress',
            'round_ceiling' => 30,
            'rounds_used' => 0,
            'max_seconds' => 1800,
            'last_progress_at' => now(),
            'started_at' => now(),
        ]);
    }

    // =================================================================
    // null when there is no managed task id, or the task has no parts
    // =================================================================

    #[Test]
    public function returns_null_when_managed_task_id_is_null(): void
    {
        $this->assertNull($this->invoke($this->service(), null));
    }

    #[Test]
    public function returns_null_when_the_task_has_no_parts_yet(): void
    {
        $task = $this->makeManagedTask();

        $this->assertNull($this->invoke($this->service(), $task->id));
    }

    // =================================================================
    // Non-null progress view -> rendered "## Task Progress" section
    // listing every part's sequence/description/state, with
    // accepted_summary for accepted parts and shortfall_reason for
    // reported_as_shortfall parts (data-model.md §4/§2)
    // =================================================================

    #[Test]
    public function renders_every_parts_sequence_description_and_state(): void
    {
        $task = $this->makeManagedTask();
        ManagedTaskPart::create([
            'managed_task_id' => $task->id,
            'sequence' => 1,
            'description' => 'Pricing comparison across three competitors.',
            'state' => 'accepted',
            'current_delegation_id' => (string) Str::uuid(),
            'accepted_delegation_id' => (string) Str::uuid(),
            'accepted_summary' => 'Compiled current list pricing for all three competitors.',
            'assignment_count' => 1,
        ]);
        ManagedTaskPart::create([
            'managed_task_id' => $task->id,
            'sequence' => 2,
            'description' => 'Market-positioning section for Competitor C.',
            'state' => 'out_for_correction',
            'current_delegation_id' => (string) Str::uuid(),
            'assignment_count' => 2,
        ]);
        ManagedTaskPart::create([
            'managed_task_id' => $task->id,
            'sequence' => 3,
            'description' => 'Feature-set breakdown.',
            'state' => 'reported_as_shortfall',
            'shortfall_reason' => 'No reliable public data was available for this section.',
            'assignment_count' => 2,
        ]);

        $section = $this->invoke($this->service(), $task->id);

        $this->assertNotNull($section);
        $this->assertStringContainsString('## Task Progress', $section);

        $this->assertStringContainsString('Pricing comparison across three competitors.', $section);
        $this->assertStringContainsString('accepted', $section);
        $this->assertStringContainsString(
            'Compiled current list pricing for all three competitors.',
            $section,
            'an accepted part\'s own accepted_summary must be surfaced (research.md D9 — never the full result_output)',
        );

        $this->assertStringContainsString('Market-positioning section for Competitor C.', $section);
        $this->assertStringContainsString('out_for_correction', $section);

        $this->assertStringContainsString('Feature-set breakdown.', $section);
        $this->assertStringContainsString('reported_as_shortfall', $section);
        $this->assertStringContainsString(
            'No reliable public data was available for this section.',
            $section,
            'a reported_as_shortfall part\'s own shortfall_reason must be surfaced',
        );
    }

    #[Test]
    public function does_not_surface_accepted_summary_for_a_part_still_out_for_assignment(): void
    {
        $task = $this->makeManagedTask();
        ManagedTaskPart::create([
            'managed_task_id' => $task->id,
            'sequence' => 1,
            'description' => 'Not yet resolved.',
            'state' => 'not_yet_assigned',
            'assignment_count' => 0,
        ]);

        $section = $this->invoke($this->service(), $task->id);

        $this->assertNotNull($section);
        $this->assertStringContainsString('not_yet_assigned', $section);
        $this->assertStringNotContainsString('Accepted result', $section);
        $this->assertStringNotContainsString('Shortfall', $section);
    }

    // =================================================================
    // Context-budget truncation (research.md D9) -- ContentSanitizer::
    // truncate() against llm-client.manager.context_budget_bytes, with
    // ContentSanitizer::isTruncated() surfaced back as an explicit note
    // =================================================================

    #[Test]
    public function truncates_against_the_context_budget_and_notes_truncation_explicitly(): void
    {
        config(['llm-client.manager.context_budget_bytes' => 200]);

        $task = $this->makeManagedTask();
        for ($i = 1; $i <= 10; $i++) {
            ManagedTaskPart::create([
                'managed_task_id' => $task->id,
                'sequence' => $i,
                'description' => str_repeat("Part {$i} description text. ", 10),
                'state' => 'accepted',
                'accepted_delegation_id' => (string) Str::uuid(),
                'accepted_summary' => str_repeat("Part {$i} was accepted with a long summary. ", 10),
                'assignment_count' => 1,
            ]);
        }

        $section = $this->invoke($this->service(), $task->id);

        $this->assertNotNull($section);
        $this->assertLessThanOrEqual(
            200 + 600,
            strlen($section),
            'the section must actually be bounded near the configured cap, not merely rendered in full (research.md D9)',
        );
        $this->assertStringContainsString(
            'truncated',
            strtolower($section),
            'a truncated section must say so explicitly so the manager does not silently lose part summaries',
        );
    }

    #[Test]
    public function does_not_add_a_truncation_note_when_the_section_fits_within_the_budget(): void
    {
        $task = $this->makeManagedTask();
        ManagedTaskPart::create([
            'managed_task_id' => $task->id,
            'sequence' => 1,
            'description' => 'Short part.',
            'state' => 'accepted',
            'accepted_delegation_id' => (string) Str::uuid(),
            'accepted_summary' => 'Short summary.',
            'assignment_count' => 1,
        ]);

        $section = $this->invoke($this->service(), $task->id);

        $this->assertNotNull($section);
        $this->assertStringNotContainsString('TRUNCATED', $section);
        $this->assertStringNotContainsString('truncated to fit', $section);
    }

    // =================================================================
    // Wired into buildMessagesPayload() -- mirrors
    // AgentLoopServiceCombinedResultsSectionTest's own Phase 8/Polish
    // "deleted call site" gap-closure precedent, applied proactively here
    // since this section has no owning phase task to have caught it
    // ==================================================================

    #[Test]
    public function build_messages_payload_includes_the_task_progress_section_for_a_managed_task_conversation(): void
    {
        // EloquentMultiChainBridge's own `creating` listener
        // (vendor/clarion-app/eloquent-multichain-bridge) always
        // overwrites `id` with a fresh Str::uuid() regardless of what is
        // passed to create() -- so the Conversation must be created
        // FIRST, and ManagedTask.conversation_id set to its real,
        // bridge-assigned id, not the other way around.
        $conversation = Conversation::factory()->create([
            'channel' => 'managed-task',
        ]);

        $task = $this->makeManagedTask($conversation->id);
        ManagedTaskPart::create([
            'managed_task_id' => $task->id,
            'sequence' => 1,
            'description' => 'Wired-through part.',
            'state' => 'accepted',
            'accepted_delegation_id' => (string) Str::uuid(),
            'accepted_summary' => 'Wired-through accepted summary.',
            'assignment_count' => 1,
        ]);

        $messages = $this->service()->buildMessagesPayload($conversation);

        $systemMsg = collect($messages)->firstWhere('role', 'system');
        $this->assertNotNull($systemMsg, 'buildMessagesPayload() must emit a system message carrying the progress section');
        $this->assertStringContainsString(
            '## Task Progress',
            $systemMsg['content'],
            'buildMessagesPayload() must actually resolve the conversation\'s own managed task and call buildManagedTaskProgressSection()',
        );
        $this->assertStringContainsString('Wired-through accepted summary.', $systemMsg['content']);
    }

    #[Test]
    public function build_messages_payload_omits_the_task_progress_section_for_an_ordinary_conversation(): void
    {
        // An unrelated ManagedTask row exists (a different conversation
        // entirely) -- proves the section is gated on THIS conversation's
        // own channel/managed task, not merely "does any managed task
        // exist anywhere."
        $task = $this->makeManagedTask();
        ManagedTaskPart::create([
            'managed_task_id' => $task->id,
            'sequence' => 1,
            'description' => 'Unrelated task\'s part.',
            'state' => 'accepted',
            'accepted_delegation_id' => (string) Str::uuid(),
            'accepted_summary' => 'Should never leak into an unrelated conversation.',
            'assignment_count' => 1,
        ]);

        $ordinaryConversation = Conversation::factory()->create();

        $messages = $this->service()->buildMessagesPayload($ordinaryConversation);

        $systemMsg = collect($messages)->firstWhere('role', 'system');

        if ($systemMsg !== null) {
            $this->assertStringNotContainsString('## Task Progress', $systemMsg['content']);
            $this->assertStringNotContainsString('Should never leak into an unrelated conversation.', $systemMsg['content']);
        } else {
            $this->assertNull($systemMsg);
        }
    }
}
