<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * mcp_client_tools -- a cached snapshot of one tool a configured
     * mcp_client_servers row currently offers. Not bridged, matching
     * operation_search_index's own precedent: a rebuildable index over an
     * external source of truth, never itself the source of truth.
     *
     * synthetic_operation_id ("mcp:{server_id}:{name}") is unique by
     * construction, so two servers offering identically-named tools are
     * two distinct rows from the moment either is cached -- no additional
     * collision handling is required.
     */
    public function up(): void
    {
        if (Schema::hasTable('mcp_client_tools')) {
            return;
        }

        Schema::create('mcp_client_tools', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('server_id');
            $table->string('synthetic_operation_id')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('input_schema');
            $table->json('annotations')->nullable();
            $table->timestamp('last_seen_at');
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
        Schema::dropIfExists('mcp_client_tools');
    }
};
