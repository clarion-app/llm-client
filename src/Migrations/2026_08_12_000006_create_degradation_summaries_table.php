<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('degradation_summaries')) {
            return;
        }

        Schema::create('degradation_summaries', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // 'user' | 'installation'.
            $table->string('entity_type');

            // The user id, or the installation sentinel
            // (SpendingCeiling::INSTALLATION_SCOPE_ID, reused rather than
            // redeclared).
            $table->uuid('entity_id');

            $table->unsignedInteger('degraded_response_count')->default(0);
            $table->timestamp('last_degraded_at')->nullable();

            // No created_at — matches context_management_summaries/
            // cost_summaries' own $timestamps = false-only-updated_at
            // shape exactly.
            $table->timestamp('updated_at')->useCurrent();

            // insertOrIgnore()'s target.
            $table->unique(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('degradation_summaries');
    }
};
