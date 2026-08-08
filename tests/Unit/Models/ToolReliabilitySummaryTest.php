<?php

namespace ClarionApp\LlmClient\Tests\Unit\Models;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\ToolReliabilitySummary;
use ClarionApp\LlmClient\ValueObjects\ToolFailureCategory;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for the ToolReliabilitySummary model's fixed constants and its
 * UUID-generating creating hook (data-model.md §4.2).
 */
class ToolReliabilitySummaryTest extends TestCase
{
    #[Test]
    public function the_unattributed_agent_bucket_is_its_own_reserved_sentinel(): void
    {
        $this->assertSame(
            '00000000-0000-0000-0000-000000000002',
            ToolReliabilitySummary::UNATTRIBUTED_AGENT_BUCKET
        );
    }

    #[Test]
    public function the_unattributed_agent_bucket_is_distinct_from_its_sibling_sentinels(): void
    {
        $this->assertNotSame(
            CostSummary::UNATTRIBUTED_AGENT_BUCKET,
            ToolReliabilitySummary::UNATTRIBUTED_AGENT_BUCKET
        );
        $this->assertSame('00000000-0000-0000-0000-000000000001', CostSummary::UNATTRIBUTED_AGENT_BUCKET);

        $this->assertNotSame(
            RoleAssignment::INSTALLATION_SCOPE_ID,
            ToolReliabilitySummary::UNATTRIBUTED_AGENT_BUCKET
        );
        $this->assertSame('00000000-0000-0000-0000-000000000000', RoleAssignment::INSTALLATION_SCOPE_ID);
    }

    #[Test]
    public function the_low_sample_threshold_is_fixed_at_ten(): void
    {
        $this->assertSame(10, ToolReliabilitySummary::LOW_SAMPLE_THRESHOLD);
    }

    #[Test]
    public function the_failure_category_columns_map_covers_every_tool_failure_category_case_one_to_one(): void
    {
        $expected = [
            'timeout' => 'failure_timeout_count',
            'connection_failure' => 'failure_connection_failure_count',
            'authentication_failure' => 'failure_authentication_failure_count',
            'invalid_input' => 'failure_invalid_input_count',
            'server_error' => 'failure_server_error_count',
            'other' => 'failure_other_count',
        ];

        $this->assertSame($expected, ToolReliabilitySummary::FAILURE_CATEGORY_COLUMNS);

        // Every ToolFailureCategory case has exactly one matching column, and
        // no extra/missing entries exist in either direction.
        $caseValues = array_map(fn (ToolFailureCategory $case) => $case->value, ToolFailureCategory::cases());
        $this->assertSame($caseValues, array_keys(ToolReliabilitySummary::FAILURE_CATEGORY_COLUMNS));
    }

    #[Test]
    public function the_uncategorized_column_is_distinct_from_the_other_category_column(): void
    {
        $this->assertSame('failure_uncategorized_count', ToolReliabilitySummary::UNCATEGORIZED_COLUMN);
        $this->assertNotContains(
            ToolReliabilitySummary::UNCATEGORIZED_COLUMN,
            ToolReliabilitySummary::FAILURE_CATEGORY_COLUMNS
        );
    }

    #[Test]
    public function creating_a_row_without_an_explicit_id_generates_a_uuid(): void
    {
        $row = ToolReliabilitySummary::create([
            'tool_name' => 'search_web',
            'agent_id' => ToolReliabilitySummary::UNATTRIBUTED_AGENT_BUCKET,
            'user_id' => (string) \Illuminate\Support\Str::uuid(),
            'period_date' => '2026-08-07',
        ]);

        $this->assertNotEmpty($row->id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $row->id
        );
    }
}
