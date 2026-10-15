<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Per-employee working hours: {"0":["10:00","19:00"], …} keyed by day of week (0 = Sunday). Null = follow the schedule. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', fn (Blueprint $t) => $t->json('work_hours')->nullable()->after('schedule_id'));
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn('work_hours'));
    }
};
