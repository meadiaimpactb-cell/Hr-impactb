<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Payroll approval: ... HR manager → finance manager → general manager (group CEO). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payroll_runs', 'fin_approved_by')) {
            Schema::table('payroll_runs', fn (Blueprint $t) => $t->unsignedBigInteger('fin_approved_by')->nullable()->after('hr_approved_by'));
        }
    }

    public function down(): void
    {
        Schema::table('payroll_runs', fn (Blueprint $t) => $t->dropColumn('fin_approved_by'));
    }
};
