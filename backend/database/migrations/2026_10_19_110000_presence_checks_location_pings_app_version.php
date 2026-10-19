<?php

use App\Services\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Policy §28: presence checks (the manager asks the employee to confirm where they are during the working day) and
 * location tracking from check-in to check-out, visible to the HR manager and the group CEO only, with the group CEO
 * able to grant a direct manager the view per employee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presence_checks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->nullable();
            $t->unsignedBigInteger('requested_by');
            $t->timestamp('requested_at');
            $t->timestamp('due_at');
            $t->timestamp('responded_at')->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->unsignedInteger('accuracy')->nullable();
            $t->unsignedInteger('distance_m')->nullable();
            $t->boolean('inside')->nullable();
            $t->string('status', 12)->default('pending'); // pending | confirmed | missed
            $t->string('note', 300)->nullable();
            $t->timestamps();
            $t->index(['employee_id', 'status']);
        });
        Schema::create('location_pings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->date('work_date');
            $t->timestamp('at');
            $t->decimal('lat', 10, 7);
            $t->decimal('lng', 10, 7);
            $t->unsignedInteger('accuracy')->nullable();
            $t->unsignedInteger('distance_m')->nullable();
            $t->boolean('inside')->nullable();
            $t->timestamps();
            $t->index(['employee_id', 'work_date']);
        });
        Schema::table('employees', fn (Blueprint $t) => $t->json('track_viewers')->nullable()->after('punch_anywhere')); // user ids the group CEO allowed to see the movement map
        foreach (['presenceCheckEnabled' => [true, 'اللائحة §28: طلب التحضير أثناء الدوام'], 'presenceCheckMinutes' => [30, 'مهلة الاستجابة لطلب التحضير (دقيقة)'],
            'locationTrackingEnabled' => [false, 'اللائحة §28: تسجيل الموقع من الحضور حتى الانصراف — يفعّله الرئيس التنفيذي للمجموعة'], 'trackIntervalMinutes' => [10, 'الفاصل بين نقاط تسجيل الموقع (دقيقة)']] as $k => [$v, $r]) {
            Settings::set($k, $v, null, $r);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('location_pings');
        Schema::dropIfExists('presence_checks');
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn('track_viewers'));
    }
};
