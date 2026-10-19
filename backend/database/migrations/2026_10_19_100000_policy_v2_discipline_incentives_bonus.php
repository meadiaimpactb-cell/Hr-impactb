<?php

use App\Services\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Policy v2.0 — Chapter 18 (violations: the policy's own ladders, fines on the basic wage, caps and deadlines),
 * §88 (incentives & excellence register), §84–87 (raise / bonus tables, instant bonus request), §75 (improvement
 * plans), §114 (letters: purpose, justification, thank-you letter, per-company templates), §7 (HR manager scope).
 */
return new class extends Migration
{
    /**
     * §99–103: each type = [code, category, name, steps]. A step is: warn | N% (of the daily basic wage) | Nd (days of
     * the daily basic wage) | dismiss (termination with EOSB per §98) | investigate (referral) | - (no further step).
     */
    public const TYPES = [
        ['late15', 'attendance', 'تأخر حتى 15 دقيقة دون أثر تشغيلي', 'warn,5%,10%,20%'],
        ['late15x', 'attendance', 'تأخر حتى 15 دقيقة مع أثر تشغيلي مثبت', 'warn,15%,25%,50%'],
        ['late30', 'attendance', 'تأخر أكثر من 15 دقيقة وحتى 30 دقيقة دون أثر تشغيلي', '10%,15%,25%,50%'],
        ['late30x', 'attendance', 'تأخر أكثر من 15 دقيقة وحتى 30 دقيقة مع أثر تشغيلي', '25%,50%,75%,1d'],
        ['late60', 'attendance', 'تأخر أكثر من 30 دقيقة وحتى 60 دقيقة دون أثر تشغيلي', '25%,50%,75%,1d'],
        ['late60x', 'attendance', 'تأخر أكثر من 30 دقيقة وحتى 60 دقيقة مع أثر تشغيلي', '30%,50%,1d,2d'],
        ['late60p', 'attendance', 'تأخر أكثر من 60 دقيقة', 'warn,1d,2d,3d'],
        ['nopunch', 'attendance', 'عدم تسجيل الحضور أو الانصراف مع ثبوت أداء العمل ودون عذر', 'warn,25%,50%,1d'],
        ['leave_site', 'attendance', 'مغادرة موقع العمل دون إذن أو عدم الاستجابة لطلب التحضير دون عذر', '25%,50%,1d,2d'],
        ['punch_fraud', 'attendance', 'التلاعب في الحضور أو التسجيل نيابة عن الغير', '1d,2d,3d,5d'],
        ['absence1', 'absence', 'غياب دون إذن أو عذر من يوم إلى 3 أيام', '1d,2d,3d,4d'],
        ['absence4', 'absence', 'غياب دون إذن أو عذر من 4 إلى 6 أيام', '2d,3d,4d,dismiss'],
        ['absence7', 'absence', 'غياب دون إذن أو عذر من 7 إلى 10 أيام', '4d,5d,dismiss,-'],
        ['noncompliance', 'instructions', 'عدم تنفيذ تعليمات عمل مشروعة قابلة للتنفيذ', '1d,2d,3d,5d'],
        ['conduct_rules', 'instructions', 'عدم الالتزام بتعليمات العمل أو قواعد السلوك المهني أو الزي أو اللباس المحتشم', '1d,2d,3d,5d'],
        ['neglect', 'instructions', 'إهمال واجب وظيفي دون خسارة مادية', '50%,1d,2d,3d'],
        ['report_missing', 'instructions', 'عدم تقديم التقرير المطلوب في موعده بعد إتاحة الوقت والأدوات', '25%,50%,1d,2d'],
        ['dev_missing', 'instructions', 'عدم إكمال تطوير إلزامي مكلف به دون عذر', '25%,50%,1d,2d'],
        ['neglect_damage', 'instructions', 'إهمال يترتب عليه ضرر مادي ثابت', '3d,4d,5d,5d'],
        ['fight', 'conduct', 'تشاجر أو مشاغبة دون اعتداء يوجب تكييفًا أشد', '1d,2d,3d,5d'],
        ['fake_sick', 'conduct', 'ادعاء المرض أو الإصابة كذبًا بدليل مثبت', '1d,2d,3d,5d'],
        ['refuse_exam', 'conduct', 'الامتناع عن فحص طبي مطلوب دون مبرر', '1d,2d,3d,5d'],
        ['indecent', 'conduct', 'الظهور بمظهر أو سلوك مخل بالآداب العامة أو بأخلاقيات العمل أو عبر منصات التواصل', '2d,3d,5d,investigate'],
        ['misuse_assets', 'conduct', 'استخدام معدات الشركة لأغراض شخصية دون إذن', 'warn,25%,50%,1d'],
        ['custody_late', 'conduct', 'التأخر في تسوية العهدة النقدية عن موعدها دون عذر', 'warn,50%,1d,2d'],
        ['cash_payment', 'conduct', 'إعداد صرف أو اعتماده أو تنفيذه نقدًا أو إلى غير حساب صاحب الاستحقاق', '3d,5d,investigate,-'],
        ['abuse', 'conduct', 'إساءة استخدام الصلاحية أو قبول منفعة شخصية غير مشروعة', '3d,5d,investigate,-'],
        ['conflict', 'conduct', 'انتهاك ضوابط تعارض المصالح أو العمل للحساب الخاص بما يتعارض مع العمل', 'dismiss,-,-,-'],
    ];

    public function up(): void
    {
        $now = now();
        // ---- §99–103 violation types (the old generic six are retired, their history stays) ----
        DB::table('lookup_groups')->updateOrInsert(['code' => 'violation_types'], ['name' => 'أنواع المخالفات (اللائحة §99–103)', 'name_en' => 'Violation types', 'builtin' => true,
            'meta_fields' => json_encode([['key' => 'category', 'label' => 'الجدول (attendance/absence/instructions/conduct)', 'type' => 'text'], ['key' => 'steps', 'label' => 'الجزاء بالمرة الأولى,الثانية,الثالثة,الرابعة (warn | 25% | 1d | dismiss | investigate | -)', 'type' => 'text'],
                ['key' => 'lookback_days', 'label' => 'مدة الرجوع للتكرار (يوم)', 'type' => 'number']], JSON_UNESCAPED_UNICODE), 'created_at' => $now, 'updated_at' => $now]);
        DB::table('lookups')->where('group', 'violation_types')->whereIn('code', ['late', 'absence', 'conduct', 'policy', 'custody', 'report'])->update(['active' => false]);
        foreach (self::TYPES as $i => [$code, $cat, $name, $steps]) {
            DB::table('lookups')->updateOrInsert(['group' => 'violation_types', 'code' => $code], ['name' => $name, 'name_en' => $code, 'sort' => $i + 1, 'active' => true,
                'meta' => json_encode(['category' => $cat, 'steps' => $steps, 'lookback_days' => 180]), 'created_at' => $now, 'updated_at' => $now]);
        }
        \Illuminate\Support\Facades\Cache::forget('hc.lookups'); // the grouped lookups are cached forever and refreshed only on model saves
        // ---- §96 penalty degrees ----
        foreach ([['1', 'لفت نظر شفهي', false, false], ['2', 'إنذار كتابي', false, false], ['3', 'إنذار نهائي', false, false], ['4', 'غرامة (حسم من الأجر)', true, false],
            ['5', 'الحرمان من العلاوة أو تأجيلها / تأجيل الترقية', false, false], ['6', 'الإيقاف عن العمل دون أجر (حتى 5 أيام في الشهر)', false, false], ['7', 'الفصل من العمل', false, true]] as $i => [$code, $name, $fin, $term]) {
            DB::table('lookups')->updateOrInsert(['group' => 'penalty_degrees', 'code' => $code], ['name' => $name, 'sort' => $i + 1, 'active' => true, 'meta' => json_encode(['financial' => $fin, 'terminates' => $term]), 'updated_at' => $now, 'created_at' => $now]);
        }
        Schema::table('violations', function (Blueprint $t) {
            $t->unsignedTinyInteger('recommended_degree')->nullable()->after('proposed_degree');
            $t->unsignedBigInteger('recommended_by')->nullable()->after('recommended_degree');
            $t->string('step', 20)->nullable()->after('penalty');          // the ladder step applied: warn | 25% | 1d …
            $t->decimal('basis', 10, 2)->nullable()->after('amount');     // daily basic wage the fine was computed on
            $t->boolean('operational_impact')->default(false)->after('evidence');
        });
        foreach (['violationStatementDays' => [3, 'اللائحة §95: مهلة الرد يومان عمل على الأقل'], 'violationObjectionDays' => [30, 'اللائحة §105'], 'violationChargeDays' => [30, 'اللائحة §97: لا اتهام بعد 30 يومًا من الكشف'],
            'bonusFundingFactor' => [1, 'اللائحة §85: معامل تمويل وعاء المكافأة (0–1)']] as $k => [$v, $r]) {
            Settings::set($k, $v, null, $r);
        }

        // ---- §88 incentives & excellence register ----
        if (! Schema::hasTable('incentives')) {
            Schema::create('incentives', function (Blueprint $t) {
                $t->id();
                $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
                $t->foreignId('company_id')->nullable();
                $t->string('kind', 24);                 // praise | thanks_letter | certificate | bonus | honor | achievement | star
                $t->string('title', 200);
                $t->text('detail')->nullable();
                $t->decimal('amount', 10, 2)->default(0); // instant bonus paid with the payroll of `period`
                $t->unsignedSmallInteger('points')->default(0); // excellence points (§88)
                $t->string('period', 7)->nullable();
                $t->boolean('paid')->default(false);
                $t->unsignedBigInteger('given_by')->nullable();
                $t->unsignedBigInteger('request_id')->nullable();
                $t->timestamps();
                $t->index(['employee_id', 'period']);
            });
        }
        // ---- §75 improvement plans ----
        if (! Schema::hasTable('improvement_plans')) {
            Schema::create('improvement_plans', function (Blueprint $t) {
                $t->id();
                $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
                $t->foreignId('company_id')->nullable();
                $t->string('stage', 16)->default('session');  // session (30-day support) | plan (60–90 days)
                $t->string('trigger', 300)->nullable();
                $t->date('start');
                $t->date('end');
                $t->json('goals')->nullable();                  // [{goal, measure, due, status}]
                $t->text('support')->nullable();
                $t->json('reviews')->nullable();                // [{at, by, note, score}]
                $t->string('status', 16)->default('draft');    // draft | active | extended | succeeded | failed | closed
                $t->text('outcome')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->timestamps();
            });
        }
        // ---- §87 instant bonus request (manager on behalf of an employee) ----
        $form = [['key' => 'amount', 'label' => 'المبلغ (500 – 5,000 ريال؛ ما زاد يعتمده الرئيس التنفيذي للمجموعة)', 'label_en' => 'Amount', 'type' => 'number', 'required' => true],
            ['key' => 'achievement', 'label' => 'الإنجاز الاستثنائي الموثق', 'label_en' => 'Documented achievement', 'type' => 'textarea', 'required' => true]];
        $flow = [['role' => 'hr_mgr', 'label' => 'الموارد البشرية'], ['role' => 'fin', 'label' => 'المدير المالي'], ['role' => 'ceo', 'label' => 'الرئيس التنفيذي للشركة'], ['role' => 'super', 'label' => 'الرئيس التنفيذي للمجموعة (فوق 5,000)', 'skip_if' => 'amount<=5000']];
        DB::table('request_types')->updateOrInsert(['code' => 'bonus'], ['name' => 'مكافأة إنجاز فورية', 'name_en' => 'Instant achievement bonus', 'op' => 'PRF', 'handler' => 'bonus',
            'flow' => json_encode($flow, JSON_UNESCAPED_UNICODE), 'form' => json_encode($form, JSON_UNESCAPED_UNICODE), 'roles' => json_encode(['mgr', 'ceo', 'hr_mgr', 'super']), 'self_service' => false, 'on_behalf' => true,
            'active' => true, 'builtin' => true, 'sort' => (int) DB::table('request_types')->max('sort') + 10, 'icon' => 'award', 'created_at' => $now, 'updated_at' => $now]);

        // ---- §114 letters ----
        if (! DB::table('lookups')->where('group', 'letter_kinds')->where('code', 'thanks')->exists()) {
            DB::table('lookups')->insert(['group' => 'letter_kinds', 'code' => 'thanks', 'name' => 'خطاب شكر', 'name_en' => 'Thank-you letter', 'sort' => 90, 'active' => true, 'meta' => null, 'created_at' => $now, 'updated_at' => $now]);
        }
        if (! Schema::hasColumn('letter_templates', 'company_id')) {
            Schema::table('letter_templates', fn (Blueprint $t) => $t->foreignId('company_id')->nullable()->after('id'));
        }
        if (! Schema::hasColumn('letters', 'purpose')) {
            Schema::table('letters', function (Blueprint $t) { $t->string('purpose', 500)->nullable()->after('to'); $t->string('justification', 1000)->nullable()->after('purpose'); });
        }
        // ---- §7 HR manager scope (null = every company) ----
        if (! Schema::hasColumn('users', 'hr_scope')) {
            Schema::table('users', fn (Blueprint $t) => $t->json('hr_scope')->nullable()->after('company_id'));
        }
        \Illuminate\Support\Facades\Cache::forget('hc.lookups');
        // ---- §24: 48 hours a week ----
        foreach (DB::table('tiers')->get(['id', 'benefits']) as $t) {
            $b = json_decode($t->benefits, true) ?: [];
            $b['weeklyHours'] = 48;
            DB::table('tiers')->where('id', $t->id)->update(['benefits' => json_encode($b, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('improvement_plans');
        Schema::dropIfExists('incentives');
    }
};
