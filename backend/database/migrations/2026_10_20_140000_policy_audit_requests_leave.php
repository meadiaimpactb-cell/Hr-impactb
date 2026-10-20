<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Policy 2.1 audit — requests, approvals, leave, holidays, job levels.
 *  · approval stage timestamps (§12: 3 working days per approver), delegation company and cap (§11)
 *  · employee job level 1–5 (§5, §63, §67), religion (iddah §59), probation (§20–21), verified IBAN (§38)
 *  · official holidays (§58) excluded from leave counts
 *  · request types of appendix 5 that were missing: tickets, ticket carry-over, extra children, leave deferral, overtime,
 *    flexible / remote arrangement, IBAN change, expense claim, probation decision and shortening, promotion / acting, transfer
 *  · flows corrected: allowance / salary change (manager → finance → group CEO)
 *  · special leaves (§59–60): sick 30 + 60 at 75% + 30 unpaid, hajj, iddah, maternity extensions
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('approval_requests', 'stage_entered_at')) {
            Schema::table('approval_requests', function (Blueprint $t) {
                $t->timestamp('stage_entered_at')->nullable();
            });
            DB::table('approval_requests')->update(['stage_entered_at' => DB::raw('updated_at')]);
        }
        if (! Schema::hasColumn('delegations', 'company_id')) {
            Schema::table('delegations', function (Blueprint $t) {
                $t->unsignedBigInteger('company_id')->nullable();
                $t->decimal('amount_cap', 12, 2)->nullable();
            });
        }
        Schema::table('employees', function (Blueprint $t) {
            if (! Schema::hasColumn('employees', 'job_level')) {
                $t->unsignedTinyInteger('job_level')->nullable()->comment('1 CEO · 2 leaders/directors · 3 section & project managers · 4 specialists/coordinators/professionals · 5 operations & support');
            }
            if (! Schema::hasColumn('employees', 'religion')) {
                $t->string('religion', 16)->nullable();
            }
            if (! Schema::hasColumn('employees', 'probation_days')) {
                $t->unsignedSmallInteger('probation_days')->nullable();
                $t->string('probation_status', 16)->nullable();
                $t->date('confirmed_at')->nullable();
            }
            if (! Schema::hasColumn('employees', 'iban_verified_at')) {
                $t->timestamp('iban_verified_at')->nullable();
            }
        });
        // existing IBANs were entered by HR from the bank certificate
        DB::table('employees')->whereNotNull('bank_iban')->whereNull('iban_verified_at')->update(['iban_verified_at' => now()]);
        // job level from the current role data: company CEOs 1, managers with reports 3, everyone else 4
        $ceos = DB::table('companies')->whereNotNull('ceo_employee_id')->pluck('ceo_employee_id')->all();
        DB::table('employees')->whereNull('job_level')->whereIn('id', $ceos ?: [0])->update(['job_level' => 1]);
        $mgrs = DB::table('employees')->whereNotNull('manager_id')->distinct()->pluck('manager_id')->all();
        DB::table('employees')->whereNull('job_level')->whereIn('id', $mgrs ?: [0])->update(['job_level' => 3]);
        DB::table('employees')->whereNull('job_level')->update(['job_level' => 4]);

        if (! Schema::hasTable('holidays')) {
            Schema::create('holidays', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->nullable();
                $t->string('name', 120);
                $t->string('kind', 16)->default('official'); // official | eid | company | bridge
                $t->date('from');
                $t->date('to');
                $t->timestamps();
                $t->index(['from', 'to']);
            });
        }

        $now = now();
        $put = function (string $code, array $row) use ($now) {
            $row['form'] = isset($row['form']) ? json_encode($row['form'], JSON_UNESCAPED_UNICODE) : null;
            $row['flow'] = json_encode($row['flow'], JSON_UNESCAPED_UNICODE);
            $row['roles'] = isset($row['roles']) ? json_encode($row['roles']) : null;
            $row += ['self_service' => true, 'on_behalf' => false, 'active' => true, 'builtin' => true, 'sort' => 50, 'version' => 1, 'op' => 'EMP'];
            if (DB::table('request_types')->where('code', $code)->exists()) {
                DB::table('request_types')->where('code', $code)->update($row + ['updated_at' => $now]);
            } else {
                DB::table('request_types')->insert($row + ['code' => $code, 'created_at' => $now, 'updated_at' => $now]);
            }
        };
        $mgr = ['role' => 'mgr', 'label' => 'المشرف المباشر'];
        $hr = ['role' => 'hr_mgr', 'label' => 'الموارد البشرية'];
        $fin = ['role' => 'fin', 'label' => 'المدير المالي'];
        $ceo = ['role' => 'ceo', 'label' => 'الرئيس التنفيذي للشركة'];
        $grp = ['role' => 'super', 'label' => 'الرئيس التنفيذي للمجموعة'];

        $put('ticket', ['name' => 'تذكرة إجازة', 'name_en' => 'Leave ticket', 'handler' => 'ticket', 'op' => 'LVE', 'icon' => 'plane', 'flow' => [$mgr, $hr, $fin + ['label' => 'المدير المالي (الحجز أو البدل)']],
            'form' => [['key' => 'travel_date', 'label' => 'تاريخ السفر', 'type' => 'date', 'required' => true], ['key' => 'destination', 'label' => 'الوجهة', 'type' => 'text', 'required' => true],
                ['key' => 'companions', 'label' => 'عدد المرافقين من الأسرة (الزوج/الزوجة والأبناء)', 'type' => 'number'], ['key' => 'estimate', 'label' => 'التكلفة التقديرية (ر.س)', 'type' => 'number', 'required' => true],
                ['key' => 'cash', 'label' => 'بدل مالي بدل التذكرة (لغير السعودي في الفئة الثالثة فقط)', 'type' => 'bool']]]);
        $put('ticket_carry', ['name' => 'ترحيل تذكرة الإجازة', 'name_en' => 'Ticket carry-over', 'handler' => 'ticket_carry', 'op' => 'LVE', 'flow' => [$mgr, $hr, $ceo, $fin + ['label' => 'المدير المالي (الاعتماد النهائي)']],
            'form' => [['key' => 'reason', 'label' => 'السبب التشغيلي الموثق', 'type' => 'textarea', 'required' => true]]]);
        $put('ticket_children', ['name' => 'زيادة عدد الأبناء المستحقين للتذكرة', 'name_en' => 'Extra children for tickets', 'handler' => 'ticket_children', 'op' => 'LVE', 'flow' => [$hr, $fin, $ceo + ['label' => 'الرئيس التنفيذي للشركة (الاعتماد النهائي)']],
            'form' => [['key' => 'children', 'label' => 'عدد الأبناء المطلوب شمولهم', 'type' => 'number', 'required' => true], ['key' => 'reason', 'label' => 'المبرر', 'type' => 'textarea', 'required' => true]]]);
        $put('leave_defer', ['name' => 'تأجيل الإجازة السنوية (حتى 3 أشهر)', 'name_en' => 'Annual leave deferral', 'handler' => 'leave_defer', 'op' => 'LVE', 'flow' => [$mgr, $ceo, $hr + ['label' => 'الموارد البشرية (الاعتماد النهائي)']],
            'form' => [['key' => 'days', 'label' => 'عدد الأيام المؤجلة', 'type' => 'number', 'required' => true], ['key' => 'reason', 'label' => 'السبب', 'type' => 'textarea', 'required' => true]]]);
        $put('overtime', ['name' => 'تكليف بعمل إضافي', 'name_en' => 'Overtime assignment', 'handler' => 'overtime', 'op' => 'ATT', 'self_service' => false, 'on_behalf' => true, 'roles' => ['mgr', 'hr_mgr', 'hr_staff', 'super', 'ceo'],
            'flow' => [$hr, $fin, $ceo + ['label' => 'الرئيس التنفيذي للشركة (الاعتماد النهائي)']],
            'form' => [['key' => 'date', 'label' => 'تاريخ العمل الإضافي', 'type' => 'date', 'required' => true], ['key' => 'hours', 'label' => 'عدد الساعات', 'type' => 'number', 'required' => true],
                ['key' => 'reason', 'label' => 'سبب التكليف', 'type' => 'textarea', 'required' => true], ['key' => 'leave_instead', 'label' => 'تعويض بإجازة مدفوعة بدل الأجر (بموافقة الموظف)', 'type' => 'bool']]]);
        $put('flex_arrangement', ['name' => 'ترتيب عمل مرن أو عن بُعد', 'name_en' => 'Flexible / remote arrangement', 'handler' => 'flex_arrangement', 'op' => 'ATT', 'flow' => [$mgr, $hr, $ceo + ['label' => 'الرئيس التنفيذي للشركة (الاعتماد النهائي)']],
            'form' => [['key' => 'kind', 'label' => 'النوع', 'type' => 'select', 'options' => ['مرونة في بدء الدوام وانتهائه', 'عمل عن بُعد'], 'required' => true],
                ['key' => 'from', 'label' => 'من تاريخ', 'type' => 'date', 'required' => true], ['key' => 'to', 'label' => 'إلى تاريخ', 'type' => 'date', 'required' => true],
                ['key' => 'details', 'label' => 'الجدول وأوقات التوفر والمخرجات', 'type' => 'textarea', 'required' => true]]]);
        $put('iban_change', ['name' => 'تعديل الحساب البنكي (الآيبان)', 'name_en' => 'Bank account change', 'handler' => 'iban_change', 'op' => 'PAY', 'flow' => [$hr + ['label' => 'الموارد البشرية (التحقق من الشهادة)'], $fin + ['label' => 'المدير المالي (الاعتماد النهائي)']],
            'form' => [['key' => 'iban', 'label' => 'رقم الآيبان الجديد', 'type' => 'text', 'required' => true], ['key' => 'bank', 'label' => 'اسم البنك', 'type' => 'text', 'required' => true]]]);
        $put('expense', ['name' => 'مطالبة مصروفات مهنية', 'name_en' => 'Expense claim', 'handler' => 'expense', 'op' => 'PAY', 'flow' => [$mgr, $fin + ['label' => 'المدير المالي (الاعتماد النهائي)']],
            'form' => [['key' => 'date', 'label' => 'تاريخ المصروف', 'type' => 'date', 'required' => true], ['key' => 'amount', 'label' => 'المبلغ (ر.س)', 'type' => 'number', 'required' => true],
                ['key' => 'description', 'label' => 'البيان والغرض', 'type' => 'textarea', 'required' => true]]]);
        $put('probation', ['name' => 'قرار فترة التجربة (تثبيت أو إنهاء)', 'name_en' => 'Probation decision', 'handler' => 'probation', 'op' => 'EMP', 'self_service' => false, 'on_behalf' => true, 'roles' => ['mgr', 'hr_mgr', 'hr_staff', 'super', 'ceo'],
            'flow' => [$hr, $ceo + ['label' => 'الرئيس التنفيذي للشركة (الاعتماد النهائي)']],
            'form' => [['key' => 'decision', 'label' => 'القرار', 'type' => 'select', 'options' => ['تثبيت', 'تثبيت مبكر', 'إنهاء العقد خلال التجربة'], 'required' => true],
                ['key' => 'effective', 'label' => 'تاريخ النفاذ', 'type' => 'date', 'required' => true], ['key' => 'notes', 'label' => 'ملاحظات التقييم', 'type' => 'textarea', 'required' => true]]]);
        $put('probation_shorten', ['name' => 'تقصير مدة التجربة', 'name_en' => 'Shorten probation', 'handler' => 'probation_shorten', 'op' => 'EMP', 'self_service' => false, 'on_behalf' => true, 'roles' => ['hr_mgr', 'hr_staff', 'super'],
            'flow' => [$grp + ['label' => 'الرئيس التنفيذي للمجموعة (الاعتماد النهائي)']],
            'form' => [['key' => 'days', 'label' => 'المدة الجديدة بالأيام', 'type' => 'number', 'required' => true], ['key' => 'consent', 'label' => 'وافق الموظف كتابةً', 'type' => 'bool', 'required' => true],
                ['key' => 'reason', 'label' => 'السبب', 'type' => 'textarea', 'required' => true]]]);
        $put('promotion', ['name' => 'ترقية أو تكليف بوظيفة أعلى', 'name_en' => 'Promotion / acting assignment', 'handler' => 'promotion', 'op' => 'EMP', 'self_service' => false, 'on_behalf' => true, 'roles' => ['mgr', 'hr_mgr', 'hr_staff', 'super', 'ceo'],
            'flow' => [$hr, $fin, $ceo, $grp + ['label' => 'الرئيس التنفيذي للمجموعة (الاعتماد النهائي)']],
            'form' => [['key' => 'kind', 'label' => 'النوع', 'type' => 'select', 'options' => ['ترقية', 'تكليف بوظيفة أعلى'], 'required' => true],
                ['key' => 'title', 'label' => 'الوظيفة الجديدة / المكلف بها', 'type' => 'text', 'required' => true], ['key' => 'effective', 'label' => 'تاريخ البداية', 'type' => 'date', 'required' => true],
                ['key' => 'new_basic', 'label' => 'الأجر الأساسي الجديد (للترقية)', 'type' => 'number'], ['key' => 'role_basic', 'label' => 'الأجر الأساسي المعتمد للوظيفة المكلف بها (للتكليف)', 'type' => 'number'],
                ['key' => 'duties', 'label' => 'المهام والمدة', 'type' => 'textarea', 'required' => true]]]);
        $put('transfer', ['name' => 'نقل بين شركات المجموعة', 'name_en' => 'Transfer between companies', 'handler' => 'transfer', 'op' => 'EMP', 'self_service' => false, 'on_behalf' => true, 'roles' => ['hr_mgr', 'hr_staff', 'super'],
            'flow' => [$mgr, $fin, $ceo, $grp + ['label' => 'الرئيس التنفيذي للمجموعة (الاعتماد النهائي)']],
            'form' => [['key' => 'to_company', 'label' => 'الشركة المنقول إليها', 'type' => 'company', 'required' => true], ['key' => 'effective', 'label' => 'تاريخ الانتقال', 'type' => 'date', 'required' => true],
                ['key' => 'method', 'label' => 'الطريقة', 'type' => 'select', 'options' => ['اتفاق ثلاثي مع استمرار الخدمة', 'عقد جديد مع تسوية العلاقة السابقة'], 'required' => true],
                ['key' => 'consent', 'label' => 'وافق الموظف على النقل', 'type' => 'bool', 'required' => true], ['key' => 'reason', 'label' => 'السبب', 'type' => 'textarea', 'required' => true]]]);
        // §9 / appendix 5: changing a wage or an allowance — manager m1, finance m2, group CEO final
        foreach (['allowance', 'salary_change'] as $c) {
            DB::table('request_types')->where('code', $c)->update(['flow' => json_encode([$mgr, $fin, $grp + ['label' => 'الرئيس التنفيذي للمجموعة (الاعتماد النهائي)']], JSON_UNESCAPED_UNICODE), 'updated_at' => $now]);
        }

        // §59–60 special leaves
        $lt = function (string $code, array $row) use ($now) {
            if (DB::table('leave_types')->where('code', $code)->exists()) {
                DB::table('leave_types')->where('code', $code)->update($row + ['updated_at' => $now]);
            } else {
                DB::table('leave_types')->insert($row + ['code' => $code, 'active' => true, 'sort' => 60, 'created_at' => $now, 'updated_at' => $now]);
            }
        };
        $lt('maternity_ext', ['name' => 'تمديد إجازة الوضع (دون أجر)', 'accrual' => 'case', 'deducts' => false, 'attachment_required' => false, 'pay' => 'unpaid', 'max_per_case' => 30]);
        $lt('maternity_care', ['name' => 'رعاية مولود مريض أو من ذوي الإعاقة', 'accrual' => 'case', 'deducts' => false, 'attachment_required' => true, 'pay' => 'full', 'max_per_case' => 30]);
        DB::table('leave_types')->where('code', 'sick')->update(['max_per_case' => null, 'updated_at' => $now]);
        DB::table('leave_types')->where('code', 'iddah')->update(['max_per_case' => 130, 'updated_at' => $now]);
        DB::table('leave_types')->where('code', 'hajj')->update(['max_per_case' => 15, 'updated_at' => $now]);

        $set = function (string $key, $value, string $label, string $group = 'الإجازات') use ($now) {
            if (! DB::table('settings')->where('key', $key)->whereNull('company_id')->exists()) {
                $row = ['key' => $key, 'value' => json_encode($value), 'company_id' => null, 'created_at' => $now, 'updated_at' => $now];
                foreach (['label' => $label, 'group' => $group, 'type' => is_bool($value) ? 'bool' : (is_numeric($value) ? 'number' : 'text')] as $k => $v) {
                    if (Schema::hasColumn('settings', $k)) $row[$k] = $v;
                }
                DB::table('settings')->insert($row);
            }
        };
        $set('maxAbsentPerTeam', 2, 'أقصى عدد من الفريق في إجازة في وقت واحد دون موافقة الموارد البشرية (§61)');
        $set('examNoticeDays', 15, 'مهلة تقديم إجازة الامتحان (يوم) §61');
        $set('ticketMinMonths', 10, 'أشهر الخدمة لاستحقاق تذكرة الإجازة (§54)', 'المزايا');
        $set('expenseClaimDays', 10, 'مهلة تقديم مطالبة المصروفات (أيام عمل) §45', 'المالية');
        $set('approvalSlaDays', 3, 'مدة البت لكل معتمد (أيام عمل) §12', 'الطلبات');
        $set('tripReportDays', 3, 'مهلة تقرير الرحلة بعد العودة (أيام عمل) §68', 'الطلبات');
        Cache::forget('hc.lookups');
        Cache::forget('hc.settings');
    }

    public function down(): void {}
};
