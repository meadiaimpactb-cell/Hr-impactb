<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Services\Audit;
use App\Services\EvaluationService;
use Illuminate\Console\Command;

/**
 * Weekly report watch (§34 + owner's notes), daily:
 *  - creates the weeks every employee owes and marks the ones past their deadline as missing: a note in the personal
 *    file, the week shown red, and the deduction in the monthly evaluation — without waiting for someone to open a screen;
 *  - first day of the employee's week: reminds him to raise the week's plan for his manager's approval;
 *  - deadline day: reminds him to raise the week's achievement.
 */
class WeeklyWatch extends Command
{
    protected $signature = 'hc:weekly-watch {--force : run again even if it already ran today}';

    protected $description = 'Weekly report weeks, missing-week notes and plan / deadline reminders';

    public function handle(): int
    {
        $today = now()->toDateString();
        // once a day: from the scheduler, or from the first visit of the day when the server has no cron yet
        if (! $this->option('force') && ! \Illuminate\Support\Facades\Cache::add('hc.ran.weekly-watch.'.$today, 1, now()->endOfDay())) {
            return 0;
        }
        $periods = array_unique([now()->format('Y-m'), now()->subDays(8)->format('Y-m')]);
        $plans = $dues = 0;
        Employee::where('status', '!=', 'terminated')->with('schedule')->get()->each(function (Employee $e) use ($periods, $today, &$plans, &$dues) {
            if (! EvaluationService::weeklyRequired($e)) {
                return;
            }
            foreach ($periods as $p) {
                EvaluationService::ensureWeeks($e, $p);
            }
            $u = User::where('employee_id', $e->id)->where('active', true)->value('id');
            if (! $u) {
                return;
            }
            foreach (WeeklyReport::where('employee_id', $e->id)->where('week_end', '>=', now()->subDays(8)->toDateString())->get() as $w) {
                $w->setRelation('employee', $e);
                if ($w->week_start->toDateString() === $today && ! $w->plan_status) {
                    Audit::notify($u, 'ارفع خطة هذا الأسبوع', 'أسبوع '.$w->week_start->format('d/m/Y').': حدّد البنود ومستهدف الأسبوع ليعتمدها مديرك', 'info', ['screen' => 'reports']);
                    $plans++;
                }
                if ($w->status === 'missing' && EvaluationService::weekDue($w)->toDateString() === $today) {
                    Audit::notify($u, 'اليوم آخر موعد للتقرير الأسبوعي', 'أسبوع '.$w->week_start->format('d/m/Y').': ارفع المنجز اليوم وإلا عُدّ الأسبوع غير مرفوع وخُصم من تقييمك الشهري', 'warn', ['screen' => 'reports']);
                    $dues++;
                }
            }
        });
        $this->info("plan reminders: $plans · deadline reminders: $dues");

        return 0;
    }
}
