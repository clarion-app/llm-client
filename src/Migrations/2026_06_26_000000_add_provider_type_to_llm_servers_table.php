<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds provider_type column to llm_servers table with default 'openai'
     * for backward compatibility with existing records.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('llm_servers', 'provider_type')) {
            Schema::table('llm_servers', function (Blueprint $table) {
                $table->string('provider_type')
                    ->default('openai')
                    ->after('token');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('llm_servers', function (Blueprint $table) {
            $table->dropColumn('provider_type');
        });
    }
};
