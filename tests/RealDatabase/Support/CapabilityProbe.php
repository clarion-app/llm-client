<?php

namespace Tests\RealDatabase\Support;

use PDO;
use PDOException;

/**
 * Functional capability probe for the resolved database.
 *
 * Executes real SQL to verify VECTOR type, VEC_DISTANCE_COSINE, and FULLTEXT support.
 * A version string alone is never evidence — MySQL 8.x reports a plausible version
 * but has none of these capabilities.
 */
class CapabilityProbe
{
    /**
     * Run the functional probe against a ConnectionSpec.
     *
     * Returns a CapabilityReport on success.
     * Throws RuntimeException on connection failure.
     */
    public function probe(ConnectionSpec $spec): CapabilityReport
    {
        $dsn = "mysql:host={$spec->host};port={$spec->port};dbname={$spec->database}";
        $pdo = null;

        try {
            $pdo = new PDO(
                $dsn,
                $spec->username,
                $spec->password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            throw new \RuntimeException(
                "Cannot connect to {$spec->host}:{$spec->port}/{$spec->database}: {$e->getMessage()}"
            );
        }

        // Server version.
        $serverVersion = $pdo->query('SELECT VERSION()')->fetchColumn();

        // Probe VECTOR type.
        $vectorType = $this->probeVectorType($pdo);

        // Probe VEC_DISTANCE_COSINE function.
        $vectorDistanceFunction = $this->probeVectorDistance($pdo);

        // Probe FULLTEXT index and MATCH AGAINST.
        $fulltextIndex = $this->probeFulltext($pdo);

        // InnoDB fulltext parameters.
        $minTokenSize = (int) $pdo->query('SELECT @@innodb_ft_min_token_size')->fetchColumn();
        $stopwordsEnabled = (bool) $pdo->query('SELECT @@innodb_ft_enable_stopword')->fetchColumn();

        return new CapabilityReport(
            serverVersion: $serverVersion,
            vectorType: $vectorType,
            vectorDistanceFunction: $vectorDistanceFunction,
            fulltextIndex: $fulltextIndex,
            minTokenSize: $minTokenSize,
            stopwordsEnabled: $stopwordsEnabled,
        );
    }

    /**
     * Probe VECTOR column type support.
     */
    private function probeVectorType(PDO $pdo): bool
    {
        try {
            $pdo->exec('DROP TABLE IF EXISTS _cap_probe_vector');
            $pdo->exec('CREATE TABLE _cap_probe_vector (id INT PRIMARY KEY, embedding VECTOR(4))');
            $pdo->exec('DROP TABLE _cap_probe_vector');
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * Probe VEC_DISTANCE_COSINE function support.
     */
    private function probeVectorDistance(PDO $pdo): bool
    {
        try {
            $pdo->exec('DROP TABLE IF EXISTS _cap_probe_vec_dist');
            $pdo->exec('CREATE TABLE _cap_probe_vec_dist (id INT PRIMARY KEY, embedding VECTOR(4))');
            $pdo->exec("INSERT INTO _cap_probe_vec_dist VALUES (1, VEC_FromText('[1,0,0,0]'))");
            $result = $pdo->query(
                "SELECT VEC_DISTANCE_COSINE(embedding, VEC_FromText('[1,0,0,0]')) FROM _cap_probe_vec_dist WHERE id = 1"
            )->fetchColumn();
            $pdo->exec('DROP TABLE _cap_probe_vec_dist');
            return is_numeric($result);
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * Probe FULLTEXT index and MATCH AGAINST support.
     */
    private function probeFulltext(PDO $pdo): bool
    {
        try {
            $pdo->exec('DROP TABLE IF EXISTS _cap_probe_fulltext');
            $pdo->exec('CREATE TABLE _cap_probe_fulltext (id INT PRIMARY KEY, content TEXT, FULLTEXT KEY ft_content (content))');
            $pdo->exec("INSERT INTO _cap_probe_fulltext VALUES (1, 'capability probe test content')");
            $result = $pdo->query(
                "SELECT COUNT(*) FROM _cap_probe_fulltext WHERE MATCH(content) AGAINST('capability' IN NATURAL LANGUAGE MODE)"
            )->fetchColumn();
            $pdo->exec('DROP TABLE _cap_probe_fulltext');
            return (int) $result > 0;
        } catch (PDOException) {
            return false;
        }
    }
}
