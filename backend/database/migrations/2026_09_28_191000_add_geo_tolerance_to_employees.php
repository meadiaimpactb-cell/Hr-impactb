<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** HR may widen the punch radius for a specific employee (extra metres) or allow punching from anywhere; plus a company-wide tolerance. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            $t->unsignedInteger('geo_tolerance')->default(0)->after('punch_required');   // extra metres added to every location radius
            $t->boolean('punch_anywhere')->default(false)->after('geo_tolerance');      // field staff / HR exception
        });
        $now = now();
        DB::table('setting_definitions')->updateOrInsert(['key' => 'geoToleranceMeters'], ['label' => 'هامش إضافي على نطاق التحضير للجميع (متر)', 'group' => 'الحضور', 'type' => 'number', 'builtin' => true, 'sort' => 996, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('settings')->updateOrInsert(['key' => 'geoToleranceMeters', 'company_id' => null], ['value' => '0', 'effective_from' => $now->toDateString(), 'reason' => 'الإعداد الأولي', 'created_at' => $now, 'updated_at' => $now]);
        \Illuminate\Support\Facades\Cache::forget('hc.settings');
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn(['geo_tolerance', 'punch_anywhere']));
    }
};
