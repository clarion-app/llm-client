<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * mcp_client_servers -- a user- or installation-configured connection
     * to a third-party MCP server. Bridged (EloquentMultiChainBridge)
     * like Server/RoleAssignment/CodingProject -- persistent, user-owned
     * configuration, not derived or frequently-changing execution data.
     */
    public function up(): void
    {
        if (Schema::hasTable('mcp_client_servers')) {
            return;
        }

        Schema::create('mcp_client_servers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('transport');
            $table->string('url')->nullable();
            $table->string('command')->nullable();
            $table->json('args')->nullable();
            $table->string('credential')->nullable();
            $table->uuid('user_id');
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcp_client_servers');
    }
};
