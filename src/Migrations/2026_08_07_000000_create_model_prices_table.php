<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('model_prices')) {
            return;
        }

        Schema::create('model_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider_type', 32);
            $table->string('model', 128);
            $table->decimal('reused_input_rate', 14, 8);
            $table->decimal('fresh_input_rate', 14, 8);
            $table->decimal('output_rate', 14, 8);
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['provider_type', 'model', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_prices');
    }
};
