<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_run_export_queue')) {
            return;
        }

        Schema::create('agent_run_export_queue', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Not FK-enforced: a run can age out of agent_runs while its
            // forwarding row is still queued, and that is an ordinary discard,
            // not an integrity violation.
            $table->uuid('run_id');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('last_error', 512)->nullable();
            $table->timestamp('created_at');

            $table->index('run_id');
            $table->index('next_attempt_at');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_run_export_queue');
    }
};
