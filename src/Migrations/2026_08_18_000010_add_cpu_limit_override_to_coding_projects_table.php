<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('coding_projects', 'cpu_limit_override')) {
            Schema::table('coding_projects', function (Blueprint $table) {
                // Deliberately a string column, never an integer/decimal --
                // mirrors command_cpu_limit's own (string) handling, since
                // Docker's --cpus accepts fractional values ("0.5") that a
                // numeric cast would corrupt or round.
                $table->string('cpu_limit_override')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('coding_projects', function (Blueprint $table) {
            $table->dropColumn('cpu_limit_override');
        });
    }
};
