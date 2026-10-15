<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the remaining gaps against the original specification:
 * month locking & reopen requests, HR bonus adjustment after approval, policy-change and custody requests,
 * role-restricted request types, peer kudos, selfie at punch, development catalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_reports', function (Blueprint $t) {
            $t->date('reopen_until')->nullable()->after('late');
            $t->string('adjust_reason', 300)->nullable()->after('hr_bonus');
        });
        Schema::table('attendance_records', fn (Blueprint $t) => $t->string('selfie_path')->nullable()->after('note'));
        Schema::table('request_types', fn (Blueprint $t) => $t->json('roles')->nullable()->after('on_behalf')); // who may raise it; null = everyone
        Schema::create('kudos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('text', 300);
            $t->string('period', 7);
            $t->timestamps();
            $t->index(['employee_id', 'period']);
        });

        $now = now();
        $types = [
            ['reopen', 'فتح شهر مقفل', 'Reopen locked month', 'PRF', 'reopen', [['role' => 'super']], null, true, false,
                [['key' => 'period', 'label' => 'الشهر (YYYY-MM)', 'label_en' => 'Month', 'type' => 'text', 'required' => true], ['key' => 'days', 'label' => 'عدد أيام الفتح', 'label_en' => 'Days', 'type' => 'number', 'required' => true], ['key' => 'reason', 'label' => 'السبب', 'label_en' => 'Reason', 'type' => 'textarea', 'required' => true]]],
            ['policy', 'تعديل سياسة', 'Policy change', 'ADM', 'policy', [['role' => 'super']], ['super', 'hr_mgr', 'ceo', 'fin'], true, false,
                [['key' => 'key', 'label' => 'مفتاح القاعدة', 'label_en' => 'Setting key', 'type' => 'text', 'required' => true], ['key' => 'value', 'label' => 'القيمة الجديدة', 'label_en' => 'New value', 'type' => 'text', 'required' => true], ['key' => 'company_id', 'label' => 'رقم الشركة (فارغ = المجموعة)', 'label_en' => 'Company id (blank = group)', 'type' => 'number', 'required' => false], ['key' => 'reason', 'label' => 'المبرر', 'label_en' => 'Justification', 'type' => 'textarea', 'required' => true]]],
            ['custody', 'طلب عهدة', 'Custody request', 'CUS', 'custody', [['role' => 'mgr'], ['role' => 'hr']], null, true, false,
                [['key' => 'kind', 'label' => 'نوع العهدة', 'label_en' => 'Custody type', 'type' => 'text', 'required' => true], ['key' => 'why', 'label' => 'المبرر', 'label_en' => 'Justification', 'type' => 'textarea', 'required' => true], ['key' => 'needed_by', 'label' => 'مطلوبة بتاريخ', 'label_en' => 'Needed by', 'type' => 'date', 'required' => false]]],
        ];
        $sort = (int) DB::table('request_types')->max('sort');
        foreach ($types as [$code, $name, $en, $op, $handler, $flow, $roles, $self, $behalf, $form]) {
            DB::table('request_types')->updateOrInsert(['code' => $code], ['name' => $name, 'name_en' => $en, 'op' => $op, 'handler' => $handler, 'flow' => json_encode($flow), 'form' => json_encode($form, JSON_UNESCAPED_UNICODE),
                'roles' => $roles ? json_encode($roles) : null, 'self_service' => $self, 'on_behalf' => $behalf, 'active' => true, 'builtin' => true, 'sort' => $sort += 10, 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('lookup_groups')->updateOrInsert(['code' => 'dev_catalog'], ['name' => 'كتالوج برامج التطوير', 'name_en' => 'Development catalogue', 'meta_fields' => json_encode([['key' => 'provider', 'label' => 'الجهة', 'type' => 'text'], ['key' => 'hours', 'label' => 'الساعات', 'type' => 'number'], ['key' => 'cost', 'label' => 'التكلفة', 'type' => 'number']], JSON_UNESCAPED_UNICODE), 'builtin' => true, 'created_at' => $now, 'updated_at' => $now]);
        foreach ([['excel', 'مهارات Excel المتقدمة', 'Advanced Excel', ['provider' => 'داخلي', 'hours' => 8, 'cost' => 0]], ['comm', 'مهارات التواصل المهني', 'Professional communication', ['provider' => 'دروب', 'hours' => 6, 'cost' => 0]], ['pm', 'أساسيات إدارة المشاريع', 'Project management basics', ['provider' => 'Coursera', 'hours' => 20, 'cost' => 500]]] as $i => [$c, $n, $ne, $meta]) {
            DB::table('lookups')->updateOrInsert(['group' => 'dev_catalog', 'code' => $c], ['name' => $n, 'name_en' => $ne, 'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'sort' => ($i + 1) * 10, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ([['annualMinMonths', 'أدنى عدد أشهر لاحتساب التقييم السنوي', 'التقارير', 'number', 6], ['selfieEnabled', 'صورة ذاتية عند البصمة', 'الحضور', 'bool', false], ['peerKudosEnabled', 'تفعيل الإشادة بين الزملاء', 'التواصل', 'bool', true], ['reportReminderDays', 'تذكير التقرير الشهري قبل الموعد (أيام)', 'التقارير', 'number', 3]] as $i => [$k, $l, $g, $t, $v]) {
            DB::table('setting_definitions')->updateOrInsert(['key' => $k], ['label' => $l, 'group' => $g, 'type' => $t, 'builtin' => true, 'sort' => 990 + $i, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('settings')->updateOrInsert(['key' => $k, 'company_id' => null], ['value' => json_encode($v), 'effective_from' => $now->toDateString(), 'reason' => 'الإعداد الأولي', 'created_at' => $now, 'updated_at' => $now]);
        }
        \Illuminate\Support\Facades\Cache::forget('hc.lookups');
        \Illuminate\Support\Facades\Cache::forget('hc.settings');
    }

    public function down(): void
    {
        Schema::dropIfExists('kudos');
        Schema::table('monthly_reports', fn (Blueprint $t) => $t->dropColumn(['reopen_until', 'adjust_reason']));
        Schema::table('attendance_records', fn (Blueprint $t) => $t->dropColumn('selfie_path'));
        Schema::table('request_types', fn (Blueprint $t) => $t->dropColumn('roles'));
    }
};
