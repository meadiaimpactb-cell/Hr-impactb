<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Punches held only for low GPS accuracy or offline sync are released; only out-of-range ones stay flagged (meeting note 2.2). */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('attendance_records')->where('pending', true)->where('geo_fail', false)->update(['pending' => false]);
    }

    public function down(): void {}
};
