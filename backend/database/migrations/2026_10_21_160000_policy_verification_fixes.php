<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Point-by-point verification against policy 2.x, the owner's notes and the authority matrix (annex 5):
 *  · flows back to the matrix: allowance change mgr → finance → group CEO; leave-balance adjustment prepared by HR,
 *    approved by the group CEO; reopening a locked month prepared by HR, approved by the group CEO; an exceptional
 *    benefit for one employee is proposed by the HR manager (§49)
 *  · special leave limits per case (§59): marriage 5, bereavement 5, maternity 84 days; paid learning days (§52, devDays)
 *  · a seeded six-day schedule brought within 48 h a week (§24); classification changes take effect on their date (§49)
 *  · presence-check expiry voids the check-in (owner's note), nursing hour (§60), referral bonus (§87),
 *    probation termination without end-of-service award (§110), skills field of the development-programme request
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $flow = fn (array $roles) => json_encode(array_map(fn ($r) => ['role' => $r[0], 'label' => $r[1]], $roles), JSON_UNESCAPED_UNICODE);
        $mgr = ['mgr', 'المشرف المباشر'];
        $fin = ['fin', 'المدير المالي'];
        $super = ['super', 'الرئيس التنفيذي للمجموعة'];
        $hrMgr = ['hr_mgr', 'مدير الموارد البشرية'];
        DB::table('request_types')->where('code', 'allowance')->update(['flow' => $flow([$mgr, $fin, $super]), 'updated_at' => $now]);
        DB::table('request_types')->where('code', 'balance_adj')->update(['flow' => $flow([$super]), 'roles' => json_encode(['hr_mgr', 'hr_staff', 'super']), 'updated_at' => $now]);
        DB::table('request_types')->where('code', 'benefit')->update(['flow' => $flow([$mgr, ['hr_staff', 'موظف الموارد البشرية'], $hrMgr, $super]), 'roles' => json_encode(['hr_mgr', 'super']), 'updated_at' => $now]);
        DB::table('request_types')->where('code', 'reopen')->update(['flow' => $flow([$hrMgr, $super]), 'updated_at' => $now]);

        // development-programme request: the skills wanted get their own field (owner's note)
        $dev = DB::table('request_types')->where('code', 'dev_program')->first();
        if ($dev) {
            $form = json_decode((string) $dev->form, true) ?: [];
            if (! collect($form)->contains(fn ($f) => ($f['key'] ?? '') === 'skills')) {
                array_splice($form, 1, 0, [['key' => 'skills', 'type' => 'textarea', 'label' => 'المهارات المستهدفة', 'label_en' => 'Target skills', 'required' => true]]);
                DB::table('request_types')->where('code', 'dev_program')->update(['form' => json_encode($form, JSON_UNESCAPED_UNICODE), 'updated_at' => $now]);
            }
        }

        foreach (['marriage' => 5, 'bereavement' => 5, 'maternity' => 84] as $code => $days) {
            DB::table('leave_types')->where('code', $code)->whereNull('max_per_case')->update(['max_per_case' => $days, 'updated_at' => $now]);
        }
        if (! DB::table('leave_types')->where('code', 'learning')->exists()) {
            DB::table('leave_types')->insert(['code' => 'learning', 'name' => 'يوم تعلم مدفوع', 'name_en' => 'Paid learning day', 'accrual' => 'yearly', 'quota_key' => 'devDays',
                'deducts' => true, 'attachment_required' => false, 'carry_over' => false, 'pay' => 'full', 'max_per_case' => null, 'extra_approval' => null, 'sort' => 90, 'active' => true,
                'created_at' => $now, 'updated_at' => $now]);
        }

        // §24/§25: 8 working hours a day over six days; the 30-minute break is outside working time
        foreach (DB::table('schedules')->where('name', 'like', '%07:00–16:00 (السبت–الخميس)%')->get() as $s) {
            $hours = json_decode((string) $s->hours, true) ?: [];
            foreach ($hours as $d => $h) {
                $hours[$d] = ['07:00', '15:30'];
            }
            DB::table('schedules')->where('id', $s->id)->update(['hours' => json_encode($hours), 'name' => str_replace('07:00–16:00', '07:00–15:30', $s->name), 'updated_at' => $now]);
        }

        if (! Schema::hasColumn('companies', 'next_tier_id')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->foreignId('next_tier_id')->nullable()->after('tier_id');
                $t->date('next_tier_from')->nullable()->after('next_tier_id');
            });
        }
        if (! Schema::hasColumn('attendance_records', 'voided_check_in')) {
            Schema::table('attendance_records', fn (Blueprint $t) => $t->dateTime('voided_check_in')->nullable()->after('check_in'));
        }
        if (! Schema::hasColumn('employees', 'nursing_until')) {
            Schema::table('employees', fn (Blueprint $t) => $t->date('nursing_until')->nullable()->after('probation_status'));
        }
        if (! Schema::hasColumn('applications', 'referred_by')) {
            Schema::table('applications', fn (Blueprint $t) => $t->foreignId('referred_by')->nullable()->after('source'));
        }
        DB::table('lookups')->updateOrInsert(['group' => 'termination_reasons', 'code' => 'probation'], ['name' => 'إنهاء خلال فترة التجربة', 'name_en' => 'Termination during probation',
            'meta' => json_encode(['eosb_factor' => '0']), 'sort' => 15, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);

        $def = function (string $key, string $label, string $group, string $type, $value) use ($now) {
            $row = ['label' => $label, 'group' => $group, 'type' => $type, 'updated_at' => $now];
            DB::table('setting_definitions')->where('key', $key)->exists()
                ? DB::table('setting_definitions')->where('key', $key)->update($row)
                : DB::table('setting_definitions')->insert($row + ['key' => $key, 'builtin' => true, 'sort' => 61, 'created_at' => $now]);
            if (! DB::table('settings')->where('key', $key)->whereNull('company_id')->exists()) {
                DB::table('settings')->insert(['key' => $key, 'value' => json_encode($value), 'company_id' => null, 'version' => 1, 'effective_from' => $now->toDateString(),
                    'reason' => 'مطابقة اللائحة', 'created_at' => $now, 'updated_at' => $now]);
            }
        };
        $def('mgrReviewDueDay', 'آخر يوم لتقييم المشرف للتقرير الشهري (0 = آخر يوم في الشهر) §36', 'التقارير', 'number', 0);
        $def('referralBonus', 'مكافأة إحالة التوظيف بعد اجتياز المرشح التجربة (ريال) §87', 'الحوافز', 'number', 1000);
        $def('nursingMinutes', 'فترة الرضاعة اليومية (دقيقة) §60', 'الحضور', 'number', 60);

        Cache::forget('hc.settings');
        Cache::forget('hc.lookups');
    }

    public function down(): void
    {
        foreach ([['companies', 'next_tier_id'], ['companies', 'next_tier_from'], ['attendance_records', 'voided_check_in'], ['employees', 'nursing_until'], ['applications', 'referred_by']] as [$t, $c]) {
            if (Schema::hasColumn($t, $c)) {
                Schema::table($t, fn (Blueprint $b) => $b->dropColumn($c));
            }
        }
    }
};
