<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('coding_projects', 'time_limit_override_seconds')) {
            Schema::table('coding_projects', function (Blueprint $table) {
                $table->integer('time_limit_override_seconds')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('coding_projects', function (Blueprint $table) {
            $table->dropColumn('time_limit_override_seconds');
        });
    }
};
