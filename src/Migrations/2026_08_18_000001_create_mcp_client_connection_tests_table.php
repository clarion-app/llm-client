<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * mcp_client_connection_tests -- ephemeral, credential-bearing scratch
     * state for a "test before saving" connection attempt (FR-003,
     * FR-004, FR-012). Deliberately carries no foreign key to
     * mcp_client_servers -- a row here is never a candidate for that
     * table by construction, and a saved server surviving this table's
     * own row being purged is exactly the point (D3). Purged on a short
     * retention cycle by the separate llm-client:purge-mcp-connection-tests
     * command, not by this migration.
     */
    public function up(): void
    {
        if (Schema::hasTable('mcp_client_connection_tests')) {
            return;
        }

        Schema::create('mcp_client_connection_tests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('transport');
            $table->string('url')->nullable();
            $table->string('command')->nullable();
            $table->json('args')->nullable();
            $table->text('credential')->nullable();
            $table->string('status')->default('pending');
            $table->string('failure_category')->nullable();
            $table->text('message')->nullable();
            $table->integer('tool_count')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcp_client_connection_tests');
    }
};
