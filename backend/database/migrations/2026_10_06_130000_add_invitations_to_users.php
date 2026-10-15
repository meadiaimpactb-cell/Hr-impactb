<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Single-use invitation / password-set links: the token is stored hashed, expires, and is cleared once used. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('invite_token', 64)->nullable()->index()->after('must_change_password');
            $t->timestamp('invite_expires_at')->nullable()->after('invite_token');
            $t->timestamp('invited_at')->nullable()->after('invite_expires_at');
            $t->timestamp('invite_accepted_at')->nullable()->after('invited_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['invite_token', 'invite_expires_at', 'invited_at', 'invite_accepted_at']));
    }
};
