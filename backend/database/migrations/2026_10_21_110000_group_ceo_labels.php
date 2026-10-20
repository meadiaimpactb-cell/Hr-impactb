<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Owner's notes: the group's top role is «الرئيس التنفيذي للمجموعة» everywhere (and «الرئيس التنفيذي للشركة»), never «المدير العام / المدير التنفيذي». */
return new class extends Migration
{
    public function up(): void
    {
        $map = ['المدير التنفيذي للمجموعة' => 'الرئيس التنفيذي للمجموعة', 'المدير العام للمجموعة' => 'الرئيس التنفيذي للمجموعة', 'المدير التنفيذي للشركة' => 'الرئيس التنفيذي للشركة', 'المدير العام' => 'الرئيس التنفيذي للمجموعة'];
        foreach (DB::table('request_types')->get(['id', 'flow']) as $t) {
            $new = strtr((string) $t->flow, $map);
            if ($new !== $t->flow) {
                DB::table('request_types')->where('id', $t->id)->update(['flow' => $new]);
            }
        }
        foreach (DB::table('approval_requests')->where('status', 'pending')->get(['id', 'flow']) as $r) {
            $new = strtr((string) $r->flow, $map);
            if ($new !== $r->flow) {
                DB::table('approval_requests')->where('id', $r->id)->update(['flow' => $new]);
            }
        }
    }

    public function down(): void {}
};
