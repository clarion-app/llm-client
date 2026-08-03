<?php

namespace Tests\RealDatabase\Support;

use PDO;
use PDOException;

/**
 * Isolation guard — Constitution §V verification before migrations.
 *
 * Returns IsolationVerdict; can only permit or refuse.
 * Refusal aborts with a message naming the resolved host, port, and database.
 * There is no bypass flag.
 */
class IsolationGuard
{
    /**
     * Run the isolation guard against a ConnectionSpec.
     *
     * Returns IsolationVerdict::isolated() if all checks pass.
     * Returns IsolationVerdict::refused() with a diagnostic message if any check fails.
     */
    public function check(ConnectionSpec $spec, string $expectedDatabase): IsolationVerdict
    {
        $checks = [];
        $refusals = [];

        // Check 1: Driver must be mysql.
        $driverOk = $spec->driver === 'mysql';
        $checks['driver_is_mysql'] = $driverOk;
        if (!$driverOk) {
            $refusals[] = "driver is '{$spec->driver}', expected 'mysql'";
        }

        // Check 2: Database name must match the expected one (generated or opted-in supplied).
        $nameOk = $spec->database === $expectedDatabase;
        $checks['database_name_matches'] = $nameOk;
        if (!$nameOk) {
            $refusals[] = "database name '{$spec->database}' does not match expected '{$expectedDatabase}'";
        }

        // Check 3: A supplied instance carries the opt-in flag. The provisioner
        // enforces this too; the guard repeats it because it is the last thing
        // standing between a run and somebody's database.
        $optInOk = $spec->origin !== 'supplied' || $this->suppliedOptInPresent();
        $checks['supplied_opt_in_present'] = $optInOk;
        if (!$optInOk) {
            $refusals[] = 'supplied instance without LLM_CLIENT_REAL_DB_ALLOW_SUPPLIED';
        }

        // Check 4: Schema must be empty (no tables the harness did not create).
        $schemaEmpty = $this->checkSchemaEmpty($spec);
        $checks['schema_is_empty'] = $schemaEmpty;
        if (!$schemaEmpty) {
            $refusals[] = 'schema contains pre-existing tables';
        }

        if (!empty($refusals)) {
            $message = $this->buildRefusalMessage($spec, $refusals);
            return IsolationVerdict::refused($message, $checks);
        }

        return IsolationVerdict::isolated();
    }

    private function suppliedOptInPresent(): bool
    {
        $flag = getenv('LLM_CLIENT_REAL_DB_ALLOW_SUPPLIED');

        return $flag === '1' || $flag === 'true';
    }

    /**
     * Check that the schema is empty (no tables exist).
     */
    private function checkSchemaEmpty(ConnectionSpec $spec): bool
    {
        try {
            $dsn = "mysql:host={$spec->host};port={$spec->port};dbname={$spec->database}";
            $pdo = new PDO(
                $dsn,
                $spec->username,
                $spec->password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $tables = $pdo->query(
                "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES "
                . "WHERE TABLE_SCHEMA = '{$spec->database}' "
                . "AND TABLE_TYPE = 'BASE TABLE'"
            )->fetchAll(PDO::FETCH_COLUMN);

            return empty($tables);
        } catch (PDOException) {
            // If we can't connect, the schema check is inconclusive — refuse.
            return false;
        }
    }

    /**
     * Build the refusal message naming host, port, and database.
     */
    private function buildRefusalMessage(ConnectionSpec $spec, array $refusals): string
    {
        $refusalList = implode('; ', $refusals);
        return "Isolation guard refused for {$spec->host}:{$spec->port}/{$spec->database}: {$refusalList}";
    }
}
