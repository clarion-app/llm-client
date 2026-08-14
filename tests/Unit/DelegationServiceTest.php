<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A schema-ordering guard over DelegationService's own queries against
 * agent_delegations (098-delegation-protocol, data-model.md §1).
 *
 * This has to be a source-level guard rather than an ordinary behavioral
 * test because the defect it exists to catch is invisible to this package's
 * own SQLite test database: agent_delegations has no created_at column (the
 * model is $timestamps = false, carrying its own started_at/completed_at),
 * and Eloquent's argument-less latest() emits `order by "created_at" desc`.
 * SQLite silently tolerates that -- a double-quoted identifier matching no
 * column degrades to a string literal, so the ordering is a harmless no-op
 * -- while MySQL/MariaDB, the production engine, raises "Unknown column
 * 'created_at' in 'order clause'" and aborts the query. The depth lookup
 * that ordering sits on runs on EVERY delegation, so the failure mode is
 * "no delegation works in production, every delegation works in the tests."
 *
 * Mirrors EndpointDerivationGuardTest's own established precedent of
 * asserting a property of the shipped source when no runtime assertion in
 * this harness can reach it.
 */
class DelegationServiceTest extends TestCase
{
    private function source(): string
    {
        $path = __DIR__.'/../../src/Services/DelegationService.php';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    #[Test]
    public function agent_delegations_genuinely_has_no_created_at_column_which_is_why_the_guard_below_exists(): void
    {
        $columns = Schema::getColumnListing('agent_delegations');

        $this->assertNotEmpty($columns, 'fixture sanity: the agent_delegations schema must be defined for this test to mean anything');
        $this->assertNotContains(
            'created_at',
            $columns,
            'agent_delegations is a $timestamps = false table with its own started_at/completed_at (data-model.md §1) -- if a created_at column is ever added, this whole guard can be retired',
        );
        $this->assertContains('started_at', $columns);
    }

    #[Test]
    public function delegation_service_never_orders_a_query_by_a_column_the_table_does_not_have(): void
    {
        $source = $this->source();

        $this->assertSame(
            0,
            preg_match_all('/->latest\(\s*\)/', $source),
            'DelegationService must never call the argument-less latest(): it orders by created_at, a column agent_delegations does not have, which SQLite silently ignores and MySQL/MariaDB rejects outright',
        );

        preg_match_all('/->latest\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $source, $matches);

        $columns = Schema::getColumnListing('agent_delegations');

        foreach ($matches[1] as $column) {
            $this->assertContains(
                $column,
                $columns,
                "DelegationService orders by \"{$column}\", which is not a column on agent_delegations",
            );
        }

        $this->assertNotEmpty(
            $matches[1],
            'fixture sanity: the enclosing-delegation depth lookup (research.md D4) is expected to order explicitly -- if that lookup is ever rewritten, re-point this guard rather than deleting it',
        );
    }
}
