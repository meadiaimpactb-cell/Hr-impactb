<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Push notification device tokens (FCM) per user. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('token', 300)->unique();
            $t->string('platform', 10)->default('android');
            $t->string('device', 120)->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
        });
        $now = now();
        foreach ([['pushEnabled', 'تفعيل الإشعارات الفورية (FCM)', 'الإشعارات', 'bool'], ['fcmProjectId', 'معرّف مشروع Firebase', 'الإشعارات', 'text'], ['fcmServiceAccount', 'حساب خدمة Firebase (JSON)', 'الإشعارات', 'secret']] as $i => [$k, $l, $g, $t]) {
            DB::table('setting_definitions')->updateOrInsert(['key' => $k], ['label' => $l, 'group' => $g, 'type' => $t, 'builtin' => true, 'sort' => 980 + $i, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
