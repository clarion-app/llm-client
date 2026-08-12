<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\ReductionStep;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\ReductionLadderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for ReductionLadderService — the sole write path for
 * reduction_steps rows (data-model.md §1, research.md D12), covering the
 * five validation rules `put()` must enforce and the ordinary
 * create/update/soft-delete round trip through the service's own `list()`.
 *
 * Mirrors ConversationWorkCeilingServiceTest's shape: rejection is expected
 * as \InvalidArgumentException (translated to a 422 at the HTTP layer,
 * elsewhere, not here), and every rejection case asserts that no row was
 * created or changed — a refused write must leave the table byte for byte
 * as it was.
 *
 * Two properties are load-bearing rather than incidental here:
 *
 *  - `withheld_tools` is validated against the closed ReducibleTool enum,
 *    not merely "any non-empty array of strings" — this is the one test
 *    (mutation-checklist row 8) that must go red if `execute_operation`,
 *    `list_applications`, or `search_operations` were ever accepted, since
 *    a rung is not permitted to withhold a tool the agent structurally
 *    cannot function without (research.md D6, FR-008).
 *  - The threshold collision rule is scoped to *enabled* rows only: a
 *    soft-deleted or disabled row at the same (axis, threshold_ratio) must
 *    never block a fresh write at that same combination, since the whole
 *    point of "disable a rung" (FR-011/US3 scenario 2) is that its slot on
 *    the ladder becomes free again.
 */
class ReductionLadderServiceTest extends TestCase
{
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = Server::create([
            'name' => 'Secondary Server',
            'server_url' => 'http://secondary.local:11434',
            'provider_type' => 'llama_cpp',
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('reduction_steps')->delete();
        DB::table('llm_servers')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function service(): ReductionLadderService
    {
        return new ReductionLadderService();
    }

    private function attributes(array $overrides = []): array
    {
        return array_merge([
            'axis' => 'budget_user',
            'threshold_ratio' => '0.7500',
            'substitute_model' => 'small-model',
        ], $overrides);
    }

    private function liveRowCount(): int
    {
        return DB::table('reduction_steps')->whereNull('deleted_at')->count();
    }

    private function totalRowCount(): int
    {
        return DB::table('reduction_steps')->count();
    }

    private function assertPutRejected(array $attributes, string $failMessage, ?string $id = null): void
    {
        $liveBefore = $this->liveRowCount();
        $totalBefore = $this->totalRowCount();

        try {
            $this->service()->put($attributes, $id);
            $this->fail($failMessage);
        } catch (\InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage(), 'A rejection must say what was wrong');
        }

        $this->assertSame($liveBefore, $this->liveRowCount(), 'A rejected write must change no live row');
        $this->assertSame($totalBefore, $this->totalRowCount(), 'A rejected write must persist nothing at all');
    }

    // ---------------------------------------------------------------
    // reduction_step_reduces_nothing
    // ---------------------------------------------------------------

    #[Test]
    public function reduction_step_reduces_nothing(): void
    {
        $this->assertPutRejected(
            ['axis' => 'budget_user', 'threshold_ratio' => '0.7500'],
            'A rung naming none of substitute_model/withheld_tools/history_budget_ratio must be rejected'
        );
    }

    #[Test]
    public function reduction_step_reduces_nothing_is_also_rejected_when_the_only_lever_is_an_empty_withheld_tools_array(): void
    {
        $this->assertPutRejected(
            ['axis' => 'budget_user', 'threshold_ratio' => '0.7500', 'withheld_tools' => []],
            'An empty withheld_tools array reduces nothing and must be rejected exactly like omitting it entirely'
        );
    }

    // ---------------------------------------------------------------
    // reduction_step_withholds_essential_tool
    // ---------------------------------------------------------------

    #[Test]
    public function reduction_step_withholds_essential_tool(): void
    {
        // The three tool names the agent structurally cannot function
        // without (research.md D6) — the exact mutation-checklist row 8
        // target: adding one of these as a ReducibleTool case, or removing
        // this validation clause, must turn this test red.
        foreach (['list_applications', 'execute_operation', 'search_operations'] as $essentialTool) {
            $this->assertPutRejected(
                $this->attributes(['substitute_model' => null, 'withheld_tools' => [$essentialTool]]),
                "withheld_tools naming '{$essentialTool}' must be rejected — it is not a ReducibleTool case"
            );
        }
    }

    #[Test]
    public function reduction_step_withholds_essential_tool_also_rejects_an_unrecognized_tool_name(): void
    {
        $this->assertPutRejected(
            $this->attributes(['substitute_model' => null, 'withheld_tools' => ['not_a_real_tool']]),
            'withheld_tools naming a tool outside ReducibleTool entirely must be rejected'
        );
    }

    #[Test]
    public function a_recognized_reducible_tool_is_accepted(): void
    {
        $step = $this->service()->put($this->attributes([
            'substitute_model' => null,
            'withheld_tools' => ['memory_search', 'propose_declarative_memory'],
        ]));

        $this->assertInstanceOf(ReductionStep::class, $step);
        $this->assertSame(['memory_search', 'propose_declarative_memory'], $step->withheld_tools);
    }

    // ---------------------------------------------------------------
    // reduction_step_ratio_out_of_range
    // ---------------------------------------------------------------

    #[Test]
    public function reduction_step_ratio_out_of_range_rejects_a_threshold_ratio_of_zero_or_below(): void
    {
        $this->assertPutRejected(
            $this->attributes(['threshold_ratio' => '0']),
            'threshold_ratio of 0 must be rejected — the range is (0, 1], exclusive of zero'
        );

        $this->assertPutRejected(
            $this->attributes(['threshold_ratio' => '-0.1']),
            'A negative threshold_ratio must be rejected'
        );
    }

    #[Test]
    public function reduction_step_ratio_out_of_range_rejects_a_threshold_ratio_above_one(): void
    {
        $this->assertPutRejected(
            $this->attributes(['threshold_ratio' => '1.0001']),
            'A threshold_ratio above 1 must be rejected'
        );
    }

    #[Test]
    public function reduction_step_ratio_out_of_range_rejects_an_out_of_range_history_budget_ratio(): void
    {
        $this->assertPutRejected(
            $this->attributes(['substitute_model' => null, 'history_budget_ratio' => '0']),
            'history_budget_ratio of 0 must be rejected'
        );

        $this->assertPutRejected(
            $this->attributes(['substitute_model' => null, 'history_budget_ratio' => '1.5']),
            'history_budget_ratio above 1 must be rejected'
        );
    }

    #[Test]
    public function a_threshold_ratio_of_exactly_one_is_accepted(): void
    {
        $step = $this->service()->put($this->attributes(['threshold_ratio' => '1.0000']));

        $this->assertSame('1.0000', $step->threshold_ratio);
    }

    // ---------------------------------------------------------------
    // reduction_step_threshold_collision
    // ---------------------------------------------------------------

    #[Test]
    public function reduction_step_threshold_collision_rejects_a_second_enabled_row_at_the_same_axis_and_threshold(): void
    {
        $this->service()->put($this->attributes(['axis' => 'budget_user', 'threshold_ratio' => '0.7500']));

        $this->assertPutRejected(
            $this->attributes(['axis' => 'budget_user', 'threshold_ratio' => '0.7500', 'substitute_model' => 'other-model']),
            'A second enabled row at the identical (axis, threshold_ratio) must be rejected — an ambiguous tie'
        );
    }

    #[Test]
    public function a_soft_deleted_row_at_the_same_combination_does_not_block_a_new_one(): void
    {
        $first = $this->service()->put($this->attributes(['axis' => 'budget_user', 'threshold_ratio' => '0.7500']));
        $this->service()->destroy($first->id);

        $second = $this->service()->put($this->attributes(['axis' => 'budget_user', 'threshold_ratio' => '0.7500']));

        $this->assertInstanceOf(ReductionStep::class, $second);
        $this->assertSame(1, $this->liveRowCount());
    }

    #[Test]
    public function a_disabled_row_at_the_same_combination_does_not_block_a_new_enabled_one(): void
    {
        $this->service()->put($this->attributes(['axis' => 'budget_user', 'threshold_ratio' => '0.7500', 'enabled' => false]));

        $second = $this->service()->put($this->attributes(['axis' => 'budget_user', 'threshold_ratio' => '0.7500', 'enabled' => true]));

        $this->assertInstanceOf(ReductionStep::class, $second);
        $this->assertTrue((bool) $second->enabled);
    }

    // ---------------------------------------------------------------
    // reduction_step_unknown_server
    // ---------------------------------------------------------------

    #[Test]
    public function reduction_step_unknown_server_rejects_a_substitute_server_id_naming_no_existing_server(): void
    {
        $this->assertPutRejected(
            $this->attributes(['substitute_server_id' => (string) Str::uuid()]),
            'substitute_server_id naming no existing Server row must be rejected'
        );
    }

    #[Test]
    public function a_substitute_server_id_naming_a_real_server_is_accepted(): void
    {
        $step = $this->service()->put($this->attributes(['substitute_server_id' => $this->server->id]));

        $this->assertSame($this->server->id, $step->substitute_server_id);
    }

    // ---------------------------------------------------------------
    // Ordinary create/update/soft-delete round trip
    // ---------------------------------------------------------------

    #[Test]
    public function an_ordinary_create_is_readable_back_via_list(): void
    {
        $created = $this->service()->put($this->attributes([
            'axis' => 'rate_limit',
            'threshold_ratio' => '0.9000',
            'substitute_model' => 'small-model',
        ]));

        $found = $this->service()->list()->firstWhere('id', $created->id);

        $this->assertNotNull($found, 'A created rung must be visible in list()');
        $this->assertSame('rate_limit', $found->axis);
        $this->assertSame('0.9000', $found->threshold_ratio);
        $this->assertSame('small-model', $found->substitute_model);
    }

    #[Test]
    public function an_update_changes_the_existing_row_rather_than_adding_a_second_one(): void
    {
        $created = $this->service()->put($this->attributes(['threshold_ratio' => '0.7500']));

        $updated = $this->service()->put(
            $this->attributes(['threshold_ratio' => '0.8000', 'substitute_model' => 'other-model']),
            $created->id
        );

        $this->assertSame($created->id, $updated->id, 'An update must change the existing row, not create a new one');
        $this->assertSame('0.8000', $updated->threshold_ratio);
        $this->assertSame('other-model', $updated->substitute_model);
        $this->assertSame(1, $this->totalRowCount());

        $listed = $this->service()->list()->firstWhere('id', $created->id);
        $this->assertSame('0.8000', $listed->threshold_ratio);
    }

    #[Test]
    public function destroy_soft_deletes_and_the_row_no_longer_appears_in_list(): void
    {
        $created = $this->service()->put($this->attributes());

        $this->service()->destroy($created->id);

        $this->assertNull($this->service()->list()->firstWhere('id', $created->id), 'A soft-deleted rung must not appear in list()');
        $this->assertSame(0, $this->liveRowCount());
        $this->assertSame(1, $this->totalRowCount(), 'The row survives as a soft delete rather than being erased');
    }
}
