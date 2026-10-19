<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Policy v2.0 — development points (§78–80), structured weekly reports (§34), monthly-report carry-over (§35),
 * special leave types (§59) and the identity of the week (§5).
 */
return new class extends Migration
{
    /** §78: points per activity; `cap` = monthly cap for the repeatable ones; `hours` = [min, max) for the course family. */
    public const ACTIVITIES = [
        ['short', 'دورة أو ورشة قصيرة (أقل من 4 ساعات)', 'Short course / workshop (< 4 h)', 15, null, [0, 4]],
        ['course', 'دورة تدريبية (4 إلى 10 ساعات)', 'Training course (4–10 h)', 30, null, [4, 10]],
        ['program', 'برنامج تدريبي (أكثر من 10 إلى 30 ساعة)', 'Training program (10–30 h)', 60, null, [10, 30]],
        ['intensive', 'برنامج مكثف أو مسار تعليمي (أكثر من 30 ساعة)', 'Intensive program / learning path (> 30 h)', 100, null, [30, 100000]],
        ['cert', 'اجتياز شهادة مهنية معتمدة', 'Professional certification passed', 150, null, null],
        ['book', 'قراءة كتاب مهني مع ملخص تطبيقي', 'Professional book with applied summary', 20, null, null],
        ['micro', 'محتوى معرفي قصير مع ملخص (مقال، بودكاست، محاضرة)', 'Short knowledge item with summary', 5, 20, null],
        ['conference', 'حضور مؤتمر أو ملتقى مهني مع تقرير', 'Conference / forum with report', 20, null, null],
        ['session', 'تقديم جلسة نقل معرفة للزملاء', 'Knowledge-transfer session delivered', 30, null, null],
        ['apply', 'تطبيق التعلم في مشروع عمل بنتيجة موثقة', 'Learning applied in a work project', 30, null, null],
        ['mentoring', 'إرشاد زميل أو توجيهه في جلسة موثقة', 'Mentoring session', 10, 30, null],
    ];

    public function up(): void
    {
        Schema::table('dev_records', function (Blueprint $t) {
            $t->string('activity', 20)->nullable()->after('kind');       // code from ACTIVITIES
            $t->unsignedSmallInteger('points')->default(0)->after('activity');
            $t->string('points_month', 7)->nullable()->after('points'); // Y-m the points count in
        });
        Schema::table('weekly_reports', function (Blueprint $t) {
            $t->json('items')->nullable()->after('employee_id');        // [{axis,title,kpi,target,achieved}]
            $t->string('plan_status', 12)->default('none')->after('status'); // none | submitted | approved
            $t->timestamp('plan_approved_at')->nullable()->after('plan_status');
            $t->unsignedBigInteger('plan_approved_by')->nullable()->after('plan_approved_at');
            $t->timestamp('noted_at')->nullable()->after('mgr_note');   // the late/missing note was written to the employee file
        });
        Schema::table('employees', function (Blueprint $t) {
            $t->boolean('weekly_report_required')->nullable()->after('daily_note_required'); // null = company setting
        });

        $now = now();
        DB::table('lookup_groups')->updateOrInsert(['code' => 'dev_activities'], ['name' => 'أنشطة التطوير ونقاطها', 'name_en' => 'Development activities & points',
            'meta_fields' => json_encode([['key' => 'points', 'label' => 'النقاط', 'type' => 'number'], ['key' => 'cap', 'label' => 'الحد الشهري', 'type' => 'number'], ['key' => 'min_hours', 'label' => 'من ساعة', 'type' => 'number'], ['key' => 'max_hours', 'label' => 'إلى ساعة', 'type' => 'number']], JSON_UNESCAPED_UNICODE),
            'builtin' => true, 'created_at' => $now, 'updated_at' => $now]);
        foreach (self::ACTIVITIES as $i => [$code, $name, $en, $points, $cap, $hours]) {
            DB::table('lookups')->updateOrInsert(['group' => 'dev_activities', 'code' => $code], ['name' => $name, 'name_en' => $en, 'sort' => $i + 1, 'active' => true,
                'meta' => json_encode(['points' => $points, 'cap' => $cap, 'min_hours' => $hours[0] ?? null, 'max_hours' => $hours[1] ?? null]), 'created_at' => $now, 'updated_at' => $now]);
        }

        // §59: special leave types that were missing
        $sort = (int) DB::table('leave_types')->max('sort');
        foreach ([
            ['hajj', 'حج', 'Hajj', 'case', 'hajj', false, false, 'full', 15, 'مرة واحدة خلال الخدمة لمن لم يؤدِّ الفريضة، بعد سنتين متصلتين'],
            ['newborn', 'مولود', 'Newborn', 'case', 'newborn', false, true, 'full', 3, 'خلال 7 أيام من الولادة'],
            ['sibling', 'وفاة أخ أو أخت', 'Sibling bereavement', 'case', 'siblingBereavement', false, true, 'full', 3, null],
            ['iddah', 'عدة الوفاة', 'Widow leave (iddah)', 'case', null, false, true, 'full', 130, '4 أشهر و10 أيام للمسلمة، و15 يومًا لغير المسلمة'],
            ['exam', 'امتحان دراسي', 'Exam', 'case', null, false, true, 'policy', null, 'أيام الامتحانات الفعلية بموافقة الشركة على الانتساب'],
        ] as [$code, $name, $en, $acc, $quota, $ded, $att, $pay, $max, $note]) {
            if (! DB::table('leave_types')->where('code', $code)->exists()) {
                DB::table('leave_types')->insert(['code' => $code, 'name' => $name, 'name_en' => $en, 'accrual' => $acc, 'quota_key' => $quota, 'deducts' => $ded, 'attachment_required' => $att,
                    'carry_over' => false, 'pay' => $pay, 'max_per_case' => $max, 'extra_approval' => null, 'sort' => ++$sort, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        // §80: request for a development program — manager → HR → finance (only when it costs money)
        $form = [
            ['key' => 'program', 'label' => 'البرنامج أو المهارة المستهدفة', 'label_en' => 'Program / skill', 'type' => 'text', 'required' => true],
            ['key' => 'relevance', 'label' => 'علاقته بعملك والأثر المتوقع', 'label_en' => 'Relevance & expected impact', 'type' => 'textarea', 'required' => true],
            ['key' => 'provider', 'label' => 'جهة التدريب', 'label_en' => 'Provider', 'type' => 'text', 'required' => false],
            ['key' => 'duration', 'label' => 'المدة (ساعات أو أيام)', 'label_en' => 'Duration', 'type' => 'text', 'required' => false],
            ['key' => 'cost', 'label' => 'التكلفة (ريال، 0 إن كان مجانيًا)', 'label_en' => 'Cost (SAR)', 'type' => 'number', 'required' => true],
            ['key' => 'start', 'label' => 'تاريخ البداية المتوقع', 'label_en' => 'Expected start', 'type' => 'date', 'required' => false],
        ];
        $flow = [['role' => 'mgr', 'label' => 'المشرف المباشر'], ['role' => 'hr_mgr', 'label' => 'الموارد البشرية'], ['role' => 'fin', 'label' => 'المدير المالي (عند وجود تكلفة)', 'skip_if' => 'cost<=0']];
        DB::table('request_types')->updateOrInsert(['code' => 'dev_program'], ['name' => 'طلب برنامج تطويري', 'name_en' => 'Development program request', 'op' => 'DEV', 'handler' => 'dev_program',
            'flow' => json_encode($flow, JSON_UNESCAPED_UNICODE), 'form' => json_encode($form, JSON_UNESCAPED_UNICODE), 'roles' => null, 'self_service' => true, 'on_behalf' => false,
            'active' => true, 'builtin' => true, 'sort' => (int) DB::table('request_types')->max('sort') + 10, 'icon' => 'training', 'created_at' => $now, 'updated_at' => $now]);

        // §72: grade A reads "ممتاز"
        foreach (DB::table('eval_templates')->get(['id', 'grades']) as $t) {
            $g = json_decode($t->grades, true) ?: [];
            foreach ($g as &$row) { if (($row[1] ?? '') === 'A' && ($row[2] ?? '') === 'متفوق') { $row[2] = 'ممتاز'; } }
            DB::table('eval_templates')->where('id', $t->id)->update(['grades' => json_encode($g, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        Schema::table('dev_records', fn (Blueprint $t) => $t->dropColumn(['activity', 'points', 'points_month']));
        Schema::table('weekly_reports', fn (Blueprint $t) => $t->dropColumn(['items', 'plan_status', 'plan_approved_at', 'plan_approved_by', 'noted_at']));
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn('weekly_report_required'));
    }
};
