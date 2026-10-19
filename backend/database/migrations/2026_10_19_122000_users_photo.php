<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Profile photo of every account (changed by the user, or by the group CEO / HR manager for anyone). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'photo_path')) {
            Schema::table('users', function (Blueprint $t) {
                $t->string('photo_path', 120)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'photo_path')) {
            Schema::table('users', function (Blueprint $t) {
                $t->dropColumn('photo_path');
            });
        }
    }
};
