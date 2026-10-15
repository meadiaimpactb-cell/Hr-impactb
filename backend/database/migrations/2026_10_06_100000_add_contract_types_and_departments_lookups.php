<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Contract types and departments become editable lists (lookups) instead of hard-coded values / free text. */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('lookup_groups')->updateOrInsert(['code' => 'contract_types'], ['name' => 'أنواع التعاقد', 'name_en' => 'Contract types', 'builtin' => true, 'created_at' => $now, 'updated_at' => $now]);
        foreach ([['full', 'دوام كامل', 'Full-time'], ['part', 'دوام جزئي', 'Part-time'], ['collab', 'متعاون بالمهام', 'Task-based collaborator']] as $i => [$code, $name, $en]) {
            DB::table('lookups')->updateOrInsert(['group' => 'contract_types', 'code' => $code], ['name' => $name, 'name_en' => $en, 'sort' => ($i + 1) * 10, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        DB::table('lookup_groups')->updateOrInsert(['code' => 'departments'], ['name' => 'الإدارات والأقسام', 'name_en' => 'Departments', 'builtin' => true, 'created_at' => $now, 'updated_at' => $now]);
        $i = 0;
        foreach (DB::table('employees')->whereNotNull('dept')->where('dept', '!=', '')->distinct()->orderBy('dept')->pluck('dept') as $dept) {
            DB::table('lookups')->updateOrInsert(['group' => 'departments', 'name' => $dept], ['code' => 'd'.(++$i).substr(md5($dept), 0, 4), 'sort' => $i * 10, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        \Illuminate\Support\Facades\Cache::forget('hc.lookups');
    }

    public function down(): void
    {
        DB::table('lookups')->whereIn('group', ['contract_types', 'departments'])->delete();
        DB::table('lookup_groups')->whereIn('code', ['contract_types', 'departments'])->delete();
    }
};
