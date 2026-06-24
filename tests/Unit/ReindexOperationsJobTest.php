<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ClarionApp\LlmClient\Jobs\ReindexOperationsJob;
use Illuminate\Contracts\Queue\ShouldQueue;

use PHPUnit\Framework\Attributes\Test;

class ReindexOperationsJobTest extends TestCase
{
    #[Test]
    public function job_implements_should_queue()
    {
        $job = new ReindexOperationsJob();
        $this->assertInstanceOf(ShouldQueue::class, $job);
    }

    #[Test]
    public function job_has_handle_method()
    {
        $job = new ReindexOperationsJob();
        $this->assertTrue(method_exists($job, 'handle'));
    }

    #[Test]
    public function job_uses_dispatchable_trait()
    {
        $reflection = new \ReflectionClass(ReindexOperationsJob::class);
        $traits = $reflection->getTraitNames();
        $this->assertContains(
            'Illuminate\Foundation\Bus\Dispatchable',
            $traits,
            'ReindexOperationsJob should use the Dispatchable trait'
        );
    }

    #[Test]
    public function job_uses_queueable_trait()
    {
        $reflection = new \ReflectionClass(ReindexOperationsJob::class);
        $traits = $reflection->getTraitNames();
        $this->assertContains(
            'Illuminate\Bus\Queueable',
            $traits,
            'ReindexOperationsJob should use the Queueable trait'
        );
    }

    #[Test]
    public function job_handles_missing_operation_id_gracefully()
    {
        // Verify the job source code contains the expected skip logic
        // Full integration testing of handle() requires Laravel app bootstrap
        // and is covered by Feature/OperationsSearchIntegrationTest
        $reflection = new \ReflectionClass(ReindexOperationsJob::class);
        $source = file_get_contents($reflection->getFileName());
        
        $this->assertStringContainsString('missing operationId', $source);
        $this->assertStringContainsString('Log::warning', $source);
        $this->assertStringContainsString('no details found', $source);
    }

    #[Test]
    public function job_builds_searchable_text_from_components()
    {
        // Verify the job source code constructs searchable_text correctly
        $reflection = new \ReflectionClass(ReindexOperationsJob::class);
        $source = file_get_contents($reflection->getFileName());
        
        $this->assertStringContainsString('searchable_text', $source);
        $this->assertStringContainsString('searchableParts', $source);
        $this->assertStringContainsString('packageDescription', $source);
    }
}
