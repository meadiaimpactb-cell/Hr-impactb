<?php

namespace Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\LeaveLedger;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\Schedule;
use App\Models\Setting;
use App\Models\Tier;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\AttendanceService;
use App\Services\Settings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        mt_srand(7);
        $year = now()->year;

        // ---------- tiers ----------
        $base = ['sick' => 30, 'emergency' => 5, 'maternity' => 70, 'marriage' => 5, 'bereavement' => 5, 'unpaidMax' => 30, 'housingPct' => 25, 'weeklyHours' => 40, 'workDays' => 5, 'programsPerMonth' => 1];
        $t1 = Tier::create(['rank' => 1, 'name' => 'مسار الانطلاق', 'name_en' => 'Catalyst Tier', 'color' => '#708BC6', 'effective_from' => "$year-01-01",
            'benefits' => $base + ['annualLeave' => 21, 'carryOverMax' => 15, 'transport' => 500, 'comm' => 0, 'flexMinutes' => 60, 'remote' => false, 'devBudget' => 1000, 'insuranceClass' => 'C', 'dependents' => 0]]);
        $t2 = Tier::create(['rank' => 2, 'name' => 'مسار التمكين', 'name_en' => 'Momentum Tier', 'color' => '#1B82C3', 'is_default' => true, 'effective_from' => "$year-01-01",
            'benefits' => $base + ['annualLeave' => 25, 'carryOverMax' => 20, 'transport' => 800, 'comm' => 200, 'flexMinutes' => 60, 'remote' => true, 'devBudget' => 3000, 'insuranceClass' => 'B', 'dependents' => 2]]);
        $t3 = Tier::create(['rank' => 3, 'name' => 'مسار الأثر', 'name_en' => 'Impact Tier', 'color' => '#8677B6', 'effective_from' => "$year-01-01",
            'benefits' => $base + ['annualLeave' => 30, 'carryOverMax' => 30, 'transport' => 1000, 'comm' => 300, 'flexMinutes' => 120, 'remote' => true, 'devBudget' => 5000, 'insuranceClass' => 'A', 'dependents' => 4]]);

        // ---------- companies ----------
        $c = [];
        foreach ([
            ['IVC', 'وادي الأثر المالية', 'Impact Valley Capital', $t3, true],
            ['IRTH', 'إرث الحرف', 'Irth Craft', $t2, false],
            ['PTRIPS', 'الرحلات المهنية', 'Professional Trips', $t2, false],
            ['MUT', 'متمرس', 'Mutamarris', $t1, false],
            ['PFEL', 'الزمالات المهنية', 'Professional Fellowships', $t2, false],
            ['SPD', 'مركز تطوير العقار الاجتماعي', 'Social Property Development', $t1, false],
            ['THURA', 'ذُرى — منصة الوقف', 'Thura Waqf Platform', $t1, false],
        ] as $i => [$code, $name, $en, $tier, $holding]) {
            $c[$code] = Company::create(['code' => $code, 'name' => $name, 'name_en' => $en, 'tier_id' => $tier->id, 'is_holding' => $holding,
                'legal_no' => 'CR-10100000'.($i + 1), 'tax_no' => '300000000'.($i + 1), 'odoo_id' => (string) ($i + 1)]);
        }

        // ---------- policy settings ----------
        $settings = [
            'graceMinutes' => 10, 'lateFactor' => 1, 'maxDeductionPct' => 25, 'closeDays' => 5, 'docAlertDays' => [90, 60, 30, 7],
            'advanceCapSalaries' => 2, 'advanceMaxInstallments' => 12, 'flexMinutes' => 60, 'dailyNoteMin' => 20, 'dailyNoteEnabled' => false,
            'noticeDaysMonthly' => 60, 'noticeDaysOther' => 30, 'objectionDays' => 7, 'maxAbsentPerTeam' => 2, 'leaveAdvanceAllowed' => false,
            'monthlyReportDueDay' => 5, 'weeklyReportEnabled' => true, 'eosbHalfYears' => 5, 'probationDays' => 90, 'accessLogEnabled' => true,
            'approvalFlows' => [], 'reqTypesOff' => [],
        ];
        foreach ($settings as $k => $v) {
            Setting::create(['key' => $k, 'value' => $v, 'effective_from' => "$year-01-01", 'reason' => 'الإعداد الأولي']);
        }
        Settings::flush();

        // ---------- leave types ----------
        foreach ([
            ['annual', 'سنوية', 'Annual', 'monthly', 'annualLeave', true, false, true, 'full', null, null],
            ['sick', 'مرضية', 'Sick', 'yearly', 'sick', true, true, false, 'tiered', null, null],
            ['emergency', 'اضطرارية', 'Emergency', 'yearly', 'emergency', true, false, false, 'full', 2, null],
            ['maternity', 'وضع', 'Maternity', 'case', 'maternity', false, true, false, 'policy', null, null],
            ['bereavement', 'وفاة', 'Bereavement', 'case', 'bereavement', false, true, false, 'full', null, null],
            ['marriage', 'زواج', 'Marriage', 'case', 'marriage', false, true, false, 'full', null, null],
            ['unpaid', 'بدون راتب', 'Unpaid', 'none', 'unpaidMax', false, false, false, 'none', null, ['hr']],
        ] as $i => [$code, $name, $en, $acc, $quota, $ded, $att, $carry, $pay, $max, $extra]) {
            LeaveType::create(['code' => $code, 'name' => $name, 'name_en' => $en, 'accrual' => $acc, 'quota_key' => $quota, 'deducts' => $ded,
                'attachment_required' => $att, 'carry_over' => $carry, 'pay' => $pay, 'max_per_case' => $max, 'extra_approval' => $extra, 'sort' => $i + 1]);
        }

        // ---------- schedules & locations ----------
        $std = Schedule::create(['name' => 'الدوام القياسي 9–5 (أحد–خميس)', 'week_days' => [0, 1, 2, 3, 4], 'flex' => true, 'week_pattern' => '5',
            'hours' => ['0' => ['09:00', '17:00'], '1' => ['09:00', '17:00'], '2' => ['09:00', '17:00'], '3' => ['09:00', '17:00'], '4' => ['09:00', '15:00']]]);
        $six = Schedule::create(['name' => 'دوام ستة أيام (سبت–خميس)', 'week_days' => [6, 0, 1, 2, 3, 4], 'week_pattern' => '6',
            'hours' => ['6' => ['08:00', '15:00'], '0' => ['08:00', '16:00'], '1' => ['08:00', '16:00'], '2' => ['08:00', '16:00'], '3' => ['08:00', '16:00'], '4' => ['08:00', '14:00']]]);
        $hq = Location::create(['name' => 'المقر الرئيسي — الرياض', 'company_id' => $c['IVC']->id, 'lat' => 24.7136, 'lng' => 46.6753, 'radius' => 150]);
        $irthLoc = Location::create(['name' => 'بيت الحرفية — إرث الحرف', 'company_id' => $c['IRTH']->id, 'lat' => 24.6877, 'lng' => 46.7219, 'radius' => 100]);
        $city = Location::create(['name' => 'مدينة الرياض', 'lat' => 24.7136, 'lng' => 46.6753, 'radius' => 40000, 'scope' => 'city']);
        $open = Location::create(['name' => 'نطاق مفتوح (ميداني)', 'scope' => 'open', 'radius' => 0]);

        // ---------- job titles ----------
        $j = [];
        foreach ([
            ['CEO', 'الرئيس التنفيذي', 'Chief Executive Officer', 'قيادة', ['قيادة الشركة وتحقيق أهدافها السنوية', 'اعتماد الخطط والميزانيات'], ['تحقيق مستهدفات الإيرادات', 'رضا الشركاء']],
            ['HRM', 'مدير الموارد البشرية', 'HR Manager', 'موارد بشرية', ['إدارة دورة حياة الموظف', 'ضبط السياسات والصلاحيات'], ['إقفال الرواتب في موعده', 'متوسط زمن الاعتماد']],
            ['HRS', 'أخصائي موارد بشرية', 'HR Specialist', 'موارد بشرية', ['متابعة الحضور والإجازات', 'تحديث ملفات الموظفين'], ['دقة سجلات الحضور']],
            ['FIN', 'المدير المالي', 'Finance Manager', 'مالية', ['اعتماد السلف والتصفيات', 'مطابقة معاملات أودو'], ['مطابقة شهرية بلا فروق']],
            ['PM', 'مدير مشاريع', 'Project Manager', 'تشغيل', ['تخطيط المشاريع الربعية', 'إدارة العقبات والقرارات'], ['نسبة إنجاز المشاريع']],
            ['MKT', 'أخصائي تسويق', 'Marketing Specialist', 'تسويق', ['إدارة المحتوى والحملات'], ['معدل التفاعل']],
            ['ACC', 'محاسب', 'Accountant', 'مالية', ['القيود اليومية', 'تسوية العهد المالية'], ['إقفال شهري في موعده']],
            ['CRAFT', 'منسق حرفيين', 'Artisans Coordinator', 'تشغيل', ['التواصل مع الحرفيين'], ['عدد الحرفيين النشطين']],
            ['TRAIN', 'منسق برامج تدريبية', 'Training Coordinator', 'تشغيل', ['تنظيم الرحلات والبرامج'], ['رضا المشاركين']],
            ['WORK', 'عامل', 'Worker', 'تشغيل', ['تنفيذ المهام التشغيلية اليومية'], ['الالتزام بالحضور']],
            ['DEV', 'مطور برمجيات', 'Software Developer', 'تقنية', ['تطوير المنصات وصيانتها'], ['خلو الإصدار من الأخطاء الحرجة']],
        ] as [$code, $name, $en, $fam, $resp, $kpis]) {
            $j[$code] = JobTitle::create(['code' => $code, 'name' => $name, 'name_en' => $en, 'family' => $fam, 'responsibilities' => $resp, 'kpis' => $kpis,
                'qualifications' => ['مؤهل جامعي مناسب', 'خبرة سنتين فأكثر'], 'skills' => ['التواصل', 'التنظيم']]);
        }

        // ---------- employees ----------
        $E = [];
        $rows = [
            ['E001', 'عبدالله الحربي', 'Abdullah Alharbi', 'IVC', 'CEO', null, '2019-01-06', 'full', false, 35000, 'الرئاسة التنفيذية', 'm', [$hq]],
            ['E002', 'نورة القحطاني', 'Noura Alqahtani', 'IVC', 'HRM', 'E001', '2020-03-01', 'full', true, 18000, 'الموارد البشرية', 'f', [$hq]],
            ['E003', 'سلطان العنزي', 'Sultan Alenezi', 'IVC', 'HRS', 'E002', '2023-09-10', 'full', true, 9000, 'الموارد البشرية', 'm', [$hq]],
            ['E004', 'فهد الشمري', 'Fahad Alshammari', 'IRTH', 'CEO', 'E001', '2021-02-14', 'full', true, 25000, 'الرئاسة التنفيذية', 'm', [$irthLoc]],
            ['E005', 'منى الدوسري', 'Muna Aldosari', 'PTRIPS', 'CEO', 'E001', '2021-06-01', 'full', true, 24000, 'الرئاسة التنفيذية', 'f', [$hq]],
            ['E006', 'يوسف الزهراني', 'Yousef Alzahrani', 'IVC', 'FIN', 'E001', '2020-08-15', 'full', true, 20000, 'المالية', 'm', [$hq]],
            ['E007', 'ماجد المطيري', 'Majed Almutairi', 'IRTH', 'PM', 'E004', '2022-01-09', 'full', true, 14000, 'العمليات', 'm', [$irthLoc]],
            ['E008', 'أحمد الغامدي', 'Ahmed Alghamdi', 'IRTH', 'CRAFT', 'E007', '2022-05-22', 'full', true, 8500, 'العمليات', 'm', [$irthLoc]],
            ['E009', 'ريم السبيعي', 'Reem Alsubaie', 'IRTH', 'MKT', 'E007', '2023-02-05', 'full', true, 9500, 'التسويق', 'f', [$open]],
            ['E010', 'محمد رفيق', 'Mohammed Rafiq', 'IRTH', 'WORK', 'E007', '2018-11-01', 'full', true, 4500, 'العمليات', 'm', [$irthLoc]],
            ['E011', 'لمياء العمري', 'Lamia Alomari', 'PTRIPS', 'TRAIN', 'E005', '2022-09-18', 'full', true, 9000, 'البرامج', 'f', [$hq]],
            ['E012', 'دانة الشهري', 'Dana Alshehri', 'MUT', 'TRAIN', 'E005', '2024-01-14', 'full', true, 8000, 'البرامج', 'f', [$city]],
            ['E013', 'خالد باوزير', 'Khaled Bawazir', 'IVC', 'DEV', 'E002', '2023-04-02', 'full', true, 16000, 'التقنية', 'm', [$hq]],
            ['E014', 'سارة الجهني', 'Sara Aljohani', 'PFEL', 'ACC', 'E006', '2024-06-01', 'part', false, 6000, 'المالية', 'f', [$hq]],
            ['E015', 'عمر بن سعيد', 'Omar Bin Saeed', 'SPD', 'PM', 'E001', '2025-03-01', 'collab', false, 12000, 'العمليات', 'm', [$open]],
        ];
        foreach ($rows as [$no, $name, $en, $co, $job, $mgr, $hire, $ct, $punch, $basic, $dept, $g, $locs]) {
            $company = $c[$co];
            $e = Employee::create([
                'emp_no' => $no, 'name' => $name, 'name_en' => $en, 'company_id' => $company->id, 'job_title_id' => $j[$job]->id,
                'manager_id' => $mgr ? $E[$mgr]->id : null, 'dept' => $dept, 'hire_date' => $hire, 'contract_type' => $ct, 'part_ratio' => $ct === 'part' ? 0.5 : null,
                'punch_required' => $punch, 'schedule_id' => $no === 'E010' ? $six->id : $std->id, 'week_pattern' => $no === 'E010' ? '6' : '5',
                'nationality' => $no === 'E010' ? 'باكستاني' : ($no === 'E014' ? 'مصري' : 'سعودي'), 'gender' => $g,
                'email' => $no === 'E010' ? null : strtolower($no).'@impactv.sa', 'email_enabled' => $no !== 'E010', 'eosb_saudi' => $no !== 'E014',
                'phone' => '05'.mt_rand(10000000, 99999999), 'bank_iban' => 'SA0380000000608010167519', 'service_companies' => [$company->id],
            ]);
            $e->locations()->sync(collect($locs)->pluck('id'));
            $b = $company->tier->benefits;
            $e->components()->createMany(array_filter([
                ['key' => 'basic', 'name' => 'الأجر الأساسي', 'amount' => $basic, 'source' => 'contract', 'valid_from' => $hire],
                ['key' => 'housing', 'name' => 'بدل السكن', 'amount' => round($basic * $b['housingPct'] / 100, 2), 'source' => 'tier', 'valid_from' => $hire],
                ['key' => 'transport', 'name' => 'بدل النقل', 'amount' => $b['transport'], 'source' => 'tier', 'valid_from' => $hire],
                $b['comm'] ? ['key' => 'comm', 'name' => 'بدل الاتصال', 'amount' => $b['comm'], 'source' => 'tier', 'valid_from' => $hire] : null,
            ]));
            $e->update(['insurance' => ['class' => $b['insuranceClass'], 'dependents' => 0, 'card' => 'INS-'.$no]]);
            $e->career()->create(['type' => 'تعيين', 'detail' => $company->name.' · '.$j[$job]->name.' · '.Employee::CONTRACTS[$ct], 'occurred_on' => $hire]);
            $E[$no] = $e;
        }
        foreach (['IVC' => 'E001', 'IRTH' => 'E004', 'PTRIPS' => 'E005', 'MUT' => 'E005', 'PFEL' => 'E002', 'SPD' => 'E001', 'THURA' => 'E001'] as $co => $no) {
            $c[$co]->update(['ceo_employee_id' => $E[$no]->id]);
        }
        $E['E013']->update(['service_companies' => [$c['IVC']->id, $c['IRTH']->id, $c['PTRIPS']->id]]);

        // ---------- users (password: hc2026) ----------
        $pass = Hash::make('hc2026');
        $users = [
            ['E001', 'admin', ['super']], ['E002', 'hr', ['hr_mgr']], ['E004', 'ceo.irth', ['ceo'], 'IRTH'], ['E005', 'ceo.ptrips', ['ceo'], 'PTRIPS'],
            ['E006', 'finance', ['fin']], ['E007', 'majed', ['mgr']], ['E008', 'ahmed', ['emp']], ['E009', 'reem', ['emp']], ['E010', 'E010', ['emp']],
            ['E011', 'lamia', ['emp']], ['E012', 'dana', ['emp']], ['E013', 'khaled', ['emp']], ['E014', 'sara', ['emp']], ['E015', 'omar', ['emp']],
        ];
        $U = [];
        foreach ($users as $u) {
            $e = $E[$u[0]];
            $U[$u[0]] = User::create(['name' => $e->name, 'login' => $u[1], 'email' => $e->email, 'password' => $pass, 'employee_id' => $e->id, 'roles' => $u[2],
                'company_id' => isset($u[3]) ? $c[$u[3]]->id : null]);
        }
        $U['E003'] = User::create(['name' => $E['E003']->name, 'login' => 'hrstaff', 'email' => $E['E003']->email, 'password' => $pass, 'employee_id' => $E['E003']->id, 'roles' => ['hr_staff'],
            'perms' => ['companies' => [$c['IRTH']->id, $c['PTRIPS']->id, $c['MUT']->id], 'ops' => ['EMP' => ['view', 'add', 'edit'], 'ATT' => ['view', 'edit', 'approve'], 'LVE' => ['view', 'add', 'approve'], 'DOC' => ['view', 'add', 'edit']], 'limits' => ['LVE' => 5], 'valid_to' => null]]);

        // ---------- attendance: last 2 months + current ----------
        $start = now()->subMonths(2)->startOfMonth();
        $batch = [];
        foreach ($E as $e) {
            if (! $e->punch_required) {
                continue;
            }
            $e->load('schedule');
            for ($d = $start->copy(); $d->lt(now()->startOfDay()); $d->addDay()) {
                if (! $e->schedule->isWorkDay($d->dayOfWeek) || $d->lt($e->hire_date)) {
                    continue;
                }
                $r = mt_rand() / mt_getrandmax();
                if ($r < 0.04) {
                    continue; // absent (no record)
                }
                [$from, $to] = $e->schedule->hoursFor($d->dayOfWeek);
                $late = $r < 0.25 ? mt_rand(11, 45) : ($r < 0.4 ? mt_rand(1, 9) : 0);
                $in = Carbon::parse($d->toDateString().' '.$from)->addMinutes($late);
                $out = Carbon::parse($d->toDateString().' '.$to)->addMinutes(mt_rand(0, 20));
                $batch[] = ['employee_id' => $e->id, 'work_date' => $d->toDateString(), 'status' => 'present', 'check_in' => $in, 'check_out' => $out,
                    'late_minutes' => AttendanceService::lateMinutes($e, $in), 'early_minutes' => 0, 'location_id' => $e->locations()->value('locations.id'),
                    'accuracy' => mt_rand(5, 30), 'device' => 'iPhone 15 · iOS 18', 'note' => $r > 0.6 ? 'متابعة المهام اليومية والتنسيق مع الفريق وإغلاق طلبات العملاء' : null,
                    'created_at' => now(), 'updated_at' => now()];
            }
        }
        foreach (array_chunk($batch, 500) as $chunk) {
            AttendanceRecord::insert($chunk);
        }

        // ---------- leave ledger opening balances ----------
        foreach ([['E008', 4], ['E010', 11], ['E011', 5]] as [$no, $days]) {
            LeaveLedger::create(['employee_id' => $E[$no]->id, 'year' => $year, 'leave_type' => 'annual', 'entry' => 'opening', 'days' => $days, 'note' => 'رصيد افتتاحي مرحّل', 'occurred_on' => "$year-01-01"]);
        }

        // ---------- sample requests (as the requesting employee) ----------
        $as = function (string $no, string $type, array $data) use ($U, $E) {
            auth()->setUser($U[$no]);

            return ApprovalService::submit($E[$no]->fresh(), $type, $data);
        };
        $f = now()->addDays(10)->toDateString();
        $t = now()->addDays(13)->toDateString();
        $as('E009', 'leave', ['type' => 'annual', 'type_name' => 'سنوية', 'from' => $f, 'to' => $t, 'days' => 4, 'substitute_id' => $E['E008']->id, 'delegate' => false, 'handover' => 'جدولة منشورات الأسبوع']);
        $as('E011', 'leave', ['type' => 'sick', 'type_name' => 'مرضية', 'from' => now()->toDateString(), 'to' => now()->addDay()->toDateString(), 'days' => 2, 'handover' => null]);
        $as('E008', 'advance', ['amount' => 6000, 'installments' => 6, 'purpose' => 'ظرف عائلي', 'monthly' => 1000]);
        $as('E012', 'correction', ['date' => now()->subDays(2)->toDateString(), 'kind' => 'نسيان تسجيل الانصراف', 'time' => '17:00', 'reason' => 'خرجت الساعة 5 ونسيت التسجيل']);
        $as('E008', 'letter', ['kind' => 'تعريف بالراتب', 'to' => 'بنك الراجحي', 'lang' => 'ar']);
        $old = $as('E008', 'leave', ['type' => 'annual', 'type_name' => 'سنوية', 'from' => "$year-03-10", 'to' => "$year-03-14", 'days' => 5, 'handover' => 'متابعة طلبات الحرفيين']);
        ApprovalService::decide($old->load('employee'), 'approve', null, $U['E007']);

        $this->call(CustodySeeder::class);
        $this->call(ConfigSeeder::class);
    }
}
