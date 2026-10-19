<?php

use App\Models\DevRecord;
use App\Services\EvaluationService;
use Illuminate\Database\Migrations\Migration;

/** Verified development records from before the points system get their points by hours (§78) in their completion month. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DevRecord::where('status', 'verified')->where('points', 0)->get() as $r) {
            $pf = EvaluationService::pointsFor($r->activity, $r->hours !== null ? (float) $r->hours : null);
            if ($pf['points'] > 0) {
                $r->update(['activity' => $pf['activity'], 'points' => $pf['points'], 'points_month' => ($r->to ?? $r->from ?? $r->verified_at ?? now())->format('Y-m')]);
            }
        }
    }

    public function down(): void {}
};
