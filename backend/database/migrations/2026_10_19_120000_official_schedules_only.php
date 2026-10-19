<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Only the group's real working schedules remain: the old demo schedules ("الدوام القياسي 9–5" and "دوام ستة أيام") are removed and
 * their employees / companies move to the official 10:00–19:00 schedule. Day names in schedule titles carry the article
 * ("الأحد–الخميس", "السبت–الخميس").
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('schedules')->get(['id', 'name']) as $s) {
            $n = strtr($s->name, ['(أحد–خميس)' => '(الأحد–الخميس)', '(سبت–خميس)' => '(السبت–الخميس)', '(أحد-خميس)' => '(الأحد–الخميس)', '(سبت-خميس)' => '(السبت–الخميس)']);
            if ($n !== $s->name) {
                DB::table('schedules')->where('id', $s->id)->update(['name' => $n]);
            }
        }
        $official = DB::table('schedules')->where('name', 'like', 'الدوام الرسمي 10:00–19:00%')->value('id');
        if (! $official) {
            return;
        }
        $old = DB::table('schedules')->where(fn ($q) => $q->where('name', 'like', 'الدوام القياسي 9–5%')->orWhere('name', 'like', 'دوام ستة أيام%'))->pluck('id');
        if ($old->isEmpty()) {
            return;
        }
        DB::table('employees')->whereIn('schedule_id', $old)->update(['schedule_id' => $official]);
        DB::table('companies')->whereIn('default_schedule_id', $old)->update(['default_schedule_id' => $official]);
        DB::table('schedules')->whereIn('id', $old)->delete();
    }

    public function down(): void {}
};
