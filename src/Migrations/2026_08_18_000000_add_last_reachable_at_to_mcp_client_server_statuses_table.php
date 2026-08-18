<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive nullable timestamp -- "when this server was last actually
     * known to have succeeded," distinct from refresh_finished_at (which
     * advances on every attempt, success or failure alike). No data
     * migration needed: null on every pre-existing row correctly means
     * "never known to have succeeded."
     */
    public function up(): void
    {
        if (!Schema::hasColumn('mcp_client_server_statuses', 'last_reachable_at')) {
            Schema::table('mcp_client_server_statuses', function (Blueprint $table) {
                $table->timestamp('last_reachable_at')->nullable()->after('refresh_finished_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('mcp_client_server_statuses', function (Blueprint $table) {
            $table->dropColumn('last_reachable_at');
        });
    }
};
