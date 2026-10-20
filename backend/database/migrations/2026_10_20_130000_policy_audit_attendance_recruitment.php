<?php

use App\Services\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Policy audit — chapters 3 and 5 (attendance, flex/remote, recruitment) and appendix 5 (vacancy approval chain).
 *  §24 weekly cap 48 h + Ramadan 6 h/day for Muslim employees · §25 12 h presence cap + 30-min break after 5 h
 *  §27 150 m default fence, excuse before absence, paper register, 3-working-day correction window
 *  §29/30 weekly flex budget (absorbed minutes) + one remote day a week (athar tier)
 *  §14–17 vacancy in/out of plan with approval stages, acceptance criteria, conflict disclosure, offer contents
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', fn (Blueprint $t) => $t->unsignedInteger('radius')->default(150)->change());
        Schema::table('schedules', fn (Blueprint $t) => $t->unsignedSmallInteger('break_minutes')->default(30)->after('cross_midnight'));
        Schema::table('employees', fn (Blueprint $t) => $t->boolean('muslim')->default(true)->after('nationality')); // §24 Ramadan hours apply to Muslim employees
        Schema::table('attendance_records', function (Blueprint $t) {
            $t->string('status', 20)->default('present')->change(); // + excuse_requested
            $t->unsignedSmallInteger('flex_minutes')->default(0)->after('early_minutes'); // late minutes absorbed by the weekly flex budget
            $t->boolean('remote')->default(false)->after('geo_fail'); // §30 remote day
            $t->string('source', 10)->default('app')->after('device'); // app | paper | correction
            $t->text('excuse')->nullable();
            $t->timestamp('excuse_at')->nullable();
            $t->timestamp('excuse_due')->nullable();
        });
        Schema::table('job_openings', function (Blueprint $t) {
            $t->boolean('in_plan')->default(true)->after('headcount');
            $t->string('stage', 12)->default('draft')->after('status'); // draft | hr | fin | ceo | group | approved | rejected
            $t->text('criteria')->nullable()->after('description'); // §15 acceptance criteria — required before publishing
            $t->string('work_location', 160)->nullable();
            $t->json('approvals')->nullable();
        });
        DB::table('job_openings')->update(['stage' => 'approved']); // openings that already exist were opened before the chain
        Schema::table('applications', function (Blueprint $t) {
            $t->text('relation')->nullable()->after('notes'); // §16 candidate's disclosed relationship / interest
            $t->json('interviewers')->nullable(); // [{user_id, name, conflict, note, at}]
            $t->json('offer')->nullable(); // §17 offer contents
        });
        foreach ([
            'weeklyHours' => [48, 'الحد الأعلى لساعات العمل الأسبوعية (§24)', 'number'],
            'ramadanFrom' => ['', 'بداية فترة رمضان (YYYY-MM-DD) — 6 ساعات يوميًا للمسلمين (§24)', 'text'],
            'ramadanTo' => ['', 'نهاية فترة رمضان (YYYY-MM-DD)', 'text'],
            'ramadanDailyHours' => [6, 'ساعات العمل اليومية في رمضان', 'number'],
            'maxPresenceHours' => [12, 'أقصى بقاء في مكان العمل يوميًا (§25)', 'number'],
            'excuseDays' => [3, 'مهلة إبداء العذر عن حضور خارج النطاق (أيام عمل) (§27)', 'number'],
            'correctionDays' => [3, 'مهلة تقديم طلب تصحيح الحضور (أيام عمل) (§27)', 'number'],
        ] as $k => [$v, $label, $type]) {
            if (Settings::get($k) === null) {
                Settings::set($k, $v, null, 'اللائحة — الباب الخامس');
            }
            DB::table('setting_definitions')->updateOrInsert(['key' => $k], ['label' => $label, 'group' => 'الحضور', 'type' => $type, 'builtin' => true, 'sort' => 996, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('applications', fn (Blueprint $t) => $t->dropColumn(['relation', 'interviewers', 'offer']));
        Schema::table('job_openings', fn (Blueprint $t) => $t->dropColumn(['in_plan', 'stage', 'criteria', 'work_location', 'approvals']));
        Schema::table('attendance_records', fn (Blueprint $t) => $t->dropColumn(['flex_minutes', 'remote', 'source', 'excuse', 'excuse_at', 'excuse_due']));
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn('muslim'));
        Schema::table('schedules', fn (Blueprint $t) => $t->dropColumn('break_minutes'));
        Schema::table('locations', fn (Blueprint $t) => $t->unsignedInteger('radius')->default(100)->change());
    }
};
