<?php

namespace App\Console\Commands;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\LeaveService;
use Illuminate\Console\Command;

/**
 * Daily policy reminders:
 *  §33 — an employee who did not write yesterday's daily achievement is alerted (the item loses one point per day).
 *  §57 — in the last quarter of the year HR is told who still has statutory annual leave to schedule (30 days' notice).
 *  §68 — an approved business trip whose report is not filed within 3 working days of return is flagged to the employee and manager.
 */
class PolicyReminders extends Command
{
    protected $signature = 'hc:policy-reminders {--force : run again even if it already ran today}';

    protected $description = 'Daily note, statutory leave scheduling and trip report reminders';

    public function handle(): int
    {
        // once a day: from the scheduler, or from the first visit of the day when the server has no cron yet
        if (! $this->option('force') && ! \Illuminate\Support\Facades\Cache::add('hc.ran.policy-reminders.'.now()->toDateString(), 1, now()->endOfDay())) {
            return 0;
        }
        $y = now()->subDay()->toDateString();
        $n = 0;
        foreach (AttendanceRecord::where('work_date', $y)->where('note_pending', true)->with('employee')->get() as $rec) {
            $u = User::where('employee_id', $rec->employee_id)->value('id');
            if ($u) {
                Audit::notify($u, 'تحضير أمس معلّق: لم تكتب المنجز اليومي', 'منجز يوم '.\Illuminate\Support\Carbon::parse($y)->format('d/m/Y').' غير مكتوب، فلا يُعتمد تحضيره حتى تكتبه (ويُخصم من بند المنجز اليومي §33). اكتبه من شاشة الحضور.', 'warn', ['screen' => 'attendance']);
                $n++;
            }
        }

        // §57: from October, HR schedules the statutory part of the annual leave for those who did not request it
        if (now()->month >= 10 && now()->day === 1) {
            foreach (Employee::where('status', 'active')->get()->groupBy('company_id') as $cid => $emps) {
                $list = $emps->map(function ($e) {
                    $b = LeaveService::balance($e, 'annual');
                    $min = LeaveService::unit($e) === 'work' ? 15 : 21; // statutory minimum in the balance unit
                    $taken = (float) $b['used'] + (float) $b['reserved'];

                    return $taken < $min && $b['available'] > 0 ? $e->name.' ('.round($min - $taken, 1).')' : null;
                })->filter()->values();
                if ($list->isNotEmpty()) {
                    Audit::notify(Access::hrManagersFor((int) $cid), 'جدولة الإجازة النظامية قبل نهاية السنة', 'موظفون لم يتمتعوا بالحد النظامي من إجازتهم — حدّد مواعيدها مع المشرف وأشعرهم قبل 30 يومًا (§57): '.$list->take(20)->implode('، '), 'warn', ['screen' => 'leaves']);
                }
            }
        }

        // §68: trip report within 3 working days of return
        foreach (\App\Models\ApprovalRequest::where('type', 'trip')->where('status', 'approved')->where('data->to', '<', now()->toDateString())->where('data->to', '>=', now()->subDays(20)->toDateString())->get() as $t) {
            if (! empty($t->data['report'])) {
                continue;
            }
            if (\App\Services\WorkCalendar::between($t->employee, $t->data['to']) === (int) \App\Services\Settings::get('tripReportDays', $t->company_id, 3) + 1) {
                $u = User::where('employee_id', $t->employee_id)->value('id');
                $mgr = $t->employee?->manager_id ? User::where('employee_id', $t->employee->manager_id)->value('id') : null;
                Audit::notify(array_filter([$u, $mgr]), 'تقرير رحلة عمل متأخر', ($t->employee?->name).' — '.($t->data['destination'] ?? '').': يُرفع التقرير خلال 3 أيام عمل من العودة (§68)', 'warn', ['screen' => 'requests']);
            }
        }
        // §35: the day after the due day, a monthly report still not submitted is noted in the file and the manager told
        $period = now()->format('Y-m');
        foreach (\App\Models\Company::pluck('id') as $cid) {
            $dueDay = min((int) \App\Services\Settings::get('monthlyReportDueDay', $cid, 26), now()->daysInMonth);
            if (now()->day !== $dueDay + 1) {
                continue;
            }
            foreach (\App\Models\MonthlyReport::with('employee')->where('company_id', $cid)->where('period', $period)->whereIn('status', ['draft', 'returned'])->whereNull('first_submitted_at')->get() as $rep) {
                if (! $rep->employee || $rep->employee->status !== 'active') {
                    continue;
                }
                $rep->employee->addCareer('تأخر التقرير الشهري', 'تقرير '.$period.' لم يُسلَّم حتى موعده ('.$dueDay.'/'.now()->format('m/Y').')');
                $mgr = $rep->employee->manager_id ? User::where('employee_id', $rep->employee->manager_id)->value('id') : null;
                Audit::notify(array_filter([User::where('employee_id', $rep->employee_id)->value('id'), $mgr]), 'تقرير شهري متأخر', $rep->employee->name.' — '.$period.': سُجّل التأخير في الملف (§35)', 'warn', ['screen' => 'reports']);
            }
        }

        // §36: the manager rates the submitted report by the review due day (mgrReviewDueDay, 0 = end of the month) — reminded two days before, HR told after
        foreach (\App\Models\MonthlyReport::with('employee')->where('status', 'submitted')->get() as $rep) {
            if (! $rep->employee?->manager_id) {
                continue;
            }
            $due = \App\Http\Controllers\Api\ReportController::reviewDue($rep); // submitted after the review day: three days to rate it
            $left = (int) now()->startOfDay()->diffInDays($due, false);
            $mgr = User::where('employee_id', $rep->employee->manager_id)->value('id');
            if (in_array($left, [2, 0], true) && $mgr) {
                Audit::notify($mgr, 'قيّم التقرير الشهري قبل '.$due->format('d/m/Y'), $rep->employee->name.' — '.$rep->period, 'approval', ['screen' => 'reports']);
            } elseif ($left === -1) {
                Audit::notify(array_filter([$mgr, ...Access::hrUsersFor($rep->company_id, 'PRF')]), 'تقييم تقرير شهري متأخر', $rep->employee->name.' — '.$rep->period.': لم يقيّمه المدير حتى '.$due->format('d/m/Y').' (§36)', 'bad', ['screen' => 'reports']);
            }
        }

        // §116: on the 1st of each month HR is told which former employees' files passed the retention period (reviewed, never deleted automatically)
        if (now()->day === 1) {
            $years = (int) \App\Services\Settings::get('dataRetentionYears', null, 10);
            $due = Employee::where('status', 'terminated')->whereNotNull('last_working_day')->where('last_working_day', '<', now()->subYears($years)->toDateString())->get(['id', 'name', 'company_id']);
            foreach ($due->groupBy('company_id') as $cid => $list) {
                Audit::notify(Access::hrManagersFor((int) $cid), 'ملفات تجاوزت مدة الاحتفاظ ('.$years.' سنوات)', $list->count().' ملف موظف سابق: '.$list->take(10)->pluck('name')->implode('، ').' — راجعها للأرشفة أو الإتلاف وفق السياسة (§116)', 'warn', ['screen' => 'employees']);
            }
        }

        // owner's note (22/10): HR and the group CEO receive yesterday's daily achievements in one summary each morning
        $recs = AttendanceRecord::with('employee:id,name,company_id')->where('work_date', $y)->where(fn ($q) => $q->where(fn ($w) => $w->whereNotNull('note')->where('note', '!=', ''))->orWhere('note_pending', true))->get();
        if ($recs->isNotEmpty()) {
            $line = fn ($r) => $r->note_pending ? ($r->employee?->name).': لم يكتب منجزه' : ($r->employee?->name).': '.mb_substr(preg_replace('/\s+/u', ' ', (string) $r->note), 0, 120);
            $title = fn ($rs) => 'المنجزات اليومية — '.\Illuminate\Support\Carbon::parse($y)->format('d/m/Y').': '.$rs->where('note_pending', false)->count().' كتبوا'.($rs->where('note_pending', true)->count() ? ' · '.$rs->where('note_pending', true)->count().' لم يكتبوا' : '');
            $link = ['screen' => 'attendance', 'tab' => 'achievements', 'date' => $y];
            foreach ($recs->groupBy(fn ($r) => $r->employee?->company_id) as $cid => $rs) {
                Audit::notify(Access::hrManagersFor((int) $cid), $title($rs), $rs->take(15)->map($line)->implode("\n"), 'info', $link);
            }
            Audit::notify(Access::usersWithRole('super'), $title($recs), $recs->take(15)->map($line)->implode("\n"), 'info', $link);
        }

        // §49: a classification approved for the next benefits year takes effect on its date
        foreach (\App\Models\Company::whereNotNull('next_tier_id')->where('next_tier_from', '<=', now()->toDateString())->get() as $co) {
            if ($tier = \App\Models\Tier::find($co->next_tier_id)) {
                \App\Services\ApprovalService::applyTier($co, $tier, 'سريان التصنيف المعتمد من '.$co->next_tier_from);
                $users = User::whereIn('employee_id', Employee::where('company_id', $co->id)->pluck('id'))->where('active', true)->pluck('id')->all();
                Audit::notify($users, 'سرى تصنيف شركتك: '.$tier->name, 'تحدّثت مزاياك وفق التصنيف الجديد (§49–50)', 'info', ['screen' => 'dashboard']);
            }
        }

        // §105: the grievance is decided within 15 days — the committee / approver is reminded, and the group CEO told when it is late
        foreach (\App\Models\Violation::with('employee:id,name')->whereIn('objection', ['open', 'proposed'])->whereNotNull('objection_at')->get() as $v) {
            $age = (int) $v->objection_at->copy()->startOfDay()->diffInDays(now()->startOfDay());
            if (! in_array($age, [10, 14, 15, 16], true)) {
                continue;
            }
            $to = $v->objection === 'open' ? ($v->committee ?: Access::hrUsersFor($v->company_id, 'VIO')) : Access::usersWithRole('super');
            Audit::notify($to, $age > 15 ? 'تظلم متأخر عن 15 يومًا' : 'تظلم بانتظار البت', ($v->employee?->name).' — قُدّم قبل '.$age.' يومًا، ويُبت فيه خلال 15 يومًا من تقديمه (§105)', $age >= 15 ? 'bad' : 'warn', ['screen' => 'violations']);
            if ($age > 15) {
                Audit::notify(Access::usersWithRole('super'), 'تظلم تجاوز مهلة البت', ($v->employee?->name).' — مضى '.$age.' يومًا (§105)', 'bad', ['screen' => 'violations']);
            }
        }
        $this->info("daily-note alerts: $n");

        return self::SUCCESS;
    }
}
