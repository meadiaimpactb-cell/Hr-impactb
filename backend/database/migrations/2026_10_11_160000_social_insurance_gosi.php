<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Saudi social insurance (GOSI) in payroll, on the contributory wage (basic + housing, capped):
 *  - non-Saudis: employer 2% (occupational hazards), nothing deducted from the employee;
 *  - Saudis on the old system (joined before 3 July 2024 / with earlier contributions): employee 9.75%, employer 11.75%;
 *  - Saudis on the new system (first subscription from 3 July 2024): annuity share rises 0.5% a year each July
 *    from 2025 to 2028 for both sides (employee 9.75 → 11.75%, employer 11.75 → 13.75%).
 * Rates live in the editable setting "gosiRates"; each employee can be forced to old / new / exempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employees', 'gosi_scheme')) {
            Schema::table('employees', fn (Blueprint $t) => $t->string('gosi_scheme', 10)->nullable()->after('eosb_saudi')); // null = automatic
        }
        DB::table('setting_definitions')->updateOrInsert(['key' => 'gosiRates'], ['label' => 'نسب التأمينات الاجتماعية (GOSI)', 'group' => 'الرواتب', 'type' => 'json', 'builtin' => 1, 'sort' => 405,
            'description' => 'الأجر الخاضع = الأساسي + السكن بحد أعلى wage_cap. old: النظام القديم، expat: غير السعوديين، new: جدول النظام الجديد بتاريخ سريان كل نسبة', 'created_at' => now(), 'updated_at' => now()]);
        if (! DB::table('settings')->where('key', 'gosiRates')->exists()) {
            \App\Services\Settings::set('gosiRates', [
                'enabled' => true,
                'wage_cap' => 45000,
                'new_system_from' => '2024-07-03',
                'old' => ['employee' => 9.75, 'employer' => 11.75],
                'expat' => ['employee' => 0, 'employer' => 2],
                'new' => [
                    ['from' => '2024-07-03', 'employee' => 9.75, 'employer' => 11.75],
                    ['from' => '2025-07-01', 'employee' => 10.25, 'employer' => 12.25],
                    ['from' => '2026-07-01', 'employee' => 10.75, 'employer' => 12.75],
                    ['from' => '2027-07-01', 'employee' => 11.25, 'employer' => 13.25],
                    ['from' => '2028-07-01', 'employee' => 11.75, 'employer' => 13.75],
                ],
            ], null, 'إعداد التأمينات الاجتماعية حسب نظام التأمينات وقرار الزيادة التدريجية');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employees', 'gosi_scheme')) {
            Schema::table('employees', fn (Blueprint $t) => $t->dropColumn('gosi_scheme'));
        }
    }
};
