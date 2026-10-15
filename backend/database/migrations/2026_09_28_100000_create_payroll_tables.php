<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll: salary advances (installment loans deducted from pay), payroll runs
 * (one per company per period, staged approval), and payslips (one per employee
 * per period, published only after manager + HR approve).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount', 12, 2);
            $t->string('purpose')->nullable();
            $t->unsignedTinyInteger('installments');
            $t->decimal('monthly_amount', 12, 2);
            $t->unsignedTinyInteger('paid')->default(0);
            $t->decimal('remaining', 12, 2);
            $t->string('status', 15)->default('repaying'); // repaying | settled | cancelled
            $t->string('start_period', 7); // Y-m of first deducted month
            $t->json('schedule'); // [{period, amount, status: due|deducted, run_id}]
            $t->unsignedBigInteger('request_id')->nullable();
            $t->timestamps();
            $t->index(['employee_id', 'status']);
        });

        Schema::create('payroll_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('period', 7); // Y-m
            $t->string('status', 15)->default('draft'); // draft | hr_review | hr_approved | approved
            $t->unsignedInteger('version')->default(1);
            $t->decimal('gross', 14, 2)->default(0);
            $t->decimal('net', 14, 2)->default(0);
            $t->json('lines'); // snapshot: per-employee earnings/deductions computed at generation time
            $t->unsignedBigInteger('generated_by')->nullable();
            $t->unsignedBigInteger('hr_review_by')->nullable();
            $t->unsignedBigInteger('hr_approved_by')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'period']);
        });

        Schema::create('payslips', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $t->string('period', 7);
            $t->string('no')->unique(); // document number
            $t->json('line'); // earnings[], deductions[], gross, net, service_days
            $t->decimal('adj_late', 12, 2)->nullable(); // manual override of the auto late deduction
            $t->string('adj_reason')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->boolean('mgr_approved')->default(false);
            $t->unsignedBigInteger('mgr_approved_by')->nullable();
            $t->boolean('hr_approved')->default(false);
            $t->unsignedBigInteger('hr_approved_by')->nullable();
            $t->boolean('published')->default(false);
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->unique(['employee_id', 'period']);
            $t->index(['company_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('advances');
    }
};
