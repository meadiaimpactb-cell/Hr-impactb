<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Download center: every file a user exports is kept so it can be downloaded again later. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 200);                 // file name shown to the user
            $t->string('kind', 120);                 // what produced it, e.g. "تقرير الحضور"
            $t->string('category', 20)->default('reports'); // reports | finance | forms | people
            $t->string('format', 10)->default('xlsx');
            $t->string('path', 300);
            $t->unsignedInteger('size')->default(0);
            $t->timestamps();
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_files');
    }
};
