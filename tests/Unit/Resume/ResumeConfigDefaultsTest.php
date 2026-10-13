<?php

namespace Tests\Unit\Resume;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The llm-client.resume config block, pinned to data-model.md §7. Red now:
 * the block does not exist yet.
 */
class ResumeConfigDefaultsTest extends TestCase
{
    #[Test]
    public function the_resume_block_has_exactly_the_data_model_keys_and_defaults(): void
    {
        $this->assertSame([
            'enabled' => true,
            'detection_window_seconds' => 300,
            'helper_lease_seconds' => 30,
            'lease_grace_seconds' => 60,
            'max_consecutive_interruptions' => 3,
            'provider_retry' => [
                'delays_seconds' => [10, 30, 90, 180],
                'max_window_seconds' => 600,
            ],
            'stream_job_timeout_seconds' => 600,
            'queue' => 'default',
            'stream_queue' => 'http',
            'effect_free_tools' => [],
            'max_options_bytes' => 65536,
        ], config('llm-client.resume'), 'exactly the data model §7 keys and defaults');
    }
}
