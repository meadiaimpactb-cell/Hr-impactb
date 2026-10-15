<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\LeaveType;
use App\Models\Setting;
use App\Models\Tier;
use App\Services\Settings;
use Illuminate\Database\Seeder;

/**
 * Reference data for a real deployment: one default benefits tier, the holding company, policy defaults,
 * standard leave types and the leadership accounts (AccountsSeeder). No demo employees, attendance or requests.
 * Request types, setting definitions and lookups are seeded by the migrations themselves.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $year = now()->year;

        if (Tier::count() === 0) {
            Tier::create(['rank' => 1, 'name' => 'الباقة الأساسية', 'name_en' => 'Standard Tier', 'color' => '#1B82C3', 'is_default' => true, 'effective_from' => "$year-01-01",
                'benefits' => ['annualLeave' => 21, 'sick' => 30, 'emergency' => 5, 'maternity' => 70, 'marriage' => 5, 'bereavement' => 5, 'unpaidMax' => 30, 'carryOverMax' => 15,
                    'housingPct' => 25, 'transport' => 500, 'comm' => 0, 'weeklyHours' => 40, 'workDays' => 5, 'flexMinutes' => 60, 'remote' => false,
                    'devBudget' => 1000, 'programsPerMonth' => 1, 'insuranceClass' => 'C', 'dependents' => 0]]);
        }

        if (Company::count() === 0) {
            Company::create(['code' => 'IVC', 'name' => 'وادي الأثر المالية', 'name_en' => 'Impact Valley Capital', 'tier_id' => Tier::where('is_default', true)->value('id'), 'is_holding' => true]);
        }

        $defaults = [
            'graceMinutes' => 10, 'lateFactor' => 1, 'maxDeductionPct' => 25, 'closeDays' => 5, 'docAlertDays' => [90, 60, 30, 7],
            'advanceCapSalaries' => 2, 'advanceMaxInstallments' => 12, 'flexMinutes' => 60, 'dailyNoteMin' => 20, 'dailyNoteEnabled' => false,
            'noticeDaysMonthly' => 60, 'noticeDaysOther' => 30, 'objectionDays' => 7, 'maxAbsentPerTeam' => 2, 'leaveAdvanceAllowed' => false,
            'monthlyReportDueDay' => 5, 'weeklyReportEnabled' => true, 'eosbHalfYears' => 5, 'probationDays' => 90, 'accessLogEnabled' => true,
            'approvalFlows' => [], 'reqTypesOff' => [], 'biometricPunch' => true, 'selfieEnabled' => false, 'geoToleranceMeters' => 0,
        ];
        foreach ($defaults as $k => $v) {
            if (! Setting::where('key', $k)->exists()) {
                Setting::create(['key' => $k, 'value' => $v, 'effective_from' => "$year-01-01", 'reason' => 'الإعداد الأولي']);
            }
        }
        Settings::flush();

        foreach ([
            ['annual', 'سنوية', 'Annual', 'monthly', 'annualLeave', true, false, true, 'full', null, null],
            ['sick', 'مرضية', 'Sick', 'yearly', 'sick', true, true, false, 'tiered', null, null],
            ['emergency', 'اضطرارية', 'Emergency', 'yearly', 'emergency', true, false, false, 'full', 2, null],
            ['maternity', 'وضع', 'Maternity', 'case', 'maternity', false, true, false, 'policy', null, null],
            ['bereavement', 'وفاة', 'Bereavement', 'case', 'bereavement', false, true, false, 'full', null, null],
            ['marriage', 'زواج', 'Marriage', 'case', 'marriage', false, true, false, 'full', null, null],
            ['unpaid', 'بدون راتب', 'Unpaid', 'none', 'unpaidMax', false, false, false, 'none', null, ['hr']],
        ] as $i => [$code, $name, $en, $acc, $quota, $ded, $att, $carry, $pay, $max, $extra]) {
            LeaveType::firstOrCreate(['code' => $code], ['name' => $name, 'name_en' => $en, 'accrual' => $acc, 'quota_key' => $quota, 'deducts' => $ded,
                'attachment_required' => $att, 'carry_over' => $carry, 'pay' => $pay, 'max_per_case' => $max, 'extra_approval' => $extra, 'sort' => $i + 1]);
        }

        $this->call(ConfigSeeder::class);

        // Real leadership accounts (group GM + HR manager); the installer sets and shows their temporary passwords.
        $this->call(AccountsSeeder::class);
    }
}
