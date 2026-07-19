<?php

namespace Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Integration\Harness\DegradationLedger;

class DegradationLedgerTest extends TestCase
{
    /* ------------------------------------------------------------------ */
    /*  T015: DegradationLedger unit tests                                 */
    /* ------------------------------------------------------------------ */

    public function test_undeclared_event_fails_reconciliation()
    {
        $ledger = new DegradationLedger();
        $ledger->observeEvent('embedding_generation_failed:timeout');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/undeclared.*degradation|unexpected.*observation/i');

        $ledger->reconcile('test_scenario', 'sync');
    }

    public function test_declared_event_passes_reconciliation()
    {
        $ledger = new DegradationLedger();
        $ledger->expect('embedding_generation_failed:*');
        $ledger->observeEvent('embedding_generation_failed:timeout');

        // Should not throw
        $ledger->reconcile('test_scenario', 'sync');
        $this->assertTrue(true);
    }

    public function test_declared_but_absent_fails_reconciliation()
    {
        $ledger = new DegradationLedger();
        $ledger->expect('embedding_generation_failed:*');
        // No event observed

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/expected.*degradation.*not.*occur|declared.*unobserved/i');

        $ledger->reconcile('test_scenario', 'sync');
    }

    public function test_prefix_wildcard_matches()
    {
        $ledger = new DegradationLedger();
        $ledger->expect('embedding_generation_failed:*');
        $ledger->observeEvent('embedding_generation_failed:timeout');

        // Should not throw
        $ledger->reconcile('test_scenario', 'sync');
        $this->assertTrue(true);
    }

    public function test_prefix_wildcard_matches_various_suffixes()
    {
        $ledger = new DegradationLedger();
        $ledger->expect('embedding_generation_failed:*');
        $ledger->observeEvent('embedding_generation_failed:connection_refused');
        $ledger->observeEvent('embedding_generation_failed:sql_error');

        // Should not throw
        $ledger->reconcile('test_scenario', 'sync');
        $this->assertTrue(true);
    }

    public function test_structured_event_preferred_over_log_text()
    {
        $ledger = new DegradationLedger();
        $ledger->expect('embedding_generation_failed:*');

        // Both a structured event and a log entry exist
        $ledger->observeEvent('embedding_generation_failed:timeout');
        $ledger->observeLog('warning', 'Embedding generation failed: timeout', ['component' => 'AutoMemoryRetriever']);

        // Should pass - the structured event matches the declaration
        $ledger->reconcile('test_scenario', 'sync');
        $this->assertTrue(true);
    }

    public function test_failure_message_contains_component_and_signal()
    {
        $ledger = new DegradationLedger();
        $ledger->observeEvent('embedding_generation_failed:timeout');

        try {
            $ledger->reconcile('test_scenario', 'sync');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('embedding_generation_failed:timeout', $message);
            $this->assertStringContainsString('test_scenario', $message);
            $this->assertStringContainsString('sync', $message);
        }
    }

    public function test_failure_message_contains_remediation_line()
    {
        $ledger = new DegradationLedger();
        $ledger->observeEvent('embedding_generation_failed:timeout');

        try {
            $ledger->reconcile('test_scenario', 'sync');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            // Should contain the expect() call as remediation
            $this->assertStringContainsString('expect(', $message);
        }
    }

    public function test_no_declarations_and_no_observations_passes()
    {
        $ledger = new DegradationLedger();

        // Should not throw - clean run expected
        $ledger->reconcile('test_scenario', 'sync');
        $this->assertTrue(true);
    }

    public function test_multiple_declarations_and_observations()
    {
        $ledger = new DegradationLedger();
        $ledger->expect('embedding_generation_failed:*');
        $ledger->expect('declarative_retrieval_failed:*');

        $ledger->observeEvent('embedding_generation_failed:timeout');
        $ledger->observeEvent('declarative_retrieval_failed:connection_error');

        // Should not throw
        $ledger->reconcile('test_scenario', 'sync');
        $this->assertTrue(true);
    }

    public function test_declared_exact_match_without_wildcard()
    {
        $ledger = new DegradationLedger();
        $ledger->expect('episodic_skipped_budget_exhausted');
        $ledger->observeEvent('episodic_skipped_budget_exhausted');

        // Should not throw - exact match
        $ledger->reconcile('test_scenario', 'sync');
        $this->assertTrue(true);
    }

    public function test_wildcard_does_not_match_partial_prefix()
    {
        $ledger = new DegradationLedger();
        $ledger->expect('embedding_generation_failed:*');

        // This event does NOT match the wildcard pattern
        $ledger->observeEvent('declarative_retrieval_failed:timeout');

        $this->expectException(\RuntimeException::class);
        $ledger->reconcile('test_scenario', 'sync');
    }

    public function test_log_observation_matching()
    {
        $ledger = new DegradationLedger();
        $ledger->expect('context_management_failed:*');

        // Log entry with matching prefix
        $ledger->observeLog('warning', 'context_management_failed:condenser_error', ['component' => 'ConversationCondenser']);

        // Should pass - log entry matches declaration
        $ledger->reconcile('test_scenario', 'sync');
        $this->assertTrue(true);
    }

    public function test_info_logs_are_ignored()
    {
        $ledger = new DegradationLedger();
        // Info logs should not be captured as degradation signals
        $ledger->observeLog('info', 'Some info message', []);

        // Should pass - info logs are ignored
        $ledger->reconcile('test_scenario', 'sync');
        $this->assertTrue(true);
    }

    public function test_debug_logs_are_ignored()
    {
        $ledger = new DegradationLedger();
        $ledger->observeLog('debug', 'Some debug message', []);

        // Should pass - debug logs are ignored
        $ledger->reconcile('test_scenario', 'sync');
        $this->assertTrue(true);
    }
}
