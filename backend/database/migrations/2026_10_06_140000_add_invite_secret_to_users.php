<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Encrypted copy of the outstanding invitation token so re-sending keeps the same (still valid) link instead of voiding earlier emails. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->text('invite_secret')->nullable()->after('invite_token'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('invite_secret'));
    }
};
