<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Policy audit (chapters 6, 14–16):
 *  - monthly_reports.first_submitted_at: the first submission decides lateness (§35); a returned report keeps it.
 *  - improvement_plans.hr_reviewed_by / hr_reviewed_at: HR review stage before the company CEO approves (§75).
 *  - dev_records.applied_note: how the learning was applied at work (§77–78).
 *  - dev_activities hour boundaries (§78): <4h → 15; 4–10h inclusive → 30; >10–30h → 60; >30h → 100.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('monthly_reports', 'first_submitted_at')) {
            Schema::table('monthly_reports', function (Blueprint $t) {
                $t->timestamp('first_submitted_at')->nullable()->after('submitted_at');
            });
            DB::table('monthly_reports')->whereNotNull('submitted_at')->update(['first_submitted_at' => DB::raw('submitted_at')]);
        }
        if (! Schema::hasColumn('improvement_plans', 'hr_reviewed_by')) {
            Schema::table('improvement_plans', function (Blueprint $t) {
                $t->unsignedBigInteger('hr_reviewed_by')->nullable();
                $t->timestamp('hr_reviewed_at')->nullable();
            });
        }
        if (! Schema::hasColumn('dev_records', 'applied_note')) {
            Schema::table('dev_records', function (Blueprint $t) {
                $t->text('applied_note')->nullable();
            });
        }

        $bounds = [
            'short' => ['min_hours' => 0, 'max_hours' => 4, 'min_inclusive' => true, 'max_inclusive' => false],
            'course' => ['min_hours' => 4, 'max_hours' => 10, 'min_inclusive' => true, 'max_inclusive' => true],
            'program' => ['min_hours' => 10, 'max_hours' => 30, 'min_inclusive' => false, 'max_inclusive' => true],
            'intensive' => ['min_hours' => 30, 'max_hours' => null, 'min_inclusive' => false, 'max_inclusive' => false],
        ];
        foreach ($bounds as $code => $b) {
            $row = DB::table('lookups')->where('group', 'dev_activities')->where('code', $code)->first();
            if ($row) {
                $meta = json_decode($row->meta ?? '{}', true) ?: [];
                DB::table('lookups')->where('id', $row->id)->update(['meta' => json_encode(array_merge($meta, $b), JSON_UNESCAPED_UNICODE)]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('monthly_reports', 'first_submitted_at')) {
            Schema::table('monthly_reports', fn (Blueprint $t) => $t->dropColumn('first_submitted_at'));
        }
        if (Schema::hasColumn('improvement_plans', 'hr_reviewed_by')) {
            Schema::table('improvement_plans', fn (Blueprint $t) => $t->dropColumn(['hr_reviewed_by', 'hr_reviewed_at']));
        }
        if (Schema::hasColumn('dev_records', 'applied_note')) {
            Schema::table('dev_records', fn (Blueprint $t) => $t->dropColumn('applied_note'));
        }
    }
};
