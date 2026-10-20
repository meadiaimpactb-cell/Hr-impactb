<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\TerminationController;
use App\Models\Termination;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use Illuminate\Console\Command;

/**
 * Daily end-of-service watch:
 * - §108: a resignation not answered within 30 days of filing is accepted by law; a postponed one is accepted when
 *   the postponement ends.
 * - §112: settlement due-date alert — dues are settled within 7 days of the end of the contract (14 when the employee
 *   ended it); finance and HR are alerted 2 days before, on the day and daily once overdue (once per day).
 */
class EndOfServiceWatch extends Command
{
    protected $signature = 'hc:end-of-service';

    protected $description = 'Auto-accept resignations after 30 days (§108) and alert settlement due dates (§112)';

    public function handle(): int
    {
        $today = now()->startOfDay();
        $accepted = 0;
        $ctl = app(TerminationController::class);
        foreach (Termination::where('reason', 'resign')->whereIn('status', ['resign_pending', 'resign_postponed'])->get() as $t) {
            $filed = $t->filed_on ?? $t->requested_at;
            $due = $t->status === 'resign_postponed' && $t->postponed_until ? $t->postponed_until : $filed->copy()->addDays(30);
            if ($due->lte($today)) {
                $ctl->acceptResignation($t, 'auto');
                $accepted++;
            }
        }
        $alerts = 0;
        foreach (Termination::with('employee:id,name')->whereIn('status', ['open', 'settled'])->whereNotNull('last_working_day')->get() as $t) {
            $due = $t->settlementDue();
            if (! $due || $t->settlement_alerted_on?->isSameDay($today)) {
                continue;
            }
            $days = (int) $today->diffInDays($due, false);
            if ($days > 2) {
                continue;
            }
            $title = $days < 0 ? 'تأخر موعد تسوية مستحقات نهاية الخدمة' : ($days === 0 ? 'اليوم آخر موعد لتسوية مستحقات نهاية الخدمة' : "موعد تسوية مستحقات نهاية الخدمة بعد $days يوم");
            $ids = array_values(array_unique([...Access::usersWithRole('fin'), ...Access::hrUsersFor($t->company_id, 'TRM')]));
            Audit::notify($ids, $title, $t->employee?->name.' — الموعد '.$due->format('d/m/Y').' ('.($t->byEmployee() ? 'أسبوعان: الموظف أنهى العقد' : 'أسبوع من انتهاء العقد').' — §112)', $days < 0 ? 'bad' : 'warn', ['screen' => 'terminations']);
            $t->update(['settlement_alerted_on' => $today->toDateString()]);
            $alerts++;
        }
        $this->info("resignations auto-accepted: $accepted · settlement alerts: $alerts");

        return self::SUCCESS;
    }
}
