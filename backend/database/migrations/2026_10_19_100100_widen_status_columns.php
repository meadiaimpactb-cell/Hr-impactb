<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Room for the new workflow states (§96 HR recommendation step) and the annual-goals rating status. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('violations', fn (Blueprint $t) => $t->string('status', 32)->change());
        Schema::table('evaluations', fn (Blueprint $t) => $t->string('grade', 16)->nullable()->change());
    }

    public function down(): void {}
};
