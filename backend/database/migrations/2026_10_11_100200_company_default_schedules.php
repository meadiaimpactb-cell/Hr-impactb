<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Meeting notes 10.1–10.3: each company has a default schedule (new employees get it), the official hours of
 * Impact Makers, Impact Valley Capital, Professional Trips and Professional Fellowships are 10:00–19:00
 * Sunday–Thursday, and Amad Craft works in shifts (morning 07:00–16:00 and sales outlet 16:00–23:00, six days).
 * Existing employees of those four companies on the old 9–5 schedule (without personal hours) move to 10–19.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'default_schedule_id')) {
            Schema::table('companies', fn (Blueprint $t) => $t->foreignId('default_schedule_id')->nullable()->after('tier_id')->constrained('schedules')->nullOnDelete());
        }
        $make = function (string $name, array $days, string $from, string $to) {
            $id = DB::table('schedules')->where('name', $name)->value('id');
            if ($id) {
                return $id;
            }

            return DB::table('schedules')->insertGetId(['name' => $name, 'week_days' => json_encode($days), 'hours' => json_encode(collect($days)->mapWithKeys(fn ($d) => [(string) $d => [$from, $to]])->all()),
                'flex' => false, 'cross_midnight' => false, 'week_pattern' => count($days) > 5 ? '6' : '5', 'created_at' => now(), 'updated_at' => now()]);
        };
        $office = $make('الدوام الرسمي 10:00–19:00 (أحد–خميس)', [0, 1, 2, 3, 4], '10:00', '19:00');
        $morning = $make('أمد الحرف — الوردية الصباحية 07:00–16:00 (سبت–خميس)', [6, 0, 1, 2, 3, 4], '07:00', '16:00');
        $make('أمد الحرف — منفذ البيع 16:00–23:00 (سبت–خميس)', [6, 0, 1, 2, 3, 4], '16:00', '23:00');

        $old = DB::table('schedules')->where('name', 'like', 'الدوام القياسي 9–5%')->value('id');
        foreach (['IMPACTB', 'IVC', 'PTRIPS', 'PFEL'] as $code) {
            $cid = DB::table('companies')->where('code', $code)->value('id');
            if (! $cid) {
                continue;
            }
            DB::table('companies')->where('id', $cid)->update(['default_schedule_id' => $office]);
            DB::table('employees')->where('company_id', $cid)->where(fn ($q) => $q->whereNull('schedule_id')->orWhere('schedule_id', $old))
                ->where(fn ($q) => $q->whereNull('work_hours')->orWhere('work_hours', '[]')->orWhere('work_hours', '{}'))->update(['schedule_id' => $office]);
        }
        DB::table('companies')->where('code', 'AMAD')->update(['default_schedule_id' => $morning]);
        if ($old) {
            DB::table('companies')->whereNull('default_schedule_id')->update(['default_schedule_id' => $old]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'default_schedule_id')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->dropConstrainedForeignId('default_schedule_id');
            });
        }
    }
};
