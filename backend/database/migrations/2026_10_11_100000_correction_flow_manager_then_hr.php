<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Attendance corrections change attendance records, so after the direct manager they also reach HR (meeting note 1.3). */
return new class extends Migration
{
    public function up(): void
    {
        $flow = [['role' => 'mgr', 'label' => 'المدير المباشر'], ['role' => 'hr', 'label' => 'الموارد البشرية']];
        $t = DB::table('request_types')->where('code', 'correction')->first();
        if ($t && count(json_decode($t->flow, true) ?: []) <= 1) {
            DB::table('request_types')->where('id', $t->id)->update(['flow' => json_encode($flow, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        }
        foreach (DB::table('approval_requests')->where('type', 'correction')->where('status', 'pending')->get() as $r) {
            $f = json_decode($r->flow, true) ?: [];
            if (! collect($f)->contains(fn ($s) => in_array($s['role'] ?? '', ['hr', 'hr_mgr', 'hr_staff'], true))) {
                $f[] = ['role' => 'hr', 'label' => 'الموارد البشرية'];
                DB::table('approval_requests')->where('id', $r->id)->update(['flow' => json_encode($f, JSON_UNESCAPED_UNICODE)]);
            }
        }
    }

    public function down(): void {}
};
