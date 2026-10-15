<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Performance: monthly & weekly reports, weighted evaluation, development records, violations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('scope', 10)->default('group');          // group | company | job
            $t->unsignedBigInteger('scope_id')->nullable();      // company_id or job_title_id
            $t->json('items');                                   // [{title,target,indicator,weight,source}]
            $t->boolean('allow_employee_items')->default(true);
            $t->unsignedTinyInteger('max_employee_items')->default(3);
            $t->unsignedTinyInteger('employee_item_weight_max')->default(15);
            $t->unsignedTinyInteger('due_day')->default(5);
            $t->unsignedInteger('version')->default(1);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('monthly_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained();
            $t->string('period', 7);
            $t->foreignId('template_id')->nullable()->constrained('report_templates')->nullOnDelete();
            $t->json('items');                                   // template items + employee items, each with achieved,pct,evidence,obstacles,solutions,next_target,self_score,mgr_note,mgr_score
            $t->json('dev')->nullable();                         // {program,provider,completed,interaction,applied,hr_score,notes}
            $t->decimal('quality', 5, 2)->nullable();            // CEO/HR quality score (0..sub.achieve.quality)
            $t->decimal('coop', 5, 2)->nullable();               // manager cooperation score (0..sub.disc.coop)
            $t->decimal('hr_bonus', 5, 2)->default(0);
            $t->string('status', 15)->default('draft');          // draft | submitted | mgr_reviewed | hr_reviewed | approved | returned
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('mgr_at')->nullable();
            $t->unsignedBigInteger('mgr_by')->nullable();
            $t->timestamp('hr_at')->nullable();
            $t->unsignedBigInteger('hr_by')->nullable();
            $t->timestamp('ceo_at')->nullable();
            $t->unsignedBigInteger('ceo_by')->nullable();
            $t->string('return_note', 500)->nullable();
            $t->boolean('late')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['employee_id', 'period']);
            $t->index(['company_id', 'period', 'status']);
        });

        Schema::create('weekly_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->date('week_start');
            $t->date('week_end');
            $t->text('done')->nullable();
            $t->text('next')->nullable();
            $t->text('blockers')->nullable();
            $t->string('status', 10)->default('missing');        // missing | submitted | late
            $t->timestamp('submitted_at')->nullable();
            $t->string('mgr_note', 500)->nullable();
            $t->timestamps();
            $t->unique(['employee_id', 'week_start']);
        });

        Schema::create('eval_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('scope', 10)->default('group');           // group | company | job
            $t->unsignedBigInteger('scope_id')->nullable();
            $t->unsignedTinyInteger('pass_score')->default(70);
            $t->json('weights');                                 // {achieve,attend,dev,disc}
            $t->json('sub');                                     // sub-weights per axis
            $t->json('grades');                                  // [[min,grade,label],…] descending
            $t->unsignedInteger('version')->default(1);
            $t->date('effective_from')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('evaluations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained();
            $t->string('period', 7);                             // YYYY-MM (monthly) or YYYY (annual)
            $t->string('type', 10)->default('monthly');
            $t->json('axes');
            $t->json('weights');
            $t->decimal('score', 5, 2);
            $t->string('grade', 5);
            $t->string('grade_label', 60);
            $t->json('details')->nullable();
            $t->foreignId('template_id')->nullable()->constrained('eval_templates')->nullOnDelete();
            $t->timestamp('published_at')->nullable();
            $t->unsignedBigInteger('published_by')->nullable();
            $t->string('objection_status', 10)->default('none');  // none | open | accepted | rejected
            $t->text('objection_text')->nullable();
            $t->timestamp('objection_at')->nullable();
            $t->text('objection_decision')->nullable();
            $t->unsignedBigInteger('objection_by')->nullable();
            $t->timestamps();
            $t->unique(['employee_id', 'period', 'type']);
        });

        Schema::create('dev_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('program', 160);
            $t->string('provider', 120)->nullable();
            $t->string('kind', 60)->nullable();                   // lookup dev_kinds
            $t->date('from')->nullable();
            $t->date('to')->nullable();
            $t->decimal('hours', 6, 1)->default(0);
            $t->decimal('cost', 10, 2)->default(0);
            $t->string('source', 10)->default('self');           // self | assigned
            $t->string('status', 10)->default('pending');        // planned | pending | verified | rejected
            $t->string('certificate_path')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('verified_by')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
            $t->index(['employee_id', 'status']);
        });

        Schema::create('violations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained();
            $t->string('type', 40);                              // lookup violation_types code
            $t->date('occurred_on');
            $t->text('description');
            $t->string('evidence', 300)->nullable();
            $t->text('statement')->nullable();                   // employee statement
            $t->timestamp('statement_at')->nullable();
            $t->unsignedTinyInteger('proposed_degree')->default(1);
            $t->unsignedTinyInteger('signed_degree')->nullable();
            $t->string('penalty', 20)->nullable();               // warning | written | deduct_days | deduct_pct | suspend | final
            $t->decimal('amount', 10, 2)->default(0);            // salary deduction amount (0 = non-financial)
            $t->string('period', 7)->nullable();                 // payroll period charged
            $t->date('effective_from')->nullable();
            $t->string('status', 20)->default('awaiting_statement'); // awaiting_statement | awaiting_decision | active | objected | cancelled | closed
            $t->string('objection', 10)->default('none');        // none | open | accepted | rejected
            $t->text('objection_text')->nullable();
            $t->text('objection_decision')->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->unsignedBigInteger('signed_by')->nullable();
            $t->unsignedBigInteger('hr_approved_by')->nullable();
            $t->timestamps();
            $t->index(['employee_id', 'status']);
            $t->index(['company_id', 'period']);
        });

        $this->seed();
    }

    private function seed(): void
    {
        $now = now();
        DB::table('report_templates')->insert([
            ['name' => 'القالب (أ) — المحاور والمؤشرات الوظيفية', 'scope' => 'group', 'scope_id' => null, 'due_day' => 5, 'items' => json_encode([
                ['title' => 'الالتزام بخطة العمل الشهرية', 'target' => 'إنجاز 100% من المهام المخططة', 'indicator' => 'نسبة المهام المغلقة', 'weight' => 40, 'source' => 'org'],
                ['title' => 'جودة المخرجات ودقتها', 'target' => 'صفر ملاحظات جوهرية', 'indicator' => 'عدد الملاحظات على المخرجات', 'weight' => 30, 'source' => 'org'],
                ['title' => 'التعاون وخدمة شركات المجموعة', 'target' => 'الاستجابة خلال يوم عمل', 'indicator' => 'تقدير الزملاء والمديرين', 'weight' => 30, 'source' => 'org'],
            ], JSON_UNESCAPED_UNICODE), 'allow_employee_items' => true, 'max_employee_items' => 3, 'employee_item_weight_max' => 15, 'version' => 1, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('eval_templates')->insert([
            'name' => 'القالب الافتراضي للمجموعة', 'scope' => 'group', 'scope_id' => null, 'pass_score' => 70,
            'weights' => json_encode(['achieve' => 50, 'attend' => 20, 'dev' => 20, 'disc' => 10]),
            'sub' => json_encode(['achieve' => ['targets' => 35, 'quality' => 15], 'attend' => ['presence' => 12, 'noLate' => 5, 'dailyNote' => 3],
                'dev' => ['complete' => 7, 'interact' => 4, 'apply' => 5, 'hrScore' => 4], 'disc' => ['weekly' => 4, 'coop' => 4, 'conduct' => 2]]),
            'grades' => json_encode([[95, 'A+', 'متميز'], [90, 'A', 'متفوق'], [85, 'B+', 'جيد جدًا مرتفع'], [80, 'B', 'جيد جدًا'], [75, 'C+', 'جيد'], [70, 'C', 'مقبول']], JSON_UNESCAPED_UNICODE),
            'version' => 1, 'effective_from' => $now->format('Y').'-01-01', 'active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        // lookup groups used by these modules
        $groups = [
            // ladder = degree for the 1st,2nd,3rd,4th occurrence within lookback_days; deduct_days applies when the signed degree is a financial one (5)
            'violation_types' => ['أنواع المخالفات', 'Violation types',
                [['key' => 'ladder', 'label' => 'سلّم الدرجات (1,2,3,5)', 'type' => 'text'], ['key' => 'lookback_days', 'label' => 'مدة الرجوع (يوم)', 'type' => 'number'], ['key' => 'deduct_days', 'label' => 'أيام الخصم عند الدرجة المالية', 'type' => 'number']],
                [['late', 'تكرار التأخر', 'Repeated lateness', ['ladder' => '1,2,3,5', 'lookback_days' => 180, 'deduct_days' => 0.5]],
                 ['absence', 'غياب بدون عذر', 'Unexcused absence', ['ladder' => '2,3,5,6', 'lookback_days' => 180, 'deduct_days' => 1]],
                 ['conduct', 'سلوك مهني', 'Professional conduct', ['ladder' => '2,3,4,6', 'lookback_days' => 365, 'deduct_days' => 0]],
                 ['policy', 'مخالفة سياسة', 'Policy breach', ['ladder' => '1,2,3,5', 'lookback_days' => 365, 'deduct_days' => 0.25]],
                 ['custody', 'إهمال عهدة', 'Custody negligence', ['ladder' => '2,3,5,5', 'lookback_days' => 365, 'deduct_days' => 0.5]],
                 ['report', 'عدم رفع التقارير', 'Missing reports', ['ladder' => '1,2,3,4', 'lookback_days' => 180, 'deduct_days' => 0]]]],
            'penalty_degrees' => ['درجات الجزاء', 'Penalty degrees', [['key' => 'financial', 'label' => 'جزاء مالي', 'type' => 'bool'], ['key' => 'terminates', 'label' => 'ينهي الخدمة', 'type' => 'bool']],
                [['1', 'لفت نظر شفهي', 'Oral warning', ['financial' => false, 'terminates' => false]], ['2', 'تنبيه كتابي', 'Written notice', ['financial' => false, 'terminates' => false]],
                 ['3', 'إنذار أول', 'First warning', ['financial' => false, 'terminates' => false]], ['4', 'إنذار نهائي', 'Final warning', ['financial' => false, 'terminates' => false]],
                 ['5', 'خصم مالي', 'Financial deduction', ['financial' => true, 'terminates' => false]], ['6', 'إيقاف عن العمل', 'Suspension', ['financial' => false, 'terminates' => false]],
                 ['7', 'إنهاء الخدمة', 'Termination', ['financial' => false, 'terminates' => true]]]],
            'dev_kinds' => ['أنواع برامج التطوير', 'Development program kinds', [], [['course', 'دورة إلكترونية', 'Online course', []], ['workshop', 'ورشة عمل', 'Workshop', []], ['path', 'مسار تعليمي', 'Learning path', []], ['cert', 'شهادة مهنية', 'Professional certificate', []], ['coaching', 'إرشاد وتوجيه', 'Coaching', []]]],
        ];
        foreach ($groups as $code => [$name, $en, $metaFields, $items]) {
            DB::table('lookup_groups')->updateOrInsert(['code' => $code], ['name' => $name, 'name_en' => $en, 'meta_fields' => json_encode($metaFields, JSON_UNESCAPED_UNICODE), 'builtin' => true, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($items as $i => [$c, $n, $ne, $meta]) {
                DB::table('lookups')->updateOrInsert(['group' => $code, 'code' => $c], ['name' => $n, 'name_en' => $ne, 'meta' => json_encode($meta), 'sort' => ($i + 1) * 10, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        \Illuminate\Support\Facades\Cache::forget('hc.lookups');
    }

    public function down(): void
    {
        foreach (['violations', 'dev_records', 'evaluations', 'eval_templates', 'weekly_reports', 'monthly_reports', 'report_templates'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
