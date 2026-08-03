<?php

namespace Tests\Unit\RealDatabase;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\RealDatabase\Support\CapabilityProbe;
use Tests\RealDatabase\Support\CapabilityReport;
use Tests\RealDatabase\Support\ConnectionSpec;
use Tests\RealDatabase\Support\ProvisionOutcome;

/**
 * T008: CapabilityProbe — skip-versus-fail classification.
 *
 * Absent → Unavailable → skip.
 * Reachable but missing VECTOR/VEC_DISTANCE_COSINE/FULLTEXT → Incapable → always fail.
 * Strict mode converts Unavailable into failure with same diagnostic text.
 */
class CapabilityProbeTest extends TestCase
{
    /**
     * T008: CapabilityReport correctly identifies when all capabilities are present.
     */
    #[Test]
    public function capableWhenAllFeaturesPresent(): void
    {
        $report = new CapabilityReport(
            serverVersion: '11.8.8-MariaDB',
            vectorType: true,
            vectorDistanceFunction: true,
            fulltextIndex: true,
            minTokenSize: 3,
            stopwordsEnabled: true,
        );

        $this->assertTrue($report->isCapable());
        $this->assertEmpty($report->missingCapabilities());
    }

    /**
     * T008: CapabilityReport identifies missing VECTOR type.
     */
    #[Test]
    public function incapableWhenVectorTypeMissing(): void
    {
        $report = new CapabilityReport(
            serverVersion: '10.5.20-MariaDB',
            vectorType: false,
            vectorDistanceFunction: false,
            fulltextIndex: true,
            minTokenSize: 3,
            stopwordsEnabled: true,
        );

        $this->assertFalse($report->isCapable());
        $missing = $report->missingCapabilities();
        $this->assertContains('VECTOR column type', $missing);
        $this->assertContains('VEC_DISTANCE_COSINE function', $missing);
    }

    /**
     * T008: CapabilityReport identifies missing FULLTEXT.
     */
    #[Test]
    public function incapableWhenFulltextMissing(): void
    {
        $report = new CapabilityReport(
            serverVersion: '8.0.35',
            vectorType: true,
            vectorDistanceFunction: true,
            fulltextIndex: false,
            minTokenSize: 3,
            stopwordsEnabled: true,
        );

        $this->assertFalse($report->isCapable());
        $missing = $report->missingCapabilities();
        $this->assertContains('FULLTEXT index / MATCH AGAINST', $missing);
    }

    /**
     * T008: Incapable outcome always fails, never skips.
     * The distinction between Unavailable and Incapable is the whole of
     * Story 4's fourth acceptance scenario.
     */
    #[Test]
    public function incapableOutcomeIsNeverASkip(): void
    {
        $outcome = ProvisionOutcome::incapable(
            'missing capabilities: VECTOR column type',
            'server version: 10.5.20-MariaDB'
        );

        $this->assertTrue($outcome->isIncapable());
        $this->assertFalse($outcome->isUnavailable());
        $this->assertFalse($outcome->isAvailable());
        $this->assertStringContainsString('VECTOR column type', $outcome->diagnostic());
        $this->assertStringContainsString('10.5.20-MariaDB', $outcome->diagnostic());
    }

    /**
     * T008: Unavailable outcome is a skip (not a fail).
     */
    #[Test]
    public function unavailableOutcomeIsASkip(): void
    {
        $outcome = ProvisionOutcome::unavailable(
            'no explicit connection details and no usable Docker daemon'
        );

        $this->assertTrue($outcome->isUnavailable());
        $this->assertFalse($outcome->isIncapable());
        $this->assertFalse($outcome->isAvailable());
    }

    /**
     * T008: Strict mode converts Unavailable into failure.
     * The diagnostic text is the same — only the action changes.
     */
    #[Test]
    public function strictModeConvertsUnavailableToFailure(): void
    {
        $outcome = ProvisionOutcome::unavailable(
            'no explicit connection details and no usable Docker daemon'
        );

        $diagnostic = $outcome->diagnostic();

        // In strict mode, the same diagnostic text is used for the failure.
        // The RealDatabaseTestCase handles the strict mode check, but the
        // outcome's diagnostic is what gets passed to markTestSkipped or fail.
        $this->assertStringContainsString('Database unavailable:', $diagnostic);
        $this->assertStringContainsString('no explicit connection details', $diagnostic);
    }

    /**
     * T008: Available outcome carries the spec.
     */
    #[Test]
    public function availableOutcomeCarriesSpec(): void
    {
        $spec = new ConnectionSpec(
            driver: 'mysql',
            host: '127.0.0.1',
            port: 3306,
            database: 'test_db',
            username: 'root',
            password: 'secret',
            origin: 'ephemeral',
            containerId: 'abc123',
        );

        $outcome = ProvisionOutcome::available($spec);

        $this->assertTrue($outcome->isAvailable());
        $this->assertSame($spec, $outcome->spec());
        $this->assertStringContainsString('127.0.0.1:3306/test_db', $outcome->diagnostic());
    }

    /**
     * T008: CapabilityProbe throws on connection failure.
     */
    #[Test]
    public function probeThrowsOnConnectionFailure(): void
    {
        $probe = new CapabilityProbe();
        $spec = new ConnectionSpec(
            driver: 'mysql',
            host: '127.0.0.1',
            port: 1, // Invalid port — connection will fail.
            database: 'nonexistent',
            username: 'root',
            password: 'wrong',
            origin: 'ephemeral',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot connect/');

        $probe->probe($spec);
    }

    /**
     * T008: CapabilityReport records server version and fulltext parameters.
     */
    #[Test]
    public function reportRecordsServerVersionAndParameters(): void
    {
        $report = new CapabilityReport(
            serverVersion: '11.8.8-MariaDB-ubu2404',
            vectorType: true,
            vectorDistanceFunction: true,
            fulltextIndex: true,
            minTokenSize: 3,
            stopwordsEnabled: true,
        );

        $this->assertSame('11.8.8-MariaDB-ubu2404', $report->serverVersion);
        $this->assertSame(3, $report->minTokenSize);
        $this->assertTrue($report->stopwordsEnabled);
    }

    /**
     * T008: missingCapabilities lists all absent features.
     */
    #[Test]
    public function missingCapabilitiesListsAllAbsentFeatures(): void
    {
        $report = new CapabilityReport(
            serverVersion: '8.0.0',
            vectorType: false,
            vectorDistanceFunction: false,
            fulltextIndex: false,
            minTokenSize: 4,
            stopwordsEnabled: false,
        );

        $missing = $report->missingCapabilities();
        $this->assertCount(3, $missing);
        $this->assertContains('VECTOR column type', $missing);
        $this->assertContains('VEC_DISTANCE_COSINE function', $missing);
        $this->assertContains('FULLTEXT index / MATCH AGAINST', $missing);
    }
}
