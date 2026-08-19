<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('coding_projects', 'memory_limit_override_mb')) {
            Schema::table('coding_projects', function (Blueprint $table) {
                $table->integer('memory_limit_override_mb')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('coding_projects', function (Blueprint $table) {
            $table->dropColumn('memory_limit_override_mb');
        });
    }
};
