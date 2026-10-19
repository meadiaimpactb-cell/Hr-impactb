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
        // Dates leave the API in the company's timezone with its offset (2026-11-30T00:00:00+03:00), never shifted to UTC:
        // a date-only value read through ->only() used to arrive as the previous day.
        \Illuminate\Support\Carbon::serializeUsing(fn ($d) => $d->copy()->setTimezone(config('app.timezone'))->format('Y-m-d\TH:i:sP'));
        \Carbon\Carbon::serializeUsing(fn ($d) => $d->copy()->setTimezone(config('app.timezone'))->format('Y-m-d\TH:i:sP'));
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
