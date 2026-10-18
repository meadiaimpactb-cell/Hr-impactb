<?php

use App\Services\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Policy v2.0 — approval chains and operating rules:
 *  §12  every request starts with the direct manager;
 *  §61  leave: manager → company CEO → HR manager (final);
 *  §42  advance: manager → company CEO → HR → finance → group CEO; probation 180 days (§20);
 *  §65  business trip: manager → HR → finance (international adds company CEO and group CEO in code);
 *  §27  attendance correction: manager → HR manager;  §114 letter: manager → HR;
 *  §44  custody: manager → HR (in-kind) / finance (cash; > 3,000 adds the group CEO — in code);
 *  §35  monthly report due on the 26th of the same month;  §108 resignation by letter (request type retired);
 *  §17  identity: national id / iqama (10 digits) or passport.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mgr = ['role' => 'mgr', 'label' => 'المشرف المباشر'];
        $ceo = ['role' => 'ceo', 'label' => 'الرئيس التنفيذي للشركة'];
        $hr = ['role' => 'hr_mgr', 'label' => 'مدير الموارد البشرية'];
        $fin = ['role' => 'fin', 'label' => 'المدير المالي'];
        $group = ['role' => 'super', 'label' => 'الرئيس التنفيذي للمجموعة'];
        $flows = [
            'leave' => [$mgr, $ceo, $hr], 'advance' => [$mgr, $ceo, $hr, $fin, $group], 'trip' => [$mgr, $hr, $fin],
            'letter' => [$mgr, $hr], 'correction' => [$mgr, $hr], 'custody' => [$mgr, $hr], 'equipment' => [$mgr, $hr],
        ];
        foreach ($flows as $code => $flow) {
            DB::table('request_types')->where('code', $code)->update(['flow' => json_encode($flow, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        }
        // custody requests carry the amount when they are cash custody (cap 3,000 — §44)
        $custody = DB::table('request_types')->where('code', 'custody')->first();
        if ($custody) {
            $form = json_decode($custody->form ?: '[]', true) ?: [];
            if (! collect($form)->contains(fn ($f) => ($f['key'] ?? '') === 'amount')) {
                $form[] = ['key' => 'amount', 'label' => 'المبلغ — للعهدة النقدية فقط (ريال)', 'label_en' => 'Amount (cash custody only)', 'type' => 'number', 'required' => false];
                DB::table('request_types')->where('code', 'custody')->update(['form' => json_encode($form, JSON_UNESCAPED_UNICODE)]);
            }
        }
        // resignation is filed as a letter and recorded by HR (§108) — the self-service request type is retired
        DB::table('request_types')->where('code', 'resignation')->update(['active' => false, 'updated_at' => now()]);

        foreach ([
            'monthlyReportDueDay' => [26, 'اللائحة 2.0 §35: الرفع حتى يوم 26 من الشهر نفسه'],
            'noticeDaysMonthly' => [30, 'اللائحة 2.0 §108: إشعار الموظف 30 يومًا'],
            'noticeDaysEmployer' => [60, 'اللائحة 2.0 §108: إشعار صاحب العمل 60 يومًا'],
            'probationDays' => [180, 'اللائحة 2.0 §20'],
            'advanceMinAmount' => [100, 'اللائحة 2.0 §42'],
            'custodyCashCap' => [3000, 'اللائحة 2.0 §44'],
            'custodySettleDays' => [10, 'اللائحة 2.0 §44: التسوية خلال 10 أيام عمل'],
            'custodySettleMaxDays' => [30, 'اللائحة 2.0 §44: بحد أقصى 30 يومًا من الصرف'],
            'reportLatePenalty' => [3, 'اللائحة 2.0 §35'],
            'reportMissingPenalty' => [10, 'اللائحة 2.0 §35'],
        ] as $key => [$value, $reason]) {
            Settings::set($key, $value, null, $reason);
        }
        if (Schema::hasColumn('report_templates', 'due_day')) {
            DB::table('report_templates')->update(['due_day' => 26]);
        }
        if (! Schema::hasColumn('employees', 'id_type')) {
            Schema::table('employees', fn (Blueprint $t) => $t->string('id_type', 12)->default('national')->after('national_id'));
        }
        // group CEO title everywhere the old label was stored
        DB::table('job_titles')->where('name', 'المدير العام للمجموعة')->update(['name' => 'الرئيس التنفيذي للمجموعة', 'name_en' => 'Group Chief Executive Officer']);
    }

    public function down(): void {}
};
