<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\CustodyController;
use App\Models\Company;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use Illuminate\Console\Command;

/**
 * Monthly (§46): reconciliation of outstanding advances and open cash custody. Finance and the group CEO get the
 * group summary; each company CEO gets their company's arrears. The full table is on the custody screen
 * (tab «المطابقة الشهرية») with an Excel export.
 */
class FinanceReconciliation extends Command
{
    protected $signature = 'hc:finance-reconciliation {--period= : Y-m, default the current month}';

    protected $description = 'Notify finance / company CEOs / group CEO of the monthly advances & cash-custody reconciliation';

    public function handle(): int
    {
        $period = $this->option('period') ?: now()->format('Y-m');
        $companies = Company::orderBy('id')->get(['id', 'name', 'ceo_employee_id']);
        $all = CustodyController::reconciliationData($companies->pluck('id')->all(), $period);
        $t = $all['totals'];
        $line = fn ($x) => 'السلف القائمة '.number_format($x['advances_balance'], 2).' · أقساط متأخرة '.number_format($x['overdue_instalments_amount'], 2)
            .' · عهد نقدية مفتوحة '.number_format($x['cash_amount'], 2).' · منها متأخرة '.number_format($x['cash_overdue_amount'], 2);
        Audit::notify(array_values(array_unique([...Access::usersWithRole('fin'), ...Access::usersWithRole('super')])), "المطابقة الشهرية للسلف والعهد — {$period}",
            $line($t).' · موظفون عليهم متأخرات: '.$t['overdue_employees'], $t['overdue_employees'] ? 'warn' : 'info', ['screen' => 'custody', 'tab' => 'recon']);
        foreach ($all['companies'] as $c) {
            $co = $companies->firstWhere('id', $c['company_id']);
            $ceos = array_filter([
                ...User::whereJsonContains('roles', 'ceo')->where('company_id', $c['company_id'])->pluck('id')->all(),
                $co?->ceo_employee_id ? User::where('employee_id', $co->ceo_employee_id)->value('id') : null,
            ]);
            if ($ceos && $c['overdue_employees'] > 0) {
                Audit::notify(array_values(array_unique($ceos)), "متأخرات السلف والعهد — {$c['company']} — {$period}", $line($c).' · موظفون عليهم متأخرات: '.$c['overdue_employees'], 'warn', ['screen' => 'custody', 'tab' => 'recon']);
            }
        }
        $this->info("{$period}: ".count($all['rows']).' employees, '.$t['overdue_employees'].' with arrears');

        return self::SUCCESS;
    }
}
