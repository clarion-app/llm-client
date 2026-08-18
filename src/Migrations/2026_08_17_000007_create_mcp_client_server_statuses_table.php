<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * mcp_client_server_statuses -- local-only reachability/refresh
     * bookkeeping for one mcp_client_servers row, one row per server,
     * upserted in place on every refresh. Not bridged, mirroring
     * ServerStatus's own precedent for this category of derived,
     * frequently-changing data.
     */
    public function up(): void
    {
        if (Schema::hasTable('mcp_client_server_statuses')) {
            return;
        }

        Schema::create('mcp_client_server_statuses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('server_id')->unique();
            $table->string('connection_status')->default('unknown');
            $table->text('last_error')->nullable();
            $table->integer('tool_count')->nullable();
            $table->timestamp('refresh_started_at')->nullable();
            $table->timestamp('refresh_finished_at')->nullable();
            $table->string('triggered_by')->nullable();
            $table->timestamps();

            $table->index('server_id');
            $table->foreign('server_id')
                ->references('id')
                ->on('mcp_client_servers')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcp_client_server_statuses');
    }
};
