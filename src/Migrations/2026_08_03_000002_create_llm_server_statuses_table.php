<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('llm_server_statuses')) {
            return;
        }

        Schema::create('llm_server_statuses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('server_id')->unique();
            $table->string('connection_status')->default('never_checked');
            $table->string('last_outcome')->nullable();
            $table->text('last_error')->nullable();
            $table->integer('model_count')->default(0);
            $table->timestamp('refresh_started_at')->nullable();
            $table->timestamp('refresh_finished_at')->nullable();
            $table->uuid('triggered_by')->nullable();
            $table->timestamps();

            $table->index('server_id');
            $table->foreign('server_id')
                ->references('id')
                ->on('llm_servers')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_server_statuses');
    }
};
