<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('coding_projects', 'output_cap_override_bytes')) {
            Schema::table('coding_projects', function (Blueprint $table) {
                $table->integer('output_cap_override_bytes')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('coding_projects', function (Blueprint $table) {
            $table->dropColumn('output_cap_override_bytes');
        });
    }
};
