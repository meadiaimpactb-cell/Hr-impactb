<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Attendance corrections are an HR matter: decided by the HR manager only (not the direct manager / group CEO). */
return new class extends Migration
{
    public function up(): void
    {
        $flow = json_encode([['role' => 'hr_mgr', 'label' => 'مدير الموارد البشرية']], JSON_UNESCAPED_UNICODE);
        DB::table('request_types')->where('code', 'correction')->update(['flow' => $flow, 'updated_at' => now()]);
        DB::table('approval_requests')->where('type', 'correction')->where('status', 'pending')->update(['flow' => $flow, 'stage' => 0]);
    }

    public function down(): void {}
};
