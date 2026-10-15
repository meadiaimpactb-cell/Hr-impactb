<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custody: typed items (devices, lines, accounts, cash floats) handed to employees with
 * an acknowledgement, a full history log, an encrypted secret (for accounts/lines) whose
 * every reveal is logged, and expense claims that settle cash custody through finance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custody_types', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('name_en')->nullable();
            $t->json('fields');                 // custom field labels, e.g. ["الرقم التسلسلي","المواصفات"]
            $t->boolean('requires_ack')->default(true);
            $t->boolean('has_secret')->default(false);
            $t->boolean('is_cash')->default(false); // financial custody settled by invoices
            $t->string('dept')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('custody_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('custody_type_id')->constrained('custody_types');
            $t->foreignId('company_id')->constrained();
            $t->string('ident');                 // description / identifier
            $t->json('fields')->nullable();      // {label: value}
            $t->decimal('value', 12, 2)->default(0);
            $t->string('condition')->nullable();
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 20)->default('available'); // available|pending_ack|in_use|return_requested|inspecting|lost
            $t->json('history');                 // [{at, event, by, employee_id?, note?}]
            $t->text('secret')->nullable();      // encrypted at rest (Laravel Crypt)
            $t->timestamp('secret_rotated_at')->nullable();
            $t->unsignedBigInteger('trip_request_id')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status']);
            $t->index('employee_id');
        });

        Schema::create('custody_secret_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('custody_item_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->string('action', 10); // reveal | rotate
            $t->string('reason')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('expense_claims', function (Blueprint $t) {
            $t->id();
            $t->foreignId('custody_item_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->text('details')->nullable();
            $t->json('invoices');                // [{name, amount}]
            $t->decimal('claimed', 12, 2);
            $t->decimal('settled', 12, 2)->default(0);
            $t->string('status', 20)->default('pending_finance'); // pending_finance | settled | to_salary | returned
            $t->string('attachment_path')->nullable();
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note')->nullable();
            $t->timestamps();
            $t->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_claims');
        Schema::dropIfExists('custody_secret_logs');
        Schema::dropIfExists('custody_items');
        Schema::dropIfExists('custody_types');
    }
};
