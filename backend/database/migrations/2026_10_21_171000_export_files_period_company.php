<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Download centre (owner's note): each exported file records the period it covers and its company, to filter by them. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('export_files', 'period')) {
            Schema::table('export_files', function (Blueprint $t) {
                $t->string('period', 7)->nullable()->after('category');
                $t->unsignedBigInteger('company_id')->nullable()->after('period');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('export_files', 'period')) {
            Schema::table('export_files', fn (Blueprint $t) => $t->dropColumn(['period', 'company_id']));
        }
    }
};
