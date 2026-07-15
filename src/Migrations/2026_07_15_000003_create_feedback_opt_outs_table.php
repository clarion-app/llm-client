<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('feedback_opt_outs')) {
            return;
        }

        Schema::create('feedback_opt_outs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('pattern_key');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'pattern_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_opt_outs');
    }
};
