<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The real leadership accounts of the group (group general manager + HR manager), each with an employee
 * record in the holding company. Idempotent: existing logins are left untouched.
 * Initial passwords are fixed (see ACCOUNTS); in production the first login must change them.
 */
class AccountsSeeder extends Seeder
{
    public const ACCOUNTS = [
        ['login' => 'imusliyar', 'password' => 'imusliyar@2026', 'email' => 'imusliyar@impactv.sa', 'name' => 'م. إلياس عبدالكريم', 'name_en' => 'Eng. Elias Abdulkarim', 'roles' => ['super'], 'job' => 'GGM', 'emp_no' => 'IVC-001', 'dept' => 'الإدارة العامة للمجموعة'],
        ['login' => 'Faisal', 'password' => 'Faisal@2026', 'email' => 'Faisal@impactb.sa', 'name' => 'أ. فيصل العنزي', 'name_en' => 'Faisal Alenezi', 'roles' => ['hr_mgr'], 'job' => 'HRM', 'emp_no' => 'IVC-002', 'dept' => 'الموارد البشرية'],
        ['login' => 'osama', 'password' => 'osama@2026', 'email' => 'finance@impactb.sa', 'name' => 'م. أسامة كمال إسماعيل أحمد', 'name_en' => 'Eng. Osama Kamal Ismail Ahmed', 'roles' => ['fin'], 'job' => 'CFO', 'emp_no' => 'IVC-003', 'dept' => 'الإدارة المالية'],
    ];

    public function run(): void
    {
        $holding = Company::where('is_holding', true)->first() ?? Company::first();
        $schedule = Schedule::first() ?? Schedule::create(['name' => 'الدوام القياسي 9–5 (أحد–خميس)', 'week_days' => [0, 1, 2, 3, 4], 'flex' => true, 'week_pattern' => '5',
            'hours' => ['0' => ['09:00', '17:00'], '1' => ['09:00', '17:00'], '2' => ['09:00', '17:00'], '3' => ['09:00', '17:00'], '4' => ['09:00', '15:00']]]);
        $jobs = [
            'GGM' => ['الرئيس التنفيذي للمجموعة', 'Group General Manager', 'قيادة'],
            'HRM' => ['مدير الموارد البشرية', 'HR Manager', 'موارد بشرية'],
            'CFO' => ['المدير المالي', 'Chief Financial Officer', 'مالية'],
        ];
        $gm = Employee::where('emp_no', self::ACCOUNTS[0]['emp_no'])->first(); // group GM (may already exist from an earlier run)

        foreach (self::ACCOUNTS as $a) {
            if (User::whereRaw('LOWER(login) = ?', [strtolower($a['login'])])->exists()) {
                continue;
            }
            [$jn, $je, $fam] = $jobs[$a['job']];
            $job = JobTitle::firstOrCreate(['code' => $a['job']], ['name' => $jn, 'name_en' => $je, 'family' => $fam, 'responsibilities' => [], 'kpis' => [], 'qualifications' => [], 'skills' => []]);
            $emp = Employee::where('emp_no', $a['emp_no'])->first() ?? Employee::create([
                'emp_no' => $a['emp_no'], 'name' => $a['name'], 'name_en' => $a['name_en'], 'company_id' => $holding?->id, 'job_title_id' => $job->id,
                'manager_id' => $a['job'] === 'GGM' ? null : $gm?->id, 'dept' => $a['dept'], 'hire_date' => now()->toDateString(), 'contract_type' => 'full',
                'punch_required' => false, 'schedule_id' => $schedule->id, 'week_pattern' => '5', 'nationality' => 'سعودي', 'gender' => 'm',
                'email' => $a['email'], 'email_enabled' => true, 'eosb_saudi' => true, 'service_companies' => $holding ? [$holding->id] : [],
            ]);
            if ($a['job'] === 'GGM') {
                $gm = $emp;
            }
            // Fixed initial passwords as agreed with the group; production forces a change at first login.
            User::create(['name' => $a['name'], 'login' => $a['login'], 'email' => $a['email'], 'password' => Hash::make($a['password']), 'employee_id' => $emp->id,
                'roles' => $a['roles'], 'active' => true, 'must_change_password' => app()->environment('production')]);
        }
    }
}
