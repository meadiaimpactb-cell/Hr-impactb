<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner's notes «الملاحظات على المنصات»:
 *  · announcements pop up on the site and the app until read (popup), a manager alerts his own team
 *  · a missing daily achievement leaves the day pending instead of blocking the check-out (note_pending)
 *  · the direct manager sets each employee's weekly-report deadline (weekly_due: end of week / first day of the next)
 *  · the work week follows the schedule: Sunday–Thursday, or Saturday–Thursday for six-day employees (week_pattern)
 *  · package allowance items paid with the salary (benefitTypes); employee benefit adjustments go
 *    direct manager → HR staff → HR manager → group CEO
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        if (! Schema::hasColumn('announcements', 'popup')) {
            Schema::table('announcements', fn (Blueprint $t) => $t->boolean('popup')->default(true)->after('require_ack'));
        }
        if (! Schema::hasColumn('attendance_records', 'note_pending')) {
            Schema::table('attendance_records', fn (Blueprint $t) => $t->boolean('note_pending')->default(false)->after('note_required')->index());
        }
        if (! Schema::hasColumn('employees', 'weekly_due')) {
            Schema::table('employees', fn (Blueprint $t) => $t->string('weekly_due', 8)->nullable()->after('weekly_report_required'));
        }

        // days already checked out without the required achievement are pending until it is written
        DB::table('attendance_records')->where('note_required', true)->whereNotNull('check_in')
            ->where(fn ($q) => $q->whereNull('note')->orWhereRaw('CHAR_LENGTH(TRIM(note)) < 20'))->update(['note_pending' => true]);

        // week pattern from the schedule / own hours (6 working days → Saturday–Thursday)
        $schedules = DB::table('schedules')->get(['id', 'week_days'])->keyBy('id');
        foreach (DB::table('employees')->get(['id', 'schedule_id', 'work_hours', 'week_pattern']) as $e) {
            $own = json_decode((string) $e->work_hours, true);
            $days = is_array($own) && $own ? count(array_filter($own, fn ($h) => is_array($h) && count($h) === 2))
                : count(json_decode((string) ($schedules[$e->schedule_id]->week_days ?? '[]'), true) ?: []);
            if ($days) {
                $p = $days >= 6 ? '6' : '5';
                if ($p !== (string) $e->week_pattern) {
                    DB::table('employees')->where('id', $e->id)->update(['week_pattern' => $p]);
                }
            }
        }

        $def = function (string $key, string $label, string $group, string $type, $value = null) use ($now) {
            $row = ['label' => $label, 'group' => $group, 'type' => $type, 'updated_at' => $now];
            DB::table('setting_definitions')->where('key', $key)->exists()
                ? DB::table('setting_definitions')->where('key', $key)->update($row)
                : DB::table('setting_definitions')->insert($row + ['key' => $key, 'builtin' => true, 'sort' => 60, 'created_at' => $now]);
            if ($value !== null && ! DB::table('settings')->where('key', $key)->whereNull('company_id')->exists()) {
                DB::table('settings')->insert(['key' => $key, 'value' => json_encode($value), 'company_id' => null, 'version' => 1, 'effective_from' => $now->toDateString(),
                    'reason' => 'ملاحظات المنصة', 'created_at' => $now, 'updated_at' => $now]);
            }
        };
        $def('benefitTypes', 'بنود الباقات التي تُصرف بدلًا شهريًا مع الراتب', 'الباقات', 'json', []);
        $def('dailyNoteLateDays', 'مهلة كتابة المنجز اليومي لليوم المعلّق (أيام)', 'الحضور', 'number', 7);
        $def('weeklyDueRule', 'موعد رفع التقرير الأسبوعي (end = آخر يوم في الأسبوع، next = أول يوم من الأسبوع التالي)', 'التقارير', 'text', 'next');

        // benefit adjustments for one employee: proposed by HR, approved by the direct manager, HR staff, the HR manager,
        // then the group CEO (owner's note) — only where the flow is still the shipped default
        $chain = [['role' => 'mgr', 'label' => 'المشرف المباشر'], ['role' => 'hr_staff', 'label' => 'موظف الموارد البشرية'], ['role' => 'hr_mgr', 'label' => 'مدير الموارد البشرية']];
        $defaults = [
            'allowance' => [json_encode([['role' => 'mgr', 'label' => 'المشرف المباشر'], ['role' => 'fin', 'label' => 'المدير المالي'], ['role' => 'super', 'label' => 'الرئيس التنفيذي للمجموعة']], JSON_UNESCAPED_UNICODE),
                [...$chain, ['role' => 'fin', 'label' => 'المدير المالي'], ['role' => 'super', 'label' => 'الرئيس التنفيذي للمجموعة']]],
            'balance_adj' => [json_encode([['role' => 'super']]), [...$chain, ['role' => 'super', 'label' => 'الرئيس التنفيذي للمجموعة']]],
        ];
        foreach ($defaults as $code => [$old, $new]) {
            $cur = DB::table('request_types')->where('code', $code)->value('flow');
            if ($cur !== null && json_decode($cur, true) == json_decode($old, true)) {
                DB::table('request_types')->where('code', $code)->update(['flow' => json_encode($new, JSON_UNESCAPED_UNICODE), 'updated_at' => $now]);
            }
        }
        Cache::forget('hc.settings');
        Cache::forget('hc.lookups');
    }

    public function down(): void
    {
        foreach ([['announcements', 'popup'], ['attendance_records', 'note_pending'], ['employees', 'weekly_due']] as [$t, $c]) {
            if (Schema::hasColumn($t, $c)) {
                Schema::table($t, fn (Blueprint $b) => $b->dropColumn($c));
            }
        }
    }
};
