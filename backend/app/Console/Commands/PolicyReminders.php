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
    protected $signature = 'hc:policy-reminders';

    protected $description = 'Daily note, statutory leave scheduling and trip report reminders';

    public function handle(): int
    {
        $y = now()->subDay()->toDateString();
        $n = 0;
        foreach (AttendanceRecord::where('work_date', $y)->where('note_required', true)->whereNull('note')->with('employee')->get() as $rec) {
            $u = User::where('employee_id', $rec->employee_id)->value('id');
            if ($u) {
                Audit::notify($u, 'لم تكتب المنجز اليومي', 'منجز يوم '.$y.' غير مكتوب — يُخصم درجة من بند المنجز اليومي (§33). اكتبه من شاشة الحضور.', 'warn', ['screen' => 'attendance']);
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
        $this->info("daily-note alerts: $n");

        return self::SUCCESS;
    }
}
