<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Policy 2.1 — completion after the settings review:
 *  · settings aligned with the text (no finance-skip cap for trips; flex comes from the tier; labelled keys)
 *  · §13 allocation of an employee's service between companies (total 100%)
 *  · §48–49 company classification request (scorecard result → tier) with its approval chain
 *  · §77 annual individual development plan, §81 training summary within 3 working days
 *  · §41–42 deductions above half the wage carried to the next month (fines included)
 *  · §116 data retention and periodic access review
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $def = function (string $key, string $label, string $group, string $type, $value = null, ?string $description = null) use ($now) {
            $row = ['label' => $label, 'group' => $group, 'type' => $type, 'description' => $description, 'updated_at' => $now];
            if (DB::table('setting_definitions')->where('key', $key)->exists()) {
                DB::table('setting_definitions')->where('key', $key)->update($row);
            } else {
                DB::table('setting_definitions')->insert($row + ['key' => $key, 'builtin' => true, 'sort' => 50, 'created_at' => $now]);
            }
            if ($value !== null && ! DB::table('settings')->where('key', $key)->whereNull('company_id')->exists()) {
                DB::table('settings')->insert(['key' => $key, 'value' => json_encode($value), 'company_id' => null, 'version' => 1, 'effective_from' => $now->toDateString(),
                    'reason' => 'اللائحة 2.1', 'created_at' => $now, 'updated_at' => $now]);
            }
        };

        // a trip is always approved by the finance manager (§65) — the old "finance only above a cap" key is removed
        DB::table('settings')->where('key', 'tripFinanceCap')->delete();
        DB::table('setting_definitions')->where('key', 'tripFinanceCap')->delete();
        // flexibility is a tier benefit (§30, §52): tier 1 has none — the global fallback is therefore 0
        DB::table('settings')->where('key', 'flexMinutes')->whereNull('company_id')->update(['value' => json_encode(0), 'version' => DB::raw('version + 1'),
            'effective_from' => $now->toDateString(), 'reason' => 'مطابقة اللائحة 2.1: المرونة من مزايا فئة الشركة (§30، §52)', 'updated_at' => $now]);
        $def('flexMinutes', 'المرونة الافتراضية (دقائق أسبوعيًا) — تُطبَّق قيمة فئة الشركة أولًا', 'الحضور', 'number');
        $def('objectionDays', 'مهلة الاعتراض على التقييم (أيام عمل) §76', 'التقارير', 'number');
        $def('violationStatementDays', 'مهلة إفادة الموظف على المخالفة (أيام عمل، لا تقل عن يومين) §95', 'المخالفات', 'number');
        $def('examNoticeDays', 'مهلة تقديم إجازة الامتحان (يوم) §61', 'الإجازات', 'number', 15);
        $def('expenseClaimDays', 'مهلة تقديم مطالبة المصروفات (أيام عمل) §45', 'العهد', 'number', 10);
        $def('ticketMinMonths', 'أشهر الخدمة لاستحقاق تذكرة الإجازة §54', 'المزايا', 'number', 10);
        $def('tripReportDays', 'مهلة تقرير الرحلة بعد العودة (أيام عمل) §68', 'الطلبات', 'number', 3);
        $def('overtimeRate', 'معامل أجر الساعة الإضافية (1.5 = الأجر + 50%) §26', 'الرواتب', 'number', 1.5);
        $def('actingAllowancePct', 'بدل التكليف بوظيفة أعلى (% من أساسي الوظيفة) §22', 'الرواتب', 'number', 15);
        $def('trainingSummaryDays', 'مهلة ملخص البرنامج التدريبي بعد انتهائه (أيام عمل) §81', 'التطوير', 'number', 3);
        $def('dataRetentionYears', 'مدة الاحتفاظ بملفات الموظفين بعد انتهاء الخدمة (سنوات) §116', 'الخصوصية', 'number', 10);
        $def('accessReviewDays', 'دورية مراجعة صلاحيات الوصول (يوم) §116', 'الخصوصية', 'number', 90);

        // §13: service to several companies — the reference company plus allocation percentages summing to 100
        if (! Schema::hasColumn('employees', 'service_allocation')) {
            Schema::table('employees', function (Blueprint $t) {
                $t->json('service_allocation')->nullable();
            });
        }
        // §81: what the employee learned and will apply, within 3 working days of finishing a program
        if (! Schema::hasColumn('dev_records', 'summary')) {
            Schema::table('dev_records', function (Blueprint $t) {
                $t->text('summary')->nullable();
                $t->timestamp('summary_at')->nullable();
            });
        }
        // §41–42: what exceeds half the wage is carried to the next month instead of being deducted
        if (! Schema::hasColumn('violations', 'deferred_amount')) {
            Schema::table('violations', function (Blueprint $t) {
                $t->decimal('deferred_amount', 12, 2)->nullable();
                $t->string('deferred_period', 7)->nullable();
            });
        }
        // §77: annual individual development plan agreed with the direct manager
        if (! Schema::hasTable('dev_plans')) {
            Schema::create('dev_plans', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('employee_id')->index();
                $t->unsignedSmallInteger('year');
                $t->text('gaps')->nullable();
                $t->json('tracks')->nullable();
                $t->string('status', 16)->default('draft'); // draft | submitted | approved | returned
                $t->text('manager_note')->nullable();
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->timestamps();
                $t->unique(['employee_id', 'year']);
            });
        }

        // §48–49 / appendix 5: classification and benefits pool — prepared by the company CEO with HR, finance m1,
        // group CEO m2, approved by the company board (its chair); effective from the next benefits year
        $flow = [['role' => 'fin', 'label' => 'المدير المالي (مراجعة النتائج)'], ['role' => 'super', 'label' => 'الرئيس التنفيذي للمجموعة'], ['role' => 'board', 'label' => 'مجلس إدارة الشركة / رئيسه (الاعتماد النهائي)']];
        $form = [['key' => 'company', 'label' => 'الشركة', 'type' => 'company', 'required' => true],
            ['key' => 'achievement', 'label' => 'نسبة تحقق بطاقة أداء الشركة للسنة المنتهية (%)', 'type' => 'number', 'required' => true],
            ['key' => 'liquidity', 'label' => 'السيولة والالتزام وشروط الاستدامة', 'type' => 'textarea', 'required' => true],
            ['key' => 'tier', 'label' => 'التصنيف المقترح', 'type' => 'select', 'options' => ['تأسيس', 'تمكين', 'أثر'], 'required' => true],
            ['key' => 'effective', 'label' => 'تاريخ السريان (بداية سنة المزايا)', 'type' => 'date', 'required' => true],
            ['key' => 'board_exception', 'label' => 'استثناء بقرار مسبَّب من مجلس الإدارة (أثر نوعي)', 'type' => 'bool']];
        $row = ['name' => 'تصنيف الشركة ووعاء المزايا', 'name_en' => 'Company classification', 'op' => 'EMP', 'handler' => 'tier_change', 'flow' => json_encode($flow, JSON_UNESCAPED_UNICODE),
            'form' => json_encode($form, JSON_UNESCAPED_UNICODE), 'self_service' => false, 'on_behalf' => false, 'roles' => json_encode(['super', 'hr_mgr', 'ceo']), 'active' => true, 'builtin' => true, 'sort' => 60, 'version' => 1, 'updated_at' => $now];
        if (DB::table('request_types')->where('code', 'tier_change')->exists()) {
            DB::table('request_types')->where('code', 'tier_change')->update($row);
        } else {
            DB::table('request_types')->insert($row + ['code' => 'tier_change', 'created_at' => $now]);
        }
        // a company-classification request is filed by the company CEO / HR for the company itself
        DB::table('request_types')->where('code', 'tier_change')->update(['self_service' => true]);

        Cache::forget('hc.settings');
        Cache::forget('hc.lookups');
    }

    public function down(): void {}
};
