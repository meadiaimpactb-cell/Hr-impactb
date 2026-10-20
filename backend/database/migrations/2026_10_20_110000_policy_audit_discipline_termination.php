<?php

use App\Services\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Policy 2.1 audit — chapters 18–20 and appendices 4–5:
 * violations (§95 working-day defence, §97 discovery / sign deadlines / notified date, §96 suspension & deprivation,
 * §104 investigation & precautionary removal, §105 grievance committee), §100–101 absence watch, §106 fines register,
 * terminations (§108 resignation case & notice, §109 decision chain + checklist, §111 clearance chain, §112 due alert),
 * §112/§114 service certificate, §115 announcement publishers, appendix 4 policy acknowledgements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('violations', function (Blueprint $t) {
            $t->string('objection', 20)->default('none')->change();
            $t->date('discovered_on')->nullable()->after('occurred_on');
            $t->date('investigation_done_on')->nullable()->after('recommended_by');
            $t->dateTime('notified_at')->nullable()->after('effective_from');
            $t->unsignedSmallInteger('suspension_days')->nullable()->after('amount');
            $t->unsignedTinyInteger('deprivation_months')->nullable()->after('suspension_days');
            $t->date('deprivation_until')->nullable()->after('deprivation_months');
            $t->unsignedBigInteger('investigator_id')->nullable()->after('recorded_by');
            $t->text('minutes')->nullable()->after('investigator_id');
            $t->string('removal_status', 20)->default('none')->after('minutes');
            $t->text('removal_note')->nullable()->after('removal_status');
            $t->unsignedBigInteger('removal_by')->nullable()->after('removal_note');
            $t->unsignedBigInteger('removal_approved_by')->nullable()->after('removal_by');
            $t->dateTime('removal_at')->nullable()->after('removal_approved_by');
            $t->dateTime('objection_at')->nullable()->after('objection_text');
            $t->json('committee')->nullable()->after('objection_at');
            $t->string('objection_proposal', 20)->nullable()->after('committee');
            $t->text('objection_proposal_note')->nullable()->after('objection_proposal');
            $t->unsignedBigInteger('objection_proposed_by')->nullable()->after('objection_proposal_note');
        });
        DB::table('violations')->whereNull('discovered_on')->update(['discovered_on' => DB::raw('occurred_on')]);
        DB::table('violations')->where('status', 'active')->whereNull('notified_at')->update(['notified_at' => DB::raw('updated_at')]);

        Schema::table('terminations', function (Blueprint $t) {
            $t->string('status', 30)->default('open')->change();
            $t->string('initiated_by', 10)->nullable()->after('reason'); // employee | employer
            $t->date('filed_on')->nullable()->after('requested_at');
            $t->date('postponed_until')->nullable()->after('filed_on');
            $t->text('postpone_reason')->nullable()->after('postponed_until');
            $t->unsignedBigInteger('postponed_by')->nullable()->after('postpone_reason');
            $t->dateTime('withdrawn_at')->nullable()->after('postponed_by');
            $t->dateTime('accepted_at')->nullable()->after('withdrawn_at');
            $t->string('accepted_how', 20)->nullable()->after('accepted_at'); // auto | approval | request
            $t->json('checklist')->nullable()->after('note');
            $t->json('flows')->nullable()->after('approvals');
            $t->date('settlement_alerted_on')->nullable()->after('paid_at');
        });

        Schema::table('letters', function (Blueprint $t) {
            $t->string('attachment_path')->nullable()->after('justification');
        });

        Schema::create('absence_alerts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('employee_id')->index();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('kind', 20); // consecutive10 | consecutive15 | scattered20 | scattered30
            $t->unsignedSmallInteger('days');
            $t->date('from');
            $t->date('to');
            $t->date('year_start');
            $t->json('dates')->nullable();
            $t->string('status', 20)->default('open'); // open | recorded | dismissed
            $t->unsignedBigInteger('violation_id')->nullable();
            $t->timestamps();
            $t->unique(['employee_id', 'kind', 'year_start', 'from']);
        });

        Schema::create('announcement_publishers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('employee_id');
            $t->unsignedBigInteger('designated_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'employee_id']);
        });

        Schema::create('policy_acknowledgements', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->unsignedBigInteger('employee_id')->nullable()->index();
            $t->string('version', 20);
            $t->string('typed_name', 160);
            $t->longText('signature')->nullable();
            $t->string('text_hash', 64);
            $t->string('ip', 64)->nullable();
            $t->string('device', 160)->nullable();
            $t->unsignedBigInteger('signed_doc_id')->nullable();
            $t->dateTime('signed_at');
            $t->timestamps();
            $t->unique(['user_id', 'version']);
        });

        $now = now();
        // §112 / §114: service certificate — issued by HR directly, free, no approval chain (distinct from the experience certificate)
        DB::table('lookups')->updateOrInsert(['group' => 'letter_kinds', 'code' => 'service'], ['name' => 'شهادة خدمة', 'name_en' => 'Service certificate', 'meta' => json_encode(['direct' => true]), 'sort' => 60, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        if (! DB::table('letter_templates')->where('kind', 'service')->whereNull('company_id')->exists()) {
            DB::table('letter_templates')->insert(['company_id' => null, 'kind' => 'service', 'lang' => 'ar', 'title' => 'شهادة خدمة', 'includes_salary' => false, 'version' => 1, 'active' => true, 'created_at' => $now, 'updated_at' => $now,
                'body' => "تشهد {{company}} بأن {{employee}} ({{nationality}}) رقم الهوية {{national_id}} قد عمل لديها بوظيفة {{job}} اعتبارًا من {{hire_date}} حتى {{last_day}}.\n\nوقد أُعطيت له هذه الشهادة بناءً على طلبه دون مقابل، وفق المادة (64) من نظام العمل والمادة (112) من اللائحة، ودون أي مسؤولية على الشركة."]);
        }
        DB::table('lookups')->where('group', 'offboarding_tasks')->where('code', 'certificate')->update(['name' => 'شهادة الخبرة / شهادة الخدمة']);

        if (Settings::get('policyVersion') === null) {
            Settings::set('policyVersion', '2.1', null, 'الملحق الرابع: إصدار اللائحة الذي يُطلب الإقرار به');
        }
        DB::table('setting_definitions')->updateOrInsert(['key' => 'policyVersion'], ['label' => 'إصدار اللائحة المطلوب الإقرار به (الملحق الرابع)', 'group' => 'الحوكمة', 'type' => 'text', 'builtin' => true, 'sort' => 900, 'created_at' => $now, 'updated_at' => $now]);
        if (Settings::get('violationStatementDays') !== null && (int) Settings::get('violationStatementDays') < 2) {
            Settings::set('violationStatementDays', 2, null, '§95: مهلة الدفاع لا تقل عن يومي عمل');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_acknowledgements');
        Schema::dropIfExists('announcement_publishers');
        Schema::dropIfExists('absence_alerts');
        Schema::table('letters', fn (Blueprint $t) => $t->dropColumn('attachment_path'));
        Schema::table('terminations', fn (Blueprint $t) => $t->dropColumn(['initiated_by', 'filed_on', 'postponed_until', 'postpone_reason', 'postponed_by', 'withdrawn_at', 'accepted_at', 'accepted_how', 'checklist', 'flows', 'settlement_alerted_on']));
        Schema::table('violations', fn (Blueprint $t) => $t->dropColumn(['discovered_on', 'investigation_done_on', 'notified_at', 'suspension_days', 'deprivation_months', 'deprivation_until', 'investigator_id', 'minutes', 'removal_status', 'removal_note', 'removal_by', 'removal_approved_by', 'removal_at', 'objection_at', 'committee', 'objection_proposal', 'objection_proposal_note', 'objection_proposed_by']));
        DB::table('lookups')->where('group', 'letter_kinds')->where('code', 'service')->delete();
    }
};
