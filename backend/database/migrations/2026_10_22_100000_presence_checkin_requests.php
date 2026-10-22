<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner's note (22/10): «طلب تحضير» is sent to employees who are late and have NOT checked in (kind = checkin) — an
 * employee who has checked in is not asked again. Open requests sent to checked-in employees are closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('presence_checks', 'kind')) {
            Schema::table('presence_checks', fn (Blueprint $t) => $t->string('kind', 10)->default('presence')->after('company_id'));
        }
        DB::table('presence_checks')->where('kind', 'presence')->where('status', 'pending')
            ->update(['status' => 'cancelled', 'note' => 'أُلغي: الموظف مسجّل حضوره — لا يُطلب منه تحضير ثانٍ', 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('presence_checks', 'kind')) {
            Schema::table('presence_checks', fn (Blueprint $t) => $t->dropColumn('kind'));
        }
    }
};
