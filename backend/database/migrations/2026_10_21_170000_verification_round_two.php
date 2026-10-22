<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verification, second pass: the points of a long (intensive) programme are spread over the months it ran (§78), and a
 * change of evaluation weights applies from the next cycle — the version in force until then is kept (§34).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('dev_records', 'points_split')) {
            Schema::table('dev_records', fn (Blueprint $t) => $t->json('points_split')->nullable()->after('points_month'));
        }
        if (! Schema::hasColumn('eval_templates', 'previous')) {
            Schema::table('eval_templates', fn (Blueprint $t) => $t->json('previous')->nullable()->after('effective_from'));
        }
    }

    public function down(): void
    {
        foreach ([['dev_records', 'points_split'], ['eval_templates', 'previous']] as [$t, $c]) {
            if (Schema::hasColumn($t, $c)) {
                Schema::table($t, fn (Blueprint $b) => $b->dropColumn($c));
            }
        }
    }
};
