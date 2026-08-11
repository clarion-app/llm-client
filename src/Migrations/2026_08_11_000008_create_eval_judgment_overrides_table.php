<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('eval_judgment_overrides')) {
            return;
        }

        Schema::create('eval_judgment_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The eval_judgments row being corrected. No FK (package
            // convention).
            $table->uuid('judgment_id');

            // The operator who made the override.
            $table->uuid('user_id');

            // Always populated, even when the operator only supplied a
            // new justification — defaulted at write time from the
            // judgment's then-current effective score, so this row is
            // always a complete, self-contained snapshot.
            $table->unsignedTinyInteger('score');
            $table->text('justification');

            // Insert-only — append-only correction, never a mutation of
            // the judgment or a prior override.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['judgment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eval_judgment_overrides');
    }
};
