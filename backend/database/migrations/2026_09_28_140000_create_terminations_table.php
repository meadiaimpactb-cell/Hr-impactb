<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** End of service: case, offboarding clearance, EOSB, settlement with 4-stage approval and payment. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained();
            $t->string('reason', 30);                        // lookup termination_reasons
            $t->date('requested_at');
            $t->unsignedSmallInteger('notice_days')->default(0);
            $t->date('last_working_day');
            $t->string('note', 500)->nullable();
            $t->unsignedBigInteger('request_id')->nullable(); // resignation request
            $t->json('clearance');                           // [{key,label,owner,done,evidence,by,at}]
            $t->json('eosb');                                // computed breakdown
            $t->decimal('eosb_adjusted', 12, 2)->nullable();
            $t->string('eosb_adjust_reason', 300)->nullable();
            $t->json('settlement')->nullable();              // {lines:[{key,name,amount}], total, version}
            $t->unsignedTinyInteger('stage')->default(0);    // 0..4 approvals done (mgr, hr, ceo, fin)
            $t->json('approvals')->nullable();               // [{role,by,at,note}]
            $t->string('status', 15)->default('open');       // open | settled | paid | cancelled
            $t->string('payment', 10)->default('none');      // none | sent | paid
            $t->timestamp('paid_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status']);
        });

        $now = now();
        $groups = [
            'termination_reasons' => ['أسباب إنهاء الخدمة', 'Termination reasons', [['key' => 'eosb_factor', 'label' => 'معامل المكافأة (فارغ = حسب النظام)', 'type' => 'text']],
                [['resign', 'استقالة', 'Resignation', ['eosb_factor' => '']], ['contract_end', 'انتهاء مدة العقد', 'Contract end', ['eosb_factor' => '1']], ['employer', 'إنهاء من صاحب العمل', 'Employer termination', ['eosb_factor' => '1']],
                 ['misconduct', 'فصل لمخالفة جسيمة', 'Gross misconduct', ['eosb_factor' => '0']], ['force', 'قوة قاهرة', 'Force majeure', ['eosb_factor' => '1']], ['special', 'حالات المرأة الخاصة', 'Special cases (women)', ['eosb_factor' => '1']],
                 ['retire', 'تقاعد', 'Retirement', ['eosb_factor' => '1']], ['death', 'وفاة', 'Death', ['eosb_factor' => '1']], ['transfer', 'نقل داخلي مع تصفية', 'Internal transfer with settlement', ['eosb_factor' => '1']]]],
            'offboarding_tasks' => ['مهام إخلاء الطرف', 'Offboarding tasks', [['key' => 'owner', 'label' => 'الجهة المسؤولة (it/fin/hr/mgr)', 'type' => 'text']],
                [['devices', 'استرداد الأجهزة والعهد', 'Recover devices & custody', ['owner' => 'it']], ['accounts', 'إغلاق الحسابات والبريد', 'Close accounts & email', ['owner' => 'it']],
                 ['finance', 'تسوية السلف والعهد المالية', 'Settle advances & cash custody', ['owner' => 'fin']], ['insurance', 'الإلغاء من التأمين', 'Remove from insurance', ['owner' => 'hr']],
                 ['handover', 'تسليم الأعمال', 'Work handover', ['owner' => 'mgr']], ['eosb', 'احتساب مكافأة نهاية الخدمة', 'Compute EOSB', ['owner' => 'hr']],
                 ['certificate', 'شهادة الخبرة', 'Experience certificate', ['owner' => 'hr']], ['clearance', 'إخلاء الطرف النهائي', 'Final clearance', ['owner' => 'hr']]]],
        ];
        foreach ($groups as $code => [$name, $en, $metaFields, $items]) {
            DB::table('lookup_groups')->updateOrInsert(['code' => $code], ['name' => $name, 'name_en' => $en, 'meta_fields' => json_encode($metaFields, JSON_UNESCAPED_UNICODE), 'builtin' => true, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($items as $i => [$c, $n, $ne, $meta]) {
                DB::table('lookups')->updateOrInsert(['group' => $code, 'code' => $c], ['name' => $n, 'name_en' => $ne, 'meta' => json_encode($meta), 'sort' => ($i + 1) * 10, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        foreach ([['eosbResignFactors', 'معاملات مكافأة الاستقالة (lt2, lt5, lt10, gte10)', 'إنهاء الخدمة', 'json'], ['tripFinanceCap', 'سقف الرحلة الذي يستلزم اعتماد المالية', 'الطلبات', 'number']] as $i => [$k, $l, $g, $t]) {
            DB::table('setting_definitions')->updateOrInsert(['key' => $k], ['label' => $l, 'group' => $g, 'type' => $t, 'builtin' => true, 'sort' => 900 + $i, 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('settings')->updateOrInsert(['key' => 'eosbResignFactors', 'company_id' => null], ['value' => json_encode(['lt2' => 0, 'lt5' => 0.3333, 'lt10' => 0.6667, 'gte10' => 1]), 'effective_from' => $now->toDateString(), 'reason' => 'الإعداد الأولي', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('settings')->updateOrInsert(['key' => 'tripFinanceCap', 'company_id' => null], ['value' => '5000', 'effective_from' => $now->toDateString(), 'reason' => 'الإعداد الأولي', 'created_at' => $now, 'updated_at' => $now]);
        \Illuminate\Support\Facades\Cache::forget('hc.lookups');
    }

    public function down(): void
    {
        Schema::dropIfExists('terminations');
    }
};
