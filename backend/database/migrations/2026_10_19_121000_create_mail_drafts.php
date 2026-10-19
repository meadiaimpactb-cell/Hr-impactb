<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Drafts of internal mail: editable and deletable by their author until sent. A sent message is a record and cannot be deleted. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mail_drafts')) {
            return;
        }
        Schema::create('mail_drafts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->json('to')->nullable();
            $t->string('subject', 200)->nullable();
            $t->text('body')->nullable();
            $t->boolean('group')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_drafts');
    }
};
