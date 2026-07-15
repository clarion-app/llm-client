<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds nullable confidence_level column (INT, 0-100) to declarative_memories.
     * Backward compatible — existing rows get NULL.
     */
    public function up(): void
    {
        if (Schema::hasColumn('declarative_memories', 'confidence_level')) {
            return;
        }

        Schema::table('declarative_memories', function (Blueprint $table) {
            $table->integer('confidence_level')->nullable()->after('source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('declarative_memories', function (Blueprint $table) {
            $table->dropColumn('confidence_level');
        });
    }
};
