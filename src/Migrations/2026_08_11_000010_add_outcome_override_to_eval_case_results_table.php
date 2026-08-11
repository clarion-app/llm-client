<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('eval_case_results') && !Schema::hasColumn('eval_case_results', 'outcome_override')) {
            Schema::table('eval_case_results', function (Blueprint $table) {
                // Written only by the override service the moment an
                // override on one of this result's judgments is recorded.
                // outcome/expectation_results/every other column on this
                // row remains exactly as originally written — never
                // touched again. Readers of a case's "current" outcome
                // read COALESCE(outcome_override, outcome), never
                // outcome alone.
                $table->string('outcome_override', 20)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('eval_case_results') && Schema::hasColumn('eval_case_results', 'outcome_override')) {
            Schema::table('eval_case_results', function (Blueprint $table) {
                $table->dropColumn('outcome_override');
            });
        }
    }
};
