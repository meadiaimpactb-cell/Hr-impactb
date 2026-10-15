<?php

namespace App\Providers;

use App\Models\Advance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\ExpenseClaim;
use App\Models\Payslip;
use App\Services\Integrations\OdooSync;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->environment('production')) {
            \Illuminate\Support\Facades\URL::forceScheme('https'); // shared hosting terminates TLS in front of PHP
        }
        // Integration hooks: every master-data / financial change lands in the sync outbox (no-op while Odoo is disabled).
        Company::saved(fn (Company $c) => OdooSync::queue('company', $c->id));
        Employee::saved(fn (Employee $e) => OdooSync::queue('employee', $e->id));
        Advance::created(fn (Advance $a) => OdooSync::queue('advance', $a->id));
        Payslip::saved(function (Payslip $p) {
            if ($p->published && $p->wasChanged('published')) {
                OdooSync::queue('payslip', $p->id);
            }
        });
        ExpenseClaim::saved(function (ExpenseClaim $c) {
            if (in_array($c->status, ['settled', 'to_salary'], true) && $c->wasChanged('status')) {
                OdooSync::queue('expense_claim', $c->id);
            }
        });
    }
}
