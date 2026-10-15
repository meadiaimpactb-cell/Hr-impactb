<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HC core schema: organisation, employees, payroll components, attendance,
 * leaves, unified approval requests, notifications and audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiers', function (Blueprint $t) {
            $t->id();
            $t->unsignedTinyInteger('rank');
            $t->string('name');
            $t->string('name_en')->nullable();
            $t->string('color', 20)->default('#1B82C3');
            $t->boolean('is_default')->default(false);
            $t->json('benefits');
            $t->unsignedInteger('version')->default(1);
            $t->date('effective_from')->nullable();
            $t->timestamps();
        });

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('name');
            $t->string('name_en')->nullable();
            $t->string('legal_no')->nullable();
            $t->string('tax_no')->nullable();
            $t->string('country', 2)->default('SA');
            $t->string('currency', 3)->default('SAR');
            $t->string('timezone')->default('Asia/Riyadh');
            $t->foreignId('tier_id')->nullable()->constrained('tiers')->nullOnDelete();
            $t->unsignedBigInteger('ceo_employee_id')->nullable();
            $t->string('odoo_id')->nullable();
            $t->string('prorate_rule', 10)->default('actual'); // actual | 30
            $t->boolean('is_holding')->default(false);
            $t->string('logo_path')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key');
            $t->json('value')->nullable();
            $t->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->date('effective_from')->nullable();
            $t->unsignedBigInteger('changed_by')->nullable();
            $t->string('reason')->nullable();
            $t->timestamps();
            $t->unique(['key', 'company_id']);
        });

        Schema::create('job_titles', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name');
            $t->string('name_en')->nullable();
            $t->string('family')->nullable();
            $t->text('purpose')->nullable();
            $t->json('responsibilities')->nullable();
            $t->json('qualifications')->nullable();
            $t->json('skills')->nullable();
            $t->json('kpis')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->string('status', 20)->default('approved');
            $t->timestamps();
        });

        Schema::create('schedules', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->json('week_days');          // [0..6] 0=Sunday
            $t->json('hours');              // {"0":["09:00","17:00"],...}
            $t->boolean('flex')->default(false);
            $t->boolean('cross_midnight')->default(false);
            $t->string('week_pattern', 1)->default('5');
            $t->timestamps();
        });

        Schema::create('locations', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('lat', 10, 7)->default(0);
            $t->decimal('lng', 10, 7)->default(0);
            $t->unsignedInteger('radius')->default(100);
            $t->string('scope', 10)->default('point'); // point | city | region | open
            $t->timestamps();
        });

        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->string('emp_no', 30)->unique();
            $t->string('name');
            $t->string('name_en')->nullable();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('job_title_id')->nullable()->constrained('job_titles')->nullOnDelete();
            $t->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->string('dept')->nullable();
            $t->date('hire_date');
            $t->string('contract_type', 10)->default('full'); // full | part | collab
            $t->decimal('part_ratio', 3, 2)->nullable();
            $t->boolean('punch_required')->default(true);
            $t->foreignId('schedule_id')->nullable()->constrained()->nullOnDelete();
            $t->string('week_pattern', 1)->default('5');
            $t->string('country', 2)->default('SA');
            $t->string('nationality')->nullable();
            $t->string('national_id')->nullable();
            $t->date('birth_date')->nullable();
            $t->string('gender', 1)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            $t->boolean('email_enabled')->default(true);
            $t->boolean('eosb_saudi')->default(true);
            $t->boolean('flex_enabled')->default(false);
            $t->boolean('daily_note_required')->default(false);
            $t->boolean('advances_enabled')->default(true);
            $t->string('status', 20)->default('active'); // onboarding|active|exiting|terminated
            $t->date('last_working_day')->nullable();
            $t->string('bank_iban')->nullable();
            $t->json('custom_benefits')->nullable();
            $t->json('insurance')->nullable();
            $t->json('service_companies')->nullable();
            $t->softDeletes();
            $t->timestamps();
            $t->index(['company_id', 'status']);
        });

        Schema::table('companies', function (Blueprint $t) {
            $t->foreign('ceo_employee_id')->references('id')->on('employees')->nullOnDelete();
        });

        Schema::create('employee_location', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('location_id')->constrained()->cascadeOnDelete();
            $t->primary(['employee_id', 'location_id']);
        });

        Schema::create('salary_components', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('key', 30);
            $t->string('name');
            $t->decimal('amount', 12, 2);
            $t->boolean('is_fixed')->default(true);
            $t->string('source', 20)->default('contract'); // contract | tier | custom | import
            $t->date('valid_from')->nullable();
            $t->date('valid_to')->nullable();
            $t->unsignedBigInteger('approval_request_id')->nullable();
            $t->timestamps();
            $t->index(['employee_id', 'key']);
        });

        Schema::create('career_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('type');
            $t->text('detail')->nullable();
            $t->date('occurred_on');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamps();
        });

        Schema::create('attendance_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->date('work_date');
            $t->string('status', 12)->default('present'); // present | absent | leave
            $t->dateTime('check_in')->nullable();
            $t->dateTime('check_out')->nullable();
            $t->unsignedSmallInteger('late_minutes')->default(0);
            $t->unsignedSmallInteger('early_minutes')->default(0);
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->unsignedInteger('accuracy')->nullable();
            $t->decimal('out_lat', 10, 7)->nullable();
            $t->decimal('out_lng', 10, 7)->nullable();
            $t->string('device')->nullable();
            $t->boolean('offline')->default(false);
            $t->boolean('geo_fail')->default(false);
            $t->boolean('low_accuracy')->default(false);
            $t->boolean('pending')->default(false);
            $t->boolean('corrected')->default(false);
            $t->boolean('note_required')->default(false);
            $t->text('note')->nullable();
            $t->dateTime('client_time')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamps();
            $t->unique(['employee_id', 'work_date']);
            $t->index('work_date');
        });

        Schema::create('leave_types', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('name');
            $t->string('name_en')->nullable();
            $t->string('accrual', 10); // monthly | yearly | case | none
            $t->string('quota_key')->nullable();
            $t->boolean('deducts')->default(true);
            $t->boolean('attachment_required')->default(false);
            $t->boolean('carry_over')->default(false);
            $t->string('pay', 10)->default('full');
            $t->unsignedSmallInteger('max_per_case')->nullable();
            $t->json('extra_approval')->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('leave_ledger', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('year');
            $t->string('leave_type', 20);
            $t->string('entry', 12); // opening | adjust | used
            $t->decimal('days', 6, 2);
            $t->string('note')->nullable();
            $t->unsignedBigInteger('request_id')->nullable();
            $t->date('occurred_on');
            $t->timestamps();
            $t->index(['employee_id', 'year', 'leave_type']);
        });

        Schema::create('approval_requests', function (Blueprint $t) {
            $t->id();
            $t->string('type', 30);
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained();
            $t->json('data');
            $t->json('flow');
            $t->unsignedTinyInteger('stage')->default(0);
            $t->string('status', 15)->default('pending'); // pending|approved|returned|cancelled
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('attachment_path')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'type']);
            $t->index(['employee_id', 'status']);
        });

        Schema::create('approval_actions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('stage');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('acting_for_user_id')->nullable();
            $t->string('decision', 10); // submit|approve|return|cancel
            $t->text('reason')->nullable();
            $t->timestamps();
        });

        Schema::create('delegations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $t->json('ops')->nullable();
            $t->date('valid_from');
            $t->date('valid_to');
            $t->string('status', 10)->default('active');
            $t->string('reason')->nullable();
            $t->timestamps();
        });

        Schema::create('app_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 15)->default('info');
            $t->string('title');
            $t->text('body')->nullable();
            $t->json('link')->nullable();
            $t->unsignedBigInteger('request_id')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'read_at']);
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('entity', 40);
            $t->string('entity_id', 40)->nullable();
            $t->string('action', 40);
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->string('reason')->nullable();
            $t->string('channel', 10)->default('web');
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
            $t->index(['entity', 'entity_id']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'app_notifications', 'delegations', 'approval_actions', 'approval_requests',
            'leave_ledger', 'leave_types', 'attendance_records', 'career_events', 'salary_components',
            'employee_location'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
        Schema::table('companies', fn (Blueprint $t) => $t->dropForeign(['ceo_employee_id']));
        foreach (['employees', 'locations', 'schedules', 'job_titles', 'settings', 'companies', 'tiers'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
