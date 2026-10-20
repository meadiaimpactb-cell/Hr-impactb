<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Policy audit — payroll & custody (§38/40/41/42/43/44/46):
 *  - payroll_runs.stage_log: who did each stage and when (separation of duties).
 *  - custody_items: disbursement / purpose-end / settlement deadline / overdue tracking, the acknowledgement
 *    undertaking text with timestamp and device, and the prior approval to exceed the custody amount.
 *  - expense_claims: refund to the company account (amount/date) and the reason a claim was returned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $t) {
            if (! Schema::hasColumn('payroll_runs', 'stage_log')) {
                $t->json('stage_log')->nullable()->after('approved_by');
            }
        });

        Schema::table('custody_items', function (Blueprint $t) {
            if (! Schema::hasColumn('custody_items', 'disbursed_at')) {
                $t->dateTime('disbursed_at')->nullable()->after('trip_request_id');
                $t->date('purpose_end')->nullable()->after('disbursed_at');
                $t->date('settle_due')->nullable()->after('purpose_end');
                $t->dateTime('overdue_at')->nullable()->after('settle_due');
                $t->dateTime('overdue_notified_at')->nullable()->after('overdue_at');
                $t->text('ack_text')->nullable()->after('overdue_notified_at');
                $t->dateTime('ack_at')->nullable()->after('ack_text');
                $t->string('ack_device', 255)->nullable()->after('ack_at');
                $t->string('ack_ip', 64)->nullable()->after('ack_device');
                $t->boolean('overspend_approved')->default(false)->after('ack_ip');
                $t->decimal('overspend_limit', 12, 2)->nullable()->after('overspend_approved');
                $t->foreignId('overspend_by')->nullable()->after('overspend_limit');
                $t->string('overspend_note', 500)->nullable()->after('overspend_by');
            }
        });

        Schema::table('expense_claims', function (Blueprint $t) {
            if (! Schema::hasColumn('expense_claims', 'refund_amount')) {
                $t->decimal('refund_amount', 12, 2)->nullable()->after('settled');
                $t->date('refund_date')->nullable()->after('refund_amount');
                $t->string('refund_ref', 120)->nullable()->after('refund_date');
                $t->decimal('salary_amount', 12, 2)->nullable()->after('refund_ref');
            }
        });

        // Existing cash custody: disbursement = first hand-over in the history, else creation.
        foreach (\DB::table('custody_items')->whereNull('disbursed_at')->whereNotNull('employee_id')->get(['id', 'history', 'created_at']) as $i) {
            $h = json_decode($i->history ?? '[]', true) ?: [];
            $at = collect($h)->first(fn ($x) => in_array($x['event'] ?? '', ['تسليم', 'طلب تسليم', 'إقرار استلام'], true))['at'] ?? $i->created_at;
            \DB::table('custody_items')->where('id', $i->id)->update(['disbursed_at' => $at]);
        }
    }

    public function down(): void
    {
        Schema::table('expense_claims', fn (Blueprint $t) => $t->dropColumn(['refund_amount', 'refund_date', 'refund_ref', 'salary_amount']));
        Schema::table('custody_items', fn (Blueprint $t) => $t->dropColumn(['disbursed_at', 'purpose_end', 'settle_due', 'overdue_at', 'overdue_notified_at', 'ack_text', 'ack_at', 'ack_device', 'ack_ip',
            'overspend_approved', 'overspend_limit', 'overspend_by', 'overspend_note']));
        Schema::table('payroll_runs', fn (Blueprint $t) => $t->dropColumn('stage_log'));
    }
};
