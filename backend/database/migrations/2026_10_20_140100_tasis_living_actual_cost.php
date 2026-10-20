<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * §66: in tier 1 (تأسيس) living and local transport on a business trip are reimbursed at actual cost with receipts up to
 * 150 SAR a day — not a fixed per-diem. Applied only where the tier still carries the seeded flat 150 per-diem.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('tiers')->where('code', 'tasis')->get() as $t) {
            $b = json_decode($t->benefits ?? '{}', true) ?: [];
            if (isset($b['livingActualCap'])) {
                continue;
            }
            $flat = collect(['perDiemCeo', 'perDiemDir', 'perDiemMgr', 'perDiemSpec', 'perDiemStaff'])->every(fn ($k) => (float) ($b[$k] ?? 150) === 150.0);
            if ($flat) {
                foreach (['perDiemCeo', 'perDiemDir', 'perDiemMgr', 'perDiemSpec', 'perDiemStaff'] as $k) {
                    $b[$k] = 0;
                }
                $b['livingActualCap'] = 150;
                DB::table('tiers')->where('id', $t->id)->update(['benefits' => json_encode($b), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void {}
};
