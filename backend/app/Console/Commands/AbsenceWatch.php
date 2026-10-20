<?php

namespace App\Console\Commands;

use App\Models\AbsenceAlert;
use App\Models\ApprovalRequest;
use App\Models\Employee;
use App\Services\Access;
use App\Services\AttendanceService;
use App\Services\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily §100–101 absence watch. Counts scheduled working days without attendance, approved leave or trip in the
 * current contract year (from the last hire anniversary) and raises alerts for HR:
 *   - 10 consecutive days  → written warning before dismissal (consecutive10)
 *   - > 15 consecutive days → consider dismissal under art. 80 (consecutive15)
 *   - 20 scattered days    → written warning before dismissal (scattered20)
 *   - > 30 scattered days  → consider dismissal under art. 80 (scattered30)
 * Alerts are suggestions only (shown in violations → suggestions); nothing is imposed automatically (§95, §101).
 */
class AbsenceWatch extends Command
{
    protected $signature = 'hc:absence-watch {--employee= : one employee id}';

    protected $description = 'Detect consecutive / scattered unexcused absence thresholds (policy §100–101)';

    public function handle(): int
    {
        $new = 0;
        $q = Employee::where('status', '!=', 'terminated')->where('punch_required', true);
        if ($this->option('employee')) {
            $q->whereKey((int) $this->option('employee'));
        }
        foreach ($q->get() as $e) {
            $new += $this->scan($e);
        }
        $this->info("absence alerts created: $new");

        return self::SUCCESS;
    }

    /** Contract year start: the last anniversary of the hire date on or before today. */
    public static function yearStart(Employee $e): Carbon
    {
        $hire = Carbon::parse($e->hire_date)->startOfDay();
        $y = $hire->copy()->year(now()->year);
        if ($y->isFuture()) {
            $y->subYear();
        }

        return $y->lt($hire) ? $hire : $y;
    }

    /** Unexcused absent working days in [from, to]. */
    public static function absentDays(Employee $e, Carbon $from, Carbon $to): array
    {
        $recs = $e->attendance()->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->get()->keyBy(fn ($r) => $r->work_date->toDateString());
        $leaves = ApprovalRequest::where('employee_id', $e->id)->whereIn('type', ['leave', 'trip'])->where('status', 'approved')->get(['data']);
        $out = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            if (! AttendanceService::isWorkDay($e, $d)) {
                continue;
            }
            $ds = $d->toDateString();
            $r = $recs[$ds] ?? null;
            if ($r && $r->status !== 'absent') {
                continue;
            }
            if ($leaves->contains(fn ($l) => ($l->data['from'] ?? '') <= $ds && ($l->data['to'] ?? '') >= $ds)) {
                continue;
            }
            $out[] = $ds;
        }

        return $out;
    }

    private function scan(Employee $e): int
    {
        $first = $e->attendance()->min('work_date');
        if (! $first) {
            return 0; // never punched on the platform: no basis to count absence
        }
        $start = self::yearStart($e);
        $from = max($start, Carbon::parse($first)->startOfDay());
        $to = now()->subDay()->startOfDay();
        if ($from->gt($to)) {
            return 0;
        }
        $days = self::absentDays($e, $from, $to);
        $created = 0;
        // consecutive streaks of scheduled working days (non-working days do not break a streak)
        $streaks = [];
        $cur = [];
        $prevWork = null;
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            if (! AttendanceService::isWorkDay($e, $d)) {
                continue;
            }
            $ds = $d->toDateString();
            if (in_array($ds, $days, true)) {
                $cur[] = $ds;
            } else {
                if (count($cur) >= 10) {
                    $streaks[] = $cur;
                }
                $cur = [];
            }
            $prevWork = $ds;
        }
        if (count($cur) >= 10) {
            $streaks[] = $cur;
        }
        foreach ($streaks as $s) {
            $created += $this->raise($e, 'consecutive10', $s, $start);
            if (count($s) > 15) {
                $created += $this->raise($e, 'consecutive15', $s, $start);
            }
        }
        if (count($days) >= 20) {
            $created += $this->raise($e, 'scattered20', $days, $start, true);
        }
        if (count($days) > 30) {
            $created += $this->raise($e, 'scattered30', $days, $start, true);
        }

        return $created;
    }

    private function raise(Employee $e, string $kind, array $dates, Carbon $yearStart, bool $scattered = false): int
    {
        $key = ['employee_id' => $e->id, 'kind' => $kind, 'year_start' => $yearStart->toDateString(), 'from' => $scattered ? $yearStart->toDateString() : $dates[0]];
        $existing = AbsenceAlert::where($key)->first();
        if ($existing) {
            if ($existing->status === 'open') {
                $existing->update(['days' => count($dates), 'to' => end($dates), 'dates' => $dates]);
            }

            return 0;
        }
        $a = AbsenceAlert::create($key + ['company_id' => $e->company_id, 'days' => count($dates), 'to' => end($dates), 'dates' => $dates, 'status' => 'open']);
        Audit::notify(Access::hrUsersFor($e->company_id, 'VIO'), 'تنبيه انقطاع عن العمل — '.$e->name, AbsenceAlert::KINDS[$kind].' · تحقق من العذر ووجّه الإنذار الكتابي النظامي', in_array($kind, ['consecutive15', 'scattered30'], true) ? 'bad' : 'warn', ['screen' => 'violations']);
        Audit::log('absence_alerts', $a->id, 'create', null, ['employee' => $e->id, 'kind' => $kind, 'days' => count($dates)]);

        return 1;
    }
}
