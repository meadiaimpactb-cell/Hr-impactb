<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** HR decision: annual leave is 21 days for everyone, granted in full for the year (not accrued monthly). */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('tiers')->get() as $t) {
            $b = json_decode($t->benefits, true) ?: [];
            if (($b['annualLeave'] ?? null) == 21) {
                continue;
            }
            $before = $b['annualLeave'] ?? null;
            $b['annualLeave'] = 21;
            DB::table('tiers')->where('id', $t->id)->update(['benefits' => json_encode($b, JSON_UNESCAPED_UNICODE), 'version' => $t->version + 1, 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['entity' => 'tiers', 'entity_id' => (string) $t->id, 'action' => 'benefits', 'before' => json_encode(['annualLeave' => $before]),
                'after' => json_encode(['annualLeave' => 21]), 'reason' => 'قرار الموارد البشرية: رصيد سنوي 21 يومًا للجميع', 'channel' => 'system', 'created_at' => now()]);
        }
        DB::table('leave_types')->where('code', 'annual')->update(['accrual' => 'yearly', 'updated_at' => now()]);
    }

    public function down(): void {}
};
