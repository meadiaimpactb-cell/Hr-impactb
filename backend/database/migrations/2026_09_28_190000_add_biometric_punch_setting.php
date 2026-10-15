<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Policy flag: confirm identity with the phone's fingerprint/face before a punch (mobile). */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('setting_definitions')->updateOrInsert(['key' => 'biometricPunch'], ['label' => 'تأكيد الهوية ببصمة الجوال عند تسجيل الحضور', 'group' => 'الحضور', 'type' => 'bool', 'builtin' => true, 'sort' => 995, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('settings')->updateOrInsert(['key' => 'biometricPunch', 'company_id' => null], ['value' => 'true', 'effective_from' => $now->toDateString(), 'reason' => 'الإعداد الأولي', 'created_at' => $now, 'updated_at' => $now]);
        \Illuminate\Support\Facades\Cache::forget('hc.settings');
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'biometricPunch')->delete();
        DB::table('setting_definitions')->where('key', 'biometricPunch')->delete();
    }
};
