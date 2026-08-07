<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('usage_records')) {
            return;
        }

        if (!Schema::hasColumn('usage_records', 'model_price_id')) {
            Schema::table('usage_records', function (Blueprint $table) {
                $table->uuid('model_price_id')->nullable()->after('co_member_tags');
                $table->decimal('reused_input_cost', 20, 10)->nullable()->after('model_price_id');
                $table->decimal('fresh_input_cost', 20, 10)->nullable()->after('reused_input_cost');
                $table->decimal('output_cost', 20, 10)->nullable()->after('fresh_input_cost');
                $table->decimal('total_cost', 20, 10)->nullable()->after('output_cost');
                $table->boolean('cost_unpriced')->default(false)->after('total_cost');
                $table->boolean('cost_estimated')->default(false)->after('cost_unpriced');
            });
        }
    }

    public function down(): void
    {
        Schema::table('usage_records', function (Blueprint $table) {
            $table->dropColumn([
                'model_price_id',
                'reused_input_cost',
                'fresh_input_cost',
                'output_cost',
                'total_cost',
                'cost_unpriced',
                'cost_estimated',
            ]);
        });
    }
};
