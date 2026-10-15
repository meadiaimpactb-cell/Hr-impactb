<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Group approval chain for employee requests: the company's chief executive → HR manager → the group's chief
 * executive (final). Advances keep the finance manager before the final approval. Attendance corrections stay with
 * the HR manager only. Also sets Professional Fellowships' chief executive (Dr. Mushari Al-Shahrani).
 */
return new class extends Migration
{
    public function up(): void
    {
        $ceo = ['role' => 'ceo', 'label' => 'المدير التنفيذي للشركة'];
        $hr = ['role' => 'hr_mgr', 'label' => 'مدير الموارد البشرية'];
        $fin = ['role' => 'fin', 'label' => 'المدير المالي'];
        $group = ['role' => 'super', 'label' => 'المدير التنفيذي للمجموعة'];
        $flows = [
            'leave' => [$ceo, $hr, $group], 'trip' => [$ceo, $hr, $group], 'resignation' => [$ceo, $hr, $group],
            'equipment' => [$ceo, $hr, $group], 'custody' => [$ceo, $hr, $group], 'letter' => [$ceo, $hr, $group],
            'advance' => [$ceo, $hr, $fin, $group],
        ];
        foreach ($flows as $code => $flow) {
            DB::table('request_types')->where('code', $code)->update(['flow' => json_encode($flow, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        }

        $pfel = DB::table('companies')->where('code', 'PFEL')->value('id');
        $mushari = DB::table('employees')->where('name', 'like', '%مشرع%الشهراني%')->value('id');
        if ($pfel && $mushari) {
            DB::table('companies')->where('id', $pfel)->update(['ceo_employee_id' => $mushari]);
        }
    }

    public function down(): void {}
};
